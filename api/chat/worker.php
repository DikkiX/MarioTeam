<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';
ignore_user_abort(true);
set_time_limit(0);


if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    exit('Database verbinding ontbreekt.');
}
// Dit script verwerkt de chat-wachtrij.
// Het pakt het oudste bericht, maakt een antwoord en slaat dat op in de database.
function schrijfWorkerLog($message)
{
    // Logbestand voor testen en foutzoeken.
    $logMap = $_SERVER['DOCUMENT_ROOT'] . '/storage/logs';
    $logBestand = $logMap . '/chat_worker.log';

    if (!is_dir($logMap)) {
        mkdir($logMap, 0775, true);
    }

    $regel = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logBestand, $regel, FILE_APPEND);
}

function haalDashboardToneOfVoice($conn)
{
    // Tone of voice komt uit de dashboard instellingen.
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM dashboard_settings WHERE setting_key = 'tone_of_voice' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || !isset($row['setting_value'])) {
            return '';
        }
        return trim((string) $row['setting_value']);
    } catch (Throwable $e) {
        return '';
    }
}

function haalWorkerSecretUitRequest()
{
    // De secret kan als header of als POST-field meegegeven worden.
    $headerSecret = $_SERVER['HTTP_X_WORKER_SECRET'] ?? '';
    if (is_string($headerSecret) && trim($headerSecret) !== '') {
        return trim((string) $headerSecret);
    }

    $postSecret = $_POST['worker_secret'] ?? '';
    if (is_string($postSecret) && trim($postSecret) !== '') {
        return trim((string) $postSecret);
    }

    return '';
}

function zorgDashboardSettingsTabel($conn)
{
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `dashboard_settings` (
            `setting_key` VARCHAR(64) NOT NULL,
            `setting_value` LONGTEXT NOT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

function haalOfMaakWorkerSecret($conn)
{
    // Dit is de secret die het worker endpoint beschermt.
    // Als hij nog niet bestaat, maken we hem 1 keer aan en slaan we hem op in de database.
    try {
        zorgDashboardSettingsTabel($conn);
        $stmt = $conn->prepare("SELECT setting_value FROM dashboard_settings WHERE setting_key = 'chat_worker_secret' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && isset($row['setting_value']) && trim((string) $row['setting_value']) !== '') {
            return trim((string) $row['setting_value']);
        }

        $nieuw = bin2hex(random_bytes(32));
        $save = $conn->prepare("
            INSERT INTO dashboard_settings (setting_key, setting_value)
            VALUES ('chat_worker_secret', :v)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $save->execute([':v' => $nieuw]);
        return $nieuw;
    } catch (Throwable) {
        return '';
    }
}

// Hiermee werken we het queue-bericht bij in de database.
// Zo kunnen we status en antwoord veilig opslaan.
function updateChatQueueBericht($conn, $berichtId, $status, $aiResponse = null)
{
    // Werk status en antwoord bij voor 1 bericht in de wachtrij.
    $stmt = $conn->prepare("
        UPDATE chat_queue
        SET ai_response = :ai_response, status = :status
        WHERE id = :id
    ");
    $stmt->execute([
        ':ai_response' => $aiResponse,
        ':status' => $status,
        ':id' => $berichtId,
    ]);
}

// Dit zijn de interne functies die OpenAI mag gebruiken.
// Zo kan het model live data opvragen in plaats van gokken.
function bouwToolsVoorOpenAi()
{
    // Dit zijn functies die de AI mag gebruiken om live dingen op te zoeken.
    return [
        [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_bestelling',
                'description' => 'Zoek live besteldata op in de tabel Bestellingen en haal (waar mogelijk) ook de artikelen uit de bestelling op. Gebruik dit alleen als de klant zowel een bestelnummer als hetzelfde e-mailadres geeft dat bij de bestelling hoort.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bestelling_id' => [
                            'type' => 'integer',
                            'description' => 'Het bestelnummer van de klant.',
                        ],
                        'email' => [
                            'type' => 'string',
                            'description' => 'Het e-mailadres dat bij de bestelling hoort.',
                        ],
                    ],
                    'required' => ['bestelling_id', 'email'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_productvoorraad',
                'description' => 'Zoek live product- en voorraadinfo op in de tabellen Winkel en info.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'zoekterm' => [
                            'type' => 'string',
                            'description' => 'Titel of deel van de titel van het product.',
                        ],
                    ],
                    'required' => ['zoekterm'],
                    'additionalProperties' => false,
                ],
            ],
        ],
    ];
}

// Deze functie kijkt of een bericht duidelijk over een bestelling gaat.
// Als bestelnummer en e-mail allebei in de tekst staan, kunnen we de functie afdwingen.
function bepaalGeforceerdeToolChoice($berichtTekst)
{
    // Als er én een bestelnummer én een e-mail in de tekst staat, gaan we meteen zoeken.
    $heeftBestelWoord = preg_match('/bestelling|bestelnummer|order|status|inhoud|artikelen|orderregels|wat heb ik besteld|wat zit er/i', $berichtTekst) === 1;
    $heeftEmail = preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $berichtTekst) === 1;
    $heeftBestelnummer = preg_match('/\b\d{4,}\b/', $berichtTekst) === 1;

    if ($heeftBestelWoord && $heeftEmail && $heeftBestelnummer) {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_bestelling',
            ],
        ];
    }

    return 'auto';
}

