<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';
// Gedeelde order-lookup (wordt ook door EmailDashboard gebruikt).
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/bestelling_lookup.php';
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
        [
            'type' => 'function',
            'function' => [
                // Nieuwe tool om de "random suggesties" bug te voorkomen:
                // aanraders/alternatieven moeten altijd uit de echte database komen (en op voorraad zijn),
                // anders lijkt het alsof de voorraadchecker niet werkt.
                'name' => 'zoek_productaanraders',
                'description' => 'Zoek live producten die op voorraad zijn (aanraders/alternatieven). Gebruik dit om alleen producten te noemen die echt in de database staan en op voorraad zijn.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'zoekterm' => [
                            'type' => 'string',
                            'description' => 'Zoekwoord voor titel/omschrijving (bijv. RPG, Mario, Zelda). Leeg = algemene aanraders.',
                        ],
                        'max_results' => [
                            'type' => 'integer',
                            'description' => 'Aantal resultaten (1 t/m 10).',
                        ],
                    ],
                    'required' => [],
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
    $heeftBestelnummer = preg_match('/\b\d+\b/', $berichtTekst) === 1;

    if ($heeftBestelWoord && $heeftEmail && $heeftBestelnummer) {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_bestelling',
            ],
        ];
    }

    // Als iemand om vergelijkbare games / suggesties / aanraders vraagt, willen we alleen echte producten noemen.
    // Daarom forceren we een lookup op in-stock producten (zoek_productaanraders).
    if (preg_match('/\b(aanraden|aanrader|suggest|suggestie|alternatief|soortgelijk|gelijke|vergelijkbaar|vergelijkbare|andere\s+games|andere\s+spellen)\b/i', (string) $berichtTekst) === 1) {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_productaanraders',
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

    $temperature = 0.2;
    $mode = function_exists('getChatModelMode') ? getChatModelMode() : 2;
    if ($mode === 2 || $mode === 3) {
        $temperature = 1;
    }
    $model = function_exists('getChatModelNameFromMode') ? getChatModelNameFromMode($mode) : 'gpt-4.1-mini';
    if (!is_string($model) || $model === '') {
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

    $temperature = 0.2;
    $mode = function_exists('getChatModelMode') ? getChatModelMode() : 2;
    if ($mode === 2 || $mode === 3) {
        $temperature = 1;
    }
    $model = function_exists('getChatModelNameFromMode') ? getChatModelNameFromMode($mode) : 'gpt-4.1-mini';
    if (!is_string($model) || $model === '') {
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

// Hier voeren we de echte databasefunctie uit die OpenAI vraagt.
// We geven daarna alleen ruwe data terug, nog geen mooi klantantwoord.
function voerInterneFunctieUit($conn, $functieNaam, $arguments)
{
    if ($functieNaam === 'zoek_bestelling') {
        // De echte order lookup staat gedeeld in include/bestelling_lookup.php.
        // Zo is de logica identiek voor chat én e-mailconcepten en onderhoud je het maar op 1 plek.
        $bestellingId = isset($arguments['bestelling_id']) ? (int) $arguments['bestelling_id'] : 0;
        $email = isset($arguments['email']) ? trim((string) $arguments['email']) : '';

        $result = zoekBestellingRuw($conn, $bestellingId, $email);

        $resultaat = isset($result['resultaat']) && is_array($result['resultaat']) ? $result['resultaat'] : [];
        $itemsLen = isset($resultaat['items']) ? strlen((string) $resultaat['items']) : 0;
        $artikelenCount = count((array) ($result['artikelen'] ?? []));
        if ($bestellingId > 0) {
            schrijfWorkerLog('Bestelling ' . $bestellingId . ' items_len=' . $itemsLen . ' artikelen_count=' . $artikelenCount);
        }

        return $result;
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

        $resultaat = [];
        try {
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
            $resultaat = is_array($resultaat) ? $resultaat : [];
        } catch (Throwable $e) {
            schrijfWorkerLog('zoek_productvoorraad fout: ' . $e->getMessage());
            return [
                'functie' => 'zoek_productvoorraad',
                'gevonden' => false,
                'status' => 'fout',
                'message' => 'Voorraad opzoeken is nu niet beschikbaar.',
                'resultaat' => [],
            ];
        }

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

        if (empty($resultaat)) {
            return [
                'functie' => 'zoek_productvoorraad',
                'gevonden' => false,
                'status' => 'niet_in_database',
                'message' => 'Geen product gevonden voor deze zoekterm.',
                'resultaat' => [],
            ];
        }

        $heeftOpVoorraad = false;
        foreach ($resultaat as $row) {
            $op = isset($row['op_voorraad']) ? strtolower(trim((string) $row['op_voorraad'])) : '';
            if ($op === 'ja') {
                $heeftOpVoorraad = true;
                break;
            }
        }

        return [
            'functie' => 'zoek_productvoorraad',
            'gevonden' => true,
            'status' => $heeftOpVoorraad ? 'op_voorraad' : 'niet_op_voorraad',
            'resultaat' => $resultaat,
        ];
    }

    if ($functieNaam === 'zoek_productaanraders') {
        global $univ_web;
        // Dit is de "aanrader/alternatief" lookup:
        // - geeft alleen producten terug die echt bestaan in Winkel
        // - en waar aantal > 0 (dus op voorraad)
        // Zo voorkomen we dat de bot titels verzint of links geeft naar niet-bestaande producten.
        $zoekterm = isset($arguments['zoekterm']) ? trim((string) $arguments['zoekterm']) : '';
        $max = isset($arguments['max_results']) ? (int) $arguments['max_results'] : 5;
        if ($max < 1) {
            $max = 1;
        }
        if ($max > 10) {
            $max = 10;
        }

        $basisUrl = '';
        if (isset($univ_web) && is_string($univ_web) && $univ_web !== '') {
            $basisUrl = 'https://www.' . $univ_web;
        }

        $rows = [];
        try {
            if ($zoekterm !== '') {
                // Met zoekterm zoeken we ook in sentence, zodat "RPG" of "Zelda" vaker matcht.
                $like = '%' . $zoekterm . '%';
                $stmt = $conn->prepare("
                    SELECT w.nr, w.titel, w.link, w.prijs, w.sentence
                    FROM Winkel w
                    WHERE w.aantal > 0
                      AND (w.titel LIKE :t_titel OR w.link LIKE :t_link OR w.sentence LIKE :t_sentence)
                    ORDER BY w.aantal DESC, w.prijs ASC
                    LIMIT " . (int) $max . "
                ");
                $stmt->bindValue(':t_titel', $like, PDO::PARAM_STR);
                $stmt->bindValue(':t_link', $like, PDO::PARAM_STR);
                $stmt->bindValue(':t_sentence', $like, PDO::PARAM_STR);
                $stmt->execute();
                $rows = $stmt->fetchAll();
            } else {
                // Zonder zoekterm: geef algemene aanraders op basis van voorraad/prijs.
                $stmt = $conn->prepare("
                    SELECT w.nr, w.titel, w.link, w.prijs, w.sentence
                    FROM Winkel w
                    WHERE w.aantal > 0
                    ORDER BY w.aantal DESC, w.prijs ASC
                    LIMIT " . (int) $max . "
                ");
                $stmt->execute();
                $rows = $stmt->fetchAll();
            }
            $rows = is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            schrijfWorkerLog('zoek_productaanraders fout: ' . $e->getMessage());
            return [
                'functie' => 'zoek_productaanraders',
                'gevonden' => false,
                'status' => 'fout',
                'message' => 'Aanraders opzoeken is nu niet beschikbaar.',
                'resultaat' => [],
            ];
        }
        if ($basisUrl !== '' && !empty($rows)) {
            // Maak van relative links een volledige product_url.
            foreach ($rows as $idx => $row) {
                $link = isset($row['link']) ? trim((string) $row['link']) : '';
                if ($link === '') {
                    continue;
                }

                if (preg_match('/^https?:\/\//i', $link) === 1) {
                    $rows[$idx]['product_url'] = $link;
                    continue;
                }

                if (preg_match('/^www\./i', $link) === 1) {
                    $rows[$idx]['product_url'] = 'https://' . $link;
                    continue;
                }

                $rows[$idx]['product_url'] = $basisUrl . '/' . ltrim($link, '/');
            }
        }

        if (empty($rows)) {
            // Geen resultaten = bot moet geen games gaan verzinnen, maar dit netjes terugkoppelen.
            return [
                'functie' => 'zoek_productaanraders',
                'gevonden' => false,
                'status' => 'geen_resultaten',
                'message' => 'Geen aanraders gevonden die nu op voorraad zijn.',
                'resultaat' => [],
            ];
        }

        return [
            'functie' => 'zoek_productaanraders',
            'gevonden' => true,
            'status' => 'gevonden',
            'resultaat' => $rows,
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

    $basisPrompt = 'Je bent een klantenservice assistent voor MarioSwitch.nl. Als je live data nodig hebt, gebruik je een functie. Geef geen data op basis van aannames als een functie nodig is. Noem nooit exacte voorraadaantallen aan klanten. Zeg alleen of iets op voorraad is of niet. Voor orderdata moet de klant eerst zowel een bestelnummer als het juiste e-mailadres geven. Als je via zoek_bestelling artikelen terugkrijgt en artikelen_gevonden true is, presenteer die als een nette lijst met per regel: "{aantal}x {productnaam} — {prijs} euro" (als prijs bekend is). Toon daarna altijd: "Verzendkosten: X euro" en "Totaal: Y euro" op basis van resultaat.verzendkosten en resultaat.totaal. Als artikelen_gevonden false is, zeg dan dat je de artikelregels nu niet kunt ophalen (en claim niet dat er geen artikelen zijn). Voor verzenden: gebruik resultaat.verzend_status (verzonden/niet_verzonden). Als resultaat.track_code gevuld is, toon die. Als track_code leeg is, zeg dat er (nog) geen track&trace code beschikbaar is. Bij bezorgtijden/verzenden/verzendkosten: gebruik alleen de info uit de FAQ die je hebt gekregen. Noem geen zelfbedachte levertijden zoals "1 tot 3 werkdagen". Als er geen exacte belofte staat, zeg dat het meestal de volgende werkdag is (bij bestelling voor 18:00), maar dat er geen 100% garantie is. Als de gebruiker vraagt of een game op voorraad is (of vraagt naar prijs/voorraad), roep altijd de functie zoek_productvoorraad aan en baseer je antwoord alleen op die uitkomst. Bij de uitkomst van zoek_productvoorraad geldt: als gevonden=false of status="niet_in_database", zeg dan dat het product op dit moment niet in het assortiment staat (dus nu niet verkocht wordt) en vraag eventueel om een link/andere zoekterm. Als status="niet_op_voorraad", zeg dat het product nu niet op voorraad is en dat het later weer kan terugkomen. Als status="op_voorraad", zeg dat het op voorraad is. Gebruik product_url als je een link wilt geven. Zeg nooit: "als er een prijs op de website staat is het op voorraad" en leid voorraad ook niet af van prijs; vertrouw alleen op zoek_productvoorraad. Zeg ook niet dat je het "voorraad aantal" niet kunt ophalen; zeg dat je alleen op voorraad ja/nee kunt geven. Als de gebruiker om aanraders/vergelijkbare games vraagt, noem dan alleen titels die je live hebt opgehaald via zoek_productaanraders. Noem nooit zelf verzonnen titels of links.';

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

    // We ondersteunen meerdere tool-rondes:
    // soms vraagt OpenAI na de eerste tool-call nog een extra lookup.
    // Als we dat niet afhandelen, kan het antwoord leeg blijven en krijgt de chat "Er ging iets mis...".
    $maxToolRondes = 3;
    $toolRonde = 0;
    $huidigeToolChoice = $toolChoice;
    $aiResponse = roepOpenAiAan($messages, $tools, $huidigeToolChoice);

    while (true) {
        if (!is_array($aiResponse) || !isset($aiResponse['choices'][0]['message'])) {
            updateChatQueueBericht($conn, $actiefBerichtId, 'error');
            schrijfWorkerLog('OpenAI gaf geen bruikbaar antwoord terug (ronde ' . (string) $toolRonde . ').');
            exit('Worker kon geen AI-antwoord maken.');
        }

        $assistantMessage = $aiResponse['choices'][0]['message'];

        if (empty($assistantMessage['tool_calls'])) {
            // Klaar: we hebben normale tekst.
            $directAntwoord = $assistantMessage['content'] ?? '';
            if (is_string($directAntwoord) && trim((string) $directAntwoord) !== '') {
                updateChatQueueBericht($conn, $actiefBerichtId, 'completed', $directAntwoord);
                schrijfWorkerLog('AI antwoord gemaakt (lengte ' . strlen((string) $directAntwoord) . ').');
                break;
            }

            updateChatQueueBericht($conn, $actiefBerichtId, 'error');
            schrijfWorkerLog('OpenAI gaf geen tekst en ook geen functie terug.');
            exit('Worker kreeg geen bruikbaar AI-antwoord terug.');
        }

        // Tool-call(s) uitvoeren.
        schrijfWorkerLog('OpenAI vroeg om interne functie(s) voor bericht ' . $bericht['id'] . ' (ronde ' . (string) $toolRonde . ').');
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

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'],
                'content' => json_encode($functieResultaat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $toolRonde += 1;
        if ($toolRonde >= $maxToolRondes) {
            updateChatQueueBericht($conn, $actiefBerichtId, 'error');
            schrijfWorkerLog('Te veel tool-rondes, breekt af.');
            exit('Worker kreeg te veel tool-rondes.');
        }

        // Volgende ronde: laat OpenAI het echte antwoord maken (of nog een extra tool-call doen).
        // Tools sturen we mee, anders kan OpenAI in sommige gevallen tool-messages afwijzen.
        $huidigeToolChoice = 'auto';
        $aiResponse = roepOpenAiAan($messages, $tools, $huidigeToolChoice);
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
