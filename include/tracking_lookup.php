<?php

// Traceerstatus voor chat-tools: PostNL / GLS live + fallback op orderdata.
// Gebruikt door chat_tools.php → voerChatToolUit(zoek_traceer).
//
// Belangrijk: traceernummer/barcode komt nooit in het resultaat voor de klant/GPT.

include_once __DIR__ . '/bestelling_lookup.php';
include_once __DIR__ . '/env.php';
include_once __DIR__ . '/gls_webhook_handler.php';

// Verwijdert traceernummers en ruwe tracktrace uit een array (recursief).
function verwijderTraceerGevoeligeVelden(array $data): array
{
    $gevoelig = [
        'track_code',
        'trackcode',
        'traceernummer',
        'barcode',
        'tracktrace',
        'tracking_code',
        'tracking_number',
        'parcel_number',
        'TrackID',
        'track_id',
    ];

    $schoon = [];
    foreach ($data as $key => $waarde) {
        if (is_string($key) && in_array(strtolower($key), array_map('strtolower', $gevoelig), true)) {
            continue;
        }
        if (is_array($waarde)) {
            $schoon[$key] = verwijderTraceerGevoeligeVelden($waarde);
            continue;
        }
        $schoon[$key] = $waarde;
    }

    return $schoon;
}

/**
 * @return array{vervoerder: string, track_code: string, tracktrace: string, bestelling_id: int|null, verzending: string, verzend_status_db: string}
 */
function haalInterneTraceerContextUitBestelling(PDO $conn, int $bestellingId, string $email): array
{
    $row = haalBestellingOp($conn, $bestellingId, $email);
    if ($row === false) {
        return [
            'gevonden' => false,
            'message' => 'De combinatie van bestelling_id en email klopt niet.',
        ];
    }

    $tracktrace = trim((string) ($row['tracktrace'] ?? ''));
    $verzending = trim((string) ($row['verzending'] ?? ''));
    $trackCode = haalTrackCodeUitTracktrace($tracktrace);
    if ($trackCode === '' && preg_match('/\b[A-Z0-9]{8,20}\b/i', $tracktrace) === 1) {
        $trackCode = strtoupper(trim($tracktrace));
    }

    $orderLookup = zoekBestellingRuw($conn, $bestellingId, $email);
    $verzendStatusDb = '';
    if (isset($orderLookup['resultaat']['verzend_status'])) {
        $verzendStatusDb = (string) $orderLookup['resultaat']['verzend_status'];
    }

    return [
        'gevonden' => true,
        'bestelling_id' => $bestellingId,
        'track_code' => $trackCode,
        'tracktrace' => $tracktrace,
        'verzending' => $verzending,
        'verzend_status_db' => $verzendStatusDb,
        'inpakdatum' => isset($row['inpakdatum']) ? (int) $row['inpakdatum'] : 0,
    ];
}

function trackingLookupHttpGetJson(string $url, array $headers = []): ?array
{
    $result = trackingLookupHttpRequest('GET', $url, null, $headers);

    return $result['json'];
}

function trackingLookupHttpPostJson(string $url, array $payload, array $headers = []): ?array
{
    $result = trackingLookupHttpRequest('POST', $url, $payload, $headers);

    return $result['json'];
}

/**
 * @return array{json: ?array, http_status: int, body: string, error: string}
 */
function trackingLookupHttpRequest(string $method, string $url, ?array $payload = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?? []));
        $defaultHeaders = ['Content-Type: application/json'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));
    } elseif ($headers !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body)) {
        $body = '';
    }

    $decoded = null;
    if ($body !== '') {
        $parsed = json_decode($body, true);
        if (is_array($parsed)) {
            $decoded = $parsed;
        }
    }

    return [
        'json' => ($err === '' && $status >= 200 && $status < 300) ? $decoded : null,
        'http_status' => $status,
        'body' => $body,
        'error' => $err,
    ];
}

function postNlApiBasisUrl(): string
{
    $sandbox = getProjectEnvValue('POSTNL_USE_SANDBOX');
    if (is_string($sandbox) && in_array(strtolower(trim($sandbox)), ['1', 'true', 'yes'], true)) {
        return 'https://api-sandbox.postnl.nl';
    }

    $custom = getProjectEnvValue('POSTNL_API_BASE');
    if (is_string($custom) && trim($custom) !== '') {
        return rtrim(trim($custom), '/');
    }

    return 'https://api.postnl.nl';
}