// Deze functie stuurt berichten naar OpenAI.
// Als we tools meegeven, mag het model ook een functie aanroepen.
function roepOpenAiAan($messages, $tools = [], $toolChoice = 'auto')
{
    // Vraag OpenAI om een antwoord. Soms vraagt OpenAI om extra info via een functie.
    global $conn;
    $apiKey = getProjectEnvValue('OPENAI_API_KEY');

    if ($apiKey === null || $apiKey === '') {
        schrijfWorkerLog('OpenAI key ontbreekt.');
        return null;
    }

    $tone = '';
    if (isset($conn) && $conn) {
        $tone = haalDashboardToneOfVoice($conn);
    }
    if (is_string($tone) && $tone !== '') {
        array_unshift($messages, [
            'role' => 'system',
            'content' => "Tone of voice instructies:\n" . $tone,
        ]);
    }

    // Model keuze zoals in ChatFunction.php:
    // CHAT_MODEL_MODE = 1 (duurste), 2 (prijs/kwaliteit), 3 (snel).
    // Default is 2 zodat de worker standaard hetzelfde gedrag heeft als ChatFunction (gpt-5-mini).
    $temperature = 0.2;
    $model = 2;
    if (function_exists('getProjectEnvValue')) {
        $mode = getProjectEnvValue('CHAT_MODEL_MODE');
        if (is_string($mode) && preg_match('/^\d+$/', $mode) === 1) {
            $model = (int) $mode;
        } elseif (is_int($mode)) {
            $model = $mode;
        }
    }
    if ($model == 1) {
        $model = 'gpt-5.2';
    } elseif ($model == 2) {
        // In ChatFunction wordt mode 2 met temperature 1 gebruikt.
        $temperature = 1;
        $model = 'gpt-5-mini';
    } elseif ($model == 3) {
        // Snelste/stabiele fallback.
        $temperature = 1;
        $model = 'gpt-4.1-mini';
    } elseif (!is_string($model) || $model === '') {
        $model = 'gpt-4.1-mini';
    }

    $data = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'max_completion_tokens' => 1200,
    ];

    if (!empty($tools)) {
        $data['tools'] = $tools;
        $data['tool_choice'] = $toolChoice;
    }

    // We doen hier een gewone API-call naar OpenAI.
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        schrijfWorkerLog('OpenAI fout: ' . $error);
        return null;
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        schrijfWorkerLog('OpenAI gaf geen geldige JSON terug.');
        return null;
    }

    return $decoded;
}

// Dit is een "korte" OpenAI-call zonder extra dashboard tone-of-voice.
// We gebruiken deze alleen voor system0 (onderwerp bepalen), zodat die stap snel en voorspelbaar blijft.
function roepOpenAiAanZonderTone($messages, $tools = [], $toolChoice = 'auto', $maxTokens = 200)
{
    $apiKey = getProjectEnvValue('OPENAI_API_KEY');

    if ($apiKey === null || $apiKey === '') {
        schrijfWorkerLog('OpenAI key ontbreekt.');
        return null;
    }

    // Zelfde model keuze als roepOpenAiAan(), maar zonder extra dashboard tone-of-voice.
    // Dit is bedoeld voor system0 (onderwerp bepalen).
    $temperature = 0.2;
    $model = 2;
    if (function_exists('getProjectEnvValue')) {
        $mode = getProjectEnvValue('CHAT_MODEL_MODE');
        if (is_string($mode) && preg_match('/^\d+$/', $mode) === 1) {
            $model = (int) $mode;
        } elseif (is_int($mode)) {
            $model = $mode;
        }
    }
    if ($model == 1) {
        $model = 'gpt-5.2';
    } elseif ($model == 2) {
        $temperature = 1;
        $model = 'gpt-5-mini';
    } elseif ($model == 3) {
        $temperature = 1;
        $model = 'gpt-4.1-mini';
    } elseif (!is_string($model) || $model === '') {
        $model = 'gpt-4.1-mini';
    }

    $data = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'max_completion_tokens' => (int) $maxTokens,
    ];

    if (!empty($tools)) {
        $data['tools'] = $tools;
        $data['tool_choice'] = $toolChoice;
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        schrijfWorkerLog('OpenAI fout: ' . $error);
        return null;
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        schrijfWorkerLog('OpenAI gaf geen geldige JSON terug.');
        return null;
    }

    return $decoded;
}

// Kleine helper voor case-insensitive vergelijken (met UTF-8 support als mbstring aanwezig is).
function lowerTekst($text)
{
    $t = (string) $text;
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($t, 'UTF-8');
    }
    return strtolower($t);
}

