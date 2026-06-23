<?php
//error_reporting(-1); //show errors
//$sqlalter = 'ALTER TABLE `spel_beoordelingen` ADD `stringAI` TEXT NOT NULL DEFAULT \'\' AFTER `string`, ADD `stringEN` TEXT NOT NULL DEFAULT \'\' AFTER `stringAI`, ADD `stringFR` TEXT NOT NULL DEFAULT \'\' AFTER `stringEN`, ADD `stringDE` TEXT NOT NULL DEFAULT \'\' AFTER `stringFR`;';
include_once __DIR__ . '/env.php';

/**
 * Maakt speciale tekens uit GPT-antwoorden leesbaar voordat ze naar de UI of database gaan.
 */
function chatGptNormaliseerAntwoordTekst(string $tekst): string
{
    $search = ['\"', "–", "—", "‘", "’", "“", "”", "…", "’"];
    $replace = ['"', "-", "-", "'", "'", '"', '"', "...", "'"];

    return str_replace($search, $replace, $tekst);
}

/**
 * Zoekt een PDO-verbinding voor tools: expliciet meegegeven of globale $conn.
 */
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

/**
 * Eén OpenAI chat/completions-request; geeft gedecodeerde JSON of null bij fout.
 *
 * @param array<string, mixed> $payload
 *
 * @return array<string, mixed>|null
 */
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

/**
 * Bouwt de messages-array voor OpenAI (system + geschiedenis + nieuw user-bericht).
 *
 * @param array<int, array<string, string>> $userAssistantArray
 *
 * @return array<int, array<string, string>>
 */
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

/**
 * OpenAI-call mét tools: tool-loop zoals in de chat-worker (max. 3 rondes).
 *
 * @param array<int, array<string, mixed>> $messages
 * @param mixed                            $toolChoice 'auto', 'required', of array met vaste functienaam
 */
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
        if (!is_array($aiResponse) || !isset($aiResponse['choices'][0]['message'])) {
            if ($test == 1) {
                return 'Fout bij het ophalen van het antwoord.<TEXTAREA>' . print_r($aiResponse, true) . '</TEXTAREA>';
            }

            return 'Fout bij het ophalen van het antwoord.';
        }

        $assistantMessage = $aiResponse['choices'][0]['message'];

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

        if ($toolRonde >= $maxToolRondes) {
            if ($test == 1) {
                return 'Fout bij het ophalen van het antwoord.<TEXTAREA>Te veel tool-rondes.</TEXTAREA>';
            }

            return 'Fout bij het ophalen van het antwoord.';
        }

        chatToolLog('CHATGPT tool-ronde ' . (string) $toolRonde);
        $messages[] = $assistantMessage;

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

/**
 * Centrale OpenAI-helper: tekst-antwoord, optioneel met database-tools (sync).
 *
 * Zonder tools ($gebruikTools = false): één call, alleen tekst — gedrag zoals voorheen.
 * Met tools ($gebruikTools = true): zelfde tool-loop als de chatbot-worker, via chat_tools.php.
 *
 * @param string|int $model               Modelnaam of modus (2/3 via getChatModelNameFromMode)
 * @param array      $UsserAssistantArray Eerdere user/assistant-berichten
 * @param int        $test                1 = uitgebreide fout bij mislukte call
 * @param bool       $gebruikTools        true = live database-tools inschakelen
 * @param PDO|null   $conn                Database (verplicht bij tools; anders globale $conn)
 * @param mixed      $toolChoice          'auto', 'required', of array met vaste functienaam;
 *                                        gebruik bepaalGeforceerdeFunctieKeuze() voor zelfde regels als chat
 */
function CHATGPT(
    $input,
    $systemContent,
    $temperature = 1,
    $model = "gpt-5-mini",
    $UsserAssistantArray = [],
    $test = 1,
    $gebruikTools = false,
    $conn = null,
    $toolChoice = 'auto'
) {
    $apiKey = getProjectEnvValue('OPENAI_API_KEY');

    if ($apiKey === null || $apiKey === '') {
        return "Fout bij het ophalen van het antwoord.";
    }

    $input = addslashes($input);

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
        $model = function_exists('getChatModelNameFromMode') ? getChatModelNameFromMode($mode) : 'gpt-4.1-mini';
    }

    $messages = chatGptBouwMessages($systemContent, $UsserAssistantArray, $input);

    if ($gebruikTools) {
        $pdo = chatGptLosDatabaseConnectieOp($conn);
        if (!($pdo instanceof PDO)) {
            return 'Fout bij het ophalen van het antwoord.';
        }

        return chatGptMetTools($apiKey, $messages, (string) $model, (float) $temperature, $pdo, $toolChoice, (int) $test);
    }

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

/**
 * Bepaalt tool_choice voor sync CHATGPT() — zelfde regels als de chat-worker (zonder gesprek-injectie).
 *
 * @return mixed 'auto', 'required', of array met vaste functienaam
 */
function chatGptBepaalToolKeuze(string $berichtTekst, string $assistant0 = '')
{
    include_once __DIR__ . '/chat_functie_keuze.php';

    $toolChoice = bepaalGeforceerdeFunctieKeuze($berichtTekst, $assistant0);

    if ($toolChoice === 'auto' && preg_match('/\b(op\s+voorraad|voorraad|beschikbaar|in\s+stock|prijs)\b/i', $berichtTekst) === 1) {
        $toolChoice = 'required';
    }

    return $toolChoice;
}