// Live status ophalen bij PostNL (Shippingstatus API v2).
function haalPostNlZendingStatus(string $barcode): array
{
    $apiKey = getProjectEnvValue('POSTNL_API_KEY');
    $customerCode = getProjectEnvValue('POSTNL_CUSTOMER_CODE');
    $customerNumber = getProjectEnvValue('POSTNL_CUSTOMER_NUMBER');

    if ($apiKey === null || trim((string) $apiKey) === '') {
        return [
            'live' => false,
            'status' => 'api_niet_geconfigureerd',
            'message' => 'Live PostNL-tracking is nog niet geconfigureerd op de server.',
        ];
    }

    $query = http_build_query([
        'detail' => 'true',
        'language' => 'NL',
        'customerCode' => is_string($customerCode) ? trim($customerCode) : '',
        'customerNumber' => is_string($customerNumber) ? trim($customerNumber) : '',
    ]);
    $url = postNlApiBasisUrl() . '/shipment/v2/status/barcode/' . rawurlencode($barcode) . '?' . $query;

    $response = trackingLookupHttpRequest('GET', $url, null, [
        'apikey: ' . trim((string) $apiKey),
        'Accept: application/json',
    ]);

    $json = $response['json'];
    if ($json === null) {
        $detail = $response['error'] !== ''
            ? $response['error']
            : 'HTTP ' . $response['http_status'];
        if ($response['http_status'] === 401) {
            $detail .= ' — productie-API-key ongeldig of geen Shipping Status-rechten';
        }

        return [
            'live' => false,
            'status' => 'fout',
            'message' => 'Kon geen live status ophalen bij PostNL (' . $detail . ').',
            'http_status' => $response['http_status'],
        ];
    }

    return normaliseerPostNlTraceerResponse($json);
}

function normaliseerPostNlTraceerResponse(array $json): array
{
    $huidig = '';
    $huidigTijd = '';
    $geschiedenis = [];

    $shipment = $json['CompleteStatus']['Shipment'] ?? null;
    if (is_array($shipment) && isset($shipment['Status']) && is_array($shipment['Status'])) {
        $st = $shipment['Status'];
        $huidig = trim((string) ($st['StatusDescription'] ?? $st['StatusCode'] ?? ''));
        $huidigTijd = trim((string) ($st['TimeStamp'] ?? $st['Timestamp'] ?? ''));
    }

    if ($huidig === '' && isset($json['CurrentStatus']['Status']) && is_array($json['CurrentStatus']['Status'])) {
        $st = $json['CurrentStatus']['Status'];
        $huidig = trim((string) ($st['StatusDescription'] ?? $st['StatusCode'] ?? ''));
        $huidigTijd = trim((string) ($st['TimeStamp'] ?? $st['Timestamp'] ?? ''));
    }

    $eventBronnen = [];
    if (is_array($shipment) && isset($shipment['Event']) && is_array($shipment['Event'])) {
        $eventBronnen = array_reverse($shipment['Event']);
    } elseif (is_array($shipment) && isset($shipment['OldStatus']) && is_array($shipment['OldStatus'])) {
        $eventBronnen = $shipment['OldStatus'];
    } else {
        $legacy = $json['CompleteStatus']['Shipment']['Status'] ?? $json['CompleteStatus']['Statuses'] ?? null;
        if (is_array($legacy) && isset($legacy[0])) {
            $eventBronnen = $legacy;
        }
    }

    foreach ($eventBronnen as $event) {
        if (!is_array($event)) {
            continue;
        }
        $statusBlock = isset($event['Status']) && is_array($event['Status']) ? $event['Status'] : $event;
        $tekst = trim((string) (
            $statusBlock['Description']
            ?? $statusBlock['StatusDescription']
            ?? $statusBlock['StatusCode']
            ?? ''
        ));
        $tijd = trim((string) ($statusBlock['TimeStamp'] ?? $statusBlock['Timestamp'] ?? ''));
        if ($tekst === '' || strcasecmp($tekst, 'Niet van toepassing') === 0) {
            continue;
        }
        $geschiedenis[] = [
            'tijd' => $tijd,
            'omschrijving' => $tekst,
        ];
    }

    if ($huidig === '' && $geschiedenis !== []) {
        $laatste = $geschiedenis[count($geschiedenis) - 1];
        $huidig = (string) ($laatste['omschrijving'] ?? '');
        $huidigTijd = (string) ($laatste['tijd'] ?? '');
    }

    return [
        'live' => $huidig !== '' || $geschiedenis !== [],
        'status' => $huidig !== '' ? 'gevonden' : 'geen_data',
        'vervoerder' => 'postnl',
        'huidige_status' => $huidig,
        'laatste_update' => $huidigTijd,
        'geschiedenis' => $geschiedenis,
        'message' => $huidig !== '' ? '' : 'Geen live status gevonden bij PostNL.',
    ];
}

