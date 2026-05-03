<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/env.php';

session_start();

// Dit bestand is het complete e-mail dashboard:
// - Login + CSRF
// - Gmail OAuth token lezen/refreshen
// - Ongelezen mails ophalen en AI-concepten aanmaken
// - Drafts bekijken/bewerken/versturen

function stuurHtml($httpStatus, $html)
{
    // Dit dashboard draait als webpagina, daarom sturen we HTML terug.
    http_response_code($httpStatus);
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function e($value)
{
    // Dit voorkomt dat HTML uit de database als echte HTML wordt uitgevoerd.
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken()
{
    // Dit token zorgt dat alleen onze eigen formulieren acties mogen uitvoeren.
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function vereisCsrf()
{
    // Als het token niet klopt, blokkeren we de aanvraag.
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    if (!isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $token)) {
        stuurHtml(400, '<h1>Ongeldige aanvraag</h1><p>CSRF token klopt niet.</p>');
    }
}

function renderLoginPagina($melding = '')
{
    // Simpele login-pagina voor medewerkers.
    $csrf = csrfToken();
    $msgHtml = '';
    if (is_string($melding) && $melding !== '') {
        $msgHtml = '<div style="background:#fee2e2; border:1px solid #ef4444; padding:10px 12px; border-radius:10px; margin-bottom:12px;">' . e($melding) . '</div>';
    }

    $html = '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Email dashboard</title></head><body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#e5e7eb; color:#111827; margin:0; padding:22px;">';
    $html .= '<div style="max-width: 520px; margin:0 auto; background:#f3f4f6; border:1px solid #9ca3af; border-radius:14px; padding:16px;">';
    $html .= '<div style="font-weight:800; font-size:18px; margin-bottom:12px;">Mario Team - AI E-mail Concepten Module</div>';
    $html .= '<h1 style="margin:0 0 12px; font-size:18px;">Inloggen</h1>';
    $html .= $msgHtml;
    $html .= '<form method="post" action="">';
    $html .= '<input type="hidden" name="csrf" value="' . e($csrf) . '">';
    $html .= '<input type="hidden" name="actie" value="login">';
    $html .= '<label style="display:block; color:#111827; margin-bottom:6px; font-weight:700;">Gebruikersnaam</label>';
    $html .= '<input name="user" autocomplete="username" style="width:100%; box-sizing:border-box; border-radius:10px; border:1px solid #9ca3af; background:#ffffff; color:#111827; padding:10px 12px; margin-bottom:10px;">';
    $html .= '<label style="display:block; color:#111827; margin-bottom:6px; font-weight:700;">Wachtwoord</label>';
    $html .= '<input type="password" name="pass" autocomplete="current-password" style="width:100%; box-sizing:border-box; border-radius:10px; border:1px solid #9ca3af; background:#ffffff; color:#111827; padding:10px 12px; margin-bottom:12px;">';
    $html .= '<button type="submit" style="background:#60a5fa; border:1px solid #3b82f6; color:#111827; font-weight:800; padding:10px 14px; border-radius:10px; cursor:pointer; width:100%;">Inloggen</button>';
    $html .= '</form>';
    $html .= '</div></body></html>';
    stuurHtml(200, $html);
}

function vereisDashboardLogin()
{
    // Simpele interne login voor medewerkers (waardes staan in .env).
    $user = getProjectEnvValue('EMAIL_DASHBOARD_USER');
    $pass = getProjectEnvValue('EMAIL_DASHBOARD_PASS');

    if ($user === null || $pass === null) {
        stuurHtml(500, '<h1>Configuratie ontbreekt</h1><p>EMAIL_DASHBOARD_USER en EMAIL_DASHBOARD_PASS ontbreken in .env.</p>');
    }

    if (isset($_POST['actie']) && (string) $_POST['actie'] === 'login') {
        vereisCsrf();
        $gegevenUser = isset($_POST['user']) ? (string) $_POST['user'] : '';
        $gegevenPass = isset($_POST['pass']) ? (string) $_POST['pass'] : '';

        $isOk = hash_equals((string) $user, $gegevenUser) && hash_equals((string) $pass, $gegevenPass);
        if (!$isOk) {
            renderLoginPagina('Gebruikersnaam of wachtwoord is verkeerd.');
        }

        $_SESSION['email_dashboard_authed'] = true;

        $locatie = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/EmailDashboard.php';
        header('Location: ' . $locatie, true, 303);
        exit;
    }

    if (!empty($_GET['logout'])) {
        $_SESSION['email_dashboard_authed'] = false;
        renderLoginPagina('Je bent uitgelogd.');
    }

    if (empty($_SESSION['email_dashboard_authed'])) {
        renderLoginPagina();
    }
}

function bepaalStorageGoogleDir()
{
    // In storage/google staat het OAuth tokenbestand dat we nodig hebben voor Gmail API.
    $startDirs = [];

    $startDirs[] = __DIR__;

    $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($docroot) && $docroot !== '') {
        $startDirs[] = $docroot;
    }

    foreach ($startDirs as $start) {
        if (!is_string($start) || trim($start) === '') {
            continue;
        }

        $dir = rtrim($start, '/');
        for ($i = 0; $i < 10; $i++) {
            $candidate = $dir . '/storage/google';
            if (is_dir($candidate)) {
                return $candidate;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }

    $fallbackBase = is_string($docroot) && $docroot !== '' ? rtrim($docroot, '/') : rtrim(__DIR__, '/');
    return $fallbackBase . '/storage/google';
}

function normaliseerHostVoorBestand($host)
{
    // Tokenbestanden zijn per host opgeslagen, dus we normaliseren www/poort.
    $host = strtolower(trim((string) $host));
    if ($host === '') {
        return '';
    }

    if (strpos($host, ':') !== false) {
        $parts = explode(':', $host);
        $mogelijkHost = $parts[0] ?? '';
        $mogelijkPort = $parts[1] ?? '';
        if ($mogelijkHost !== '' && $mogelijkPort !== '' && ctype_digit($mogelijkPort)) {
            $host = $mogelijkHost;
        }
    }

    return $host;
}

function lijstTokenBestandenInDir($storageDir)
{
    // Voor debug en fallback: welke tokenbestanden bestaan er?
    if (!is_string($storageDir) || $storageDir === '' || !is_dir($storageDir)) {
        return [];
    }

    $files = glob(rtrim($storageDir, '/') . '/oauth_token_*.json');
    if (!is_array($files)) {
        return [];
    }

    $result = [];
    foreach ($files as $f) {
        if (is_string($f) && is_file($f)) {
            $result[] = $f;
        }
    }

    sort($result);
    return $result;
}

function leesTokenBestandVoorHost($host)
{
    // Het tokenbestand is per host opgeslagen (www/non-www kunnen verschillen).
    $storageDir = bepaalStorageGoogleDir();
    $hostRaw = trim((string) $host);
    $hostNorm = normaliseerHostVoorBestand($hostRaw);

    $kandidaten = [];
    if ($hostRaw !== '') {
        $kandidaten[] = $hostRaw;
    }
    if ($hostNorm !== '' && $hostNorm !== $hostRaw) {
        $kandidaten[] = $hostNorm;
    }

    foreach ($kandidaten as $h) {
        $h = strtolower((string) $h);
        $varianten = [$h];
        if (substr($h, 0, 4) === 'www.') {
            $varianten[] = substr($h, 4);
        } else {
            $varianten[] = 'www.' . $h;
        }

        foreach ($varianten as $v) {
            $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $v);
            $filePath = $storageDir . '/oauth_token_' . $safe . '.json';
            if (is_file($filePath)) {
                return $filePath;
            }
        }
    }

    $alleTokens = lijstTokenBestandenInDir($storageDir);
    if (count($alleTokens) === 1) {
        return $alleTokens[0];
    }

    return null;
}

function laadTokenPayload($tokenFilePath)
{
    // Leest het tokenbestand (JSON) van de schijf.
    $raw = @file_get_contents($tokenFilePath);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    return $data;
}

function slaTokenPayloadOp($tokenFilePath, $payload)
{
    // Slaat het (ververste) token terug op in hetzelfde bestand.
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return false;
    }
    $result = @file_put_contents($tokenFilePath, $json, LOCK_EX);
    if ($result === false) {
        return false;
    }
    @chmod($tokenFilePath, 0600);
    return true;
}

function tokenIsVerlopen($payload)
{
    // Access tokens verlopen snel, dus we gebruiken saved_at + expires_in.
    if (!is_array($payload) || !isset($payload['saved_at']) || !isset($payload['token']) || !is_array($payload['token'])) {
        return true;
    }
    $savedAt = strtotime((string) $payload['saved_at']);
    if (!is_int($savedAt) || $savedAt <= 0) {
        return true;
    }
    $expiresIn = isset($payload['token']['expires_in']) ? (int) $payload['token']['expires_in'] : 0;
    if ($expiresIn <= 0) {
        return false;
    }
    return (time() >= ($savedAt + $expiresIn - 60));
}

function refreshAccessToken($clientId, $clientSecret, $refreshToken)
{
    // Refresh via Google token endpoint: refresh_token -> nieuw access_token.
    $postData = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ], '', '&', PHP_QUERY_RFC3986);

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
        return ['ok' => false, 'error' => 'Curl fout: ' . $curlErr];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Refresh response is geen geldige JSON.'];
    }
    if ($status < 200 || $status >= 300) {
        $err = isset($data['error_description']) ? (string) $data['error_description'] : (isset($data['error']) ? (string) $data['error'] : 'Onbekende fout');
        return ['ok' => false, 'error' => $err];
    }
    return ['ok' => true, 'data' => $data];
}

