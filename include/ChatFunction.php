<?php
//error_reporting(-1); //show errors

// Sync OpenAI-helper: één plek voor CHATGPT()-aanroepen (tekst of met database-tools).
include_once __DIR__ . '/env.php';

// Vervangt rare tekens uit GPT-antwoorden (aanhalingstekens, streepjes) door normale tekens.
function chatGptNormaliseerAntwoordTekst(string $tekst): string
{
    $search = ['\"', "–", "—", "‘", "’", "“", "”", "…", "’"];
    $replace = ['"', "-", "-", "'", "'", '"', '"', "...", "'"];

    return str_replace($search, $replace, $tekst);
}

// Geeft een database-verbinding terug: eerst $conn parameter, anders globale $conn.
function chatGptLosDatabaseConnectieOp($conn = null): ?PDO
{
    if ($conn instanceof PDO) {
        return $conn;
    }

    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
        return $GLOBALS['conn'];
    }

    return null;
}

// Stuurt één request naar OpenAI chat/completions. Geeft JSON terug of null bij fout.
function chatGptOpenAiAanroep(string $apiKey, array $payload): ?array
{
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return null;
    }

    $decoded = json_decode((string) $response, true);

    return is_array($decoded) ? $decoded : null;
}

// Zet system prompt + oude berichten + nieuw klantbericht om naar OpenAI messages-formaat.
function chatGptBouwMessages(string $systemContent, array $userAssistantArray, string $input): array
{
    $dataUA = [];
    if (!empty($userAssistantArray) && is_array($userAssistantArray)) {
        foreach ($userAssistantArray as $conversation) {
            if (isset($conversation['user'])) {
                $dataUA[] = [
                    'role' => 'user',
                    'content' => $conversation['user'],
                ];
            }
            if (isset($conversation['assistant'])) {
                $dataUA[] = [
                    'role' => 'assistant',
                    'content' => $conversation['assistant'],
                ];
            }
        }
    }

    return array_merge(
        [
            [
                'role' => 'system',
                'content' => $systemContent,
            ],
        ],
        $dataUA,
        [
            [
                'role' => 'user',
                'content' => $input,
            ],
        ]
    );
}

// OpenAI met database-tools: GPT mag functies aanroepen, max. 3 rondes (zelfde idee als worker).
function chatGptMetTools(
    string $apiKey,
    array $messages,
    string $model,
    float $temperature,
    PDO $conn,
    $toolChoice,
    int $test
): string {
    include_once __DIR__ . '/chat_tools.php';

    $tools = bouwChatTools();
    $maxToolRondes = 3;
    $toolRonde = 0;
    $huidigeToolChoice = $toolChoice;

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'max_completion_tokens' => 4096,
        'tools' => $tools,
        'tool_choice' => $huidigeToolChoice,
    ];

    $aiResponse = chatGptOpenAiAanroep($apiKey, $payload);

    while (true) {
        // Geen geldig antwoord van OpenAI
        if (!is_array($aiResponse) || !isset($aiResponse['choices'][0]['message'])) {
            if ($test == 1) {
                return 'Fout bij het ophalen van het antwoord.<TEXTAREA>' . print_r($aiResponse, true) . '</TEXTAREA>';
            }

            return 'Fout bij het ophalen van het antwoord.';
        }

        $assistantMessage = $aiResponse['choices'][0]['message'];

        // GPT is klaar: gewoon tekstantwoord teruggeven
        if (empty($assistantMessage['tool_calls'])) {
            $directAntwoord = $assistantMessage['content'] ?? '';
            if (is_string($directAntwoord) && trim($directAntwoord) !== '') {
                return chatGptNormaliseerAntwoordTekst($directAntwoord);
            }

            if ($test == 1) {
                return 'Fout bij het ophalen van het antwoord.<TEXTAREA>' . print_r($aiResponse, true) . '</TEXTAREA>';
            }

            return 'Fout bij het ophalen van het antwoord.';
        }

        // Te veel tool-rondes — stoppen
        if ($toolRonde >= $maxToolRondes) {
            if ($test == 1) {
                return 'Fout bij het ophalen van het antwoord.<TEXTAREA>Te veel tool-rondes.</TEXTAREA>';
            }

            return 'Fout bij het ophalen van het antwoord.';
        }

        chatToolLog('CHATGPT tool-ronde ' . (string) $toolRonde);
        $messages[] = $assistantMessage;

        // Elke tool die GPT vraagt uitvoeren in de database
        foreach ($assistantMessage['tool_calls'] as $toolCall) {
            $functieNaam = $toolCall['function']['name'] ?? '';
            $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);
            if (!is_array($arguments)) {
                $arguments = [];
            }

            chatToolLog('CHATGPT functie aangeroepen: ' . $functieNaam);
            $functieResultaat = voerChatToolUit($conn, $functieNaam, $arguments);

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'],
                'content' => json_encode($functieResultaat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        // Nog een OpenAI-call met de tool-resultaten; daarna mag GPT zelf kiezen
        $toolRonde++;
        $huidigeToolChoice = 'auto';

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_completion_tokens' => 4096,
            'tools' => $tools,
            'tool_choice' => $huidigeToolChoice,
        ];

        $aiResponse = chatGptOpenAiAanroep($apiKey, $payload);
    }
}