// Live status ophalen bij GLS (EU public tracking API).
// Credentials: zie gls-api.php — GLS_API_USER/PASS + optioneel GLS_API_KEY (subscription).
function haalGlsApiCredentials(): array
{
    $user = getProjectEnvValue('GLS_API_USER');
    if ($user === null || trim((string) $user) === '') {
        $user = getProjectEnvValue('GLS_TRACK_API_USER');
    }

    $pass = getProjectEnvValue('GLS_API_PASS');
    if ($pass === null || trim((string) $pass) === '') {
        $pass = getProjectEnvValue('GLS_TRACK_API_PASS');
    }

    $apiKey = getProjectEnvValue('GLS_API_KEY');

    return [
        'user' => is_string($user) ? trim($user) : '',
        'pass' => is_string($pass) ? trim($pass) : '',
        'api_key' => is_string($apiKey) ? trim($apiKey) : '',
    ];
}

// GLS-status ophalen: leest uit gls_pakket_status (gevuld door api/gls/webhook.php).
// GLS NL heeft geen pull-tracking API, alleen webhooks. Zie api-portal.gls.nl.
function haalGlsZendingStatus(string $parcelId): array
{
    global $conn;

    if (!isset($conn) || !($conn instanceof PDO)) {
        return [
            'live'          => false,
            'status'        => 'geen_db',
            'vervoerder'    => 'gls',
            'huidige_status' => '',
            'laatste_update' => '',
            'geschiedenis'  => [],
            'message'       => 'Geen databaseverbinding beschikbaar.',
        ];
    }

    return haalGlsStatusUitDb($conn, $parcelId);
}

// Behouden voor tests die normaliseerGlsTraceerResponse direct aanroepen.
function normaliseerGlsTraceerResponse(array $json): array
{
    $huidig = '';
    $huidigTijd = '';
    $geschiedenis = [];

    $events = $json['Events'] ?? $json['events'] ?? [];
    if (is_array($events)) {
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $tekst = trim((string) ($event['DescriptionNL'] ?? $event['DescriptionEN'] ?? $event['description'] ?? ''));
            $tijd  = trim((string) ($event['Date'] ?? $event['timestamp'] ?? ''));
            if ($tekst !== '') {
                $geschiedenis[] = ['tijd' => $tijd, 'omschrijving' => $tekst];
            }
        }
    }

    $huidig = trim((string) ($json['State'] ?? $json['state'] ?? ''));
    if ($huidig === '' && $geschiedenis !== []) {
        $laatste    = $geschiedenis[count($geschiedenis) - 1];
        $huidig     = (string) ($laatste['omschrijving'] ?? '');
        $huidigTijd = (string) ($laatste['tijd'] ?? '');
    }

    return [
        'live' => $huidig !== '' || $geschiedenis !== [],
        'status' => $huidig !== '' || $geschiedenis !== [] ? 'gevonden' : 'geen_data',
        'vervoerder' => 'gls',
        'huidige_status' => $huidig,
        'laatste_update' => $huidigTijd,
        'geschiedenis' => $geschiedenis,
        'message' => $huidig !== '' || $geschiedenis !== [] ? '' : 'Geen live status gevonden bij GLS.',
    ];
}

// Score voor kiezen welke carrier-API het beste antwoord gaf (hoger = beter).
function scoreTraceerApiResultaat(array $result): int
{
    if (empty($result['live'])) {
        return 0;
    }

    $score = 10;
    if (trim((string) ($result['huidige_status'] ?? '')) !== '') {
        $score += 5;
    }

    $geschiedenis = $result['geschiedenis'] ?? [];
    if (is_array($geschiedenis)) {
        $score += count($geschiedenis);
    }

    return $score;
}

// Kiest het sterkste antwoord uit PostNL- en GLS-response.
function kiesBesteTraceerApiResultaat(array $postnl, array $gls): array
{
    $scorePostnl = scoreTraceerApiResultaat($postnl);
    $scoreGls = scoreTraceerApiResultaat($gls);

    if ($scorePostnl >= $scoreGls && $scorePostnl > 0) {
        $postnl['vervoerder'] = 'postnl';

        return $postnl;
    }
    if ($scoreGls > 0) {
        $gls['vervoerder'] = 'gls';

        return $gls;
    }

    $berichten = array_filter([
        trim((string) ($postnl['message'] ?? '')),
        trim((string) ($gls['message'] ?? '')),
    ]);

    return [
        'live' => false,
        'status' => 'geen_live_data',
        'vervoerder' => 'onbekend',
        'huidige_status' => '',
        'laatste_update' => '',
        'geschiedenis' => [],
        'message' => $berichten !== []
            ? implode(' ', array_unique($berichten))
            : 'Geen live bezorgstatus gevonden bij PostNL of GLS.',
    ];
}

// Altijd beide API's proberen; beste antwoord wint (geen gok op code-vorm of vervoerder).
function haalBesteLiveZendingStatus(string $trackCode): array
{
    if ($trackCode === '') {
        return [
            'live' => false,
            'status' => 'geen_trackcode',
            'message' => 'Er is nog geen traceercode bekend voor deze zending.',
        ];
    }

    return kiesBesteTraceerApiResultaat(
        haalPostNlZendingStatus($trackCode),
        haalGlsZendingStatus($trackCode)
    );
}