function bepaalBasisUrlVoorOAuth()
{
    // We bouwen hier de basis URL voor de redirect_uri (http/https + host).
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $host = trim($host);
    if ($host === '') {
        return null;
    }

    $isHttps = false;
    if (!empty($_SERVER['HTTPS']) && (string) $_SERVER['HTTPS'] !== 'off') {
        $isHttps = true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
        $isHttps = true;
    }

    $scheme = $isHttps ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function maakGoogleAuthUrl()
{
    // URL om de Google OAuth flow te starten (offline + consent voor refresh_token).
    $clientId = getProjectEnvValue('GOOGLE_OAUTH_CLIENT_ID');
    if ($clientId === null || $clientId === '') {
        return null;
    }

    $basis = bepaalBasisUrlVoorOAuth();
    if ($basis === null) {
        return null;
    }

    $redirectUri = $basis . '/api/google/oauth/callback';
    $scopes = [
        'https://www.googleapis.com/auth/gmail.modify',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    $params = [
        'client_id' => (string) $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => implode(' ', $scopes),
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function maakGoogleKoppelKnopHtml($authUrl)
{
    // Kleine helper: in de foutmelding tonen we een echte knop, geen losse link.
    $u = trim((string) $authUrl);
    if ($u === '') {
        return '';
    }
    return '<a href="' . e($u) . '" style="display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid #3b82f6; background:#60a5fa; color:#111827; font-weight:800; text-decoration:none;">Koppel Google opnieuw</a>';
}

function isGmailTokenIngetrokkenFout($errorTekst)
{
    // Google stuurt bij ingetrokken/verlopen refresh tokens vaak "invalid_grant".
    $t = strtolower((string) $errorTekst);
    return strpos($t, 'expired or revoked') !== false || strpos($t, 'invalid_grant') !== false;
}

function haalGmailAccessTokenOp()
{
    // Dit regelt een geldig access_token (inclusief refresh als nodig).
    $clientId = getProjectEnvValue('GOOGLE_OAUTH_CLIENT_ID');
    $clientSecret = getProjectEnvValue('GOOGLE_OAUTH_CLIENT_SECRET');
    if ($clientId === null || $clientSecret === null) {
        return ['ok' => false, 'error' => 'GOOGLE_OAUTH_CLIENT_ID/SECRET ontbreken in .env.'];
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return ['ok' => false, 'error' => 'Host kon niet worden bepaald.'];
    }

    $tokenFile = leesTokenBestandVoorHost($host);
    if ($tokenFile === null) {
        // Geen tokenbestand: eerst koppelen via Google OAuth.
        $authUrl = maakGoogleAuthUrl();
        if (is_string($authUrl) && $authUrl !== '') {
            return ['ok' => false, 'error' => 'Gmail is nog niet gekoppeld.', 'reauth_url' => $authUrl];
        }
        return ['ok' => false, 'error' => 'Gmail is nog niet gekoppeld.'];
    }

    $payload = laadTokenPayload($tokenFile);
    if (!is_array($payload) || !isset($payload['token']) || !is_array($payload['token'])) {
        return ['ok' => false, 'error' => 'Tokenbestand is ongeldig.'];
    }

    $token = $payload['token'];
    $accessToken = isset($token['access_token']) ? (string) $token['access_token'] : '';
    $refreshToken = isset($token['refresh_token']) ? (string) $token['refresh_token'] : '';

    if ($accessToken === '') {
        return ['ok' => false, 'error' => 'access_token ontbreekt.'];
    }

    if (!tokenIsVerlopen($payload)) {
        return ['ok' => true, 'access_token' => $accessToken];
    }

    if ($refreshToken === '') {
        // Zonder refresh_token kunnen we niet automatisch vernieuwen.
        $authUrl = maakGoogleAuthUrl();
        if (is_string($authUrl) && $authUrl !== '') {
            return ['ok' => false, 'error' => 'Token is verlopen en refresh_token ontbreekt.', 'reauth_url' => $authUrl];
        }
        return ['ok' => false, 'error' => 'Token is verlopen en refresh_token ontbreekt.'];
    }

    $refresh = refreshAccessToken($clientId, $clientSecret, $refreshToken);
    if (empty($refresh['ok'])) {
        // Bij "invalid_grant" moeten we opnieuw koppelen (oude token is waardeloos).
        $err = isset($refresh['error']) ? (string) $refresh['error'] : 'Refresh mislukt.';
        if (isGmailTokenIngetrokkenFout($err)) {
            @unlink($tokenFile);
            $authUrl = maakGoogleAuthUrl();
            if (is_string($authUrl) && $authUrl !== '') {
                return ['ok' => false, 'error' => 'Google token is verlopen of ingetrokken.', 'reauth_url' => $authUrl];
            }
            return ['ok' => false, 'error' => 'Google token is verlopen of ingetrokken.'];
        }
        return ['ok' => false, 'error' => $err];
    }

    $nieuw = $refresh['data'] ?? [];
    if (!is_array($nieuw) || empty($nieuw['access_token'])) {
        return ['ok' => false, 'error' => 'Refresh gaf geen access_token terug.'];
    }

    $payload['saved_at'] = gmdate('c');
    $payload['token']['access_token'] = (string) $nieuw['access_token'];
    if (isset($nieuw['expires_in'])) {
        $payload['token']['expires_in'] = (int) $nieuw['expires_in'];
    }
    if (isset($nieuw['scope'])) {
        $payload['token']['scope'] = (string) $nieuw['scope'];
    }
    if (isset($nieuw['token_type'])) {
        $payload['token']['token_type'] = (string) $nieuw['token_type'];
    }

    slaTokenPayloadOp($tokenFile, $payload);

    return ['ok' => true, 'access_token' => (string) $nieuw['access_token']];
}

function gmailApiRequest($method, $path, $accessToken, $body = null, $query = [])
{
    // Wrapper om Gmail API aan te roepen (GET/POST).
    $url = 'https://gmail.googleapis.com/gmail/v1/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_filter([
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]));

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'Curl fout: ' . $curlErr];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'status' => $status, 'error' => 'API response is geen geldige JSON.'];
    }

    if ($status < 200 || $status >= 300) {
        $err = isset($data['error']['message']) ? (string) $data['error']['message'] : 'Onbekende API fout';
        return ['ok' => false, 'status' => $status, 'error' => $err, 'data' => $data];
    }

    return ['ok' => true, 'status' => $status, 'data' => $data];
}

function base64UrlDecode($data)
{
    // Gmail gebruikt base64url encoding voor inhoud.
    $data = strtr((string) $data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($data, true);
    return $decoded === false ? '' : $decoded;
}

function haalHeaderOp($headers, $naam)
{
    // Gmail geeft headers als lijst met naam + waarde. Deze functie zoekt één header op.
    if (!is_array($headers)) {
        return null;
    }
    foreach ($headers as $h) {
        if (!is_array($h)) {
            continue;
        }
        $n = isset($h['name']) ? (string) $h['name'] : '';
        if (strcasecmp($n, (string) $naam) === 0) {
            return isset($h['value']) ? (string) $h['value'] : '';
        }
    }
    return null;
}

function zoekTekstPlainInPayload($payload)
{
    if (!is_array($payload)) {
        return null;
    }

    if (isset($payload['mimeType']) && (string) $payload['mimeType'] === 'text/plain') {
        $data = $payload['body']['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = base64UrlDecode($data);
            return $decoded !== '' ? $decoded : null;
        }
    }

    if (isset($payload['parts']) && is_array($payload['parts'])) {
        foreach ($payload['parts'] as $part) {
            $found = zoekTekstPlainInPayload($part);
            if (is_string($found) && $found !== '') {
                return $found;
            }
        }
    }

    return null;
}

function zoekTekstHtmlInPayload($payload)
{
    // Sommige mails hebben alleen HTML (geen text/plain).
    // Deze functie zet HTML om naar normale tekst, zodat we het in het dashboard kunnen tonen.
    if (!is_array($payload)) {
        return null;
    }

    if (isset($payload['mimeType']) && (string) $payload['mimeType'] === 'text/html') {
        $data = $payload['body']['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = base64UrlDecode($data);
            if ($decoded === '') {
                return null;
            }

            $html = str_replace(["\r\n", "\r"], "\n", $decoded);
            $html = preg_replace('/<\s*head\b[^>]*>[\s\S]*?<\s*\/\s*head\s*>/i', '', (string) $html);
            $html = preg_replace('/<\s*style\b[^>]*>[\s\S]*?<\s*\/\s*style\s*>/i', '', (string) $html);
            $html = preg_replace('/<\s*script\b[^>]*>[\s\S]*?<\s*\/\s*script\s*>/i', '', (string) $html);
            $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html);
            $html = preg_replace('/<\/\s*p\s*>/i', "\n\n", $html);
            $html = preg_replace('/<\/\s*div\s*>/i', "\n", $html);
            $text = strip_tags($html);
            $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);
            $text = trim((string) $text);
            return $text !== '' ? $text : null;
        }
    }

    if (isset($payload['parts']) && is_array($payload['parts'])) {
        foreach ($payload['parts'] as $part) {
            $found = zoekTekstHtmlInPayload($part);
            if (is_string($found) && $found !== '') {
                return $found;
            }
        }
    }

    return null;
}

function normaliseerTekst($text)
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim((string) $text);
}

function haalHtmlUitPayload($payload)
{
    // Sommige mails hebben alleen HTML, dit haalt de ruwe HTML string uit de payload.
    if (!is_array($payload)) {
        return null;
    }

    if (isset($payload['mimeType']) && (string) $payload['mimeType'] === 'text/html') {
        $data = $payload['body']['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = base64UrlDecode($data);
            return $decoded !== '' ? $decoded : null;
        }
    }

    if (isset($payload['parts']) && is_array($payload['parts'])) {
        foreach ($payload['parts'] as $part) {
            $found = haalHtmlUitPayload($part);
            if (is_string($found) && $found !== '') {
                return $found;
            }
        }
    }

    return null;
}

function sanitizeEmailHtmlVoorDashboard($html)
{
    // We willen opmaak tonen, maar geen scripts/styling/rare attributen uitvoeren.
    $html = str_replace(["\r\n", "\r"], "\n", (string) $html);
    $html = preg_replace('/<\s*head\b[^>]*>[\s\S]*?<\s*\/\s*head\s*>/i', '', (string) $html);
    $html = preg_replace('/<\s*style\b[^>]*>[\s\S]*?<\s*\/\s*style\s*>/i', '', (string) $html);
    $html = preg_replace('/<\s*script\b[^>]*>[\s\S]*?<\s*\/\s*script\s*>/i', '', (string) $html);
    $html = preg_replace('/<\s*meta\b[^>]*>/i', '', (string) $html);
    $html = preg_replace('/<\s*link\b[^>]*>/i', '', (string) $html);

    $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><a><table><thead><tbody><tr><td><th><div><span><hr>';
    $html = strip_tags((string) $html, $allowed);

    $html = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', (string) $html);
    $html = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', (string) $html);
    $html = preg_replace('/\son\w+\s*=\s*[^\s>]+/i', '', (string) $html);

    $html = preg_replace('/\sstyle\s*=\s*"[^"]*"/i', '', (string) $html);
    $html = preg_replace("/\sstyle\s*=\s*'[^']*'/i", '', (string) $html);
    $html = preg_replace('/\sclass\s*=\s*"[^"]*"/i', '', (string) $html);
    $html = preg_replace("/\sclass\s*=\s*'[^']*'/i", '', (string) $html);

    $html = preg_replace_callback('/<\s*a\b([^>]*)>/i', function ($m) {
        $attrs = (string) ($m[1] ?? '');
        $href = '';
        if (preg_match('/href\s*=\s*"([^"]*)"/i', $attrs, $hm) === 1) {
            $href = (string) ($hm[1] ?? '');
        } elseif (preg_match("/href\s*=\s*'([^']*)'/i", $attrs, $hm) === 1) {
            $href = (string) ($hm[1] ?? '');
        }

        $href = trim($href);
        if ($href !== '' && preg_match('/^\s*javascript:/i', $href) === 1) {
            $href = '';
        }

        if ($href === '') {
            return '<a>';
        }

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">';
    }, (string) $html);

    $html = preg_replace("/\n{3,}/", "\n\n", (string) $html);
    $html = trim((string) $html);
    return $html;
}

function parseerEmailAdresUitFromHeader($fromHeader)
{
    // We willen alleen het e-mailadres uit de afzender-regel (From) halen.
    $fromHeader = trim((string) $fromHeader);
    if ($fromHeader === '') {
        return '';
    }

    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $fromHeader, $m) === 1) {
        $candidate = trim((string) ($m[0] ?? ''));
        return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : '';
    }

    return '';
}

function parseerEmailAdressenUitHeaderTekst($headerTekst)
{
    // Haal 1 of meerdere e-mailadressen uit een header-string (To/Cc/Delivered-To/etc).
    $t = trim((string) $headerTekst);
    if ($t === '') {
        return [];
    }

    $result = [];
    if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $t, $matches) > 0) {
        $found = $matches[0] ?? [];
        if (is_array($found)) {
            foreach ($found as $m) {
                $candidate = strtolower(trim((string) $m));
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    $result[] = $candidate;
                }
            }
        }
    } else {
        $single = parseerEmailAdresUitFromHeader($t);
        if ($single !== '') {
            $result[] = strtolower($single);
        }
    }

    return array_values(array_unique($result));
}

function tabelHeeftKolom($conn, $table, $kolom)
{
    // Check of een kolom bestaat, zodat we veilig iets kunnen toevoegen als dat nodig is.
    $table = trim((string) $table);
    $kolom = trim((string) $kolom);
    if ($table === '' || $kolom === '') {
        return false;
    }

    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $stmt->execute([':c' => $kolom]);
        return (bool) $stmt->fetch();
    } catch (Throwable) {
        return false;
    }
}

function zorgEmailConceptenAliasKolommen($conn)
{
    // Voeg extra kolommen toe als ze nog niet bestaan, zodat oude databases ook blijven werken.
    try {
        if (!tabelHeeftKolom($conn, 'email_concepten', 'onderwerp')) {
            $conn->exec("ALTER TABLE email_concepten ADD COLUMN onderwerp VARCHAR(255) NULL AFTER gmail_thread_id");
        }
        if (!tabelHeeftKolom($conn, 'email_concepten', 'ontvangen_op_email')) {
            $conn->exec("ALTER TABLE email_concepten ADD COLUMN ontvangen_op_email VARCHAR(255) NULL AFTER klant_email");
        }
        if (!tabelHeeftKolom($conn, 'email_concepten', 'afzender_alias_email')) {
            $conn->exec("ALTER TABLE email_concepten ADD COLUMN afzender_alias_email VARCHAR(255) NULL AFTER ontvangen_op_email");
        }
    } catch (Throwable) {
    }
}