// Hoofdfunctie: vraag aan OpenAI stellen en antwoordtekst teruggeven.
// Met $conn: tools worden meegestuurd — GPT kiest zelf via tool_choice ('auto' tenzij caller anders zegt).
// Zonder $conn: alleen tekst (system0-label, e-mail, enz.).
function CHATGPT(
    $input,
    $systemContent,
    $temperature = 1,
    $model = "gpt-5-mini",
    $UsserAssistantArray = [],
    $test = 1,
    $conn = null,
    $toolChoice = 'auto'
) {
    $apiKey = getProjectEnvValue('OPENAI_API_KEY');
    if ($apiKey === null || $apiKey === '') {
        return "Fout bij het ophalen van het antwoord.";
    }

    $input = addslashes($input);

    // Model 1/2/3 uit .env omzetten naar echte modelnaam
    $mode = null;
    if (is_int($model)) {
        $mode = $model;
    } elseif (is_string($model) && preg_match('/^\d+$/', $model) === 1) {
        $mode = (int) $model;
    }
    if ($mode !== null) {
        if ($mode === 2 || $mode === 3) {
            $temperature = 1;
        }
        $model = getChatModelNameFromMode($mode);
    }

    $messages = chatGptBouwMessages($systemContent, $UsserAssistantArray, $input);

    // Database beschikbaar → tools aanbieden; GPT beslist of/welke tool (tool_choice, standaard 'auto')
    $pdo = chatGptLosDatabaseConnectieOp($conn);
    if ($pdo instanceof PDO) {
        return chatGptMetTools($apiKey, $messages, (string) $model, (float) $temperature, $pdo, $toolChoice, (int) $test);
    }

    // Geen database → alleen tekst
    $data = [
        'model' => $model,
        'messages' => $messages,
        'max_completion_tokens' => 4096,
        'temperature' => $temperature,
    ];

    $aiResponse = chatGptOpenAiAanroep($apiKey, $data);

    if ($aiResponse === null) {
        return "cURL Error #: kon OpenAI niet bereiken.";
    }

    if (isset($aiResponse['choices'][0]['message']['content'])) {
        return chatGptNormaliseerAntwoordTekst((string) $aiResponse['choices'][0]['message']['content']);
    }

    if ($test == 1) {
        return "Fout bij het ophalen van het antwoord." . '<TEXTAREA>' . print_r($aiResponse) . '</TEXTAREA>';
    }

    return "Fout bij het ophalen van het antwoord.";
}

// Bepaalt welke tool GPT moet/kan gebruiken vóór de CHATGPT()-call (zelfde regels als worker).
// Geeft 'auto', 'required', of één vaste functienaam terug.
function chatGptBepaalToolKeuze(string $berichtTekst, string $assistant0 = '')
{
    include_once __DIR__ . '/chat_functie_keuze.php';

    $toolChoice = bepaalGeforceerdeFunctieKeuze($berichtTekst, $assistant0);

    // Bij voorraad/prijs-vragen: GPT moet een tool gebruiken
    if ($toolChoice === 'auto' && preg_match('/\b(op\s+voorraad|voorraad|beschikbaar|in\s+stock|prijs)\b/i', $berichtTekst) === 1) {
        $toolChoice = 'required';
    }

    return $toolChoice;
}