// Parser: in onze DB staan de artikelregels als tekst in Bestellingen.items.
// We zetten die tekst om naar een nette lijst met producten + aantallen (en soms prijs).
function parseBestellingItemsTekst($itemsTekst)
{
    $t = trim((string) $itemsTekst);
    if ($t === '') {
        return [];
    }

    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $delen = preg_split('/\n+/', $t);
    if (!is_array($delen)) {
        $delen = [$t];
    }

    $samengevoegd = [];

    foreach ($delen as $deel) {
        $regel = trim((string) $deel);
        if ($regel === '') {
            continue;
        }

        if (preg_match('/^(korting|betaal|betaling)\s*[:=]/i', $regel) === 1) {
            continue;
        }
        if (preg_match('/^verzendkosten\s*[:=]/i', $regel) === 1) {
            continue;
        }
        if (preg_match('/^totaal\s*[:=]/i', $regel) === 1) {
            continue;
        }

        $aantal = 1;
        $naam = $regel;
        $prijsEuro = null;

        if (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)\s*$/u', $regel, $m) === 1) {
            $aantal = (int) $m[1];
            $naam = trim((string) $m[2]);
        }

        if (preg_match('/^(.*?)\s*->\s*([\d.,]+)\s*euro\s*$/i', (string) $naam, $m) === 1) {
            $naam = trim((string) $m[1]);
            $rawPrijs = str_replace(',', '.', (string) $m[2]);
            if (is_numeric($rawPrijs)) {
                $prijsEuro = (float) $rawPrijs;
            }
        }

        if ($aantal <= 0) {
            $aantal = 1;
        }
        if ($naam === '') {
            continue;
        }

        $prijsKey = $prijsEuro === null ? '' : number_format($prijsEuro, 2, '.', '');
        $key = lowerTekst($naam) . '|' . $prijsKey;
        if (!isset($samengevoegd[$key])) {
            $samengevoegd[$key] = [
                'productnaam' => $naam,
                'aantal' => 0,
                'prijs_euro' => $prijsEuro,
            ];
        }
        $samengevoegd[$key]['aantal'] += $aantal;
    }

    $artikelen = array_values($samengevoegd);
    usort($artikelen, function ($a, $b) {
        return strcmp((string) ($a['productnaam'] ?? ''), (string) ($b['productnaam'] ?? ''));
    });

    return $artikelen;
}

// Parser: probeert verzendkosten/totaal uit dezelfde "items" tekst te halen.
function parseBestellingKostenTekst($itemsTekst)
{
    $t = trim((string) $itemsTekst);
    if ($t === '') {
        return [
            'verzendkosten_euro' => null,
            'totaal_euro' => null,
        ];
    }

    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $verzend = null;
    $totaal = null;
    $delen = preg_split('/\n+/', $t);
    if (!is_array($delen)) {
        $delen = [$t];
    }

    foreach ($delen as $deel) {
        $regel = trim((string) $deel);
        if ($regel === '') {
            continue;
        }
        if (preg_match('/^verzendkosten\s*:\s*([\d.,]+)\s*euro\s*$/i', $regel, $m) === 1) {
            $raw = str_replace(',', '.', (string) $m[1]);
            if (is_numeric($raw)) {
                $verzend = (float) $raw;
            }
        }
        if (preg_match('/^totaal\s*:\s*([\d.,]+)\s*euro\s*$/i', $regel, $m) === 1) {
            $raw = str_replace(',', '.', (string) $m[1]);
            if (is_numeric($raw)) {
                $totaal = (float) $raw;
            }
        }
    }

    return [
        'verzendkosten_euro' => $verzend,
        'totaal_euro' => $totaal,
    ];
}

// Probeert uit een tracktrace veld een echte track&trace code te halen.
function haalTrackCodeUitTracktrace($tracktrace)
{
    $tt = trim((string) $tracktrace);
    if ($tt === '') {
        return '';
    }

    $delen = preg_split('/\|+/', $tt);
    if (!is_array($delen) || empty($delen)) {
        return '';
    }

    for ($i = count($delen) - 1; $i >= 0; $i--) {
        $candidate = trim((string) $delen[$i]);
        if ($candidate === '') {
            continue;
        }
        if (preg_match('/^[A-Z0-9]{6,}$/i', $candidate) === 1) {
            return $candidate;
        }
    }

    return '';
}

// Bestelling ophalen uit de Bestellingen tabel.
function haalBestellingOp($conn, $bestellingId, $email)
{
    try {
        $bestellingId = (int) $bestellingId;
        $email = trim((string) $email);
        if ($bestellingId <= 0 || $email === '') {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT
                id,
                betaling,
                verzendkosten,
                totaal,
                totaal_site,
                status,
                verzending,
                datum,
                PayStatus,
                tracktrace,
                items,
                inpakdatum
            FROM Bestellingen
            WHERE id = :id AND mail = :email
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $bestellingId,
            ':email' => $email,
        ]);
        return $stmt->fetch();
    } catch (Throwable) {
        return false;
    }
}

