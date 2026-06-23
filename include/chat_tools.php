<?php

/**
 * Centrale chat-tools: definities voor OpenAI + uitvoering in PHP (database).
 *
 * Gebruikt door:
 * - api/chat/worker.php        → chatbot (async, met AI)
 * - include/ChatFunction.php   → CHATGPT() (sync, met AI, optioneel)
 * - admin / klantenservice     → voerChatToolUit() direct, zonder AI
 *
 * Nieuwe tool toevoegen (altijd op deze plek):
 * 1. OpenAI-blok in bouwChatTools()
 * 2. if-blok in voerChatToolUit()
 * 3. eventueel zware logica in apart include-bestand (bijv. tracking_lookup.php)
 *
 * Worker en CHATGPT() hoeven dan niet apart aangepast te worden.
 */

include_once __DIR__ . '/bestelling_lookup.php';
include_once __DIR__ . '/chat_product_zoek.php';

/**
 * Logt tool-acties naar storage/logs/chat_worker.log als de worker draait.
 * Buiten de worker (bijv. admin of CHATGPT) gebeurt er niets — geen fout.
 */
function chatToolLog(string $message): void
{
    if (function_exists('schrijfWorkerLog')) {
        schrijfWorkerLog($message);
    }
}

/**
 * Geeft de tool-definities terug die OpenAI mag aanroepen (function calling).
 *
 * Dit is het "menu" voor GPT: welke functienamen, beschrijvingen en parameters bestaan.
 * De echte database-logica staat in voerChatToolUit() en de include-helpers.
 *
 * @return array<int, array<string, mixed>>
 */
function bouwChatTools(): array
{
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

/**
 * Voert één chat-tool uit en geeft ruwe data terug (nog geen klanttekst).
 *
 * Wordt aangeroepen wanneer OpenAI een tool_call stuurt, of direct vanuit admin
 * zonder AI: voerChatToolUit($conn, 'zoek_bestelling', ['bestelling_id' => 123, 'email' => '…']).
 *
 * @param PDO    $conn         Databaseverbinding
 * @param string $functieNaam  Naam uit bouwChatTools(), bijv. zoek_bestelling
 * @param array  $arguments    Parameters van GPT of van het admin-formulier
 *
 * @return array<string, mixed>
 */
function voerChatToolUit(PDO $conn, string $functieNaam, array $arguments): array
{
    if ($functieNaam === 'zoek_bestelling') {
        // Orderdata via gedeelde helper (zelfde als e-maildashboard).
        $bestellingId = isset($arguments['bestelling_id']) ? (int) $arguments['bestelling_id'] : 0;
        $email = isset($arguments['email']) ? trim((string) $arguments['email']) : '';

        $result = zoekBestellingRuw($conn, $bestellingId, $email);

        $resultaat = isset($result['resultaat']) && is_array($result['resultaat']) ? $result['resultaat'] : [];
        $itemsLen = isset($resultaat['items']) ? strlen((string) $resultaat['items']) : 0;
        $artikelenCount = count((array) ($result['artikelen'] ?? []));
        if ($bestellingId > 0) {
            chatToolLog('Bestelling ' . $bestellingId . ' items_len=' . $itemsLen . ' artikelen_count=' . $artikelenCount);
        }

        return $result;
    }

    if ($functieNaam === 'zoek_productvoorraad') {
        // Eén product/titel opzoeken in Winkel + info.
        global $univ_web;
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
            chatToolLog('zoek_productvoorraad fout: ' . $e->getMessage());
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
        // Meerdere zoektermen / genre → producten op voorraad (chat_product_zoek.php).
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
            chatToolLog('zoek_productaanraders fout: ' . $e->getMessage());
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
        // Interne tool: voorraad van producten uit eerdere bot-berichten (worker gebruikt dit indirect).
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