function bestaatEmailConceptVoorThread($conn, $threadId)
{
    // Zo maken we geen dubbele concepten voor dezelfde thread.
    $stmt = $conn->prepare("
        SELECT id
        FROM email_concepten
        WHERE gmail_thread_id = :thread_id
          AND status = 'draft'
        LIMIT 1
    ");
    $stmt->execute([
        ':thread_id' => (string) $threadId,
    ]);

    return (bool) $stmt->fetch();
}

function voegEmailConceptToe($conn, $threadId, $klantEmail, $conceptTekst, $ontvangenOpEmail = '', $afzenderAliasEmail = '', $onderwerp = '')
{
    // Dit slaat het concept op als draft.
    zorgEmailConceptenAliasKolommen($conn);
    $heeftOnderwerp = tabelHeeftKolom($conn, 'email_concepten', 'onderwerp');
    $heeftOntvangen = tabelHeeftKolom($conn, 'email_concepten', 'ontvangen_op_email');
    $heeftAfzender = tabelHeeftKolom($conn, 'email_concepten', 'afzender_alias_email');

    $kolommen = ['gmail_thread_id', 'klant_email'];
    $placeholders = [':thread_id', ':klant_email'];
    $params = [
        ':thread_id' => (string) $threadId,
        ':klant_email' => (string) $klantEmail,
    ];

    if ($heeftOnderwerp) {
        $kolommen[] = 'onderwerp';
        $placeholders[] = ':onderwerp';
        $params[':onderwerp'] = (string) $onderwerp;
    }
    if ($heeftOntvangen) {
        $kolommen[] = 'ontvangen_op_email';
        $placeholders[] = ':ontvangen';
        $params[':ontvangen'] = (string) $ontvangenOpEmail;
    }
    if ($heeftAfzender) {
        $kolommen[] = 'afzender_alias_email';
        $placeholders[] = ':afzender';
        $params[':afzender'] = (string) $afzenderAliasEmail;
    }

    $kolommen[] = 'concept_tekst';
    $placeholders[] = ':concept_tekst';
    $params[':concept_tekst'] = (string) $conceptTekst;

    $kolommen[] = 'status';
    $placeholders[] = "'draft'";

    $stmt = $conn->prepare("
        INSERT INTO email_concepten (" . implode(', ', $kolommen) . ")
        VALUES (" . implode(', ', $placeholders) . ")
    ");
    $stmt->execute($params);

    return (int) $conn->lastInsertId();
}

function roepOpenAiAanVoorEmailConcept($onderwerp, $klantTekst, $extraInstructies = '')
{
    // Dit maakt een concept-antwoord op basis van de klantmail.
    $apiKey = getProjectEnvValue('OPENAI_API_KEY');
    if ($apiKey === null || $apiKey === '') {
        return ['ok' => false, 'error' => 'OPENAI_API_KEY ontbreekt in .env.'];
    }

    $system = 'Je schrijft een concept-antwoord voor de klantenservice van de webshops van MarioTeam. Schrijf in het Nederlands. Als informatie ontbreekt, stel eerst korte, duidelijke vragen. Geef geen exacte voorraadaantallen. Als de klant om ordergegevens vraagt, vraag eerst om bestelnummer + e-mailadres. Geef alleen het antwoord (geen uitleg over je stappen).';
    $tone = '';
    try {
        global $conn;
        if (isset($conn) && $conn) {
            $tone = haalDashboardSetting($conn, 'tone_of_voice');
        }
    } catch (Throwable) {
        $tone = '';
    }
    if (is_string($tone) && trim($tone) !== '') {
        $system .= "\n\nTone of voice instructies:\n" . trim($tone);
    }
    if (is_string($extraInstructies) && trim($extraInstructies) !== '') {
        $system .= "\n\nExtra regels/instructies:\n" . trim($extraInstructies);
    }
    $user = "Onderwerp: " . (string) $onderwerp . "\n\nKlantmail:\n" . (string) $klantTekst;

    $data = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.2,
        'max_completion_tokens' => 900,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'OpenAI curl fout: ' . (string) $err];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'OpenAI gaf geen geldige JSON terug.'];
    }

    if ($status < 200 || $status >= 300) {
        $msg = isset($decoded['error']['message']) ? (string) $decoded['error']['message'] : 'Onbekende OpenAI fout';
        return ['ok' => false, 'error' => $msg];
    }

    $content = $decoded['choices'][0]['message']['content'] ?? '';
    $content = is_string($content) ? trim($content) : '';
    if ($content === '') {
        return ['ok' => false, 'error' => 'OpenAI gaf geen tekst terug.'];
    }

    return ['ok' => true, 'content' => $content];
}

function bouwRfc2822Bericht($toEmail, $subject, $bodyText, $inReplyTo = null, $references = null, $fromHeader = null)
{
    // Gmail API verwacht het bericht als RFC2822 in base64url (raw).
    $headers = [];
    if (is_string($fromHeader) && trim($fromHeader) !== '') {
        $headers[] = 'From: ' . trim($fromHeader);
    }
    $headers[] = 'To: ' . $toEmail;
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset="UTF-8"';
    $headers[] = 'Content-Transfer-Encoding: 7bit';
    if (is_string($inReplyTo) && $inReplyTo !== '') {
        $headers[] = 'In-Reply-To: ' . $inReplyTo;
    }
    if (is_string($references) && $references !== '') {
        $headers[] = 'References: ' . $references;
    }

    $raw = implode("\r\n", $headers) . "\r\n\r\n" . (string) $bodyText;
    $b64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    return $b64;
}