// Hier voeren we de echte databasefunctie uit die OpenAI vraagt.
// We geven daarna alleen ruwe data terug, nog geen mooi klantantwoord.
function voerInterneFunctieUit($conn, $functieNaam, $arguments)
{
    if ($functieNaam === 'zoek_bestelling') {
        // Voor orderdata eisen we nu altijd 2 gegevens:
        // bestelnummer + hetzelfde e-mailadres als in de bestelling.
        $bestellingId = isset($arguments['bestelling_id']) ? (int) $arguments['bestelling_id'] : 0;
        $email = isset($arguments['email']) ? trim((string) $arguments['email']) : '';

        if ($bestellingId <= 0 || $email === '') {
            schrijfWorkerLog('Bestelling-validatie mislukt: bestelnummer of e-mail ontbreekt.');
            return [
                'functie' => 'zoek_bestelling',
                'gevonden' => false,
                'message' => 'Voor orderdata zijn zowel bestelling_id als email verplicht.',
            ];
        }

        // Eerst controleren we of het bestelnummer echt bij dit e-mailadres hoort.
        $validatieStmt = $conn->prepare("
            SELECT id
            FROM Bestellingen
            WHERE id = :id AND mail = :email
            LIMIT 1
        ");
        $validatieStmt->execute([
            ':id' => $bestellingId,
            ':email' => $email,
        ]);
        $validatieResultaat = $validatieStmt->fetch();

        if (!$validatieResultaat) {
            schrijfWorkerLog('Bestelling-validatie mislukt voor bestelling ' . $bestellingId . '.');
            return [
                'functie' => 'zoek_bestelling',
                'gevonden' => false,
                'message' => 'De combinatie van bestelling_id en email klopt niet.',
            ];
        }

        $resultaat = haalBestellingOp($conn, $bestellingId, $email);

        $artikelenInfo = [
            'gevonden' => false,
            'artikelen' => [],
            'bron' => '',
            'message' => '',
        ];
        if ($resultaat !== false) {
            $itemsTekst = isset($resultaat['items']) ? trim((string) $resultaat['items']) : '';
            if ($itemsTekst !== '') {
                $fallback = parseBestellingItemsTekst($itemsTekst);
                if (!empty($fallback)) {
                    $artikelenInfo = [
                        'gevonden' => true,
                        'artikelen' => $fallback,
                        'bron' => 'Bestellingen.items',
                        'message' => '',
                    ];
                } else {
                    $artikelenInfo['message'] = 'Geen artikelregels gevonden in bestelling.';
                }
            } else {
                $artikelenInfo['message'] = 'Geen items gevonden in bestelling.';
            }
        }

        $verzendStatus = 'onbekend';
        $tracktrace = is_array($resultaat) && isset($resultaat['tracktrace']) ? trim((string) $resultaat['tracktrace']) : '';
        $statusTekst = is_array($resultaat) && isset($resultaat['status']) ? trim((string) $resultaat['status']) : '';
        $verzendingTekst = is_array($resultaat) && isset($resultaat['verzending']) ? trim((string) $resultaat['verzending']) : '';
        $trackCode = haalTrackCodeUitTracktrace($tracktrace);

        $heeftVerzendTekst = $verzendingTekst !== '' && preg_match('/\bverzonden\b/i', $verzendingTekst) === 1;
        $heeftInpakDatum = is_array($resultaat) && isset($resultaat['inpakdatum']) && (int) $resultaat['inpakdatum'] > 0;
        $statusIsVerzonden = $statusTekst === '3';

        if ($trackCode !== '') {
            $verzendStatus = 'verzonden';
        } elseif ($statusIsVerzonden) {
            $verzendStatus = 'verzonden';
        } elseif ($heeftVerzendTekst) {
            $verzendStatus = 'verzonden';
        } elseif ($heeftInpakDatum) {
            $verzendStatus = 'niet_verzonden';
        } else {
            $verzendStatus = 'niet_verzonden';
        }

        if (is_array($resultaat)) {
            $resultaat['verzend_status'] = $verzendStatus;
            $resultaat['track_code'] = $trackCode;
        }

        if (is_array($resultaat) && isset($resultaat['items']) && trim((string) $resultaat['items']) !== '') {
            $kostenUitItems = parseBestellingKostenTekst($resultaat['items']);
            if (
                (!isset($resultaat['verzendkosten']) || (float) $resultaat['verzendkosten'] <= 0)
                && $kostenUitItems['verzendkosten_euro'] !== null
            ) {
                $resultaat['verzendkosten'] = $kostenUitItems['verzendkosten_euro'];
            }
            if (
                (!isset($resultaat['totaal']) || (float) $resultaat['totaal'] <= 0)
                && $kostenUitItems['totaal_euro'] !== null
            ) {
                $resultaat['totaal'] = $kostenUitItems['totaal_euro'];
            }
        }

        $itemsLen = is_array($resultaat) && isset($resultaat['items']) ? strlen((string) $resultaat['items']) : 0;
        schrijfWorkerLog('Bestelling ' . $bestellingId . ' items_len=' . $itemsLen . ' artikelen_count=' . count((array) ($artikelenInfo['artikelen'] ?? [])));

        return [
            'functie' => 'zoek_bestelling',
            'gevonden' => $resultaat !== false,
            'resultaat' => $resultaat,
            'artikelen' => $artikelenInfo['artikelen'] ?? [],
            'artikelen_gevonden' => (bool) ($artikelenInfo['gevonden'] ?? false),
            'artikelen_bron' => (string) ($artikelenInfo['bron'] ?? ''),
            'artikelen_message' => (string) ($artikelenInfo['message'] ?? ''),
        ];
    }

    if ($functieNaam === 'zoek_productvoorraad') {
        global $univ_web;
        // Hiermee kan de AI live voorraad en productinfo opvragen.
        $zoekterm = isset($arguments['zoekterm']) ? trim((string) $arguments['zoekterm']) : '';

        if ($zoekterm === '') {
            return [
                'functie' => 'zoek_productvoorraad',
                'gevonden' => false,
                'message' => 'Er is geen zoekterm meegegeven.',
            ];
        }

        // We zoeken op titel of link en geven de beste matches terug.
        $zoektermLike = '%' . $zoekterm . '%';
        $stmt = $conn->prepare("
            SELECT 
                w.nr,
                w.titel,
                w.link,
                w.prijs,
                w.sentence,
                CASE WHEN w.aantal > 0 THEN 'ja' ELSE 'nee' END AS op_voorraad,
                i.leeftijd,
                i.spelers,
                i.GemCijfer,
                i.TotBeoord
            FROM Winkel w
            LEFT JOIN info i ON i.link = w.link
            WHERE w.titel LIKE :zoekterm_titel OR w.link LIKE :zoekterm_link
            ORDER BY w.aantal DESC, w.prijs ASC
            LIMIT 5
        ");
        $stmt->execute([
            ':zoekterm_titel' => $zoektermLike,
            ':zoekterm_link' => $zoektermLike,
        ]);

        $resultaat = $stmt->fetchAll();

        $basisUrl = '';
        if (isset($univ_web) && is_string($univ_web) && $univ_web !== '') {
            $basisUrl = 'https://www.' . $univ_web;
        }

        if (!empty($resultaat) && $basisUrl !== '') {
            foreach ($resultaat as $idx => $row) {
                $link = isset($row['link']) ? trim((string) $row['link']) : '';
                if ($link === '') {
                    continue;
                }

                if (preg_match('/^https?:\/\//i', $link) === 1) {
                    $resultaat[$idx]['product_url'] = $link;
                    continue;
                }

                if (preg_match('/^www\./i', $link) === 1) {
                    $resultaat[$idx]['product_url'] = 'https://' . $link;
                    continue;
                }

                $resultaat[$idx]['product_url'] = $basisUrl . '/' . ltrim($link, '/');
            }
        }

        return [
            'functie' => 'zoek_productvoorraad',
            'gevonden' => !empty($resultaat),
            'resultaat' => $resultaat,
        ];
    }

    return [
        'functie' => $functieNaam,
        'gevonden' => false,
        'message' => 'Onbekende functie aangevraagd.',
    ];
}

// Hiermee halen we eerdere berichten van dezelfde bezoeker op.
// Zo kan de chatbot vervolgvragen beter begrijpen.
function haalGespreksContextOp($conn, $cookie, $actiefBerichtId, $maxBerichten = 6)
{
    if ($cookie === '') {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT id, user_message, ai_response
        FROM chat_queue
        WHERE cookie = :cookie
          AND id < :actief_id
          AND status = 'completed'
        ORDER BY id DESC
        LIMIT :max_berichten
    ");
    $stmt->bindValue(':cookie', $cookie, PDO::PARAM_STR);
    $stmt->bindValue(':actief_id', $actiefBerichtId, PDO::PARAM_INT);
    $stmt->bindValue(':max_berichten', $maxBerichten, PDO::PARAM_INT);
    $stmt->execute();

    $resultaten = $stmt->fetchAll();

    if (empty($resultaten)) {
        return [];
    }

    $resultaten = array_reverse($resultaten);
    $contextMessages = [];

    foreach ($resultaten as $vorigBericht) {
        if (!empty($vorigBericht['user_message'])) {
            $contextMessages[] = [
                'role' => 'user',
                'content' => $vorigBericht['user_message'],
            ];
        }

        if (!empty($vorigBericht['ai_response'])) {
            $contextMessages[] = [
                'role' => 'assistant',
                'content' => $vorigBericht['ai_response'],
            ];
        }
    }

    return $contextMessages;
}

// FAQ/contact bestanden bevatten veel HTML. De chat-UI zet HTML om naar tekst,
// maar voor de prompt is het fijner om dit alvast naar "gewone tekst" te strippen.
function stripHtmlNaarTekst($html)
{
    $t = (string) $html;
    $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t);
    $t = preg_replace('/<\/\s*p\s*>\s*<\s*p[^>]*>/i', "\n\n", $t);
    $t = preg_replace('/<\s*\/?p[^>]*>/i', '', $t);
    $t = preg_replace('/<\s*li[^>]*>/i', "\n- ", $t);
    $t = preg_replace('/<\s*\/li\s*>/i', '', $t);
    $t = preg_replace('/<\s*\/?ul[^>]*>/i', "\n", $t);
    $t = preg_replace('/<\s*\/?ol[^>]*>/i', "\n", $t);
    $t = preg_replace('/<[^>]+>/', '', $t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace("\r\n", "\n", $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim((string) $t);
}

// Dit is de  "system0" stap:
// 1) we geven system0 + (optioneel) context + nieuwe uservraag aan OpenAI
// 2) OpenAI geeft een korte label terug zoals: **Aankoop**Switch**
// Daarna gebruiken we dat label om te bepalen welke FAQ files we includen.
function haalAssistant0VoorBericht($contextMessages, $userMessage)
{
    include_once $_SERVER['DOCUMENT_ROOT'] . '/include/ChatGPT/system0.php';
    $system0 = isset($system0) ? trim((string) $system0) : '';
    if ($system0 === '') {
        return '';
    }

    $messages = [
        [
            'role' => 'system',
            'content' => $system0,
        ],
    ];

    if (is_array($contextMessages)) {
        foreach ($contextMessages as $m) {
            if (isset($m['role'], $m['content'])) {
                $messages[] = $m;
            }
        }
    }

    $messages[] = [
        'role' => 'user',
        'content' => (string) $userMessage,
    ];

    $resp = roepOpenAiAanZonderTone($messages, [], 'auto', 200);
    if (!is_array($resp) || !isset($resp['choices'][0]['message'])) {
        return '';
    }

    $content = $resp['choices'][0]['message']['content'] ?? '';
    return is_string($content) ? trim($content) : '';
}

// Bepaalt platform uit system0-output (bijv. "Switch"). Als system0 geen platform geeft,
// gebruiken we de universe fallback (bijv. $univ_one).
function bepaalPlatformUitAssistant0($assistant0, $fallback)
{
    $platformArray = ["Switch", "Wii U", "3DS", "Wii", "DS", "GC", "GBA", "N64", "SNES"];
    foreach ($platformArray as $value) {
        if (preg_match("/$value/i", (string) $assistant0)) {
            return $value;
        }
    }
    return (string) $fallback;
}

// Bouwt de system prompt op met bestaande include-files.
// Belangrijk: we kopiëren geen grote tekstblokken uit ChatGptMrM.php,
// we gebruiken de bestaande FAQ arrays uit include/ChatGPT/*.php.
function bouwSystem1MetIncludes($assistant0, $platform)
{
    global $univ_one, $univ_web, $univ_nin, $univ_web_text, $univ_mar, $univ_zoeken;

    include_once $_SERVER['DOCUMENT_ROOT'] . '/include/ChatGPT/mrM.php';
    $systemMrM = isset($systemMrM) ? (string) $systemMrM : '';
    $systemMrmPersoonlijk = isset($systemMrmPersoonlijk) ? (string) $systemMrmPersoonlijk : '';

    $system1 = $systemMrM;
    if (preg_match("/Persoonlijk/i", (string) $assistant0)) {
        $system1 .= $systemMrmPersoonlijk;
    }

    $FAQ = [];
    if (preg_match("/Aankoop/i", (string) $assistant0) == 1) {
        include_once $_SERVER['DOCUMENT_ROOT'] . "/include/ChatGPT/aankoop.php";
    }
    if (preg_match("/Zending/i", (string) $assistant0) == 1) {
        include_once $_SERVER['DOCUMENT_ROOT'] . "/include/ChatGPT/zending.php";
    }
    if (preg_match("/Inkoop/i", (string) $assistant0) == 1) {
        include_once $_SERVER['DOCUMENT_ROOT'] . "/include/ChatGPT/inkoop.php";
    }
    if (preg_match("/Service/i", (string) $assistant0) == 1) {
        include_once $_SERVER['DOCUMENT_ROOT'] . "/include/ChatGPT/service.php";
    }
    if (preg_match("/Loyaliteit/i", (string) $assistant0) == 1) {
        include_once $_SERVER['DOCUMENT_ROOT'] . "/include/ChatGPT/loyaliteit.php";
    }

    $FAQ = isset($FAQ) && is_array($FAQ) ? $FAQ : [];
    if (!empty($FAQ)) {
        $system1 .= "\n\nC. FAQ op onze website\n";
        foreach ($FAQ as $valueArray) {
            foreach ((array) $valueArray as $tonenSiteTextArr) {
                if (!isset($tonenSiteTextArr['site'], $tonenSiteTextArr['text'])) {
                    continue;
                }
                if (($tonenSiteTextArr['site'] == $platform) || ($tonenSiteTextArr['site'] == 'All')) {
                    $system1 .= "\n\n" . stripHtmlNaarTekst($tonenSiteTextArr['text']);
                }
            }
        }
    }

    include_once $_SERVER['DOCUMENT_ROOT'] . "/include/mijnemail_universeel.inc";
    $bodymain3 = '';
    include_once $_SERVER['DOCUMENT_ROOT'] . "/include/contact.inc";
    $bodymain3 = isset($bodymain3) ? (string) $bodymain3 : '';
    if (trim($bodymain3) !== '') {
        $system1 .= "\n\nD. Contactgegevens\n" . stripHtmlNaarTekst($bodymain3);
    }

    return $system1;
}

// Dit maakt het gesprek voor OpenAI.
// Eerst voegen we wat eerdere context toe en daarna de nieuwste vraag.
function maakBerichtenVoorOpenAi($conn, $bericht)
{
    global $univ_one, $univ_web, $univ_nin, $univ_web_text, $univ_mar, $univ_zoeken;

    $basisPrompt = 'Je bent een klantenservice assistent voor MarioSwitch.nl. Als je live data nodig hebt, gebruik je een functie. Geef geen data op basis van aannames als een functie nodig is. Noem nooit exacte voorraadaantallen aan klanten. Zeg alleen of iets op voorraad is of niet. Voor orderdata moet de klant eerst zowel een bestelnummer als het juiste e-mailadres geven. Als je via zoek_bestelling artikelen terugkrijgt en artikelen_gevonden true is, presenteer die als een nette lijst met per regel: "{aantal}x {productnaam} — {prijs} euro" (als prijs bekend is). Toon daarna altijd: "Verzendkosten: X euro" en "Totaal: Y euro" op basis van resultaat.verzendkosten en resultaat.totaal. Als artikelen_gevonden false is, zeg dan dat je de artikelregels nu niet kunt ophalen (en claim niet dat er geen artikelen zijn). Voor verzenden: gebruik resultaat.verzend_status (verzonden/niet_verzonden). Als resultaat.track_code gevuld is, toon die. Als track_code leeg is, zeg dat er (nog) geen track&trace code beschikbaar is. Bij bezorgtijden/verzenden/verzendkosten: gebruik alleen de info uit de FAQ die je hebt gekregen. Noem geen zelfbedachte levertijden zoals "1 tot 3 werkdagen". Als er geen exacte belofte staat, zeg dat het meestal de volgende werkdag is (bij bestelling voor 18:00), maar dat er geen 100% garantie is. Als de gebruiker vraagt of een game op voorraad is (of vraagt naar prijs/voorraad), roep altijd de functie zoek_productvoorraad aan en baseer je antwoord alleen op die uitkomst.';

    // Stap 1: haal context (laatste afgeronde berichten) op uit de queue.
    $contextMessages = haalGespreksContextOp(
        $conn,
        (string) ($bericht['cookie'] ?? ''),
        (int) ($bericht['id'] ?? 0)
    );

    // Stap 2: draai system0 om onderwerp + platform te bepalen.
    $assistant0 = haalAssistant0VoorBericht($contextMessages, (string) ($bericht['user_message'] ?? ''));
    $platform = bepaalPlatformUitAssistant0($assistant0, isset($univ_one) ? (string) $univ_one : '');

    // Stap 3: bouw system1 op met tone-of-voice + relevante FAQ/contact info.
    // Dit zorgt dat de bot niet hoeft te gokken over bedrijfsinfo.
    $system1 = bouwSystem1MetIncludes($assistant0, $platform);
    $systemPrompt = $basisPrompt . "\n\n" . $system1;

    $messages = [
        [
            'role' => 'system',
            'content' => $systemPrompt,
        ],
    ];

    // Stap 4: voeg context + nieuwe uservraag toe.
    foreach ($contextMessages as $contextMessage) {
        $messages[] = $contextMessage;
    }

    // Als laatste komt altijd de nieuwe vraag van de gebruiker.
    return array_merge($messages, [
        [
            'role' => 'user',
            'content' => $bericht['user_message'],
        ]
    ]);
}

// De worker draait via een interne trigger.
// Zonder de juiste secret mag niemand dit endpoint gebruiken.
$requiredSecret = getProjectEnvValue('CHAT_WORKER_SECRET');
$requiredSecret = is_string($requiredSecret) ? trim($requiredSecret) : '';
if ($requiredSecret === '') {
    $requiredSecret = haalOfMaakWorkerSecret($conn);
}
if ($requiredSecret !== '') {
    if ((string) ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit('Alleen POST is toegestaan.');
    }

    $given = haalWorkerSecretUitRequest();
    if ($given === '' || !hash_equals($requiredSecret, $given)) {
        http_response_code(403);
        exit('Niet toegestaan.');
    }
} else {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) {
        http_response_code(405);
        exit('Alleen GET en POST zijn toegestaan.');
    }
}

$actiefBerichtId = 0;

try {
    $conn->beginTransaction();

    // We pakken altijd het oudste bericht dat nog op pending staat.
    $selectSql = "
        SELECT id, cookie, user_message, status, created_at
        FROM chat_queue
        WHERE status = :status
        ORDER BY created_at ASC, id ASC
        LIMIT 1
        FOR UPDATE
    ";
    $selectStmt = $conn->prepare($selectSql);
    $selectStmt->execute([
        ':status' => 'pending',
    ]);
    $bericht = $selectStmt->fetch();

    // Als er niets meer in de wachtrij staat, stoppen we meteen netjes.
    if (!$bericht) {
        $conn->commit();
        schrijfWorkerLog('Geen pending berichten gevonden.');
        echo 'Geen pending berichten gevonden.';
        exit;
    }

    $actiefBerichtId = (int) $bericht['id'];

    // Meteen op processing zetten voorkomt dat hetzelfde bericht twee keer gedaan wordt.
    $updateSql = "
        UPDATE chat_queue
        SET status = :nieuwe_status
        WHERE id = :id AND status = :oude_status
    ";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([
        ':nieuwe_status' => 'processing',
        ':id' => (int) $bericht['id'],
        ':oude_status' => 'pending',
    ]);

    if ($updateStmt->rowCount() !== 1) {
        $conn->rollBack();
        schrijfWorkerLog('Bericht kon niet op processing worden gezet.');
        http_response_code(409);
        exit('Bericht kon niet op processing worden gezet.');
    }

    $conn->commit();

    // Vanaf hier gaat het bericht echt naar OpenAI.
    schrijfWorkerLog('Bericht ' . $bericht['id'] . ' is op processing gezet.');

    $messages = maakBerichtenVoorOpenAi($conn, $bericht);
    $tools = bouwToolsVoorOpenAi();
    $userMessage = (string) ($bericht['user_message'] ?? '');
    $toolChoice = bepaalGeforceerdeToolChoice($userMessage);
    // Als de gebruiker naar voorraad/prijs vraagt willen we altijd live data ophalen
    // via zoek_productvoorraad, zodat de bot niet gaat gokken.
    if ($toolChoice === 'auto' && preg_match('/\b(op\s+voorraad|voorraad|beschikbaar|in\s+stock|prijs)\b/i', $userMessage) === 1) {
        $toolChoice = 'required';
    }

    if ($toolChoice !== 'auto') {
        if (is_array($toolChoice) && isset($toolChoice['function']['name'])) {
            schrijfWorkerLog('Worker forceert functie ' . (string) $toolChoice['function']['name'] . ' voor bericht ' . $bericht['id'] . '.');
        } elseif ($toolChoice === 'required') {
            schrijfWorkerLog('Worker forceert het gebruik van tools voor bericht ' . $bericht['id'] . '.');
        }
    }

    $eersteAntwoord = roepOpenAiAan($messages, $tools, $toolChoice);

    if (!isset($eersteAntwoord['choices'][0]['message'])) {
        updateChatQueueBericht($conn, $actiefBerichtId, 'error');
        schrijfWorkerLog('OpenAI gaf geen bruikbaar eerste antwoord terug.');
        exit('Worker kon geen eerste AI-antwoord maken.');
    }

    $assistantMessage = $eersteAntwoord['choices'][0]['message'];

    // Als OpenAI iets wil opzoeken, voeren we die functie hier uit.
    if (!empty($assistantMessage['tool_calls'])) {
        schrijfWorkerLog('OpenAI vroeg om een interne functie voor bericht ' . $bericht['id'] . '.');

        // We bewaren eerst het bericht van OpenAI, zodat het gesprek klopt.
        $messages[] = $assistantMessage;

        foreach ($assistantMessage['tool_calls'] as $toolCall) {
            $functieNaam = $toolCall['function']['name'] ?? '';
            $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);

            if (!is_array($arguments)) {
                $arguments = [];
            }

            schrijfWorkerLog('Functie aangeroepen: ' . $functieNaam);
            $functieResultaat = voerInterneFunctieUit($conn, $functieNaam, $arguments);
            $resultJson = json_encode($functieResultaat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $len = is_string($resultJson) ? strlen($resultJson) : 0;
            schrijfWorkerLog('Functie-resultaat ontvangen (' . $len . ' bytes).');

            // Hier geven we de gevonden data terug aan OpenAI.
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'],
                'content' => json_encode($functieResultaat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        // Nu maakt OpenAI met die data het echte antwoord.
        $tweedeAntwoord = roepOpenAiAan($messages);
        $definitiefAntwoord = $tweedeAntwoord['choices'][0]['message']['content'] ?? '';

        if ($definitiefAntwoord !== '') {
            updateChatQueueBericht($conn, $actiefBerichtId, 'completed', $definitiefAntwoord);
            schrijfWorkerLog('Definitief AI-antwoord gemaakt voor bericht ' . $bericht['id'] . ' (lengte ' . strlen((string) $definitiefAntwoord) . ').');
        } else {
            updateChatQueueBericht($conn, $actiefBerichtId, 'error');
            schrijfWorkerLog('Na het opzoeken kwam er geen definitief antwoord terug.');
            exit('Worker kon geen definitief AI-antwoord maken.');
        }
    } else {
        // Soms geeft OpenAI meteen al een normaal antwoord terug.
        $directAntwoord = $assistantMessage['content'] ?? '';

        if ($directAntwoord !== '') {
            updateChatQueueBericht($conn, $actiefBerichtId, 'completed', $directAntwoord);
            schrijfWorkerLog('OpenAI gaf direct antwoord zonder functie (lengte ' . strlen((string) $directAntwoord) . ').');
        } else {
            updateChatQueueBericht($conn, $actiefBerichtId, 'error');
            schrijfWorkerLog('OpenAI gaf geen tekst en ook geen functie terug.');
            exit('Worker kreeg geen bruikbaar AI-antwoord terug.');
        }
    }

    echo 'Bericht ' . $bericht['id'] . ' is verwerkt.';
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    if ($actiefBerichtId > 0) {
        try {
            updateChatQueueBericht($conn, $actiefBerichtId, 'error');
        } catch (Throwable $updateError) {
            schrijfWorkerLog('Kon error-status niet opslaan voor bericht ' . $actiefBerichtId . '.');
        }
    }

    // We loggen wat er misging, zodat testen makkelijker wordt.
    schrijfWorkerLog('Worker fout: ' . $e->getMessage());
    http_response_code(500);
    exit('Worker kon de wachtrij niet verwerken.');
}
