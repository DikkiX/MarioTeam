<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/env.php';

session_start();

// Dit is een testpagina voor US11.
// Doel: laten zien dat we met de officiële Gmail API kunnen inloggen en mails kunnen lezen.
// Wat je op het scherm ziet:
// - From = wie het heeft gestuurd
// - Subject = onderwerp
// - Thread ID = gesprek-id (handig voor later om te antwoorden in dezelfde thread)
// - Tekst = de inhoud van de mail (zo goed mogelijk als plain text)

function stuurHtml($httpStatus, $titel, $bodyHtml)
{
    // Dit endpoint wordt in de browser geopend, daarom geven we HTML terug.
    http_response_code($httpStatus);
    header('Content-Type: text/html; charset=utf-8');
    $titelEsc = htmlspecialchars((string) $titel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $titelEsc . '</title></head><body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding: 20px;">';
    echo '<h1 style="margin:0 0 12px;">' . $titelEsc . '</h1>';
    echo $bodyHtml;
    echo '</body></html>';
    exit;
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken()
{
    // Dit token zorgt dat alleen onze eigen login-form deze actie mag uitvoeren.
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function vereisCsrf()
{
    // Als dit niet klopt, is het geen echte login-post van onze pagina.
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    if (!isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $token)) {
        stuurHtml(400, 'Ongeldige aanvraag', '<p>CSRF token klopt niet.</p>');
    }
}

function renderLoginPagina($melding = '')
{
    // Deze pagina is beveiligd met een simpele login uit .env.
    $csrf = csrfToken();
    $msgHtml = '';
    if (is_string($melding) && $melding !== '') {
        $msgHtml = '<div style="background:#fee2e2; border:1px solid #ef4444; padding:10px 12px; border-radius:10px; margin-bottom:12px;">' . e($melding) . '</div>';
    }

    $html = '<div style="max-width: 520px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:14px; padding:16px;">';
    $html .= '<h2 style="margin:0 0 12px;">Inloggen</h2>';
    $html .= $msgHtml;
    $html .= '<form method="post" action="">';
    $html .= '<input type="hidden" name="csrf" value="' . e($csrf) . '">';
    $html .= '<input type="hidden" name="actie" value="login">';
    $html .= '<label style="display:block; margin-bottom:6px;">Gebruikersnaam</label>';
    $html .= '<input name="user" autocomplete="username" style="width:100%; box-sizing:border-box; border-radius:10px; border:1px solid #cbd5e1; padding:10px 12px; margin-bottom:10px;">';
    $html .= '<label style="display:block; margin-bottom:6px;">Wachtwoord</label>';
    $html .= '<input type="password" name="pass" autocomplete="current-password" style="width:100%; box-sizing:border-box; border-radius:10px; border:1px solid #cbd5e1; padding:10px 12px; margin-bottom:12px;">';
    $html .= '<button type="submit" style="background:#111827; border:none; color:#fff; font-weight:700; padding:10px 14px; border-radius:10px; cursor:pointer; width:100%;">Inloggen</button>';
    $html .= '</form>';
    $html .= '</div>';
    stuurHtml(200, 'Gmail API test', $html);
}

function vereisLogin()
{
    // Hier checken we of je bent ingelogd.
    // De gebruikersnaam en wachtwoord komen uit de server .env.
    $user = getProjectEnvValue('EMAIL_DASHBOARD_USER');
    $pass = getProjectEnvValue('EMAIL_DASHBOARD_PASS');

    if ($user === null || $pass === null) {
        stuurHtml(500, 'Configuratie ontbreekt', '<p>EMAIL_DASHBOARD_USER en EMAIL_DASHBOARD_PASS ontbreken in .env.</p>');
    }

    if (isset($_POST['actie']) && (string) $_POST['actie'] === 'login') {
        // Dit is de login actie die door het form wordt verstuurd.
        vereisCsrf();
        $gegevenUser = isset($_POST['user']) ? (string) $_POST['user'] : '';
        $gegevenPass = isset($_POST['pass']) ? (string) $_POST['pass'] : '';

        $isOk = hash_equals((string) $user, $gegevenUser) && hash_equals((string) $pass, $gegevenPass);
        if (!$isOk) {
            renderLoginPagina('Gebruikersnaam of wachtwoord is verkeerd.');
        }

        $_SESSION['email_dashboard_authed'] = true;
        $locatie = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/api/google/gmail/unread.php';
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

function base64UrlDecode($data)
{
    // Gmail API geeft mail-inhoud vaak terug als base64url.
    // Dit zet dat om naar normale tekst.
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
    // In Gmail JSON zitten mail-headers in een lijst (From, Subject, etc).
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
    // We zoeken naar het "text/plain" deel van de mail.
    // Als dat er niet is, gebruiken we later de snippet als fallback.
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
    // Deze functie haalt de HTML uit de Gmail payload en zet het om naar leesbare tekst.
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
    // Dit maakt de tekst leesbaar op het scherm:
    // - Windows regels omzetten naar normale regels
    // - meerdere lege regels beperken
    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim((string) $text);
}

function bepaalStorageGoogleDir()
{
    // We moeten het tokenbestand kunnen vinden op de server.
    // Hosting kan soms een rare DOCUMENT_ROOT geven, dus we zoeken "omhoog" tot we storage/google zien.
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
    // Soms geeft de server HTTP_HOST met een poort (bijv. marioswitch1.nl:443).
    // Dit maakt er een nette host van, zodat de bestandsnaam klopt.
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
    // Dit haalt alle tokenbestanden op uit de storage map.
    // Handig als we niet precies weten hoe het bestand heet.
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
    // We proberen het juiste tokenbestand te vinden voor dit domein (met/zonder www).
    // Als er maar 1 tokenbestand bestaat, gebruiken we die automatisch.
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
    // Dit leest de JSON uit het tokenbestand en zet het om naar een PHP array.
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
    // Als we een access token hebben ververst, slaan we het nieuwe token weer op in hetzelfde bestand.
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
    // Access tokens verlopen snel (meestal ~1 uur).
    // Als hij verlopen is, moeten we refreshen met de refresh_token.
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
    // Dit vraagt een nieuw access_token aan bij Google met de refresh_token.
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

function haalGmailAccessTokenOp()
{
    // Dit is de hoofd-functie voor "inloggen":
    // - We lezen het tokenbestand
    // - We controleren of het access_token nog geldig is
    // - Zo niet, dan refreshen we automatisch
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
        $basis = 'Geen tokenbestand gevonden. Doe eerst OAuth via /api/google/oauth/callback.';
        $debug = isset($_GET['debug']) && (string) $_GET['debug'] === '1';
        if (!$debug) {
            return ['ok' => false, 'error' => $basis];
        }

        $storageDir = bepaalStorageGoogleDir();
        $hostRaw = trim((string) $host);
        $hostNorm = normaliseerHostVoorBestand($hostRaw);
        $tokens = lijstTokenBestandenInDir($storageDir);

        $html = '<div style="margin-top:12px; padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; background:#f8fafc;">';
        $html .= '<div><b>Debug</b></div>';
        $html .= '<div>HTTP_HOST: ' . e($_SERVER['HTTP_HOST'] ?? '') . '</div>';
        $html .= '<div>Host raw: ' . e($hostRaw) . '</div>';
        $html .= '<div>Host norm: ' . e($hostNorm) . '</div>';
        $html .= '<div>DOCUMENT_ROOT: ' . e((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) . '</div>';
        $html .= '<div>__DIR__: ' . e(__DIR__) . '</div>';
        $html .= '<div>storage/google dir: ' . e($storageDir) . '</div>';
        $html .= '<div>gevonden tokenbestanden: ' . e((string) count($tokens)) . '</div>';
        if (!empty($tokens)) {
            $html .= '<ul style="margin:6px 0 0; padding-left:18px;">';
            foreach ($tokens as $t) {
                $html .= '<li>' . e(basename($t)) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';

        return ['ok' => false, 'error' => $basis . $html];
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
        return ['ok' => false, 'error' => 'Token is verlopen en refresh_token ontbreekt. Doe OAuth opnieuw met access_type=offline & prompt=consent.'];
    }

    $refresh = refreshAccessToken($clientId, $clientSecret, $refreshToken);
    if (empty($refresh['ok'])) {
        $err = isset($refresh['error']) ? (string) $refresh['error'] : 'Refresh mislukt.';
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

function gmailApiRequest($method, $path, $accessToken, $query = [])
{
    // Dit is een simpele helper om een Gmail API request te doen (GET/POST etc).
    // We sturen het access_token mee als Bearer token.
    $url = 'https://gmail.googleapis.com/gmail/v1/' . ltrim((string) $path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
    ]);

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

// =========================
// Concept helpers
// =========================
// Deze functies maken van een klantmail een concept-antwoord met OpenAI.
// Daarna slaan we dat concept op in de database als 'draft' (concept).
// Dat doen we alleen als je ?generate=1 in de URL zet.

function parseerEmailAdresUitFromHeader($fromHeader)
{
    // Gmail geeft "From" vaak als: "Naam <email@domein.nl>".
    // Wij willen daar alleen het e-mailadres uit halen.
    $fromHeader = trim((string) $fromHeader);

    if ($fromHeader === '') {
        return '';
    }

    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $fromHeader, $m) === 1) {
        $candidate = trim((string) ($m[0] ?? ''));
        return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : '';
    }

    if (preg_match('/<([^>]+)>/', $fromHeader, $m) === 1) {
        $candidate = trim((string) $m[1]);
        return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : '';
    }

    $candidate = preg_replace('/\s+/', '', $fromHeader);
    return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? (string) $candidate : '';
}

function roepOpenAiAanVoorEmailConcept($onderwerp, $klantTekst)
{
    // Dit stuurt de klantmail naar OpenAI en vraagt om een concept-antwoord.
    $apiKey = getProjectEnvValue('OPENAI_API_KEY');
    if ($apiKey === null || $apiKey === '') {
        return ['ok' => false, 'error' => 'OPENAI_API_KEY ontbreekt in .env.'];
    }

    $system = 'Je schrijft een concept-antwoord voor de klantenservice van de webshops van MarioTeam. Schrijf in het Nederlands. Als informatie ontbreekt, stel eerst korte, duidelijke vragen. Geef geen exacte voorraadaantallen. Als de klant om ordergegevens vraagt, vraag eerst om bestelnummer + e-mailadres. Geef alleen het antwoord (geen uitleg over je stappen).';
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

function haalDatabaseConnectieVoorEmailConcepten()
{
    // We hebben een database nodig om concepten op te slaan.
    // In deze codebase maakt include/db.inc de PDO connectie aan als $conn.
    global $conn;
    include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';
    return $conn ?? null;
}

function bestaatEmailConceptVoorThread($conn, $threadId)
{
    // We willen geen dubbele concepten aanmaken voor hetzelfde Gmail gesprek.
    // Daarom checken we of er al een draft/sent record bestaat voor deze thread.
    $stmt = $conn->prepare("
        SELECT id
        FROM email_concepten
        WHERE gmail_thread_id = :thread_id
          AND status IN ('draft', 'sent')
        LIMIT 1
    ");
    $stmt->execute([
        ':thread_id' => (string) $threadId,
    ]);

    return (bool) $stmt->fetch();
}

function voegEmailConceptToe($conn, $threadId, $klantEmail, $conceptTekst)
{
    // Dit slaat het concept-antwoord op in de database.
    // Status is altijd 'draft', zodat een medewerker het later nog kan aanpassen.
    $stmt = $conn->prepare("
        INSERT INTO email_concepten (gmail_thread_id, klant_email, concept_tekst, status)
        VALUES (:thread_id, :klant_email, :concept_tekst, 'draft')
    ");
    $stmt->execute([
        ':thread_id' => (string) $threadId,
        ':klant_email' => (string) $klantEmail,
        ':concept_tekst' => (string) $conceptTekst,
    ]);

    return (int) $conn->lastInsertId();
}

vereisLogin();

// Dit bepaalt hoeveel mails we maximaal laten zien op het scherm.
$max = isset($_GET['max']) ? (int) $_GET['max'] : 5;
if ($max <= 0) {
    $max = 5;
}
if ($max > 20) {
    $max = 20;
}

// Dit regelt dat we een werkend access_token hebben (en dus echt met Gmail kunnen praten).
$token = haalGmailAccessTokenOp();
if (empty($token['ok'])) {
    $msg = isset($token['error']) ? (string) $token['error'] : 'Geen Gmail token.';
    stuurHtml(500, 'Gmail API test', '<p>' . e($msg) . '</p>');
}

$accessToken = (string) $token['access_token'];

// We halen de nieuwste ongelezen mails op uit de inbox.
$lijst = gmailApiRequest('GET', 'users/me/messages', $accessToken, [
    'labelIds' => 'INBOX',
    'q' => 'is:unread',
    'maxResults' => $max,
]);

if (empty($lijst['ok'])) {
    $err = isset($lijst['error']) ? (string) $lijst['error'] : 'Ophalen lijst is mislukt.';
    stuurHtml(500, 'Gmail API test', '<p>' . e($err) . '</p>');
}

$messages = $lijst['data']['messages'] ?? [];
if (!is_array($messages) || empty($messages)) {
    stuurHtml(200, 'Gmail API test', '<p>Geen ongelezen mails gevonden.</p>');
}

// Als je ?generate=1 gebruikt, maken we ook concepten en slaan we ze op.
// Zonder ?generate=1 is dit alleen een test om ongelezen mails te tonen.
$wilConceptenMaken = isset($_GET['generate']) && (string) $_GET['generate'] === '1';
$mailItems = [];
$itemsHtml = '';
foreach ($messages as $m) {
    // Voor elke mail-id halen we daarna de volledige mail op, zodat we headers en tekst kunnen lezen.
    if (!is_array($m) || empty($m['id'])) {
        continue;
    }

    $msgId = (string) $m['id'];
    $detail = gmailApiRequest('GET', 'users/me/messages/' . rawurlencode($msgId), $accessToken, [
        'format' => 'full',
    ]);

    if (empty($detail['ok'])) {
        $err = isset($detail['error']) ? (string) $detail['error'] : 'Detail ophalen mislukt.';
        $itemsHtml .= '<div style="border:1px solid #ddd; border-radius:10px; padding:12px; margin:12px 0;"><b>Fout</b><br><span>' . e($err) . '</span></div>';
        continue;
    }

    $data = $detail['data'] ?? [];
    $threadId = isset($data['threadId']) ? (string) $data['threadId'] : '';
    $payload = $data['payload'] ?? [];
    $headers = is_array($payload) && isset($payload['headers']) ? $payload['headers'] : [];
    $from = haalHeaderOp($headers, 'From') ?? '';
    $subject = haalHeaderOp($headers, 'Subject') ?? '';

    // We proberen eerst echte plain-text uit de mail te halen.
    // Als dat niet lukt, tonen we de "snippet" van Gmail (kort stukje tekst).
    $text = zoekTekstPlainInPayload($payload);
    if (!is_string($text) || $text === '') {
        $text = zoekTekstHtmlInPayload($payload);
    }
    if (!is_string($text) || $text === '') {
        $text = isset($data['snippet']) ? (string) $data['snippet'] : '';
    }
    $text = normaliseerTekst($text);

    // We bewaren alvast de info die we later nodig hebben:
    // e-mail + thread id + tekst + onderwerp.
    $klantEmail = parseerEmailAdresUitFromHeader($from);
    $mailItems[] = [
        'from' => (string) $from,
        'klant_email' => (string) $klantEmail,
        'subject' => (string) $subject,
        'thread_id' => (string) $threadId,
        'tekst' => (string) $text,
    ];

    $itemsHtml .= '<div style="border:1px solid #ddd; border-radius:10px; padding:12px; margin:12px 0;">';
    $itemsHtml .= '<div><b>From:</b> ' . e($from) . '</div>';
    $itemsHtml .= '<div><b>Subject:</b> ' . e($subject) . '</div>';
    $itemsHtml .= '<div><b>Thread ID:</b> ' . e($threadId) . '</div>';
    $itemsHtml .= '<div style="margin-top:10px;"><b>Tekst:</b></div>';
    $itemsHtml .= '<pre style="white-space:pre-wrap; margin:6px 0 0;">' . e($text) . '</pre>';
    $itemsHtml .= '</div>';
}

if (!$wilConceptenMaken) {
    // Alleen laten zien wat er ongelezen binnenkomt.
    // Met de knop kun je daarna concepten laten maken en opslaan.
    $actieHtml = '<p style="margin-top: 14px;"><a href="?generate=1&amp;max=' . e($max) . '" style="display:inline-block; background:#111827; color:#fff; padding:10px 12px; border-radius:10px; text-decoration:none; font-weight:700;">Concept-antwoorden maken</a></p>';
    stuurHtml(200, 'Gmail API test', '<p>Dit zijn de nieuwste ongelezen mails (max ' . e($max) . ').</p>' . $actieHtml . $itemsHtml);
}

$conn = haalDatabaseConnectieVoorEmailConcepten();
if (!$conn) {
    stuurHtml(500, 'Concept-generator', '<p>Databaseverbinding ontbreekt.</p>');
}

// Hier maken we de concepten en slaan we ze op in email_concepten.
$aantalNieuw = 0;
$aantalOvergeslagen = 0;
$aantalFouten = 0;
$resultaatHtml = '';

$debugDbHtml = '';
$debug = isset($_GET['debug']) && (string) $_GET['debug'] === '1';
if ($debug) {
    try {
        $dbNaam = $conn->query('SELECT DATABASE() AS db')->fetch();
        $dbNaam = is_array($dbNaam) && isset($dbNaam['db']) ? (string) $dbNaam['db'] : '';
        $count = $conn->query('SELECT COUNT(*) AS c FROM email_concepten')->fetch();
        $count = is_array($count) && isset($count['c']) ? (int) $count['c'] : 0;
        $debugDbHtml .= '<div style="margin:12px 0; padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; background:#f8fafc;">';
        $debugDbHtml .= '<div><b>Debug</b></div>';
        $debugDbHtml .= '<div>Database: ' . e($dbNaam) . '</div>';
        $debugDbHtml .= '<div>Records in email_concepten: ' . e($count) . '</div>';
        $debugDbHtml .= '</div>';
    } catch (Throwable $e) {
        $debugDbHtml .= '<div style="margin:12px 0; padding:10px 12px; border:1px solid #fca5a5; border-radius:10px; background:#fee2e2;">';
        $debugDbHtml .= '<div><b>Debug (Database) fout</b></div>';
        $debugDbHtml .= '<div>' . e($e->getMessage()) . '</div>';
        $debugDbHtml .= '</div>';
    }
}

foreach ($mailItems as $item) {
    $threadId = (string) ($item['thread_id'] ?? '');
    $klantEmail = (string) ($item['klant_email'] ?? '');
    $onderwerp = (string) ($item['subject'] ?? '');
    $tekst = (string) ($item['tekst'] ?? '');

    // Als er cruciale info ontbreekt, kunnen we geen concept opslaan.
    if ($threadId === '' || $klantEmail === '' || $tekst === '') {
        $aantalFouten++;
        $resultaatHtml .= '<div style="border:1px solid #fca5a5; background:#fee2e2; border-radius:10px; padding:12px; margin:12px 0;">';
        $resultaatHtml .= '<div style="font-weight:800;">Overgeslagen (mist data)</div>';
        $resultaatHtml .= '<div><b>Thread ID:</b> ' . e($threadId) . '</div>';
        $resultaatHtml .= '<div><b>Klant e-mail:</b> ' . e($klantEmail) . '</div>';
        $resultaatHtml .= '<div style="margin-top:6px;">Zorg dat From echt een e-mailadres bevat en dat de mail tekst/snippet heeft.</div>';
        $resultaatHtml .= '</div>';
        continue;
    }

    // Als we al een concept hebben voor deze thread, slaan we deze mail over.
    if (bestaatEmailConceptVoorThread($conn, $threadId)) {
        $aantalOvergeslagen++;
        $resultaatHtml .= '<div style="border:1px solid #ddd; border-radius:10px; padding:12px; margin:12px 0;">';
        $resultaatHtml .= '<div style="font-weight:800;">Overgeslagen (bestond al)</div>';
        $resultaatHtml .= '<div><b>Klant:</b> ' . e($klantEmail) . '</div>';
        $resultaatHtml .= '<div><b>Thread ID:</b> ' . e($threadId) . '</div>';
        $resultaatHtml .= '</div>';
        continue;
    }

    // OpenAI maakt het concept-antwoord.
    $ai = roepOpenAiAanVoorEmailConcept($onderwerp, $tekst);
    if (empty($ai['ok'])) {
        $err = isset($ai['error']) ? (string) $ai['error'] : 'Onbekende OpenAI fout';
        $aantalFouten++;
        $resultaatHtml .= '<div style="border:1px solid #fca5a5; background:#fee2e2; border-radius:10px; padding:12px; margin:12px 0;">';
        $resultaatHtml .= '<div style="font-weight:800;">Fout (OpenAI)</div>';
        $resultaatHtml .= '<div><b>Klant:</b> ' . e($klantEmail) . '</div>';
        $resultaatHtml .= '<div><b>Thread ID:</b> ' . e($threadId) . '</div>';
        $resultaatHtml .= '<div style="margin-top:6px;"><b>Melding:</b> ' . e($err) . '</div>';
        $resultaatHtml .= '</div>';
        continue;
    }

    // Concept opslaan in de database met status 'draft'.
    $conceptTekst = (string) $ai['content'];
    try {
        $nieuwId = voegEmailConceptToe($conn, $threadId, $klantEmail, $conceptTekst);
        $aantalNieuw++;
    } catch (Throwable $e) {
        $aantalFouten++;
        $resultaatHtml .= '<div style="border:1px solid #fca5a5; background:#fee2e2; border-radius:10px; padding:12px; margin:12px 0;">';
        $resultaatHtml .= '<div style="font-weight:800;">Fout (Database)</div>';
        $resultaatHtml .= '<div><b>Klant:</b> ' . e($klantEmail) . '</div>';
        $resultaatHtml .= '<div><b>Thread ID:</b> ' . e($threadId) . '</div>';
        $resultaatHtml .= '<div style="margin-top:6px;"><b>Melding:</b> ' . e($e->getMessage()) . '</div>';
        $resultaatHtml .= '</div>';
        continue;
    }

    $resultaatHtml .= '<div style="border:1px solid #ddd; border-radius:10px; padding:12px; margin:12px 0;">';
    $resultaatHtml .= '<div><b>Opgeslagen als draft</b> (id ' . e($nieuwId) . ')</div>';
    $resultaatHtml .= '<div><b>Klant:</b> ' . e($klantEmail) . '</div>';
    $resultaatHtml .= '<div><b>Thread ID:</b> ' . e($threadId) . '</div>';
    $resultaatHtml .= '<div style="margin-top:10px;"><b>Concept:</b></div>';
    $resultaatHtml .= '<pre style="white-space:pre-wrap; margin:6px 0 0;">' . e($conceptTekst) . '</pre>';
    $resultaatHtml .= '</div>';
}

$samenvatting = '<p><b>Resultaat</b><br>Nieuw: ' . e($aantalNieuw) . ' | Overgeslagen (bestond al): ' . e($aantalOvergeslagen) . ' | Fouten: ' . e($aantalFouten) . '</p>';
stuurHtml(200, 'Concept-generator', $debugDbHtml . $samenvatting . $resultaatHtml);