// Optioneel label uit DB-verzendtekst (alleen fallback-tekst als APIs niets geven).
function haalVervoerderLabelUitVerzending(string $verzending): ?string
{
    if (preg_match('/\b(postnl|post\s*nl)\b/i', $verzending) === 1) {
        return 'postnl';
    }
    if (preg_match('/\b(gls)\b/i', $verzending) === 1) {
        return 'gls';
    }

    return null;
}

// Hoofdfunctie voor de chat-tool.
function zoekTraceerRuw(PDO $conn, array $arguments): array
{
    $traceernummer = isset($arguments['traceernummer']) ? trim((string) $arguments['traceernummer']) : '';
    $bestellingId = isset($arguments['bestelling_id']) ? (int) $arguments['bestelling_id'] : 0;
    $email = isset($arguments['email']) ? trim((string) $arguments['email']) : '';

    $context = [
        'bestelling_id' => null,
        'track_code' => '',
        'verzend_status_db' => '',
        'verzending' => '',
    ];

    if ($traceernummer !== '') {
        $context['track_code'] = strtoupper(preg_replace('/\s+/', '', $traceernummer) ?? $traceernummer);
    } elseif ($bestellingId > 0 && $email !== '') {
        $orderContext = haalInterneTraceerContextUitBestelling($conn, $bestellingId, $email);
        if (empty($orderContext['gevonden'])) {
            return verwijderTraceerGevoeligeVelden([
                'functie' => 'zoek_traceer',
                'gevonden' => false,
                'message' => (string) ($orderContext['message'] ?? 'Bestelling niet gevonden.'),
            ]);
        }
        $context['bestelling_id'] = $bestellingId;
        $context['track_code'] = (string) $orderContext['track_code'];
        $context['verzend_status_db'] = (string) $orderContext['verzend_status_db'];
        $context['verzending'] = (string) $orderContext['verzending'];

        if ($context['track_code'] === '' && $context['verzend_status_db'] === 'niet_verzonden') {
            $vervoerderHint = haalVervoerderLabelUitVerzending($context['verzending']);

            return verwijderTraceerGevoeligeVelden([
                'functie' => 'zoek_traceer',
                'gevonden' => true,
                'status' => 'nog_niet_verzonden',
                'bestelling_id' => $bestellingId,
                'vervoerder' => $vervoerderHint,
                'message' => 'Je bestelling is nog niet verzonden; er is nog geen track & trace beschikbaar.',
            ]);
        }
    } else {
        return verwijderTraceerGevoeligeVelden([
            'functie' => 'zoek_traceer',
            'gevonden' => false,
            'message' => 'Geef een traceernummer óf bestelling_id met het bijbehorende e-mailadres.',
        ]);
    }

    $live = haalBesteLiveZendingStatus($context['track_code']);

    $vervoerderLabel = isset($live['vervoerder']) ? (string) $live['vervoerder'] : 'onbekend';
    if ($vervoerderLabel === 'onbekend') {
        $hint = haalVervoerderLabelUitVerzending($context['verzending']);
        if ($hint !== null) {
            $vervoerderLabel = $hint;
        }
    }

    $resultaat = [
        'functie' => 'zoek_traceer',
        'gevonden' => true,
        'status' => (string) ($live['status'] ?? 'onbekend'),
        'bestelling_id' => $context['bestelling_id'],
        'vervoerder' => $vervoerderLabel !== 'onbekend' ? $vervoerderLabel : null,
        'verzend_status_database' => $context['verzend_status_db'] !== '' ? $context['verzend_status_db'] : null,
        'live_tracking' => !empty($live['live']),
        'huidige_status' => (string) ($live['huidige_status'] ?? ''),
        'laatste_update' => (string) ($live['laatste_update'] ?? ''),
        'geschiedenis' => isset($live['geschiedenis']) && is_array($live['geschiedenis']) ? $live['geschiedenis'] : [],
        'message' => (string) ($live['message'] ?? ''),
    ];

    if ($resultaat['huidige_status'] === '' && $context['verzend_status_db'] === 'verzonden') {
        $naam = $vervoerderLabel === 'postnl' ? 'PostNL' : ($vervoerderLabel === 'gls' ? 'GLS' : 'de bezorger');
        $resultaat['message'] = 'Je pakket is bij ons als verzonden geregistreerd'
            . ($naam !== 'de bezorger' ? ' via ' . $naam : '')
            . ', maar de actuele bezorgstatus konden we nu niet live ophalen.';
    }

    return verwijderTraceerGevoeligeVelden($resultaat);
}
