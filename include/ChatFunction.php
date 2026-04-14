<?php
//error_reporting(-1); //show errors
//$sqlalter = 'ALTER TABLE `spel_beoordelingen` ADD `stringAI` TEXT NOT NULL DEFAULT \'\' AFTER `string`, ADD `stringEN` TEXT NOT NULL DEFAULT \'\' AFTER `stringAI`, ADD `stringFR` TEXT NOT NULL DEFAULT \'\' AFTER `stringEN`, ADD `stringDE` TEXT NOT NULL DEFAULT \'\' AFTER `stringFR`;';
include_once __DIR__ . '/env.php';

function CHATGPT($input, $systemContent, $temperature = 1, $model = "gpt-5-mini", $UsserAssistantArray = [], $test = 1)
{
    $apiKey = getProjectEnvValue('OPENAI_API_KEY');

    if ($apiKey === null || $apiKey === '') {
        return "Fout bij het ophalen van het antwoord.";
    }

    $input = addslashes($input);

    if ($model == 1) //duurste model
        $model = "gpt-5.2";
    elseif ($model == 2) //prijs technisch beste model
    {
        $temperature = 1;
        $model = "gpt-5-mini";
        //$model = "gpt-4.1-mini";
    } elseif ($model == 3) //gpt-5 nog altijd erg traag
    {
        $temperature = 1;
        $model = "gpt-4.1-mini";
    }
    $endpoint = 'https://api.openai.com/v1/chat/completions'; // Juiste API-eindpunt voor chat/completions


    /////////////////////////////////////////////////////////////
    // Bouw de conversatiegeschiedenis op optioneel

    //De array met eerdere vragen en antwoorden van de gebruiker en de assistant
    //$UsserAssistantArray[1]['user'] = 'Welke game raad je aan?'; 
    //$UsserAssistantArray[2]['assistant'] = 'Voor welk systeem zoek je een game?'; 
    //$UsserAssistantArray[3]['user'] = 'Switch'; 

    $dataUA = [];
    if (!empty($UsserAssistantArray) && is_array($UsserAssistantArray)) {
        foreach ($UsserAssistantArray as $conversation) {
            if (isset($conversation['user'])) {
                $dataUA[] = [
                    'role' => 'user',
                    'content' => $conversation['user']
                ];
            }
            if (isset($conversation['assistant'])) {
                $dataUA[] = [
                    'role' => 'assistant',
                    'content' => $conversation['assistant']
                ];
            }
        }
    }

    /////////////////////////////////////////////////////////////
    // Voeg het systeembericht en de nieuwste gebruikersvraag toe aan de berichtenarray
    $data = [
        'model' => $model, // Voeg hier het gewenste GPT-model toe, bijv. 'gpt-3.5-turbo' of 'gpt-4'
        'messages' => array_merge(
            [
                [
                    'role' => 'system',
                    'content' => $systemContent // Het systeem bericht (context of instructies)
                ]
            ],
            $dataUA, // De gegenereerde conversatiegeschiedenis
            [
                [
                    'role' => 'user',
                    'content' => $input // De nieuwe vraag van de gebruiker
                ]
            ]
        ),
        'max_completion_tokens' => 4096, // Pas het aantal tokens aan dat je wilt ontvangen
        'temperature' => $temperature, // Tussen 0.1 - 1
        // Andere parameters die je nodig hebt voor je specifieke GPT-service
    ];


    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $err = curl_error($ch);

    curl_close($ch);

    if ($err) {
        return "cURL Error #:" . $err;
    } else {
        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {

            //voor de gpt-5.2 update een paar extra replaces  
            $search  = ['\"', "–", "—", "‘", "’", "“", "”", "…", "’"]; //omdat het direct naar de database gaat denkt chatgpt soms dat het escaped moet worden.
            $replace = ['"', "-", "-", "'", "'", '"', '"', "...", "'"]; //
            $string95 = str_replace($search, $replace, $result['choices'][0]['message']['content']);

            return $string95;
        } else {
            if ($test == 1)
                return "Fout bij het ophalen van het antwoord." . '<TEXTAREA>' . print_r($result) . '</TEXTAREA>'; //niet meer veranderen
            else
                return "Fout bij het ophalen van het antwoord."; //niet meer veranderen
        }
    }
}