function zorgDashboardSettingsTabel($conn)
{
    // Deze tabel bewaart dashboard instellingen zoals tone of voice.
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `dashboard_settings` (
            `setting_key` VARCHAR(64) NOT NULL,
            `setting_value` LONGTEXT NOT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

function haalDashboardSetting($conn, $key)
{
    // Dit haalt 1 instelling op uit de database.
    zorgDashboardSettingsTabel($conn);
    $stmt = $conn->prepare("SELECT setting_value FROM dashboard_settings WHERE setting_key = :k LIMIT 1");
    $stmt->execute([':k' => (string) $key]);
    $row = $stmt->fetch();
    if (!$row || !isset($row['setting_value'])) {
        return '';
    }
    return (string) $row['setting_value'];
}

function slaDashboardSettingOp($conn, $key, $value)
{
    // Dit slaat 1 instelling op (upsert).
    zorgDashboardSettingsTabel($conn);
    $stmt = $conn->prepare("
        INSERT INTO dashboard_settings (setting_key, setting_value)
        VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([
        ':k' => (string) $key,
        ':v' => (string) $value,
    ]);
}

function zorgEmailRulesTabel($conn)
{
    // Deze tabel bewaart regels & filters voor e-mails.
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `email_rules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `condition_type` VARCHAR(32) NOT NULL,
            `condition_value` VARCHAR(255) NOT NULL,
            `action_type` VARCHAR(32) NOT NULL,
            `action_value` LONGTEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX (`is_enabled`),
            INDEX (`condition_type`),
            INDEX (`action_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

function haalEmailRules($conn)
{
    // Dit haalt alle regels op voor de instellingenpagina.
    zorgEmailRulesTabel($conn);
    $stmt = $conn->prepare("
        SELECT id, is_enabled, condition_type, condition_value, action_type, action_value, created_at, updated_at
        FROM email_rules
        ORDER BY id DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function haalActieveEmailRules($conn)
{
    // Dit haalt alleen actieve regels op voor het filteren.
    zorgEmailRulesTabel($conn);
    $stmt = $conn->prepare("
        SELECT id, condition_type, condition_value, action_type, action_value
        FROM email_rules
        WHERE is_enabled = 1
        ORDER BY id ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function verwerkEmailRulesVoorMail($rules, $fromHeader, $subject)
{
    // Dit controleert of de mail voldoet aan regels, en geeft acties terug.
    $fromHeader = strtolower((string) $fromHeader);
    $subject = strtolower((string) $subject);

    $ignore = false;
    $extra = [];

    foreach ($rules as $r) {
        if (!is_array($r)) {
            continue;
        }
        $condType = isset($r['condition_type']) ? (string) $r['condition_type'] : '';
        $condValue = isset($r['condition_value']) ? trim((string) $r['condition_value']) : '';
        $actionType = isset($r['action_type']) ? (string) $r['action_type'] : '';
        $actionValue = isset($r['action_value']) ? trim((string) $r['action_value']) : '';

        if ($condValue === '' || $condType === '' || $actionType === '') {
            continue;
        }

        $needle = strtolower($condValue);
        $match = false;
        if ($condType === 'from_contains') {
            $match = (strpos($fromHeader, $needle) !== false);
        } elseif ($condType === 'subject_contains') {
            $match = (strpos($subject, $needle) !== false);
        }

        if (!$match) {
            continue;
        }

        if ($actionType === 'ignore') {
            $ignore = true;
            break;
        }
        if ($actionType === 'add_prompt' && $actionValue !== '') {
            $extra[] = $actionValue;
        }
    }

    return [
        'ignore' => $ignore,
        'extra_instructies' => implode("\n\n", $extra),
    ];
}

function zorgEmailAliassenTabel($conn)
{
    // Deze tabel bewaart welke afzender-adressen (send-as) beschikbaar zijn en of de AI ze mag gebruiken.
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `email_aliassen` (
            `send_as_email` VARCHAR(255) NOT NULL,
            `display_name` VARCHAR(255) NOT NULL DEFAULT '',
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `is_default` TINYINT(1) NOT NULL DEFAULT 0,
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`send_as_email`),
            INDEX (`is_enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

function haalEmailAliassen($conn)
{
    // Alle aliassen (ook uitgeschakelde) voor het dashboard.
    zorgEmailAliassenTabel($conn);
    $stmt = $conn->prepare("
        SELECT send_as_email, display_name, is_primary, is_default, is_enabled, updated_at
        FROM email_aliassen
        ORDER BY is_default DESC, is_primary DESC, send_as_email ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function haalActieveEmailAliassen($conn)
{
    // Alleen aliassen die aan staan (keuzelijst voor het versturen).
    zorgEmailAliassenTabel($conn);
    $stmt = $conn->prepare("
        SELECT send_as_email, display_name, is_primary, is_default
        FROM email_aliassen
        WHERE is_enabled = 1
        ORDER BY is_default DESC, is_primary DESC, send_as_email ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function upsertEmailAliassenVanGmail($conn, $sendAsArray)
{
    // Zet aliassen uit Gmail in de database. Bestaat hij al? Dan werken we hem bij.
    zorgEmailAliassenTabel($conn);
    if (!is_array($sendAsArray)) {
        return 0;
    }

    $count = 0;
    foreach ($sendAsArray as $row) {
        if (!is_array($row)) {
            continue;
        }
        $email = isset($row['sendAsEmail']) ? strtolower(trim((string) $row['sendAsEmail'])) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $display = isset($row['displayName']) ? trim((string) $row['displayName']) : '';
        $isPrimary = !empty($row['isPrimary']) ? 1 : 0;
        $isDefault = !empty($row['isDefault']) ? 1 : 0;

        $stmt = $conn->prepare("
            INSERT INTO email_aliassen (send_as_email, display_name, is_primary, is_default, is_enabled)
            VALUES (:email, :display, :is_primary, :is_default, 1)
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                is_primary = VALUES(is_primary),
                is_default = VALUES(is_default)
        ");
        $stmt->execute([
            ':email' => $email,
            ':display' => $display,
            ':is_primary' => $isPrimary,
            ':is_default' => $isDefault,
        ]);
        $count++;
    }

    return $count;
}

function slaEmailAliassenActiefOp($conn, $enabledMap)
{
    // Sla op welke aliassen aan of uit staan.
    zorgEmailAliassenTabel($conn);
    if (!is_array($enabledMap)) {
        $enabledMap = [];
    }

    $rows = haalEmailAliassen($conn);
    foreach ($rows as $r) {
        $email = isset($r['send_as_email']) ? (string) $r['send_as_email'] : '';
        if ($email === '') {
            continue;
        }
        $enabled = isset($enabledMap[$email]) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE email_aliassen SET is_enabled = :en WHERE send_as_email = :email");
        $stmt->execute([
            ':en' => $enabled,
            ':email' => $email,
        ]);
    }
}

function haalOntvangerEmailUitMailHeaders($headers)
{
    // Probeer te bepalen op welk inbox/alias-adres de klant gemaild heeft.
    if (!is_array($headers)) {
        return '';
    }

    $kandidaten = ['Delivered-To', 'X-Original-To', 'To', 'Cc', 'Bcc'];
    foreach ($kandidaten as $naam) {
        $v = haalHeaderOp($headers, $naam);
        if (!is_string($v) || trim($v) === '') {
            continue;
        }
        $emails = parseerEmailAdressenUitHeaderTekst($v);
        if (!empty($emails)) {
            return (string) $emails[0];
        }
    }

    return '';
}

function bepaalAfzenderAliasVoorOntvanger($conn, $ontvangerEmail)
{
    // Als het ontvanger-adres een actieve alias is: gebruik die. Anders fallback naar eerste actieve alias.
    $ontvangerEmail = strtolower(trim((string) $ontvangerEmail));
    if ($ontvangerEmail !== '' && filter_var($ontvangerEmail, FILTER_VALIDATE_EMAIL)) {
        try {
            zorgEmailAliassenTabel($conn);
            $stmt = $conn->prepare("
                SELECT send_as_email
                FROM email_aliassen
                WHERE send_as_email = :email AND is_enabled = 1
                LIMIT 1
            ");
            $stmt->execute([':email' => $ontvangerEmail]);
            $row = $stmt->fetch();
            if (is_array($row) && isset($row['send_as_email']) && (string) $row['send_as_email'] !== '') {
                return (string) $row['send_as_email'];
            }
        } catch (Throwable) {
        }
    }

    try {
        $actief = haalActieveEmailAliassen($conn);
        if (is_array($actief) && !empty($actief)) {
            $first = $actief[0];
            return isset($first['send_as_email']) ? (string) $first['send_as_email'] : '';
        }
    } catch (Throwable) {
    }

    return '';
}

function bouwFromHeaderVoorAlias($conn, $aliasEmail)
{
    // Maak de From-regel voor de mail (naam + e-mail als we die naam hebben).
    $aliasEmail = strtolower(trim((string) $aliasEmail));
    if ($aliasEmail === '' || !filter_var($aliasEmail, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    try {
        zorgEmailAliassenTabel($conn);
        $stmt = $conn->prepare("SELECT display_name FROM email_aliassen WHERE send_as_email = :e LIMIT 1");
        $stmt->execute([':e' => $aliasEmail]);
        $row = $stmt->fetch();
        $display = is_array($row) && isset($row['display_name']) ? trim((string) $row['display_name']) : '';
        if ($display !== '') {
            $displaySafe = str_replace(['"', "\r", "\n"], ['', '', ''], $display);
            return $displaySafe . ' <' . $aliasEmail . '>';
        }
    } catch (Throwable) {
    }
    return $aliasEmail;
}

function schrijfEmailWorkerLog($message)
{
    // Schrijf fouten en status van de achtergrond-sync naar een logbestand.
    $logMap = $_SERVER['DOCUMENT_ROOT'] . '/storage/logs';
    $logBestand = $logMap . '/email_worker.log';
    if (!is_dir($logMap)) {
        @mkdir($logMap, 0775, true);
    }
    $regel = '[' . date('Y-m-d H:i:s') . '] ' . (string) $message . PHP_EOL;
    @file_put_contents($logBestand, $regel, FILE_APPEND);
}

function haalEmailWorkerSecretUitRequest()
{
    // Lees de geheime sleutel uit de request (header of POST).
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

function haalOfMaakEmailWorkerSecret($conn)
{
    // Deze geheime sleutel beveiligt de worker. Als hij nog niet bestaat, maken we hem 1 keer aan.
    try {
        zorgDashboardSettingsTabel($conn);
        $stmt = $conn->prepare("SELECT setting_value FROM dashboard_settings WHERE setting_key = 'email_worker_secret' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && isset($row['setting_value']) && trim((string) $row['setting_value']) !== '') {
            return trim((string) $row['setting_value']);
        }

        $nieuw = bin2hex(random_bytes(32));
        $save = $conn->prepare("
            INSERT INTO dashboard_settings (setting_key, setting_value)
            VALUES ('email_worker_secret', :v)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $save->execute([':v' => $nieuw]);
        return $nieuw;
    } catch (Throwable) {
        return '';
    }
}

function openEmailSyncLockHandle()
{
    // Dit is een "slotje" zodat de sync niet twee keer tegelijk kan draaien.
    $logMap = $_SERVER['DOCUMENT_ROOT'] . '/storage/logs';
    if (!is_dir($logMap)) {
        @mkdir($logMap, 0775, true);
    }

    $lockPath = $logMap . '/email_sync.lock';
    $fh = @fopen($lockPath, 'c+');
    if ($fh === false) {
        return null;
    }
    if (!@flock($fh, LOCK_EX | LOCK_NB)) {
        @fclose($fh);
        return null;
    }
    return $fh;
}

function runEmailSyncOnce($conn, $maxResults = 5)
{
    // Doe 1 keer sync: haal ongelezen mails op, maak concepten, markeer mails als gelezen.
    $maxResults = (int) $maxResults;
    if ($maxResults <= 0) {
        $maxResults = 1;
    }
    if ($maxResults > 10) {
        $maxResults = 10;
    }

    $token = haalGmailAccessTokenOp();
    if (empty($token['ok'])) {
        $errTekst = isset($token['error']) ? (string) $token['error'] : 'Gmail token ontbreekt.';
        return ['ok' => false, 'new' => 0, 'error' => $errTekst];
    }

    $accessToken = (string) $token['access_token'];

    $backfillOnderwerpen = function ($limit) use ($conn, $accessToken) {
        try {
            zorgEmailConceptenAliasKolommen($conn);
            if (!tabelHeeftKolom($conn, 'email_concepten', 'onderwerp')) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $limit = (int) $limit;
        if ($limit <= 0) {
            $limit = 1;
        }
        if ($limit > 30) {
            $limit = 30;
        }

        $stmt = $conn->prepare("
            SELECT id, gmail_thread_id
            FROM email_concepten
            WHERE status = 'draft'
              AND (onderwerp IS NULL OR onderwerp = '')
            ORDER BY created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!is_array($rows) || empty($rows)) {
            return;
        }

        foreach ($rows as $r) {
            $id = isset($r['id']) ? (int) $r['id'] : 0;
            $threadId = isset($r['gmail_thread_id']) ? (string) $r['gmail_thread_id'] : '';
            if ($id <= 0 || $threadId === '') {
                continue;
            }

            $t = gmailApiRequest('GET', 'users/me/threads/' . rawurlencode($threadId), $accessToken, null, [
                'format' => 'metadata',
                'metadataHeaders' => 'Subject',
            ]);
            if (empty($t['ok']) || !isset($t['data']['messages']) || !is_array($t['data']['messages'])) {
                continue;
            }

            $messages = $t['data']['messages'];
            $last = end($messages);
            if (!is_array($last) || !isset($last['payload']['headers'])) {
                continue;
            }

            $sub = haalHeaderOp($last['payload']['headers'], 'Subject');
            $sub = is_string($sub) ? trim($sub) : '';
            if ($sub === '') {
                continue;
            }

            $upd = $conn->prepare("UPDATE email_concepten SET onderwerp = :o WHERE id = :id AND (onderwerp IS NULL OR onderwerp = '')");
            $upd->execute([
                ':o' => $sub,
                ':id' => $id,
            ]);
        }
    };
    $aliassen = [];
    try {
        $backfillOnderwerpen(50);
    } catch (Throwable) {
    }

    try {
        $aliassen = haalEmailAliassen($conn);
    } catch (Throwable) {
        $aliassen = [];
        $aliassen = [];
    }
    $aliasEmails = [];
    foreach ($aliassen as $a) {
        if (is_array($a) && isset($a['send_as_email'])) {
            $em = strtolower(trim((string) $a['send_as_email']));
            if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $aliasEmails[$em] = true;
            }
        }
    }

    $actieveRegels = [];
    try {
        $actieveRegels = haalActieveEmailRules($conn);
    } catch (Throwable) {
        $actieveRegels = [];
    }

    $aantalNieuwe = 0;
    $lijst = gmailApiRequest('GET', 'users/me/messages', $accessToken, null, [
        'labelIds' => 'INBOX',
        'q' => 'is:unread',
        'maxResults' => $maxResults,
    ]);

    if (empty($lijst['ok'])) {
        $err = isset($lijst['error']) ? (string) $lijst['error'] : 'Ongelezen mails ophalen is mislukt.';
        return ['ok' => false, 'new' => 0, 'error' => $err];
    }

    $messages = $lijst['data']['messages'] ?? [];
    if (!is_array($messages) || empty($messages)) {
        return ['ok' => true, 'new' => 0, 'error' => ''];
    }

    foreach ($messages as $m) {
        if (!is_array($m) || empty($m['id'])) {
            continue;
        }

        $msgId = (string) $m['id'];
        $detail = gmailApiRequest('GET', 'users/me/messages/' . rawurlencode($msgId), $accessToken, null, [
            'format' => 'full',
        ]);
        if (empty($detail['ok'])) {
            continue;
        }

        $data = $detail['data'] ?? [];
        $threadId = isset($data['threadId']) ? (string) $data['threadId'] : '';
        $payload = $data['payload'] ?? [];
        $headers = is_array($payload) && isset($payload['headers']) ? $payload['headers'] : [];
        $from = haalHeaderOp($headers, 'From') ?? '';
        $subject = haalHeaderOp($headers, 'Subject') ?? '';
        $klantEmail = parseerEmailAdresUitFromHeader($from);
        $ontvangerEmail = haalOntvangerEmailUitMailHeaders($headers);
        $ontvangerEmail = strtolower(trim((string) $ontvangerEmail));
        if ($ontvangerEmail !== '' && !isset($aliasEmails[$ontvangerEmail])) {
            $ontvangerEmail = '';
        }
        $afzenderAlias = bepaalAfzenderAliasVoorOntvanger($conn, $ontvangerEmail);

        if ($threadId === '' || $klantEmail === '') {
            continue;
        }

        $rulesResult = verwerkEmailRulesVoorMail($actieveRegels, $from, $subject);
        if (!empty($rulesResult['ignore'])) {
            gmailApiRequest('POST', 'users/me/messages/' . rawurlencode($msgId) . '/modify', $accessToken, [
                'removeLabelIds' => ['UNREAD'],
            ]);
            continue;
        }

        if (bestaatEmailConceptVoorThread($conn, $threadId)) {
            gmailApiRequest('POST', 'users/me/messages/' . rawurlencode($msgId) . '/modify', $accessToken, [
                'removeLabelIds' => ['UNREAD'],
            ]);
            continue;
        }

        $text = zoekTekstPlainInPayload($payload);
        if (!is_string($text) || $text === '') {
            $text = zoekTekstHtmlInPayload($payload);
        }
        if (!is_string($text) || $text === '') {
            $text = isset($data['snippet']) ? (string) $data['snippet'] : '';
        }
        $text = normaliseerTekst($text);
        if ($text === '') {
            continue;
        }

        $extraInstructies = isset($rulesResult['extra_instructies']) ? (string) $rulesResult['extra_instructies'] : '';
        $ai = roepOpenAiAanVoorEmailConcept($subject, $text, $extraInstructies);
        if (empty($ai['ok'])) {
            $err = isset($ai['error']) ? (string) $ai['error'] : 'OpenAI fout.';
            schrijfEmailWorkerLog('OpenAI fout: ' . $err);
            continue;
        }

        $conceptTekst = (string) $ai['content'];
        if ($conceptTekst === '') {
            continue;
        }

        voegEmailConceptToe($conn, $threadId, $klantEmail, $conceptTekst, $ontvangerEmail, $afzenderAlias, $subject);
        gmailApiRequest('POST', 'users/me/messages/' . rawurlencode($msgId) . '/modify', $accessToken, [
            'removeLabelIds' => ['UNREAD'],
        ]);
        $aantalNieuwe++;
    }

    return ['ok' => true, 'new' => $aantalNieuwe, 'error' => ''];
}

function triggerEmailSyncWorkerInBackground($conn)
{
    // Start de worker zonder te wachten op antwoord (zoals de chat worker).
    $host = $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string) $host);
    if (!is_string($host) || $host === '') {
        return false;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $poort = $isHttps ? 443 : 80;
    $socketHost = ($isHttps ? 'ssl://' : '') . $host;

    $secret = getProjectEnvValue('EMAIL_WORKER_SECRET');
    $secret = is_string($secret) ? trim($secret) : '';
    if ($secret === '') {
        $secret = haalOfMaakEmailWorkerSecret($conn);
    }
    if (!is_string($secret) || $secret === '') {
        return false;
    }

    $body = http_build_query([
        'run' => '1',
        'worker_secret' => $secret,
    ]);

    $socket = @fsockopen($socketHost, $poort, $errorCode, $errorMessage, 1);
    if ($socket === false) {
        return false;
    }

    $request = "POST /EmailDashboard.php?email_worker=1 HTTP/1.1\r\n";
    $request .= "Host: " . $host . "\r\n";
    $request .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $request .= "Content-Length: " . strlen($body) . "\r\n";
    $request .= "X-Worker-Secret: " . $secret . "\r\n";
    $request .= "Connection: Close\r\n\r\n";
    $request .= $body;

    @fwrite($socket, $request);
    @fclose($socket);
    return true;
}

if (isset($_GET['email_worker']) && (string) $_GET['email_worker'] === '1') {
    // Dit is de worker-mode: alleen bedoeld voor interne calls (niet voor normale bezoekers).
    if ((string) ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit('Alleen POST is toegestaan.');
    }

    $requiredSecret = getProjectEnvValue('EMAIL_WORKER_SECRET');
    $requiredSecret = is_string($requiredSecret) ? trim($requiredSecret) : '';
    if ($requiredSecret === '') {
        $requiredSecret = haalOfMaakEmailWorkerSecret($conn);
    }
    if ($requiredSecret === '') {
        http_response_code(500);
        exit('Secret ontbreekt.');
    }

    $given = haalEmailWorkerSecretUitRequest();
    if ($given === '' || !hash_equals($requiredSecret, $given)) {
        http_response_code(403);
        exit('Niet toegestaan.');
    }

    // Als er al een run bezig is, stoppen we meteen.
    $lockHandle = openEmailSyncLockHandle();
    if ($lockHandle === null) {
        http_response_code(200);
        exit('Busy');
    }

    // Cooldown zodat we Gmail/OpenAI niet te vaak achter elkaar aanroepen.
    $cooldownSec = 60;
    $lastRunRaw = '';
    try {
        $lastRunRaw = haalDashboardSetting($conn, 'email_sync_last_run');
    } catch (Throwable) {
        $lastRunRaw = '';
    }
    $lastRun = (int) trim((string) $lastRunRaw);
    if ($lastRun > 0 && (time() - $lastRun) < $cooldownSec) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
        http_response_code(200);
        exit('Cooldown');
    }

    try {
        slaDashboardSettingOp($conn, 'email_sync_last_run', (string) time());
    } catch (Throwable) {
    }

    $result = null;
    try {
        $result = runEmailSyncOnce($conn, 5);
    } catch (Throwable $e) {
        schrijfEmailWorkerLog('Worker fout: ' . $e->getMessage());
        $result = ['ok' => false, 'new' => 0, 'error' => 'Worker fout.'];
    }

    if (is_array($result) && empty($result['ok'])) {
        $err = isset($result['error']) ? (string) $result['error'] : 'Onbekende fout';
        schrijfEmailWorkerLog('Sync fout: ' . $err);
        try {
            slaDashboardSettingOp($conn, 'email_sync_last_error', $err);
        } catch (Throwable) {
        }
    } else {
        try {
            slaDashboardSettingOp($conn, 'email_sync_last_error', '');
        } catch (Throwable) {
        }
    }

    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);

    http_response_code(200);
    $new = is_array($result) && isset($result['new']) ? (int) $result['new'] : 0;
    exit('OK new=' . (string) $new);
}

vereisDashboardLogin();

$melding = null;
$meldingType = 'ok';

if (isset($_SESSION['email_dashboard_flash']) && is_array($_SESSION['email_dashboard_flash'])) {
    // Flash melding na redirect (bijv. na versturen).
    $flash = $_SESSION['email_dashboard_flash'];
    unset($_SESSION['email_dashboard_flash']);
    if (isset($flash['melding'])) {
        $melding = (string) $flash['melding'];
    }
    if (isset($flash['type'])) {
        $meldingType = (string) $flash['type'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Hier verwerken we de knoppen in het dashboard.
    vereisCsrf();

    $actie = isset($_POST['actie']) ? (string) $_POST['actie'] : '';
    if ($actie === 'save_tone') {
        // Dit slaat de tone of voice tekst op in de database.
        $tone = isset($_POST['tone_of_voice']) ? trim((string) $_POST['tone_of_voice']) : '';
        try {
            slaDashboardSettingOp($conn, 'tone_of_voice', $tone);
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'ok',
                'melding' => 'Instellingen zijn opgeslagen.',
            ];
        } catch (Throwable) {
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'error',
                'melding' => 'Opslaan is mislukt.',
            ];
        }
        header('Location: /EmailDashboard.php?settings=1&tab=tone', true, 303);
        exit;
    }
    if ($actie === 'save_rule') {
        // Dit maakt of wijzigt een regel.
        $ruleId = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        $conditionType = isset($_POST['condition_type']) ? (string) $_POST['condition_type'] : '';
        $conditionValue = isset($_POST['condition_value']) ? trim((string) $_POST['condition_value']) : '';
        $actionType = isset($_POST['action_type']) ? (string) $_POST['action_type'] : '';
        $actionValue = isset($_POST['action_value']) ? trim((string) $_POST['action_value']) : '';

        $allowedCondition = ['from_contains', 'subject_contains'];
        $allowedAction = ['ignore', 'add_prompt'];

        if (!in_array($conditionType, $allowedCondition, true) || !in_array($actionType, $allowedAction, true) || $conditionValue === '') {
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'error',
                'melding' => 'Voorwaarde en actie zijn verplicht.',
            ];
            header('Location: /EmailDashboard.php?settings=1&tab=rules', true, 303);
            exit;
        }

        if ($actionType === 'ignore') {
            $actionValue = '';
        }
        if ($actionType === 'add_prompt' && $actionValue === '') {
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'error',
                'melding' => 'Vul een instructie in voor de AI.',
            ];
            header('Location: /EmailDashboard.php?settings=1&tab=rules', true, 303);
            exit;
        }

        try {
            zorgEmailRulesTabel($conn);
            if ($ruleId > 0) {
                $stmt = $conn->prepare("
                    UPDATE email_rules
                    SET is_enabled = :en,
                        condition_type = :ct,
                        condition_value = :cv,
                        action_type = :at,
                        action_value = :av
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':en' => $isEnabled,
                    ':ct' => $conditionType,
                    ':cv' => $conditionValue,
                    ':at' => $actionType,
                    ':av' => ($actionValue === '' ? null : $actionValue),
                    ':id' => $ruleId,
                ]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO email_rules (is_enabled, condition_type, condition_value, action_type, action_value)
                    VALUES (:en, :ct, :cv, :at, :av)
                ");
                $stmt->execute([
                    ':en' => $isEnabled,
                    ':ct' => $conditionType,
                    ':cv' => $conditionValue,
                    ':at' => $actionType,
                    ':av' => ($actionValue === '' ? null : $actionValue),
                ]);
            }
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'ok',
                'melding' => 'Regel is opgeslagen.',
            ];
        } catch (Throwable) {
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'error',
                'melding' => 'Opslaan is mislukt.',
            ];
        }

        header('Location: /EmailDashboard.php?settings=1&tab=rules', true, 303);
        exit;
    }
    if ($actie === 'toggle_rule') {
        // Dit zet een regel aan/uit.
        $ruleId = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        if ($ruleId > 0) {
            try {
                zorgEmailRulesTabel($conn);
                $stmt = $conn->prepare("UPDATE email_rules SET is_enabled = :en WHERE id = :id");
                $stmt->execute([':en' => $isEnabled, ':id' => $ruleId]);
            } catch (Throwable) {
            }
        }
        header('Location: /EmailDashboard.php?settings=1&tab=rules', true, 303);
        exit;
    }
    if ($actie === 'delete_rule') {
        // Dit verwijdert een regel.
        $ruleId = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;
        if ($ruleId > 0) {
            try {
                zorgEmailRulesTabel($conn);
                $stmt = $conn->prepare("DELETE FROM email_rules WHERE id = :id");
                $stmt->execute([':id' => $ruleId]);
                $_SESSION['email_dashboard_flash'] = [
                    'type' => 'ok',
                    'melding' => 'Regel is verwijderd.',
                ];
            } catch (Throwable) {
                $_SESSION['email_dashboard_flash'] = [
                    'type' => 'error',
                    'melding' => 'Verwijderen is mislukt.',
                ];
            }
        }
        header('Location: /EmailDashboard.php?settings=1&tab=rules', true, 303);
        exit;
    }
    if ($actie === 'sync_aliases') {
        $token = haalGmailAccessTokenOp();
        if (empty($token['ok'])) {
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'error',
                'melding' => 'Gmail is niet gekoppeld. Koppel Google opnieuw en probeer het opnieuw.',
            ];
            header('Location: /EmailDashboard.php?settings=1&tab=aliases', true, 303);
            exit;
        }

        $accessToken = (string) $token['access_token'];
        $resp = gmailApiRequest('GET', 'users/me/settings/sendAs', $accessToken);
        if (empty($resp['ok'])) {
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'error',
                'melding' => isset($resp['error']) ? (string) $resp['error'] : 'Aliassen ophalen via Gmail API is mislukt.',
            ];
            header('Location: /EmailDashboard.php?settings=1&tab=aliases', true, 303);
            exit;
        }

        $sendAs = $resp['data']['sendAs'] ?? [];
        try {
            $aantal = upsertEmailAliassenVanGmail($conn, $sendAs);
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'ok',
                'melding' => 'Aliassen gesynchroniseerd (' . (string) $aantal . ').',
            ];
        } catch (Throwable) {
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'error',
                'melding' => 'Aliassen opslaan in de database is mislukt.',
            ];
        }

        header('Location: /EmailDashboard.php?settings=1&tab=aliases', true, 303);
        exit;
    }
    if ($actie === 'save_aliases') {
        $enabled = isset($_POST['alias_enabled']) && is_array($_POST['alias_enabled']) ? $_POST['alias_enabled'] : [];
        $enabledMap = [];
        foreach ($enabled as $k => $v) {
            $email = strtolower(trim((string) $k));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $enabledMap[$email] = true;
            }
        }

        try {
            slaEmailAliassenActiefOp($conn, $enabledMap);
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'ok',
                'melding' => 'Aliassen zijn opgeslagen.',
            ];
        } catch (Throwable) {
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'error',
                'melding' => 'Opslaan is mislukt.',
            ];
        }

        header('Location: /EmailDashboard.php?settings=1&tab=aliases', true, 303);
        exit;
    }
    if ($actie === 'delete') {
        // Verwijderen betekent: uit de draft-lijst halen.
        $conceptId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($conceptId <= 0) {
            $meldingType = 'error';
            $melding = 'id is verplicht.';
        } else {
            $upd = $conn->prepare("UPDATE email_concepten SET status = 'error' WHERE id = :id AND status = 'draft'");
            $upd->execute([':id' => $conceptId]);
            $_SESSION['email_dashboard_flash'] = [
                'type' => 'ok',
                'melding' => 'Concept is verwijderd uit de lijst.',
            ];
            header('Location: /EmailDashboard.php', true, 303);
            exit;
        }
    }
    if ($actie === 'send') {
        // Versturen betekent: mail sturen via Gmail API en daarna status op sent zetten.
        $conceptId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $nieuweTekst = isset($_POST['concept_tekst']) ? trim((string) $_POST['concept_tekst']) : '';

        if ($conceptId <= 0 || $nieuweTekst === '') {
            $meldingType = 'error';
            $melding = 'id en concept_tekst zijn verplicht.';
        } else {
            zorgEmailConceptenAliasKolommen($conn);
            $stmt = $conn->prepare("SELECT id, gmail_thread_id, klant_email, concept_tekst, status, ontvangen_op_email, afzender_alias_email FROM email_concepten WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $conceptId]);
            $concept = $stmt->fetch();

            if (!$concept) {
                $meldingType = 'error';
                $melding = 'Concept bestaat niet.';
            } elseif ((string) $concept['status'] !== 'draft') {
                $meldingType = 'error';
                $melding = 'Concept is niet meer draft.';
            } else {
                $token = haalGmailAccessTokenOp();
                if (empty($token['ok'])) {
                    $meldingType = 'error';
                    $errTekst = isset($token['error']) ? (string) $token['error'] : 'Geen Gmail token.';
                    $authUrl = isset($token['reauth_url']) ? (string) $token['reauth_url'] : '';
                    if ($authUrl !== '') {
                        $melding = [
                            'html' => '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between;"><div>' . e($errTekst) . '</div>' . maakGoogleKoppelKnopHtml($authUrl) . '</div>',
                        ];
                    } else {
                        $melding = $errTekst;
                    }
                } else {
                    $accessToken = (string) $token['access_token'];
                    $threadId = (string) $concept['gmail_thread_id'];
                    $toEmail = (string) $concept['klant_email'];

                    $thread = gmailApiRequest('GET', 'users/me/threads/' . rawurlencode($threadId), $accessToken, null, [
                        'format' => 'full',
                    ]);

                    $subject = 'Re: Klantenservice';
                    $inReplyTo = null;
                    $references = null;

                    if (!empty($thread['ok']) && isset($thread['data']['messages']) && is_array($thread['data']['messages'])) {
                        $messages = $thread['data']['messages'];
                        $last = end($messages);
                        if (is_array($last) && isset($last['payload']['headers'])) {
                            $h = $last['payload']['headers'];
                            $sub = haalHeaderOp($h, 'Subject');
                            if (is_string($sub) && $sub !== '') {
                                $subject = preg_match('/^Re:/i', $sub) ? $sub : ('Re: ' . $sub);
                            }
                            $msgId = haalHeaderOp($h, 'Message-Id');
                            if (is_string($msgId) && $msgId !== '') {
                                $inReplyTo = $msgId;
                                $references = $msgId;
                            }
                            $refs = haalHeaderOp($h, 'References');
                            if (is_string($refs) && $refs !== '') {
                                $references = trim($refs . ' ' . ($msgId ?? ''));
                            }
                        }
                    }

                    $ontvangenOp = isset($concept['ontvangen_op_email']) ? (string) $concept['ontvangen_op_email'] : '';
                    $conceptAlias = isset($concept['afzender_alias_email']) ? (string) $concept['afzender_alias_email'] : '';
                    $gekozenAlias = bepaalAfzenderAliasVoorOntvanger($conn, $conceptAlias !== '' ? $conceptAlias : $ontvangenOp);
                    $fromHeader = bouwFromHeaderVoorAlias($conn, $gekozenAlias);
                    $raw = bouwRfc2822Bericht($toEmail, $subject, $nieuweTekst, $inReplyTo, $references, $fromHeader);
                    $send = gmailApiRequest('POST', 'users/me/messages/send', $accessToken, [
                        'raw' => $raw,
                        'threadId' => $threadId,
                    ]);

                    if (empty($send['ok'])) {
                        $meldingType = 'error';
                        $melding = isset($send['error']) ? (string) $send['error'] : 'Versturen via Gmail API is mislukt.';
                    } else {
                        $upd = $conn->prepare("UPDATE email_concepten SET concept_tekst = :tekst, status = 'sent' WHERE id = :id");
                        $upd->execute([
                            ':tekst' => $nieuweTekst,
                            ':id' => $conceptId,
                        ]);
                        $_SESSION['email_dashboard_flash'] = [
                            'type' => 'ok',
                            'melding' => 'Concept is verstuurd en op sent gezet.',
                        ];
                        header('Location: /EmailDashboard.php', true, 303);
                        exit;
                    }
                }
            }
        }
    }
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$settings = isset($_GET['settings']) && (string) $_GET['settings'] === '1';
$settingsTab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'tone';
$csrf = csrfToken();

function renderLayout($titel, $contentHtml, $melding, $meldingType)
{
    // Centrale layout (bovenbalk + melding + content).
    $msgHtml = '';
    if (is_array($melding) && isset($melding['html']) && is_string($melding['html']) && $melding['html'] !== '') {
        $bg = $meldingType === 'error' ? '#fee2e2' : '#dcfce7';
        $bd = $meldingType === 'error' ? '#ef4444' : '#22c55e';
        $msgHtml = '<div style="background:' . $bg . '; border:1px solid ' . $bd . '; padding:10px 12px; border-radius:10px; margin:12px 0;">' . $melding['html'] . '</div>';
    } elseif (is_string($melding) && $melding !== '') {
        $bg = $meldingType === 'error' ? '#fee2e2' : '#dcfce7';
        $bd = $meldingType === 'error' ? '#ef4444' : '#22c55e';
        $msgHtml = '<div style="background:' . $bg . '; border:1px solid ' . $bd . '; padding:10px 12px; border-radius:10px; margin:12px 0;">' . e($melding) . '</div>';
    }

    $html = '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . e($titel) . '</title><style>:root{--grid-main-cols:360px 1fr;--grid-settings-cols:260px 1fr;--list-max-h:calc(100vh - 220px);}@media (max-width: 900px){:root{--grid-main-cols:1fr;--grid-settings-cols:1fr;--list-max-h:260px;}body{padding:14px!important;}}</style></head><body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#e5e7eb; color:#111827; margin:0; padding:22px;">';
    $html .= '<div style="max-width: 1200px; margin:0 auto;">';
    $html .= '<div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; padding:10px 12px; background:#f3f4f6; border:1px solid #9ca3af; border-radius:12px;">';
    $html .= '<div style="font-weight:800; font-size:18px;">Mario Team - AI E-mail Concepten Module</div>';
    $html .= '<div style="display:flex; gap:14px; align-items:center;">';
    $html .= '<a href="/EmailDashboard.php" style="color:#111827; text-decoration:none;">Overzicht</a>';
    $html .= '<a href="/EmailDashboard.php?settings=1" style="color:#111827; text-decoration:none;">Instellingen</a>';
    $html .= '<a href="/EmailDashboard.php?logout=1" style="color:#111827; text-decoration:none;">Uitloggen</a>';
    $html .= '</div></div>';
    $html .= $msgHtml;
    $html .= $contentHtml;
    $html .= '</div></body></html>';
    return $html;
}

$instellingenHtml = '';
if ($settings) {
    // Instellingenpagina met een zijmenu (hier komen later meerdere items).
    $activeTone = ($settingsTab === 'tone');
    $activeRules = ($settingsTab === 'rules');
    $activeAliases = ($settingsTab === 'aliases');
    $toneValue = '';
    try {
        $toneValue = haalDashboardSetting($conn, 'tone_of_voice');
    } catch (Throwable) {
        $toneValue = '';
    }

    $menu = '<div style="background:#f3f4f6; border:1px solid #9ca3af; border-radius:14px; overflow:hidden;">';
    $menu .= '<div style="padding:12px 14px; border-bottom:1px solid #9ca3af; font-weight:800;">Instellingen</div>';
    $menu .= '<div style="padding:10px;">';
    $menu .= '<a href="/EmailDashboard.php?settings=1&amp;tab=tone" style="display:block; padding:10px 12px; border-radius:10px; text-decoration:none; border:1px solid ' . ($activeTone ? '#60a5fa' : '#9ca3af') . '; background:' . ($activeTone ? '#bfdbfe' : '#e5e7eb') . '; color:#111827; font-weight:800;">Tone of voice</a>';
    $menu .= '<div style="height:10px;"></div>';
    $menu .= '<a href="/EmailDashboard.php?settings=1&amp;tab=rules" style="display:block; padding:10px 12px; border-radius:10px; text-decoration:none; border:1px solid ' . ($activeRules ? '#60a5fa' : '#9ca3af') . '; background:' . ($activeRules ? '#bfdbfe' : '#e5e7eb') . '; color:#111827; font-weight:800;">Regels &amp; filters</a>';
    $menu .= '<div style="height:10px;"></div>';
    $menu .= '<a href="/EmailDashboard.php?settings=1&amp;tab=aliases" style="display:block; padding:10px 12px; border-radius:10px; text-decoration:none; border:1px solid ' . ($activeAliases ? '#60a5fa' : '#9ca3af') . '; background:' . ($activeAliases ? '#bfdbfe' : '#e5e7eb') . '; color:#111827; font-weight:800;">E-mail aliassen</a>';
    $menu .= '</div></div>';

    $content = '<div style="background:#f3f4f6; border:1px solid #9ca3af; border-radius:14px; padding:14px 16px;">';
    if ($activeTone) {
        $content .= '<div style="font-weight:800; margin-bottom:8px;">Tone of voice</div>';
        $content .= '<div style="color:#6b7280; margin-bottom:10px;">Deze tekst wordt toegevoegd aan de systeem-instructies van de AI.</div>';
        $content .= '<form method="post" action="/EmailDashboard.php?settings=1&amp;tab=tone">';
        $content .= '<input type="hidden" name="csrf" value="' . e($csrf) . '">';
        $content .= '<input type="hidden" name="actie" value="save_tone">';
        $content .= '<textarea name="tone_of_voice" rows="10" style="width:100%; box-sizing:border-box; border-radius:10px; border:1px solid #9ca3af; background:#ffffff; color:#111827; padding:10px 12px; resize:vertical;">' . e($toneValue) . '</textarea>';
        $content .= '<div style="display:flex; justify-content:flex-end; margin-top:10px;">';
        $content .= '<button type="submit" style="background:#60a5fa; border:1px solid #3b82f6; color:#111827; font-weight:800; padding:10px 14px; border-radius:10px; cursor:pointer;">Opslaan</button>';
        $content .= '</div></form>';
    } elseif ($activeRules) {
        $editId = isset($_GET['edit_rule']) ? (int) $_GET['edit_rule'] : 0;
        $edit = null;
        $regels = [];
        try {
            $regels = haalEmailRules($conn);
            if ($editId > 0) {
                foreach ($regels as $r) {
                    if (isset($r['id']) && (int) $r['id'] === $editId) {
                        $edit = $r;
                        break;
                    }
                }
            }
        } catch (Throwable) {
            $regels = [];
            $edit = null;
        }

        $content .= '<div style="font-weight:800; margin-bottom:8px;">Regels &amp; filters</div>';
        $content .= '<div style="color:#6b7280; margin-bottom:12px;">Regels worden toegepast voordat er een AI-concept gemaakt wordt.</div>';

        $ruleIdValue = $edit ? (int) $edit['id'] : 0;
        $isEnabledValue = $edit ? ((int) $edit['is_enabled'] === 1) : true;
        $condTypeValue = $edit ? (string) $edit['condition_type'] : 'from_contains';
        $condValueValue = $edit ? (string) $edit['condition_value'] : '';
        $actionTypeValue = $edit ? (string) $edit['action_type'] : 'ignore';
        $actionValueValue = $edit ? (string) ($edit['action_value'] ?? '') : '';

        $content .= '<div style="background:#ffffff; border:1px solid #9ca3af; border-radius:12px; padding:12px 12px; margin-bottom:14px;">';
        $content .= '<div style="font-weight:800; margin-bottom:10px;">' . ($edit ? 'Regel aanpassen' : 'Nieuwe regel') . '</div>';
        $content .= '<form method="post" action="/EmailDashboard.php?settings=1&amp;tab=rules">';
        $content .= '<input type="hidden" name="csrf" value="' . e($csrf) . '">';
        $content .= '<input type="hidden" name="actie" value="save_rule">';
        $content .= '<input type="hidden" name="rule_id" value="' . e((string) $ruleIdValue) . '">';

        $content .= '<label style="display:flex; gap:10px; align-items:center; margin-bottom:10px;">';
        $content .= '<input type="checkbox" name="is_enabled" value="1" ' . ($isEnabledValue ? 'checked' : '') . '>';
        $content .= '<span>Regel is actief</span>';
        $content .= '</label>';

        $content .= '<div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px;">';
        $content .= '<div>';
        $content .= '<div style="font-weight:700; margin-bottom:6px;">Voorwaarde</div>';
        $content .= '<select name="condition_type" style="width:100%; border-radius:10px; border:1px solid #9ca3af; background:#ffffff; color:#111827; padding:10px 12px;">';
        $content .= '<option value="from_contains" ' . ($condTypeValue === 'from_contains' ? 'selected' : '') . '>Als afzender bevat...</option>';
        $content .= '<option value="subject_contains" ' . ($condTypeValue === 'subject_contains' ? 'selected' : '') . '>Als onderwerp bevat...</option>';
        $content .= '</select>';
        $content .= '</div>';
        $content .= '<div>';
        $content .= '<div style="font-weight:700; margin-bottom:6px;">Tekst</div>';
        $content .= '<input type="text" name="condition_value" value="' . e($condValueValue) . '" style="width:100%; box-sizing:border-box; border-radius:10px; border:1px solid #9ca3af; background:#ffffff; color:#111827; padding:10px 12px;">';
        $content .= '</div>';
        $content .= '</div>';

        $content .= '<div style="display:grid; grid-template-columns: 1fr; gap:10px; margin-bottom:10px;">';
        $content .= '<div>';
        $content .= '<div style="font-weight:700; margin-bottom:6px;">Actie</div>';
        $content .= '<select name="action_type" style="width:100%; border-radius:10px; border:1px solid #9ca3af; background:#ffffff; color:#111827; padding:10px 12px;">';
        $content .= '<option value="ignore" ' . ($actionTypeValue === 'ignore' ? 'selected' : '') . '>Negeer deze e-mail</option>';
        $content .= '<option value="add_prompt" ' . ($actionTypeValue === 'add_prompt' ? 'selected' : '') . '>Voeg instructie toe aan AI</option>';
        $content .= '</select>';
        $content .= '</div>';
        $content .= '<div>';
        $content .= '<div style="font-weight:700; margin-bottom:6px;">AI instructie (alleen bij “Voeg instructie toe”)</div>';
        $content .= '<textarea name="action_value" rows="4" style="width:100%; box-sizing:border-box; border-radius:10px; border:1px solid #9ca3af; background:#ffffff; color:#111827; padding:10px 12px; resize:vertical;">' . e($actionValueValue) . '</textarea>';
        $content .= '</div>';
        $content .= '</div>';

        $content .= '<div style="display:flex; gap:10px; justify-content:flex-end; margin-top:10px;">';
        if ($edit) {
            $content .= '<a href="/EmailDashboard.php?settings=1&amp;tab=rules" style="display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid #9ca3af; background:#e5e7eb; color:#111827; text-decoration:none; font-weight:800;">Annuleren</a>';
        }
        $content .= '<button type="submit" style="background:#60a5fa; border:1px solid #3b82f6; color:#111827; font-weight:800; padding:10px 14px; border-radius:10px; cursor:pointer;">Opslaan</button>';
        $content .= '</div></form>';
        $content .= '</div>';

        $content .= '<div style="font-weight:800; margin-bottom:10px;">Bestaande regels</div>';
        if (!is_array($regels) || count($regels) === 0) {
            $content .= '<div style="color:#6b7280;">Nog geen regels.</div>';
        } else {
            $content .= '<div style="display:flex; flex-direction:column; gap:10px;">';
            foreach ($regels as $r) {
                $rid = isset($r['id']) ? (int) $r['id'] : 0;
                $en = isset($r['is_enabled']) && (int) $r['is_enabled'] === 1;
                $ct = isset($r['condition_type']) ? (string) $r['condition_type'] : '';
                $cv = isset($r['condition_value']) ? (string) $r['condition_value'] : '';
                $at = isset($r['action_type']) ? (string) $r['action_type'] : '';
                $av = isset($r['action_value']) ? (string) $r['action_value'] : '';

                $condLabel = $ct === 'subject_contains' ? 'Als onderwerp bevat' : 'Als afzender bevat';
                $actionLabel = $at === 'add_prompt' ? 'Voeg AI instructie toe' : 'Negeer';

                $content .= '<div style="background:#ffffff; border:1px solid #9ca3af; border-radius:12px; padding:12px 12px;">';
                $content .= '<div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">';
                $content .= '<div style="font-weight:800;">Regel #' . e((string) $rid) . '</div>';
                $content .= '<div style="color:#6b7280;">' . ($en ? 'Actief' : 'Uit') . '</div>';
                $content .= '</div>';
                $content .= '<div style="margin-top:8px;"><span style="font-weight:700;">Voorwaarde:</span> ' . e($condLabel) . ' <span style="font-weight:800;">' . e($cv) . '</span></div>';
                $content .= '<div style="margin-top:4px;"><span style="font-weight:700;">Actie:</span> ' . e($actionLabel) . '</div>';
                if ($at === 'add_prompt' && trim($av) !== '') {
                    $content .= '<div style="margin-top:8px; background:#f3f4f6; border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; white-space:pre-wrap;">' . e($av) . '</div>';
                }
                $content .= '<div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap; margin-top:10px;">';

                $content .= '<form method="post" action="/EmailDashboard.php?settings=1&amp;tab=rules" style="margin:0;">';
                $content .= '<input type="hidden" name="csrf" value="' . e($csrf) . '">';
                $content .= '<input type="hidden" name="actie" value="toggle_rule">';
                $content .= '<input type="hidden" name="rule_id" value="' . e((string) $rid) . '">';
                $content .= '<label style="display:flex; gap:8px; align-items:center; padding:8px 10px; border-radius:10px; border:1px solid #9ca3af; background:#e5e7eb; cursor:pointer;">';
                $content .= '<input type="checkbox" name="is_enabled" value="1" ' . ($en ? 'checked' : '') . ' onchange="this.form.submit()">';
                $content .= '<span>Actief</span>';
                $content .= '</label>';
                $content .= '</form>';

                $content .= '<a href="/EmailDashboard.php?settings=1&amp;tab=rules&amp;edit_rule=' . e((string) $rid) . '" style="display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid #9ca3af; background:#e5e7eb; color:#111827; text-decoration:none; font-weight:800;">Bewerken</a>';

                $content .= '<form method="post" action="/EmailDashboard.php?settings=1&amp;tab=rules" style="margin:0;" onsubmit="return confirm(\'Regel verwijderen?\')">';
                $content .= '<input type="hidden" name="csrf" value="' . e($csrf) . '">';
                $content .= '<input type="hidden" name="actie" value="delete_rule">';
                $content .= '<input type="hidden" name="rule_id" value="' . e((string) $rid) . '">';
                $content .= '<button type="submit" style="background:#fee2e2; border:1px solid #ef4444; color:#111827; font-weight:800; padding:10px 14px; border-radius:10px; cursor:pointer;">Verwijderen</button>';
                $content .= '</form>';

                $content .= '</div></div>';
            }
            $content .= '</div>';
        }
    } elseif ($activeAliases) {
        $aliassen = [];
        try {
            $aliassen = haalEmailAliassen($conn);
        } catch (Throwable) {
            $aliassen = [];
        }

        $content .= '<div style="font-weight:800; margin-bottom:8px;">E-mail aliassen</div>';
        $content .= '<div style="color:#6b7280; margin-bottom:12px;">Deze aliassen komen uit Gmail (Send mail as). Zet aan welke adressen de AI mag gebruiken.</div>';

        $content .= '<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">';
        $content .= '<form method="post" action="/EmailDashboard.php?settings=1&amp;tab=aliases" style="margin:0;">';
        $content .= '<input type="hidden" name="csrf" value="' . e($csrf) . '">';
        $content .= '<input type="hidden" name="actie" value="sync_aliases">';
        $content .= '<button type="submit" style="background:#e5e7eb; border:1px solid #9ca3af; color:#111827; font-weight:800; padding:10px 14px; border-radius:10px; cursor:pointer;">Synchroniseer aliassen</button>';
        $content .= '</form>';
        $content .= '</div>';

        if (!is_array($aliassen) || empty($aliassen)) {
            $content .= '<div style="color:#6b7280;">Nog geen aliassen gevonden. Klik op “Synchroniseer aliassen”.</div>';
        } else {
            $content .= '<form method="post" action="/EmailDashboard.php?settings=1&amp;tab=aliases" style="margin:0;">';
            $content .= '<input type="hidden" name="csrf" value="' . e($csrf) . '">';
            $content .= '<input type="hidden" name="actie" value="save_aliases">';
            $content .= '<div style="display:flex; flex-direction:column; gap:10px;">';
            foreach ($aliassen as $a) {
                $email = isset($a['send_as_email']) ? (string) $a['send_as_email'] : '';
                $display = isset($a['display_name']) ? (string) $a['display_name'] : '';
                $isEnabled = isset($a['is_enabled']) && (int) $a['is_enabled'] === 1;
                $isPrimary = isset($a['is_primary']) && (int) $a['is_primary'] === 1;
                $isDefault = isset($a['is_default']) && (int) $a['is_default'] === 1;

                $labels = [];
                if ($isDefault) {
                    $labels[] = 'Default';
                }
                if ($isPrimary) {
                    $labels[] = 'Primary';
                }
                $labelText = !empty($labels) ? (' • ' . implode(' • ', $labels)) : '';

                $content .= '<label style="display:flex; gap:10px; align-items:center; background:#ffffff; border:1px solid #9ca3af; border-radius:12px; padding:12px 12px;">';
                $content .= '<input type="checkbox" name="alias_enabled[' . e(strtolower($email)) . ']" value="1" ' . ($isEnabled ? 'checked' : '') . '>';
                $content .= '<div style="flex:1 1 auto;">';
                $content .= '<div style="font-weight:800;">' . e($email) . '<span style="color:#6b7280; font-weight:700;">' . e($labelText) . '</span></div>';
                if (trim($display) !== '') {
                    $content .= '<div style="color:#6b7280;">' . e($display) . '</div>';
                }
                $content .= '</div>';
                $content .= '<div style="color:#6b7280;">' . ($isEnabled ? 'Actief' : 'Uit') . '</div>';
                $content .= '</label>';
            }
            $content .= '</div>';
            $content .= '<div style="display:flex; justify-content:flex-end; margin-top:12px;">';
            $content .= '<button type="submit" style="background:#60a5fa; border:1px solid #3b82f6; color:#111827; font-weight:800; padding:10px 14px; border-radius:10px; cursor:pointer;">Opslaan</button>';
            $content .= '</div></form>';
        }
    } else {
        $content .= '<div style="font-weight:800; margin-bottom:8px;">Instellingen</div>';
        $content .= '<div style="color:#6b7280;">Kies links een onderdeel.</div>';
    }
    $content .= '</div>';

    $layout = '<div style="display:grid; grid-template-columns: var(--grid-settings-cols); gap:16px; align-items:start;">' . $menu . $content . '</div>';
    stuurHtml(200, renderLayout('Email dashboard', $layout, $melding, $meldingType));
}

if (empty($_GET['email_worker'])) {
    // Bij openen van het overzicht starten we de worker op de achtergrond (niet wachten).
    $cooldownSec = 15;
    $vorigeTrigger = isset($_SESSION['email_dashboard_worker_trigger_at']) ? (int) $_SESSION['email_dashboard_worker_trigger_at'] : 0;
    if ((time() - $vorigeTrigger) >= $cooldownSec) {
        $_SESSION['email_dashboard_worker_trigger_at'] = time();
        triggerEmailSyncWorkerInBackground($conn);
    }
}

$stmt = $conn->prepare("SELECT id, gmail_thread_id, klant_email, onderwerp, created_at FROM email_concepten WHERE status = 'draft' ORDER BY created_at DESC");
$stmt->execute();
$rows = $stmt->fetchAll();

$concept = null;
if ($id > 0) {
    // We openen 1 concept uit de lijst (rechts in beeld).
    zorgEmailConceptenAliasKolommen($conn);
    $sel = $conn->prepare("SELECT id, gmail_thread_id, klant_email, onderwerp, concept_tekst, status, created_at FROM email_concepten WHERE id = :id LIMIT 1");
    $sel->execute([':id' => $id]);
    $concept = $sel->fetch();
    if (!$concept) {
        $meldingType = 'error';
        $melding = 'Concept niet gevonden.';
        $id = 0;
    }
}

$lijstHtml = '<div style="background:#f3f4f6; border:1px solid #9ca3af; border-radius:14px; overflow:hidden;">';
$lijstHtml .= '<div style="padding:12px 14px; border-bottom:1px solid #9ca3af; font-weight:800;">Openstaande Concepten (Lijst)</div>';
if (empty($rows)) {
    $lijstHtml .= '<div style="padding:14px; color:#6b7280;">Geen draft concepten gevonden.</div>';
} else {
    // Als er nog concepten zonder onderwerp zijn, halen we een paar onderwerpen op uit Gmail.
    // Dit is eenmalig: zodra ze opgeslagen zijn, komt de lijst weer volledig uit de database.
    $onderwerpCache = [];
    $missendeThreads = [];
    foreach ($rows as $r) {
        $onderwerpDb = isset($r['onderwerp']) ? trim((string) $r['onderwerp']) : '';
        $threadIdDb = isset($r['gmail_thread_id']) ? (string) $r['gmail_thread_id'] : '';
        if ($onderwerpDb === '' && $threadIdDb !== '' && !isset($missendeThreads[$threadIdDb])) {
            $missendeThreads[$threadIdDb] = true;
        }
        if (count($missendeThreads) >= 8) {
            break;
        }
    }
    if (!empty($missendeThreads)) {
        $tokenVoorOnderwerp = haalGmailAccessTokenOp();
        $accessTokenVoorOnderwerp = !empty($tokenVoorOnderwerp['ok']) ? (string) $tokenVoorOnderwerp['access_token'] : '';
        if ($accessTokenVoorOnderwerp !== '') {
            foreach (array_keys($missendeThreads) as $threadIdDb) {
                $t = gmailApiRequest('GET', 'users/me/threads/' . rawurlencode($threadIdDb), $accessTokenVoorOnderwerp, null, [
                    'format' => 'metadata',
                    'metadataHeaders' => 'Subject',
                ]);
                if (empty($t['ok']) || !isset($t['data']['messages']) || !is_array($t['data']['messages'])) {
                    continue;
                }
                $messages = $t['data']['messages'];
                $last = end($messages);
                if (!is_array($last) || !isset($last['payload']['headers'])) {
                    continue;
                }
                $sub = haalHeaderOp($last['payload']['headers'], 'Subject');
                $sub = is_string($sub) ? trim($sub) : '';
                if ($sub === '') {
                    continue;
                }
                $onderwerpCache[$threadIdDb] = $sub;
                try {
                    zorgEmailConceptenAliasKolommen($conn);
                    $upd = $conn->prepare("UPDATE email_concepten SET onderwerp = :o WHERE gmail_thread_id = :t AND (onderwerp IS NULL OR onderwerp = '')");
                    $upd->execute([
                        ':o' => $sub,
                        ':t' => $threadIdDb,
                    ]);
                } catch (Throwable) {
                }
            }
        }
    }

    $lijstHtml .= '<div style="padding:10px; max-height: var(--list-max-h); overflow:auto;">';
    foreach ($rows as $r) {
        $isActief = ($id > 0 && (int) $r['id'] === (int) $id);
        $bg = $isActief ? '#bfdbfe' : '#e5e7eb';
        $border = $isActief ? '#60a5fa' : '#9ca3af';
        $onderwerp = isset($r['onderwerp']) ? trim((string) $r['onderwerp']) : '';
        if ($onderwerp === '') {
            $threadId = isset($r['gmail_thread_id']) ? (string) $r['gmail_thread_id'] : '';
            if ($threadId !== '' && isset($onderwerpCache[$threadId])) {
                $onderwerp = (string) $onderwerpCache[$threadId];
            }
        }
        $titelLinks = $onderwerp !== '' ? $onderwerp : ('Concept #' . (string) $r['id']);
        if (strlen($titelLinks) > 90) {
            $titelLinks = substr($titelLinks, 0, 90) . '...';
        }
        $lijstHtml .= '<a href="/EmailDashboard.php?id=' . urlencode((string) $r['id']) . '" style="display:block; text-decoration:none; border:1px solid ' . $border . '; background:' . $bg . '; border-radius:12px; padding:10px 12px; margin-bottom:10px;">';
        $lijstHtml .= '<div style="font-weight:800; color:#111827;">' . e($titelLinks) . '</div>';
        $lijstHtml .= '<div style="margin-top:4px; color:#111827; font-size:13px;">Datum: ' . e($r['created_at']) . '</div>';
        $lijstHtml .= '<div style="margin-top:2px; color:#111827; font-size:13px;">Status: draft</div>';
        $lijstHtml .= '<div style="margin-top:2px; color:#111827; font-size:13px;">Klant: ' . e($r['klant_email']) . '</div>';
        $lijstHtml .= '</a>';
    }
    $lijstHtml .= '</div>';
}
$lijstHtml .= '</div>';

$detailHtml = '<div style="background:#f3f4f6; border:1px solid #9ca3af; border-radius:14px; padding:12px 14px; min-height:420px;">';
if (!$concept) {
    $detailHtml .= '<div style="font-weight:800; margin-bottom:10px;">Geselecteerd Concept</div>';
    $detailHtml .= '<div style="color:#6b7280;">Klik links een concept aan om de originele klantmail en het AI-concept te bekijken.</div>';
    $detailHtml .= '</div>';
} else {
    // Als je een concept opent, laden we de hele conversatie om de originele mail te tonen.
    $origineelTekst = null;
    $origineelHtml = '';
    $origineelOnderwerp = isset($concept['onderwerp']) ? trim((string) $concept['onderwerp']) : '';
    $token = haalGmailAccessTokenOp();
    if (!empty($token['ok'])) {
        $accessToken = (string) $token['access_token'];
        $threadId = (string) $concept['gmail_thread_id'];
        $thread = gmailApiRequest('GET', 'users/me/threads/' . rawurlencode($threadId), $accessToken, null, ['format' => 'full']);
        if (!empty($thread['ok']) && isset($thread['data']['messages']) && is_array($thread['data']['messages'])) {
            $messages = $thread['data']['messages'];
            $klant = (string) $concept['klant_email'];
            $gevonden = null;
            foreach (array_reverse($messages) as $m) {
                if (!is_array($m) || !isset($m['payload']['headers'])) {
                    continue;
                }
                $from = haalHeaderOp($m['payload']['headers'], 'From');
                if (is_string($from) && $from !== '' && stripos($from, $klant) !== false) {
                    $gevonden = $m;
                    break;
                }
            }
            if ($gevonden === null) {
                $last = end($messages);
                if (is_array($last)) {
                    $gevonden = $last;
                }
            }
            if (is_array($gevonden)) {
                $h = $gevonden['payload']['headers'] ?? [];
                $sub = haalHeaderOp($h, 'Subject');
                $from = haalHeaderOp($h, 'From');
                $date = haalHeaderOp($h, 'Date');
                if (is_string($sub) && $sub !== '') {
                    $origineelOnderwerp = $sub;
                    $onderwerpDb = isset($concept['onderwerp']) ? trim((string) $concept['onderwerp']) : '';
                    if ($onderwerpDb === '') {
                        try {
                            $upd = $conn->prepare("UPDATE email_concepten SET onderwerp = :o WHERE id = :id AND (onderwerp IS NULL OR onderwerp = '')");
                            $upd->execute([
                                ':o' => $origineelOnderwerp,
                                ':id' => (int) $concept['id'],
                            ]);
                        } catch (Throwable) {
                        }
                    }
                }

                $rawHtml = haalHtmlUitPayload($gevonden['payload'] ?? []);
                if (is_string($rawHtml) && trim($rawHtml) !== '') {
                    $origineelHtml = sanitizeEmailHtmlVoorDashboard($rawHtml);
                }

                $text = zoekTekstPlainInPayload($gevonden['payload'] ?? []);
                if (!is_string($text) || $text === '') {
                    $text = zoekTekstHtmlInPayload($gevonden['payload'] ?? []);
                }
                if (!is_string($text) || $text === '') {
                    $text = isset($gevonden['snippet']) ? (string) $gevonden['snippet'] : '';
                }
                $origineelTekst = normaliseerTekst($text);
            }
        }
    }

    $kop = $origineelOnderwerp !== '' ? ('Geselecteerd Concept: ' . $origineelOnderwerp) : ('Geselecteerd Concept #' . (string) $concept['id']);
    $detailHtml .= '<div style="font-weight:800; margin-bottom:10px;">' . e($kop) . '</div>';

    $detailHtml .= '<div style="border:1px solid #9ca3af; background:#ffffff; border-radius:12px; padding:10px 12px; margin-bottom:12px;">';
    $detailHtml .= '<div style="font-weight:800; margin-bottom:6px;">Originele Klantvraag:</div>';
    if (is_string($origineelHtml) && $origineelHtml !== '') {
        $detailHtml .= '<div style="background:#ffffff; color:#111827; font-size:14px; line-height:1.45;">' . $origineelHtml . '</div>';
    } elseif (is_string($origineelTekst) && $origineelTekst !== '') {
        $detailHtml .= '<div style="white-space:pre-wrap;">' . e($origineelTekst) . '</div>';
    } else {
        $detailHtml .= '<div style="color:#6b7280;">Niet beschikbaar. OAuth/token of thread ophalen is nog niet gelukt.</div>';
    }
    $detailHtml .= '</div>';

    $detailHtml .= '<div style="border:1px solid #9ca3af; background:#ffffff; border-radius:12px; padding:10px 12px;">';
    $detailHtml .= '<div style="font-weight:800; margin-bottom:6px;">AI Gegenereerd Draft (Bewerkbaar):</div>';
    $detailHtml .= '<form method="post" action="/EmailDashboard.php?id=' . urlencode((string) $concept['id']) . '">';
    $detailHtml .= '<input type="hidden" name="csrf" value="' . e($csrf) . '">';
    $detailHtml .= '<input type="hidden" name="id" value="' . e($concept['id']) . '">';
    $detailHtml .= '<textarea name="concept_tekst" rows="14" style="width:100%; box-sizing:border-box; border-radius:10px; border:1px solid #9ca3af; background:#ffffff; color:#111827; padding:10px 12px; resize:vertical;">' . e((string) $concept['concept_tekst']) . '</textarea>';
    $detailHtml .= '<div style="display:flex; justify-content:space-between; gap:12px; margin-top:10px;">';
    $detailHtml .= '<button type="submit" name="actie" value="delete" style="background:#e5e7eb; border:1px solid #9ca3af; color:#111827; font-weight:800; padding:10px 14px; border-radius:10px; cursor:pointer;">Verwijder Concept</button>';
    $disabled = ((string) $concept['status'] !== 'draft') ? 'disabled' : '';
    $btnStyle = 'background:#60a5fa; border:1px solid #3b82f6; color:#111827; font-weight:800; padding:10px 14px; border-radius:10px; cursor:pointer;';
    $btnStyleDisabled = 'background:#e5e7eb; border:1px solid #9ca3af; color:#6b7280; cursor:not-allowed; font-weight:800; padding:10px 14px; border-radius:10px;';
    $detailHtml .= '<button type="submit" name="actie" value="send" ' . $disabled . ' style="' . ($disabled ? $btnStyleDisabled : $btnStyle) . '">Verstuur mail via Gmail API</button>';
    $detailHtml .= '</div>';
    $detailHtml .= '</form>';
    $detailHtml .= '</div>';
    $detailHtml .= '</div>';
}

$grid = '<div style="display:grid; grid-template-columns: var(--grid-main-cols); gap:16px; align-items:start;">' . $lijstHtml . $detailHtml . '</div>';
stuurHtml(200, renderLayout('Email dashboard', $grid, $melding, $meldingType));
