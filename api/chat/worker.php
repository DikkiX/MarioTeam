<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';

ignore_user_abort(true);
set_time_limit(0);

function schrijfWorkerLog($message)
{
    $logMap = $_SERVER['DOCUMENT_ROOT'] . '/storage/logs';
    $logBestand = $logMap . '/chat_worker.log';

    if (!is_dir($logMap)) {
        mkdir($logMap, 0775, true);
    }

    $regel = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logBestand, $regel, FILE_APPEND);
}

// Dit zijn de interne functies die OpenAI mag gebruiken.
// Zo kan het model live data opvragen in plaats van gokken.
function bouwToolsVoorOpenAi()
{
    return [
        [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_bestelling',
                'description' => 'Zoek live besteldata op in de tabel Bestellingen. Gebruik dit alleen als de klant zowel een bestelnummer als hetzelfde e-mailadres geeft dat bij de bestelling hoort.',
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

// Deze functie stuurt berichten naar OpenAI.
// Als we tools meegeven, mag het model ook een functie aanroepen.
function roepOpenAiAan($messages, $tools = [])
{
    $apiKey = getProjectEnvValue('OPENAI_API_KEY');

    if ($apiKey === null || $apiKey === '') {
        schrijfWorkerLog('OpenAI key ontbreekt.');
        return null;
    }

    $data = [
        'model' => 'gpt-4.1-mini',
        'messages' => $messages,
        'temperature' => 0.2,
        'max_completion_tokens' => 1200,
    ];

    if (!empty($tools)) {
        $data['tools'] = $tools;
        $data['tool_choice'] = 'auto';
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

        // Pas na de check halen we de orderinformatie op.
        // We geven alleen de velden terug die nodig zijn voor de statusvraag.
        $stmt = $conn->prepare("
            SELECT id, betaling, totaal, status, verzending, datum, PayStatus, tracktrace
            FROM Bestellingen
            WHERE id = :id AND mail = :email
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $bestellingId,
            ':email' => $email,
        ]);
        $resultaat = $stmt->fetch();

        return [
            'functie' => 'zoek_bestelling',
            'gevonden' => $resultaat !== false,
            'resultaat' => $resultaat,
        ];
    }

    if ($functieNaam === 'zoek_productvoorraad') {
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

// Dit maakt het eerste gesprek voor OpenAI.
// Eerst krijgt het model alleen de vraag van de gebruiker.
function maakBerichtenVoorOpenAi($bericht)
{
    return [
        [
            'role' => 'system',
            'content' => 'Je bent een klantenservice assistent voor MarioSwitch.nl. Als je live data nodig hebt, gebruik je een functie. Geef geen data op basis van aannames als een functie nodig is. Noem nooit exacte voorraadaantallen aan klanten. Zeg alleen of iets op voorraad is of niet. Voor orderdata moet de klant eerst zowel een bestelnummer als het juiste e-mailadres geven.',
        ],
        [
            'role' => 'user',
            'content' => $bericht['user_message'],
        ],
    ];
}

// De worker mag via POST door de trigger starten.
// GET mag ook, zodat je hem makkelijk handmatig kunt testen.
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) {
    http_response_code(405);
    exit('Alleen GET en POST zijn toegestaan.');
}

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

    // Meteen op processing zetten voorkomt dubbele verwerking.
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

    $messages = maakBerichtenVoorOpenAi($bericht);
    $tools = bouwToolsVoorOpenAi();
    $eersteAntwoord = roepOpenAiAan($messages, $tools);

    if (!isset($eersteAntwoord['choices'][0]['message'])) {
        schrijfWorkerLog('OpenAI gaf geen bruikbaar eerste antwoord terug.');
        echo 'Bericht ' . $bericht['id'] . ' is op processing gezet.';
        exit;
    }

    $assistantMessage = $eersteAntwoord['choices'][0]['message'];

    // Als OpenAI een functie wil gebruiken, voeren we die hier uit.
    if (!empty($assistantMessage['tool_calls'])) {
        schrijfWorkerLog('OpenAI vroeg om een interne functie voor bericht ' . $bericht['id'] . '.');

        // We bewaren eerst welke tool-call OpenAI wilde doen.
        $messages[] = $assistantMessage;

        foreach ($assistantMessage['tool_calls'] as $toolCall) {
            $functieNaam = $toolCall['function']['name'] ?? '';
            $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);

            if (!is_array($arguments)) {
                $arguments = [];
            }

            schrijfWorkerLog('Functie aangeroepen: ' . $functieNaam);
            $functieResultaat = voerInterneFunctieUit($conn, $functieNaam, $arguments);
            schrijfWorkerLog('Functie-resultaat: ' . json_encode($functieResultaat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // Hier geven we de ruwe database-uitkomst terug aan OpenAI.
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'],
                'content' => json_encode($functieResultaat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        // Nu geven we de ruwe data terug aan OpenAI voor het echte antwoord.
        $tweedeAntwoord = roepOpenAiAan($messages);
        $definitiefAntwoord = $tweedeAntwoord['choices'][0]['message']['content'] ?? '';

        if ($definitiefAntwoord !== '') {
            schrijfWorkerLog('Definitief AI-antwoord gemaakt voor bericht ' . $bericht['id'] . ': ' . $definitiefAntwoord);
        } else {
            schrijfWorkerLog('Na function calling kwam er geen definitief antwoord terug.');
        }
    } else {
        // Soms geeft OpenAI meteen al een normaal antwoord terug.
        $directAntwoord = $assistantMessage['content'] ?? '';

        if ($directAntwoord !== '') {
            schrijfWorkerLog('OpenAI gaf direct antwoord zonder functie: ' . $directAntwoord);
        } else {
            schrijfWorkerLog('OpenAI gaf geen tekst en ook geen functie terug.');
        }
    }

    echo 'Bericht ' . $bericht['id'] . ' is op processing gezet.';
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    // We loggen hier wat er echt misging, zodat testen makkelijker wordt.
    schrijfWorkerLog('Worker fout: ' . $e->getMessage());
    http_response_code(500);
    exit('Worker kon de wachtrij niet verwerken.');
}
