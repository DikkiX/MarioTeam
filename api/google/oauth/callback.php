<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/env.php';

// Dit is de "callback" URL voor Google OAuth.
// Google stuurt de gebruiker hierheen terug nadat die op "Toestaan" heeft geklikt.
// Wij doen dan 3 dingen:
// 1) We lezen de tijdelijke "code" uit de URL.
// 2) We wisselen die code om voor tokens via Google (token endpoint).
// 3) We slaan de tokens op de server op, zodat we later Gmail API calls kunnen doen.

function stuurHtmlResponse($httpStatus, $titel, $bericht)
{
    // We laten een simpele HTML pagina zien (in plaats van JSON),
    // omdat dit in de browser wordt geopend.
    http_response_code($httpStatus);
    header('Content-Type: text/html; charset=utf-8');

    // We zetten tekst om naar "veilig" HTML zodat er geen rare tekens/HTML kan worden uitgevoerd.
    $titelEsc = htmlspecialchars((string) $titel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $berichtEsc = htmlspecialchars((string) $bericht, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo "<!doctype html><html lang=\"nl\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{$titelEsc}</title></head><body style=\"font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding: 24px;\"><h1 style=\"margin: 0 0 12px;\">{$titelEsc}</h1><p style=\"margin: 0; line-height: 1.4;\">{$berichtEsc}</p></body></html>";
    exit;
}

function bepaalBasisUrl()
{
    // We bepalen op welk domein we nu draaien.
    // Dit gebruiken we om de redirect URL exact te bouwen zoals Google die verwacht.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return null;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (!$isHttps && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $isHttps = (strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    }

    return ($isHttps ? 'https' : 'http') . '://' . $host;
}

function bepaalRedirectPad()
{
    $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $pad = parse_url($requestUri, PHP_URL_PATH);
    $pad = is_string($pad) ? $pad : '';

    if ($pad === '/api/google/oauth/callback.php') {
        return '/api/google/oauth/callback.php';
    }

    return '/api/google/oauth/callback';
}

function bepaalStorageGoogleDir()
{
    $kandidaten = [];

    $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($docroot) && $docroot !== '') {
        $kandidaten[] = rtrim($docroot, '/');
    }

    $projectRoot = realpath(__DIR__ . '/../../../../');
    if (is_string($projectRoot) && $projectRoot !== '') {
        $kandidaten[] = rtrim($projectRoot, '/');
    }

    foreach ($kandidaten as $base) {
        if (!is_string($base) || $base === '') {
            continue;
        }

        $dir = $base . '/storage/google';
        if (is_dir($dir)) {
            return $dir;
        }
    }

    $fallbackBase = isset($kandidaten[0]) ? (string) $kandidaten[0] : rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    return $fallbackBase . '/storage/google';
}

function wisselCodeVoorTokens($code, $clientId, $clientSecret, $redirectUri)
{
    // Dit is de standaard OAuth stap:
    // "authorization code" -> "access token" (+ meestal ook een refresh token).
    // Google wil deze velden exact volgens hun OAuth regels.
    $postData = http_build_query([
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ], '', '&', PHP_QUERY_RFC3986);

    // Dit is de Google URL waar je de code kunt omwisselen voor tokens.
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
    ]);

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        // We konden Google niet bereiken, of cURL had een fout.
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'Curl fout: ' . $curlErr,
        ];
    }

    // Google stuurt JSON terug.
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'Token response is geen geldige JSON.',
        ];
    }

    if ($status < 200 || $status >= 300) {
        // Bij fouten stuurt Google vaak "error" en/of "error_description".
        $err = isset($data['error_description']) ? (string) $data['error_description'] : (isset($data['error']) ? (string) $data['error'] : 'Onbekende fout');
        return [
            'ok' => false,
            'status' => $status,
            'error' => $err,
        ];
    }

    // Bij succes zit hier o.a. "access_token" en soms "refresh_token".
    return [
        'ok' => true,
        'status' => $status,
        'data' => $data,
    ];
}

function schrijfTokenBestand($host, $tokenData)
{
    // We slaan tokens op in storage, zodat de server er later bij kan.
    // We tonen tokens niet in de browser.
    $storageDir = bepaalStorageGoogleDir();
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0700, true);
    }

    if (!is_dir($storageDir)) {
        return false;
    }

    // We maken de host "veilig" voor een bestandsnaam.
    $safeHost = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $host);
    $filePath = $storageDir . '/oauth_token_' . $safeHost . '.json';

    // We bewaren ook wanneer het is opgeslagen, voor debug.
    $payload = [
        'saved_at' => gmdate('c'),
        'host' => (string) $host,
        'token' => $tokenData,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return false;
    }

    $result = @file_put_contents($filePath, $json, LOCK_EX);
    if ($result === false) {
        return false;
    }

    // Alleen de server mag dit bestand lezen/schrijven.
    @chmod($filePath, 0600);
    return true;
}

// We halen de OAuth client gegevens uit .env.
$clientId = getProjectEnvValue('GOOGLE_OAUTH_CLIENT_ID');
$clientSecret = getProjectEnvValue('GOOGLE_OAUTH_CLIENT_SECRET');

if ($clientId === null || $clientSecret === null) {
    stuurHtmlResponse(500, 'Configuratie ontbreekt', 'GOOGLE_OAUTH_CLIENT_ID en/of GOOGLE_OAUTH_CLIENT_SECRET ontbreken in .env.');
}

// Als de gebruiker op "Annuleren" drukt, stuurt Google "error" mee.
if (isset($_GET['error'])) {
    $error = trim((string) ($_GET['error'] ?? ''));
    $description = trim((string) ($_GET['error_description'] ?? ''));
    $msg = $description !== '' ? $description : ($error !== '' ? $error : 'Onbekende fout');
    stuurHtmlResponse(400, 'Google login geannuleerd', $msg);
}

// Bij succes stuurt Google een tijdelijke code mee in de URL (?code=...).
$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    stuurHtmlResponse(400, 'Geen autorisatiecode', 'Er is geen code ontvangen van Google.');
}

$basisUrl = bepaalBasisUrl();
if ($basisUrl === null) {
    stuurHtmlResponse(500, 'Serverfout', 'Host kon niet worden bepaald.');
}

// Deze redirect URL moet exact overeenkomen met wat in Google Console staat.
// Sommige servers ondersteunen geen "mooie URL" zonder .php, daarom ondersteunen we beide paden.
$redirectUri = $basisUrl . bepaalRedirectPad();

// Code omwisselen voor tokens.
$exchange = wisselCodeVoorTokens($code, $clientId, $clientSecret, $redirectUri);
if (!is_array($exchange) || empty($exchange['ok'])) {
    $msg = isset($exchange['error']) ? (string) $exchange['error'] : 'Onbekende fout bij token ophalen.';
    stuurHtmlResponse(500, 'Token ophalen mislukt', $msg);
}

$tokenData = $exchange['data'] ?? [];
$host = $_SERVER['HTTP_HOST'] ?? 'unknown';

// Tokens opslaan op de server.
if (!schrijfTokenBestand($host, $tokenData)) {
    stuurHtmlResponse(500, 'Opslaan mislukt', 'Token is opgehaald, maar opslaan op de server is mislukt.');
}

// Klaar: gebruiker ziet alleen "Gelukt".
stuurHtmlResponse(200, 'Gelukt', 'Google toestemming is opgeslagen. Je kunt dit tabblad sluiten.');
