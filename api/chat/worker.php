<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';
// Gedeelde order-lookup (wordt ook door EmailDashboard gebruikt).
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/bestelling_lookup.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/chat_functie_keuze.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/chat_product_zoek.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/chat_geschiedenis.php';
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

// --- Wachtrij: welk bericht pakken + oude rijen opruimen ---
// send.php stuurt message_id mee. Die krijgt voorrang (geen oud test-bericht eerst).
// TTL uit .env: oude pending/processing → status error (na loadtests of vastgelopen worker).

function haalGevraagdeBerichtIdUitRequest(): int
{
    // POST komt van send (fire-and-forget). GET kan handmatig bij testen.
    $raw = $_POST['message_id'] ?? $_GET['message_id'] ?? 0;
    $id = (int) $raw;

    return $id > 0 ? $id : 0;
}

function haalChatQueueTtlSeconden(string $envKey, int $default): int
{
    // Leest seconden uit .env. Bij lege of ongeldige waarde: default.
    $waarde = getProjectEnvValue($envKey);
    if (is_string($waarde) && preg_match('/^\d+$/', $waarde) === 1) {
        $seconden = (int) $waarde;

        return $seconden > 0 ? $seconden : $default;
    }

    return $default;
}

function ruimVerlopenWachtrijBerichtenOp(PDO $conn): void
{
    // Zonder opruimen pakt de worker na een loadtest eerst oude pending-rijen.
    $pendingSec = haalChatQueueTtlSeconden('CHAT_QUEUE_PENDING_TTL_SECONDS', 1800);
    $processingSec = haalChatQueueTtlSeconden('CHAT_QUEUE_PROCESSING_TTL_SECONDS', 600);

    $stmt = $conn->prepare("
        UPDATE chat_queue
        SET status = 'error'
        WHERE status = 'pending'
          AND created_at < DATE_SUB(NOW(), INTERVAL :sec SECOND)
    ");
    $stmt->execute([':sec' => $pendingSec]);
    $verlopenPending = $stmt->rowCount();

    $stmt = $conn->prepare("
        UPDATE chat_queue
        SET status = 'error'
        WHERE status = 'processing'
          AND created_at < DATE_SUB(NOW(), INTERVAL :sec SECOND)
    ");
    $stmt->execute([':sec' => $processingSec]);
    $verlopenProcessing = $stmt->rowCount();

    if ($verlopenPending > 0 || $verlopenProcessing > 0) {
        schrijfWorkerLog(
            'Verlopen wachtrij opgeruimd: '
            . $verlopenPending
            . ' pending, '
            . $verlopenProcessing
            . ' processing (ouder dan '
            . $pendingSec
            . '/'
            . $processingSec
            . ' sec).'
        );
    }
}

function pakPendingBerichtVoorWorker(PDO $conn, int $gevraagdBerichtId): ?array
{
    // 1) Probeer het bericht dat send net heeft aangemaakt.
    // 2) Anders het oudste pending (handmatige worker-call zonder message_id).
    $basisSelect = "
        SELECT id, cookie, user_message, status, created_at
        FROM chat_queue
        WHERE status = :status
    ";

    if ($gevraagdBerichtId > 0) {
        $selectSql = $basisSelect . "
            AND id = :id
            LIMIT 1
            FOR UPDATE
        ";
        $selectStmt = $conn->prepare($selectSql);
        $selectStmt->execute([
            ':status' => 'pending',
            ':id' => $gevraagdBerichtId,
        ]);
        $bericht = $selectStmt->fetch();
        if (is_array($bericht)) {
            return $bericht;
        }

        schrijfWorkerLog('Gevraagd bericht ' . $gevraagdBerichtId . ' is niet pending; pak oudste resterende pending.');
    }

    $selectSql = $basisSelect . "
        ORDER BY created_at ASC, id ASC
        LIMIT 1
        FOR UPDATE
    ";
    $selectStmt = $conn->prepare($selectSql);
    $selectStmt->execute([
        ':status' => 'pending',
    ]);
    $bericht = $selectStmt->fetch();

    return is_array($bericht) ? $bericht : null;
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
                // Aanraders: alleen producten die echt in Winkel staan (aantal > 0).
                'name' => 'zoek_productaanraders',
                'description' => 'Zoek live producten op voorraad. Geef 3-6 zoektermen die in titels/omschrijvingen kunnen staan (bij danspellen: dans, dance, danser, party — niet alleen bekende merken). Alleen producten uit resultaat noemen.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'zoektermen' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                            'description' => 'Lijst met zoekwoorden, bijv. ["dans","dance","just dance"] of ["rpg","jrpg"]. Gebruik 2 tot 5 termen bij genre-vragen.',
                        ],
                        'zoekterm' => [
                            'type' => 'string',
                            'description' => 'Optioneel: één zoekwoord (oud veld). Liever zoektermen gebruiken.',
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

// Deze functie stuurt berichten naar OpenAI.
// Als we tools meegeven, mag het model ook een functie aanroepen.
function roepOpenAiAan($messages, $tools = [], $toolChoice = 'auto')
{
    // Vraag OpenAI om een antwoord. Soms vraagt OpenAI om extra info via een functie.
    $apiKey = getProjectEnvValue('OPENAI_API_KEY');

    if ($apiKey === null || $apiKey === '') {
        schrijfWorkerLog('OpenAI key ontbreekt.');
        return null;
    }

    // Tone of voice uit EmailDashboard (dashboard_settings.tone_of_voice) hoort alleen bij
    // e-mailconcepten, niet bij deze chat. Chat gebruikt uitsluitend Mr M. (sectie A in system1).

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

// Korte OpenAI-call (bijv. system0) zonder aparte system-injecties — snel en voorspelbaar.
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
            // Meerdere schrijfwijzen (bijv. dubbele punt in titel) zodat de database wél matcht.
            $perLink = [];
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
            foreach (maakProductZoektermVarianten($zoekterm) as $term) {
                $zoektermLike = '%' . $term . '%';
                $stmt->execute([
                    ':zoekterm_titel' => $zoektermLike,
                    ':zoekterm_link' => $zoektermLike,
                ]);
                $batch = $stmt->fetchAll();
                if (!is_array($batch)) {
                    continue;
                }
                foreach ($batch as $row) {
                    $link = isset($row['link']) ? trim((string) $row['link']) : '';
                    $sleutel = $link !== '' ? $link : (string) ($row['nr'] ?? '');
                    if ($sleutel === '' || isset($perLink[$sleutel])) {
                        continue;
                    }
                    $perLink[$sleutel] = $row;
                    if (count($perLink) >= 5) {
                        break 2;
                    }
                }
            }
            $resultaat = array_values($perLink);
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
        // Zoeken zelf gebeurt in include/chat_product_zoek.php.
        global $univ_web;
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

        $zoektermen = haalZoektermenUitToolArgs($arguments);
        $rows = [];
        $gebruikteZoektermen = [];

        try {
            if (!empty($zoektermen)) {
                $zoekResultaat = zoekAanradersInDatabase($conn, $zoektermen, $max, $max);
                $rows = $zoekResultaat['rijen'] ?? [];
                $gebruikteZoektermen = $zoekResultaat['gebruikte_zoektermen'] ?? [];
            } else {
                $rows = haalAlgemeneAanradersUitDatabase($conn, $max);
            }
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

        $rows = voegProductUrlsToeAanRijen($rows, $basisUrl);

        if (empty($rows)) {
            return [
                'functie' => 'zoek_productaanraders',
                'gevonden' => false,
                'status' => 'geen_resultaten',
                'message' => 'Geen aanraders op voorraad voor deze zoektermen. Noem geen producten die niet in resultaat staan.',
                'gebruikte_zoektermen' => $gebruikteZoektermen,
                'resultaat' => [],
            ];
        }

        return [
            'functie' => 'zoek_productaanraders',
            'gevonden' => true,
            'status' => 'gevonden',
            'gebruikte_zoektermen' => $gebruikteZoektermen,
            'resultaat' => $rows,
        ];
    }

    if ($functieNaam === 'controleer_voorraad_lijst') {
        global $univ_web;
        $basisUrl = '';
        if (isset($univ_web) && is_string($univ_web) && $univ_web !== '') {
            $basisUrl = 'https://www.' . $univ_web;
        }
        $context = isset($arguments['_gesprek_context']) && is_array($arguments['_gesprek_context'])
            ? $arguments['_gesprek_context']
            : [];

        return controleerVoorraadUitGesprek($conn, $context, $basisUrl);
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

// Bouwt de system prompt op (Mr M, FAQ, verkoopvragen, …). Zelfde idee als ChatGptMrM.php.
function bouwSystem1MetIncludes($assistant0, $platform, $userMessage = '')
{
    global $univ_one, $univ_web, $univ_nin, $univ_web_text, $univ_mar, $univ_zoeken;

    include_once $_SERVER['DOCUMENT_ROOT'] . '/include/ChatGPT/mrM.php';
    $systemMrM = isset($systemMrM) ? (string) $systemMrM : '';
    $systemMrmPersoonlijk = isset($systemMrmPersoonlijk) ? (string) $systemMrmPersoonlijk : '';

    $system1 = $systemMrM;
    if (preg_match("/Persoonlijk/i", (string) $assistant0)) {
        $system1 .= $systemMrmPersoonlijk;
    }

    // Als system0 “ProductFinder” zegt: laad de 5 verkoopvragen (VerkoopAdvies3).
    if (preg_match("/ProductFinder/i", (string) $assistant0)) {
        include_once $_SERVER['DOCUMENT_ROOT'] . '/include/ChatGPT/VerkoopAdvies3.php';
        $systemAdviesVragen = isset($systemAdviesVragen) ? (string) $systemAdviesVragen : '';
        if ($systemAdviesVragen !== '') {
            $system1 .= "\n\n" . stripHtmlNaarTekst($systemAdviesVragen);
        }
    }

    // Na “Ik heb antwoord op al mijn vragen” mag de AI producten uit de shop kiezen (ProductList).
    $toonProductLijst = preg_match('/ik heb antwoord op al mijn vragen/i', (string) $userMessage) === 1;
    if ($toonProductLijst || preg_match("/ProductList/i", (string) $assistant0)) {
        if (preg_match("/Switch/i", (string) $assistant0) || (string) $platform === 'Switch') {
            include_once $_SERVER['DOCUMENT_ROOT'] . '/include/ChatGPT/ProductList.php';
            $systemList = isset($systemList) ? (string) $systemList : '';
            if ($systemList !== '') {
                $system1 .= "\n\n" . stripHtmlNaarTekst($systemList);
            }
        }
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

// Maakt de berichtenlijst voor OpenAI (instructies + oude chat + nieuwe vraag).
function maakBerichtenVoorOpenAi($conn, $bericht, $assistant0Vooraf = null)
{
    global $univ_one, $univ_web, $univ_nin, $univ_web_text, $univ_mar, $univ_zoeken;

    // Korte regels over functies (voorraad zoeken) en Mr M-stijl — geen e-maildashboard-tone.
    $basisPrompt = 'Je bent een klantenservice assistent voor MarioSwitch.nl. Volg altijd de tone of voice uit sectie A (Mr M): enthousiast, uitroepen zoals Haha en Fantastisch, geen emoji. Gebruik geen formele e-mailstijl: geen afsluitingen zoals "Met vriendelijke groet", "Hoogachtend" of handtekeningen met bedrijfsnaam; dit is een chat als Mr M., kort en menselijk. Jullie verkopen geen unpatched of "hackbare" Switch-consoles; zeg dat kort als de klant daarom vraagt en verwijs naar regulier Switch-aanbod voor normaal gamen — geen homebrew-/firmware-handleidingen. Nintendo Switch 2: bestaat als nieuwe Nintendo-console (bij Nintendo verkrijgbaar), maar bij MarioSwitch.nl nog niet in het assortiment — zeg dat helder en stuur door naar OLED/Lite/andere Switch waar relevant. Als sectie B Verkoopadvies in je instructies staat: volg die volgorde (maximaal 5 korte vragen, 1 vraag per antwoord). Geef dan nog geen definitief productadvies tot de klant zegt: "Ik heb antwoord op al mijn vragen." Daarna gebruik je zoek_productaanraders met meerdere zoektermen uit het gesprek (woorden die in producttitels kunnen staan, NL en EN, geen verzonnen merken). Voor andere productvragen: gebruik functies voor live data; geef geen voorraad/prijzen op basis van aannames. Noem nooit exacte voorraadaantallen. Voor orderdata: bestelnummer + e-mail. Bij zoek_productaanraders: alleen titels uit resultaat noemen, in Mr M-stijl met links. Bij zoek_productvoorraad: alleen op voorraad ja/nee op basis van de functie. Als de klant voorraad vraagt van spellen die jij net noemde: gebruik de verplichte voorraadcontrole uit het systeembericht; zeg nooit dat een genoemd product niet in de database staat. Bij weinig resultaten: opnieuw zoeken met andere zoektermen in een volgende functie-call, niet aan de klant vragen om te raden. Noem nooit zelf verzonnen titels of links.';

    // Stap 1: haal context (laatste afgeronde berichten) op uit de queue.
    $contextMessages = haalGespreksContextOp(
        $conn,
        (string) ($bericht['cookie'] ?? ''),
        (int) ($bericht['id'] ?? 0)
    );

    $userMessage = (string) ($bericht['user_message'] ?? '');

    // Stap 2: system0 bepaalt onderwerp (bijv. ProductFinder, Zending) + platform.
    $assistant0 = is_string($assistant0Vooraf) && $assistant0Vooraf !== ''
        ? $assistant0Vooraf
        : haalAssistant0VoorBericht($contextMessages, $userMessage);
    $platform = bepaalPlatformUitAssistant0($assistant0, isset($univ_one) ? (string) $univ_one : '');

    // Stap 3: bouw system1 op met Mr M. / verkoopadvies / FAQ / contact.
    $system1 = bouwSystem1MetIncludes($assistant0, $platform, $userMessage);
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
$gevraagdBerichtId = haalGevraagdeBerichtIdUitRequest();

try {
    ruimVerlopenWachtrijBerichtenOp($conn);

    $conn->beginTransaction();

    // Eerst het bericht dat send net heeft aangemaakt (message_id), anders oudste pending.
    $bericht = pakPendingBerichtVoorWorker($conn, $gevraagdBerichtId);

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

    // Eerst system0 (welk onderwerp is dit bericht?).
    $userMessage = (string) ($bericht['user_message'] ?? '');
    $contextMessages = haalGespreksContextOp(
        $conn,
        (string) ($bericht['cookie'] ?? ''),
        (int) ($bericht['id'] ?? 0)
    );
    $assistant0 = haalAssistant0VoorBericht($contextMessages, $userMessage);

    $messages = maakBerichtenVoorOpenAi($conn, $bericht, $assistant0);
    $tools = bouwToolsVoorOpenAi();
    $toolChoice = bepaalGeforceerdeFunctieKeuze($userMessage, $assistant0);

    // Vervolgvraag “zijn ze op voorraad?”: check de shop-links uit het vorige bot-antwoord.
    if (isVoorraadFollowUpVraag($userMessage)) {
        global $univ_web;
        $basisUrl = '';
        if (isset($univ_web) && is_string($univ_web) && $univ_web !== '') {
            $basisUrl = 'https://www.' . $univ_web;
        }
        $voorraadUitGesprek = controleerVoorraadUitGesprek($conn, $contextMessages, $basisUrl);
        $messages[] = [
            'role' => 'system',
            'content' => 'Verplichte voorraadcontrole op eerder genoemde producten in dit gesprek. '
                . 'Gebruik alleen deze data; zeg nooit dat een product niet in de database staat als het hier wel tussen staat. '
                . 'Data: ' . json_encode($voorraadUitGesprek, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $toolChoice = 'auto';
        schrijfWorkerLog('Voorraad follow-up: ' . count($voorraadUitGesprek['resultaat'] ?? []) . ' product(en) uit gesprek gecontroleerd.');
    } elseif ($toolChoice === 'auto' && preg_match('/\b(op\s+voorraad|voorraad|beschikbaar|in\s+stock|prijs)\b/i', $userMessage) === 1) {
        // Algemene voorraadvraag: tool verplicht, maar niet bij “zijn ze op voorraad” (daar hebben we al data).
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
                $cookieVoorGeschiedenis = (string) ($bericht['cookie'] ?? '');
                if ($cookieVoorGeschiedenis !== '') {
                    voegBerichtToeAanChatGeschiedenis($conn, $cookieVoorGeschiedenis, 'bot', $directAntwoord);
                }
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
