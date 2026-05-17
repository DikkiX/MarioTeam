<?php
$conn = $conn ?? null;

include_once __DIR__ . '/include/env.php';
include_once __DIR__ . '/include/ChatFunction.php';
include_once __DIR__ . '/include/bestelling_lookup.php';
include_once __DIR__ . '/include/email_dashboard_helpers.php';

if (!defined('EMAIL_DASHBOARD_LIB_ONLY')) {
    include_once __DIR__ . '/include/db.inc';
    $conn = $conn ?? null;
    if (!($conn instanceof PDO)) {
        http_response_code(500);
        exit('Database verbinding ontbreekt.');
    }

    assert($conn instanceof PDO);
    session_start();
}

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
    // Escape helper:
    // Alles wat je in HTML echo't (ook uit de database) moet je "escapen".
    // Anders kan er per ongeluk HTML/JS uitgevoerd worden in de browser.
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken()
{
    // CSRF = iemand probeert jouw browser "stiekem" een actie te laten uitvoeren (bijv. verwijderen),
    // terwijl jij al ingelogd bent.
    // Dit token is een willekeurige sleutel die we in de session bewaren en in elk formulier stoppen.
    // Bij POST controleren we of het token klopt. Zo weten we: het komt echt van ons formulier.
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function vereisCsrf()
{
    // Beveiligingscheck voor alle POST acties:
    // Als CSRF token ontbreekt of anders is: request afbreken.
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
    // We gebruiken PHP sessions:
    // - na inloggen zetten we een vlag in $_SESSION
    // - bij elke pagina-load checken we die vlag
    $user = getProjectEnvValue('EMAIL_DASHBOARD_USER');
    $pass = getProjectEnvValue('EMAIL_DASHBOARD_PASS');

    if ($user === null || $pass === null) {
        stuurHtml(500, '<h1>Configuratie ontbreekt</h1><p>EMAIL_DASHBOARD_USER en EMAIL_DASHBOARD_PASS ontbreken in .env.</p>');
    }

    if (isset($_POST['actie']) && (string) $_POST['actie'] === 'login') {
        vereisCsrf();
        $gegevenUser = isset($_POST['user']) ? (string) $_POST['user'] : '';
        $gegevenPass = isset($_POST['pass']) ? (string) $_POST['pass'] : '';

        // hash_equals = veilig vergelijken (zodat timing geen info lekt).
        $isOk = hash_equals((string) $user, $gegevenUser) && hash_equals((string) $pass, $gegevenPass);
        if (!$isOk) {
            renderLoginPagina('Gebruikersnaam of wachtwoord is verkeerd.');
        }

        // Onthoud "ingelogd" in de session.
        $_SESSION['email_dashboard_authed'] = true;

        $locatie = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/EmailDashboard.php';
        header('Location: ' . $locatie, true, 303);
        exit;
    }

    if (!empty($_GET['logout'])) {
        // Logout = sessie vlag uitzetten en login pagina tonen.
        $_SESSION['email_dashboard_authed'] = false;
        renderLoginPagina('Je bent uitgelogd.');
    }

    if (empty($_SESSION['email_dashboard_authed'])) {
        // Niet ingelogd -> eerst login pagina tonen.
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

function gmailZorgLabelId($accessToken, $labelNaam)
{
    // We gebruiken een Gmail label om te onthouden dat we al een concept hebben gemaakt.
    // Zo kunnen mails ongeopend blijven, maar worden ze niet steeds opnieuw verwerkt.
    $labelNaam = trim((string) $labelNaam);
    if ($labelNaam === '') {
        return '';
    }

    $lijst = gmailApiRequest('GET', 'users/me/labels', $accessToken);
    if (!empty($lijst['ok']) && isset($lijst['data']['labels']) && is_array($lijst['data']['labels'])) {
        foreach ($lijst['data']['labels'] as $l) {
            if (!is_array($l)) {
                continue;
            }
            $name = isset($l['name']) ? (string) $l['name'] : '';
            if ($name === $labelNaam) {
                return isset($l['id']) ? (string) $l['id'] : '';
            }
        }
    }

    $maak = gmailApiRequest('POST', 'users/me/labels', $accessToken, [
        'name' => $labelNaam,
        'labelListVisibility' => 'labelShow',
        'messageListVisibility' => 'show',
    ]);
    if (!empty($maak['ok']) && isset($maak['data']['id'])) {
        return (string) $maak['data']['id'];
    }

    return '';
}

function haalKennisVoorEmailAi($assistant0 = '')
{
    // Bouwt een korte "kennis" tekst voor de AI.
    // Dit is vooral bedoeld om standaard informatie (FAQ + contact + openingstijden) mee te geven,
    // zodat de AI minder hoeft te gokken.
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
    if ($docRoot === '') {
        return '';
    }

    $pathMijnEmail = $docRoot . '/include/mijnemail_universeel.inc';
    $pathContact = $docRoot . '/include/contact.inc';

    $assistant0 = is_string($assistant0) ? trim($assistant0) : '';
    $faqPaths = [];
    if ($assistant0 !== '') {
        if (preg_match("/Aankoop/i", (string) $assistant0) === 1) {
            $faqPaths[] = $docRoot . '/include/ChatGPT/aankoop.php';
        }
        if (preg_match("/Zending/i", (string) $assistant0) === 1) {
            $faqPaths[] = $docRoot . '/include/ChatGPT/zending.php';
        }
        if (preg_match("/Service/i", (string) $assistant0) === 1) {
            $faqPaths[] = $docRoot . '/include/ChatGPT/service.php';
        }
        if (preg_match("/Inkoop/i", (string) $assistant0) === 1) {
            $faqPaths[] = $docRoot . '/include/ChatGPT/inkoop.php';
        }
        if (preg_match("/Loyaliteit/i", (string) $assistant0) === 1) {
            $faqPaths[] = $docRoot . '/include/ChatGPT/loyaliteit.php';
        }
    }
    if (empty($faqPaths)) {
        $faqPaths = [
            // Deze bestanden vullen de $FAQ array met HTML-tekstblokken (op site + voor bot).
            $docRoot . '/include/ChatGPT/aankoop.php',
            $docRoot . '/include/ChatGPT/zending.php',
            $docRoot . '/include/ChatGPT/service.php',
            $docRoot . '/include/ChatGPT/inkoop.php',
            $docRoot . '/include/ChatGPT/loyaliteit.php',
        ];
    }

    $heeftBestanden = is_file($pathMijnEmail) || is_file($pathContact);
    if (!$heeftBestanden) {
        foreach ($faqPaths as $p) {
            if (is_file($p)) {
                $heeftBestanden = true;
                break;
            }
        }
    }
    if (!$heeftBestanden) {
        return '';
    }

    $bodymain3 = '';
    $FAQ = [];

    set_error_handler(function () {
        return true;
    });

    ob_start();
    if (is_file($pathMijnEmail)) {
        // Universele variabelen (o.a. contacttijden) die contact.inc gebruikt.
        include_once $pathMijnEmail;
    }
    foreach ($faqPaths as $p) {
        if (is_file($p)) {
            // Deze includes vullen $FAQ[...] met content.
            include $p;
        }
    }
    if (is_file($pathContact)) {
        // Contactpagina bouwt $bodymain3 met HTML (werktijden/gegevens).
        include_once $pathContact;
    }
    $echoed = ob_get_clean();

    restore_error_handler();

    $stripHtmlNaarTekst = function ($html) {
        // Veel van de bestaande content is HTML. Voor de prompt maken we daar platte tekst van.
        $t = str_replace(["\r\n", "\r"], "\n", (string) $html);
        $t = preg_replace('/<\s*head\b[^>]*>[\s\S]*?<\s*\/\s*head\s*>/i', '', (string) $t);
        $t = preg_replace('/<\s*style\b[^>]*>[\s\S]*?<\s*\/\s*style\s*>/i', '', (string) $t);
        $t = preg_replace('/<\s*script\b[^>]*>[\s\S]*?<\s*\/\s*script\s*>/i', '', (string) $t);
        $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", (string) $t);
        $t = preg_replace('/<\/\s*p\s*>/i', "\n\n", (string) $t);
        $t = preg_replace('/<\/\s*tr\s*>/i', "\n", (string) $t);
        $t = preg_replace('/<\/\s*div\s*>/i', "\n", (string) $t);
        $t = preg_replace('/<\s*li[^>]*>/i', "\n- ", (string) $t);
        $t = preg_replace('/<\s*\/li\s*>/i', '', (string) $t);
        $t = preg_replace('/<\s*\/?ul[^>]*>/i', "\n", (string) $t);
        $t = preg_replace('/<\s*\/?ol[^>]*>/i', "\n", (string) $t);
        $t = strip_tags((string) $t);
        $t = html_entity_decode((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = preg_replace("/[ \t]+\n/", "\n", (string) $t);
        $t = preg_replace("/\n{3,}/", "\n\n", (string) $t);
        return trim((string) $t);
    };

    $maxLen = 8000;

    $contactHtml = '';
    if (is_string($bodymain3) && trim($bodymain3) !== '') {
        $contactHtml .= $bodymain3;
    }
    if (is_string($echoed) && trim($echoed) !== '') {
        $contactHtml .= "\n" . $echoed;
    }
    // Contact houden we expres kort; dit moet vooral "bedrijf/werktijden" zijn.
    $contactText = $stripHtmlNaarTekst($contactHtml);
    if ($contactText !== '' && strlen($contactText) > 2000) {
        $contactText = rtrim(substr($contactText, 0, 2000));
    }

    $faqText = '';
    $FAQ = (isset($FAQ) && is_array($FAQ)) ? $FAQ : [];
    if (!empty($FAQ)) {
        // De rest van de ruimte is voor FAQ-kennis (liefst zo veel mogelijk, maar met harde max).
        $doelLen = max(1000, $maxLen - strlen($contactText) - 30);
        $faqText = "FAQ:\n";
        foreach ($FAQ as $valueArray) {
            foreach ((array) $valueArray as $item) {
                if (!isset($item['text'])) {
                    continue;
                }
                $clean = $stripHtmlNaarTekst($item['text']);
                if ($clean === '') {
                    continue;
                }
                $toAdd = ($faqText === "FAQ:\n") ? $clean : ("\n\n" . $clean);
                if (strlen($faqText) + strlen($toAdd) > $doelLen) {
                    $remaining = $doelLen - strlen($faqText);
                    if ($remaining > 50) {
                        $faqText .= substr($toAdd, 0, $remaining);
                        $faqText = rtrim($faqText);
                    }
                    break 2;
                }
                $faqText .= $toAdd;
            }
        }
        $faqText = trim($faqText) !== 'FAQ:' ? trim($faqText) : '';
    }

    $parts = [];
    if ($faqText !== '') {
        $parts[] = $faqText;
    }
    if ($contactText !== '') {
        $parts[] = $contactText;
    }

    $result = trim(implode("\n\n", $parts));
    if ($result === '') {
        return '';
    }
    if (strlen($result) > $maxLen) {
        $result = rtrim(substr($result, 0, $maxLen));
    }
    return $result;
}

function haalAssistant0VoorEmail($onderwerp, $klantTekst, $threadContext = '')
{
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
    if ($docRoot === '') {
        return '';
    }

    $system0 = '';
    try {
        include_once $docRoot . '/include/ChatGPT/system0.php';
        $system0 = isset($system0) ? trim((string) $system0) : '';
    } catch (Throwable) {
        $system0 = '';
    }
    if ($system0 === '') {
        return '';
    }

    $input = "Onderwerp: " . (string) $onderwerp;
    if (is_string($threadContext) && trim($threadContext) !== '') {
        $input .= "\n\nEerdere berichten (ingekort):\n" . trim((string) $threadContext);
    }
    $input .= "\n\nLaatste klantmail:\n" . (string) $klantTekst;

    $mode = function_exists('getChatModelMode') ? getChatModelMode() : 2;
    $label = CHATGPT($input, $system0, 0.2, $mode, [], 0);
    return is_string($label) ? trim($label) : '';
}

function bouwThreadContextVoorAi($threadMessages, $klantEmail, $maxVorige = 5)
{
    if (!is_array($threadMessages) || empty($threadMessages)) {
        return '';
    }

    $klantEmail = strtolower(trim((string) $klantEmail));
    $items = [];

    foreach ($threadMessages as $m) {
        if (!is_array($m)) {
            continue;
        }
        $payload = $m['payload'] ?? [];
        $headers = (is_array($payload) && isset($payload['headers']) && is_array($payload['headers'])) ? $payload['headers'] : [];
        $fromHeader = haalHeaderOp($headers, 'From') ?? '';
        $fromEmail = function_exists('parseerEmailAdresUitFromHeader') ? parseerEmailAdresUitFromHeader($fromHeader) : '';
        $fromEmail = strtolower(trim((string) $fromEmail));
        $isKlant = ($klantEmail !== '' && $fromEmail !== '' && $fromEmail === $klantEmail);

        $text = zoekTekstPlainInPayload($payload);
        if (!is_string($text) || $text === '') {
            $text = zoekTekstHtmlInPayload($payload);
        }
        if (!is_string($text) || $text === '') {
            $text = isset($m['snippet']) ? (string) $m['snippet'] : '';
        }
        $text = stripQuotedEnHandtekeningTekst($text);
        if ($text === '') {
            continue;
        }

        $items[] = [
            'who' => $isKlant ? 'Klant' : 'Wij',
            'text' => $text,
        ];
    }

    if (count($items) <= 1) {
        return '';
    }

    $vorige = array_slice($items, 0, -1);
    $maxVorige = (int) $maxVorige;
    if ($maxVorige <= 0) {
        $maxVorige = 1;
    }
    if ($maxVorige > 10) {
        $maxVorige = 10;
    }
    if (count($vorige) > $maxVorige) {
        $vorige = array_slice($vorige, -$maxVorige);
    }

    $blocks = [];
    foreach ($vorige as $it) {
        if (!is_array($it)) {
            continue;
        }
        $who = isset($it['who']) ? (string) $it['who'] : '';
        $txt = isset($it['text']) ? (string) $it['text'] : '';
        $who = $who !== '' ? $who : 'Bericht';
        $txt = trim($txt);
        if ($txt === '') {
            continue;
        }
        $blocks[] = $who . ":\n" . $txt;
    }

    return implode("\n\n", $blocks);
}

function formatteerGmailOntvangstTijdVoorDashboard($gmailMessageData, $headers)
{
    // Voor de lijst willen we de ontvangstmoment-tijd van Gmail tonen (niet het moment dat wij het concept opslaan).
    // We proberen eerst de "Date" header, en vallen terug op Gmail internalDate.
    $tz = new DateTimeZone('Europe/Amsterdam');
    $dateHeader = is_array($headers) ? (haalHeaderOp($headers, 'Date') ?? '') : '';
    $dateHeader = is_string($dateHeader) ? trim($dateHeader) : '';
    if ($dateHeader !== '') {
        try {
            $dt = new DateTimeImmutable($dateHeader);
            return $dt->setTimezone($tz)->format('Y-m-d H:i');
        } catch (Throwable) {
        }
    }

    if (is_array($gmailMessageData) && isset($gmailMessageData['internalDate'])) {
        $ms = (int) $gmailMessageData['internalDate'];
        if ($ms > 0) {
            try {
                $sec = (int) floor($ms / 1000);
                $dt = new DateTimeImmutable('@' . (string) $sec);
                return $dt->setTimezone($tz)->format('Y-m-d H:i');
            } catch (Throwable) {
            }
        }
    }

    return '';
}

function bouwOrderContextTekstVoorAi($orderResult)
{
    // Dit zet ruwe orderdata om naar een kort tekstblok.
    // Dat tekstblok sturen we mee als "feiten" zodat de AI niet hoeft te gokken.
    if (!is_array($orderResult) || empty($orderResult['gevonden'])) {
        return '';
    }
    $r = isset($orderResult['resultaat']) && is_array($orderResult['resultaat']) ? $orderResult['resultaat'] : [];
    $artikelen = isset($orderResult['artikelen']) && is_array($orderResult['artikelen']) ? $orderResult['artikelen'] : [];

    $lines = [];
    $id = isset($r['id']) ? (string) $r['id'] : '';
    if ($id !== '') {
        $lines[] = 'Bestelnummer: ' . $id;
    }
    $vs = isset($r['verzend_status']) ? (string) $r['verzend_status'] : '';
    if ($vs !== '') {
        $lines[] = 'Verzendstatus: ' . $vs;
    }
    $tc = isset($r['track_code']) ? (string) $r['track_code'] : '';
    if ($tc !== '') {
        $lines[] = 'Track&Trace code: ' . $tc;
    }
    $totaal = isset($r['totaal']) ? (string) $r['totaal'] : '';
    if ($totaal !== '') {
        $lines[] = 'Totaal: ' . $totaal;
    }
    $verzend = isset($r['verzendkosten']) ? (string) $r['verzendkosten'] : '';
    if ($verzend !== '') {
        $lines[] = 'Verzendkosten: ' . $verzend;
    }
    if (!empty($artikelen)) {
        $lines[] = 'Artikelen:';
        foreach ($artikelen as $a) {
            if (!is_array($a)) {
                continue;
            }
            $naam = isset($a['productnaam']) ? trim((string) $a['productnaam']) : '';
            $aantal = isset($a['aantal']) ? (int) $a['aantal'] : 0;
            if ($naam === '') {
                continue;
            }
            if ($aantal <= 0) {
                $aantal = 1;
            }
            $lines[] = '- ' . $aantal . 'x ' . $naam;
        }
    }

    return implode("\n", $lines);
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
        if (!tabelHeeftKolom($conn, 'email_concepten', 'ontvangen_op_gmail')) {
            $conn->exec("ALTER TABLE email_concepten ADD COLUMN ontvangen_op_gmail VARCHAR(255) NULL AFTER onderwerp");
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

function voegEmailConceptToe($conn, $threadId, $klantEmail, $conceptTekst, $ontvangenOpEmail = '', $afzenderAliasEmail = '', $onderwerp = '', $ontvangenOpGmail = '')
{
    // Dit slaat het concept op als draft.
    zorgEmailConceptenAliasKolommen($conn);
    $heeftOnderwerp = tabelHeeftKolom($conn, 'email_concepten', 'onderwerp');
    $heeftOntvangenGmail = tabelHeeftKolom($conn, 'email_concepten', 'ontvangen_op_gmail');
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
    if ($heeftOntvangenGmail) {
        $kolommen[] = 'ontvangen_op_gmail';
        $placeholders[] = ':ontvangen_gmail';
        $params[':ontvangen_gmail'] = (string) $ontvangenOpGmail;
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

function roepOpenAiAanVoorEmailConcept($onderwerp, $klantTekst, $extraInstructies = '', $threadContext = '')
{
    // Dit maakt een concept-antwoord op basis van de klantmail.
    global $conn;
    $apiKey = getProjectEnvValue('OPENAI_API_KEY');
    if ($apiKey === null || $apiKey === '') {
        return ['ok' => false, 'error' => 'OPENAI_API_KEY ontbreekt in .env.'];
    }

    $model = function_exists('getChatModelName') ? getChatModelName() : 'gpt-4.1-mini';

    $system = 'Je schrijft een concept-antwoord voor de klantenservice van de webshops van MarioTeam. Schrijf in het Nederlands. Als informatie ontbreekt, stel eerst korte, duidelijke vragen. Geef geen exacte voorraadaantallen. Als de klant om ordergegevens vraagt: vraag alleen om ontbrekende gegevens (bestelnummer en/of e-mailadres). Als ze al in de tekst staan, vraag niet om bevestiging maar gebruik ze. Als er orderdata uit de database is meegegeven, baseer je antwoord daarop en verzin niets. Als order lookup NIET GEVONDEN is (of niet beschikbaar): zeg expliciet dat je de bestelling met deze combinatie (bestelnummer + e-mailadres) niet kunt terugvinden, dat dit vaak een typefout of ander e-mailadres is, en vraag de klant om te controleren. Zeg in dat geval niet dat de bestelling bestaat/ontvangen is en noem geen verzendstatus of track&trace. Als de klant naar actuele prijs/voorraad vraagt, zeg dat je dat niet live kunt checken in e-mail en verwijs naar de website of de chat. Geef alleen het antwoord (geen uitleg over je stappen).';
    $tone = '';
    try {
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
    $assistant0 = haalAssistant0VoorEmail($onderwerp, $klantTekst, $threadContext);
    $kennis = haalKennisVoorEmailAi($assistant0);
    if (is_string($kennis) && trim($kennis) !== '') {
        $system .= "\n\nRelevante informatie (FAQ/contact/werktijden/bedrijfsgegevens):\n" . trim($kennis);
    }

    // als de klant zijn bestelnummer + e-mailadres al geeft, halen we orderdata direct uit de DB.
    // Zo kan de AI meteen een feitelijk concept maken (zonder dat de medewerker zelf moet zoeken).
    $orderInfo = extracteerBestelEnEmailUitTekst($klantTekst);
    $bestellingId = isset($orderInfo['bestelling_id']) ? (int) $orderInfo['bestelling_id'] : 0;
    $emailInTekst = isset($orderInfo['email']) ? (string) $orderInfo['email'] : '';
    $orderLookupStatus = '';
    if ($bestellingId > 0 && $emailInTekst !== '' && isset($conn) && ($conn instanceof PDO)) {
        try {
            $orderResult = zoekBestellingRuw($conn, $bestellingId, $emailInTekst);
            if (!empty($orderResult['gevonden'])) {
                $orderText = bouwOrderContextTekstVoorAi($orderResult);
                if ($orderText !== '') {
                    $system .= "\n\nOrdergegevens uit database:\n" . $orderText;
                }
                $orderLookupStatus = 'GEVONDEN';
            } else {
                $system .= "\n\nOrder lookup:\nNIET GEVONDEN voor de opgegeven combinatie. Zeg dat je de bestelling met deze gegevens niet kunt vinden en vraag de klant om bestelnummer en e-mailadres te controleren.";
                $orderLookupStatus = 'NIET GEVONDEN';
            }
        } catch (Throwable) {
            $system .= "\n\nOrder lookup:\nNIET BESCHIKBAAR. Geef geen orderstatus/track&trace en vraag de klant om bestelnummer en e-mailadres te controleren.";
            $orderLookupStatus = 'NIET BESCHIKBAAR';
        }
    }
    $user = "Onderwerp: " . (string) $onderwerp;
    if (is_string($threadContext) && trim($threadContext) !== '') {
        $user .= "\n\nEerdere berichten (ingekort):\n" . trim($threadContext);
    }
    $user .= "\n\nLaatste klantmail:\n" . (string) $klantTekst;
    if ($bestellingId > 0 || $emailInTekst !== '') {
        $user .= "\n\nGegeven gegevens:";
        if ($bestellingId > 0) {
            $user .= "\n- Bestelnummer: " . (string) $bestellingId;
        }
        if ($emailInTekst !== '') {
            $user .= "\n- E-mailadres: " . (string) $emailInTekst;
        }
    }
    if ($orderLookupStatus !== '') {
        $user .= "\n\nOrder lookup resultaat: " . $orderLookupStatus;
    }

    $mode = function_exists('getChatModelMode') ? getChatModelMode() : 2;
    $content = CHATGPT($user, $system, 0.2, $mode, [], 1);
    return ['ok' => true, 'content' => $content];
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
    // Handig omdat meerdere page-loads tegelijk kunnen triggeren.
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
    // Doe 1 keer sync:
    // 1) haal ongelezen INBOX mails op
    // 2) maak AI concepten
    // 3) label de mail als "verwerkt" zodat we niet dubbel werken
    $maxResults = (int) $maxResults;
    if ($maxResults <= 0) {
        $maxResults = 1;
    }
    if ($maxResults > 500) {
        // Gmail geeft per pagina maximaal een beperkt aantal items terug.
        // In de praktijk is 500 een stevige "catch-up" batch.
        $maxResults = 500;
    }

    $token = haalGmailAccessTokenOp();
    if (empty($token['ok'])) {
        $errTekst = isset($token['error']) ? (string) $token['error'] : 'Gmail token ontbreekt.';
        return ['ok' => false, 'new' => 0, 'error' => $errTekst];
    }

    $accessToken = (string) $token['access_token'];

    // We willen dat mails ongeopend blijven tot er echt gereageerd is.
    // Daarom zetten we ze niet op "gelezen", maar geven we ze een label als we ze verwerkt hebben.
    // Zo kan klantenservice nog steeds in Gmail zien dat hij ongelezen is, maar wij slaan hem wel over.
    $aiLabelNaam = 'AI_CONCEPT';
    $aiLabelId = gmailZorgLabelId($accessToken, $aiLabelNaam);

    $backfillOnderwerpen = function ($limit) use ($conn, $accessToken) {
        try {
            zorgEmailConceptenAliasKolommen($conn);
            if (!tabelHeeftKolom($conn, 'email_concepten', 'onderwerp')) {
                return;
            }
            $heeftOntvangenGmail = tabelHeeftKolom($conn, 'email_concepten', 'ontvangen_op_gmail');
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

        $where = "status = 'draft' AND (onderwerp IS NULL OR onderwerp = '')";
        if ($heeftOntvangenGmail) {
            $where = "status = 'draft' AND ((onderwerp IS NULL OR onderwerp = '') OR (ontvangen_op_gmail IS NULL OR ontvangen_op_gmail = ''))";
        }
        $stmt = $conn->prepare("
            SELECT id, gmail_thread_id
            FROM email_concepten
            WHERE $where
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
            ]);
            if (empty($t['ok']) || !isset($t['data']['messages']) || !is_array($t['data']['messages'])) {
                continue;
            }

            $messages = $t['data']['messages'];
            $last = end($messages);
            if (!is_array($last) || !isset($last['payload']['headers'])) {
                continue;
            }

            $headers = $last['payload']['headers'];

            $sub = haalHeaderOp($headers, 'Subject');
            $sub = is_string($sub) ? trim($sub) : '';
            $ontvangenOpGmail = $heeftOntvangenGmail ? formatteerGmailOntvangstTijdVoorDashboard($last, $headers) : '';

            if ($sub !== '') {
                $upd = $conn->prepare("UPDATE email_concepten SET onderwerp = :o WHERE id = :id AND (onderwerp IS NULL OR onderwerp = '')");
                $upd->execute([
                    ':o' => $sub,
                    ':id' => $id,
                ]);
            }

            if ($heeftOntvangenGmail && $ontvangenOpGmail !== '') {
                $upd2 = $conn->prepare("UPDATE email_concepten SET ontvangen_op_gmail = :d WHERE id = :id AND (ontvangen_op_gmail IS NULL OR ontvangen_op_gmail = '')");
                $upd2->execute([
                    ':d' => $ontvangenOpGmail,
                    ':id' => $id,
                ]);
            }
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
    $pageToken = '';

    while (true) {
        // Gmail API werkt met pagina's (nextPageToken). Zo kunnen we "alles ongelezen" ophalen.
        $params = [
            // Alleen INBOX, want anders pak je ook spam/promoties/archief.
            'labelIds' => 'INBOX',
            // Alleen ongelezen, zodat we niet eindeloos dezelfde mails verwerken.
            'q' => 'is:unread',
            'maxResults' => $maxResults,
        ];
        if ($pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }
        $lijst = gmailApiRequest('GET', 'users/me/messages', $accessToken, null, $params);

        if (empty($lijst['ok'])) {
            $err = isset($lijst['error']) ? (string) $lijst['error'] : 'Ongelezen mails ophalen is mislukt.';
            return ['ok' => false, 'new' => 0, 'error' => $err];
        }

        $messages = $lijst['data']['messages'] ?? [];
        if (!is_array($messages) || empty($messages)) {
            break;
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
            $labelIds = (isset($data['labelIds']) && is_array($data['labelIds'])) ? $data['labelIds'] : [];
            // Als deze mail al eerder door ons is verwerkt, slaan we hem over.
            if ($aiLabelId !== '' && in_array($aiLabelId, $labelIds, true)) {
                continue;
            }
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

            $ontvangenOpGmail = formatteerGmailOntvangstTijdVoorDashboard($data, $headers);

            $rulesResult = verwerkEmailRulesVoorMail($actieveRegels, $from, $subject);
            if (!empty($rulesResult['ignore'])) {
                // Regels kunnen zeggen: deze mail negeren. We labelen hem dan wel als verwerkt.
                if ($aiLabelId !== '') {
                    gmailApiRequest('POST', 'users/me/messages/' . rawurlencode($msgId) . '/modify', $accessToken, [
                        'addLabelIds' => [$aiLabelId],
                    ]);
                }
                continue;
            }

            if (bestaatEmailConceptVoorThread($conn, $threadId)) {
                // Er is al een concept voor deze conversatie.
                // We updaten de datum zodat hij weer bovenaan komt in de lijst.
                try {
                    $upd = $conn->prepare("
                    UPDATE email_concepten
                    SET onderwerp = CASE WHEN (onderwerp IS NULL OR onderwerp = '') THEN :o ELSE onderwerp END
                    WHERE gmail_thread_id = :t
                    LIMIT 1
                ");
                    $upd->execute([
                        ':o' => (string) $subject,
                        ':t' => (string) $threadId,
                    ]);
                } catch (Throwable) {
                }
                if ($aiLabelId !== '') {
                    gmailApiRequest('POST', 'users/me/messages/' . rawurlencode($msgId) . '/modify', $accessToken, [
                        'addLabelIds' => [$aiLabelId],
                    ]);
                }
                continue;
            }

            $text = zoekTekstPlainInPayload($payload);
            if (!is_string($text) || $text === '') {
                $text = zoekTekstHtmlInPayload($payload);
            }
            if (!is_string($text) || $text === '') {
                // Snippet is een korte Gmail preview. Alleen gebruiken als we echt geen body vinden.
                $text = isset($data['snippet']) ? (string) $data['snippet'] : '';
            }
            $text = normaliseerTekst($text);
            $text = stripQuotedEnHandtekeningTekst($text);
            if ($text === '') {
                continue;
            }

            $extraInstructies = isset($rulesResult['extra_instructies']) ? (string) $rulesResult['extra_instructies'] : '';
            $threadContext = '';
            try {
                // Thread context is handig bij vervolgvragen ("zoals eerder besproken...").
                $t = gmailApiRequest('GET', 'users/me/threads/' . rawurlencode($threadId), $accessToken, null, ['format' => 'full']);
                if (!empty($t['ok']) && isset($t['data']['messages']) && is_array($t['data']['messages'])) {
                    $threadContext = bouwThreadContextVoorAi($t['data']['messages'], $klantEmail, 5);
                }
            } catch (Throwable) {
                $threadContext = '';
            }

            $ai = roepOpenAiAanVoorEmailConcept($subject, $text, $extraInstructies, $threadContext);
            if (empty($ai['ok'])) {
                $err = isset($ai['error']) ? (string) $ai['error'] : 'OpenAI fout.';
                schrijfEmailWorkerLog('OpenAI fout: ' . $err);
                continue;
            }

            $conceptTekst = (string) $ai['content'];
            if ($conceptTekst === '') {
                continue;
            }

            voegEmailConceptToe($conn, $threadId, $klantEmail, $conceptTekst, $ontvangerEmail, $afzenderAlias, $subject, $ontvangenOpGmail);
            if ($aiLabelId !== '') {
                // Labelen is onze "idempotency": voorkomt dubbel verwerken.
                gmailApiRequest('POST', 'users/me/messages/' . rawurlencode($msgId) . '/modify', $accessToken, [
                    'addLabelIds' => [$aiLabelId],
                ]);
            }
            $aantalNieuwe++;
        }

        $pageToken = isset($lijst['data']['nextPageToken']) ? trim((string) $lijst['data']['nextPageToken']) : '';
        if ($pageToken === '') {
            break;
        }
    }

    return ['ok' => true, 'new' => $aantalNieuwe, 'error' => ''];
}

function triggerEmailSyncWorkerInBackground($conn)
{
    // Start de worker zonder te wachten op antwoord.
    // Dit maakt de pagina-load sneller: de user ziet meteen het dashboard, sync loopt "achter de schermen".
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
        // Grote batch, zodat we ook na een weekend/afwezigheid kunnen "inhalen".
        $result = runEmailSyncOnce($conn, 500);
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

if (!defined('EMAIL_DASHBOARD_LIB_ONLY')) {
    assert($conn instanceof PDO);
    vereisDashboardLogin();

    if (!empty($_GET['attachment'])) {
        // US24: link om een bijlage te downloaden of (alleen bij plaatjes) in de browser te tonen.
        // Dit draait in dezelfde sessie/login als het dashboard.
        $messageId = isset($_GET['message_id']) ? trim((string) $_GET['message_id']) : '';
        $attachmentId = isset($_GET['attachment_id']) ? trim((string) $_GET['attachment_id']) : '';
        $partPath = isset($_GET['part_path']) ? trim((string) $_GET['part_path']) : '';
        $filename = isset($_GET['filename']) ? trim((string) $_GET['filename']) : '';
        $inline = !empty($_GET['inline']);

        if ($messageId === '' || ($attachmentId === '' && $partPath === '')) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Ongeldige aanvraag.';
            exit;
        }

        $token = haalGmailAccessTokenOp();
        if (empty($token['ok'])) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Gmail is niet gekoppeld.';
            exit;
        }

        $accessToken = (string) $token['access_token'];

        $msg = gmailApiRequest('GET', 'users/me/messages/' . rawurlencode($messageId), $accessToken, null, ['format' => 'full']);
        if (empty($msg['ok']) || !isset($msg['data']['payload']) || !is_array($msg['data']['payload'])) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Mail niet gevonden.';
            exit;
        }

        $payload = $msg['data']['payload'];
        $part = null;
        if ($attachmentId !== '') {
            $part = vindBijlagePartOpAttachmentId($payload, $attachmentId);
        } elseif ($partPath !== '') {
            $part = vindBijlagePartOpPad($payload, $partPath);
        }

        $mimeType = (is_array($part) && isset($part['mimeType']) && trim((string) $part['mimeType']) !== '') ? trim((string) $part['mimeType']) : 'application/octet-stream';
        if ($filename === '' && is_array($part) && isset($part['filename']) && trim((string) $part['filename']) !== '') {
            $filename = trim((string) $part['filename']);
        }
        if ($filename === '') {
            $filename = 'bijlage';
        }

        $bytes = '';
        if ($attachmentId !== '') {
            $att = gmailApiRequest('GET', 'users/me/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($attachmentId), $accessToken);
            if (empty($att['ok']) || !isset($att['data']['data']) || !is_string($att['data']['data'])) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Bijlage ophalen is mislukt.';
                exit;
            }
            $bytes = base64UrlDecode((string) $att['data']['data']);
        } else {
            $body = (is_array($part) && isset($part['body']) && is_array($part['body'])) ? $part['body'] : [];
            $data = isset($body['data']) && is_string($body['data']) ? (string) $body['data'] : '';
            $bytes = $data !== '' ? base64UrlDecode($data) : '';
        }
        if (!is_string($bytes) || $bytes === '') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Bijlage ophalen is mislukt.';
            exit;
        }

        // Veiligheidsregel: in de browser tonen mag alleen voor plaatjes.
        $inlineOk = $inline && (preg_match('/^image\//i', $mimeType) === 1);

        http_response_code(200);
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Content-Type: ' . $mimeType);
        $safe = preg_replace('/[^a-zA-Z0-9._\- ]+/', '_', (string) $filename);
        $safe = trim((string) $safe);
        if ($safe === '') {
            $safe = 'bijlage';
        }
        header('Content-Disposition: ' . ($inlineOk ? 'inline' : 'attachment') . '; filename="' . $safe . '"');
        header('Content-Length: ' . (string) strlen((string) $bytes));
        echo $bytes;
        exit;
    }

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
                            // Pas na echt versturen zetten we de Gmail conversatie op "gelezen".
                            gmailApiRequest('POST', 'users/me/threads/' . rawurlencode($threadId) . '/modify', $accessToken, [
                                'removeLabelIds' => ['UNREAD'],
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

    // Dit is de tekst die je typt in de zoekbalk.
    // Als dit leeg is, laten we gewoon alles zien.
    $zoekTerm = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
    if (strlen($zoekTerm) > 200) {
        $zoekTerm = substr($zoekTerm, 0, 200);
    }

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

        $html = '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . e($titel) . '</title><style>:root{--grid-main-cols:360px 1fr;--grid-settings-cols:260px 1fr;--list-max-h:calc(100vh - 220px);--thread-max-h:50vh;}@media (max-width: 900px){:root{--grid-main-cols:1fr;--grid-settings-cols:1fr;--list-max-h:260px;--thread-max-h:40vh;}body{padding:14px!important;}}</style></head><body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#e5e7eb; color:#111827; margin:0; padding:22px;">';
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
        // Als iemand het dashboard open laat, blijft hij hierdoor "af en toe" syncen.
        $cooldownSec = 15;
        $vorigeTrigger = isset($_SESSION['email_dashboard_worker_trigger_at']) ? (int) $_SESSION['email_dashboard_worker_trigger_at'] : 0;
        if ((time() - $vorigeTrigger) >= $cooldownSec) {
            $_SESSION['email_dashboard_worker_trigger_at'] = time();
            triggerEmailSyncWorkerInBackground($conn);
        }
    }

    // We halen de lijst uit de database (snel).
    // Als er een zoekterm is, filteren we op: klant e-mail, onderwerp en tekst.
    $conn && zorgEmailConceptenAliasKolommen($conn);
    $heeftOntvangenGmail = tabelHeeftKolom($conn, 'email_concepten', 'ontvangen_op_gmail');
    $params = [];
    $sql = "SELECT id, gmail_thread_id, klant_email, onderwerp, created_at, updated_at" . ($heeftOntvangenGmail ? ", ontvangen_op_gmail" : "") . "
        FROM email_concepten
        WHERE status = 'draft'";
    if ($zoekTerm !== '') {
        $sql .= " AND (
                klant_email LIKE :q1
                OR onderwerp LIKE :q2
                OR concept_tekst LIKE :q3
            )";
        $q = '%' . $zoekTerm . '%';
        $params[':q1'] = $q;
        $params[':q2'] = $q;
        $params[':q3'] = $q;
    }
    $sql .= " ORDER BY updated_at DESC LIMIT 300";
    $rows = [];
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        $rows = [];
        $meldingType = 'error';
        $melding = 'Zoeken is nu even niet gelukt.';
    }

    // Regels werken ook voor de bestaande lijst:
    // Als er een regel is met "Negeer deze e-mail", dan verbergen we die ook in het overzicht.
    $actieveRegels = [];
    try {
        $actieveRegels = haalActieveEmailRules($conn);
    } catch (Throwable) {
        $actieveRegels = [];
    }

    if (is_array($actieveRegels) && !empty($actieveRegels) && is_array($rows) && !empty($rows)) {
        $gefilterd = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $from = isset($r['klant_email']) ? (string) $r['klant_email'] : '';
            $subject = isset($r['onderwerp']) ? (string) $r['onderwerp'] : '';
            $res = verwerkEmailRulesVoorMail($actieveRegels, $from, $subject);
            if (!empty($res['ignore'])) {
                continue;
            }
            $gefilterd[] = $r;
        }
        $rows = $gefilterd;
    }

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
        } else {
            // Als er een "negeer" regel is, verbergen we dit concept ook als iemand de link direct opent.
            if (is_array($actieveRegels) && !empty($actieveRegels)) {
                $from = isset($concept['klant_email']) ? (string) $concept['klant_email'] : '';
                $subject = isset($concept['onderwerp']) ? (string) $concept['onderwerp'] : '';
                $res = verwerkEmailRulesVoorMail($actieveRegels, $from, $subject);
                if (!empty($res['ignore'])) {
                    $concept = null;
                    $meldingType = 'error';
                    $melding = 'Dit concept is verborgen door een regel.';
                    $id = 0;
                }
            }
        }
    }

    $lijstHtml = '<div style="background:#f3f4f6; border:1px solid #9ca3af; border-radius:14px; overflow:hidden;">';
    $lijstHtml .= '<div style="padding:12px 14px; border-bottom:1px solid #9ca3af;">';
    $lijstHtml .= '<div style="font-weight:800;">Openstaande Concepten (Lijst)</div>';
    $lijstHtml .= '<div style="margin-top:8px;">';
    $lijstHtml .= '<form method="get" action="/EmailDashboard.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0;">';
    if ($id > 0) {
        $lijstHtml .= '<input type="hidden" name="id" value="' . e((string) $id) . '">';
    }
    $lijstHtml .= '<input id="emailZoekbalk" type="text" name="q" value="' . e($zoekTerm) . '" placeholder="Zoek op e-mail, onderwerp, bestelnummer..." style="flex:1 1 220px; min-width:220px; box-sizing:border-box; border-radius:10px; border:1px solid #9ca3af; background:#ffffff; color:#111827; padding:10px 12px;">';
    $lijstHtml .= '<button type="submit" style="background:#60a5fa; border:1px solid #3b82f6; color:#111827; font-weight:800; padding:10px 14px; border-radius:10px; cursor:pointer;">Zoek</button>';
    $lijstHtml .= '<button type="button" onclick="(function(){var i=document.getElementById(\'emailZoekbalk\'); if(i){i.value=\'\';} var f=i && i.form ? i.form : null; if(f){f.submit();}})()" style="background:#e5e7eb; border:1px solid #9ca3af; color:#111827; font-weight:800; padding:10px 14px; border-radius:10px; cursor:pointer;">Wissen</button>';
    $lijstHtml .= '</form>';
    $lijstHtml .= '</div>';
    $lijstHtml .= '</div>';
    if (empty($rows)) {
        // We laten een korte melding zien als er niks gevonden is.
        if ($zoekTerm !== '') {
            $lijstHtml .= '<div style="padding:14px; color:#6b7280;">Geen concepten gevonden.</div>';
        } else {
            $lijstHtml .= '<div style="padding:14px; color:#6b7280;">Geen concepten gevonden.</div>';
        }
    } else {
        // Als er nog concepten zonder onderwerp zijn, halen we de onderwerpen op uit Gmail.
        // Zodra ze opgeslagen zijn, komt de lijst weer volledig uit de database.
        $onderwerpCache = [];
        $missendeThreads = [];
        foreach ($rows as $r) {
            $onderwerpDb = isset($r['onderwerp']) ? trim((string) $r['onderwerp']) : '';
            $threadIdDb = isset($r['gmail_thread_id']) ? (string) $r['gmail_thread_id'] : '';
            if ($onderwerpDb === '' && $threadIdDb !== '' && !isset($missendeThreads[$threadIdDb])) {
                $missendeThreads[$threadIdDb] = true;
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
            $url = '/EmailDashboard.php?id=' . urlencode((string) $r['id']);
            if ($zoekTerm !== '') {
                $url .= '&q=' . urlencode($zoekTerm);
            }
            $lijstHtml .= '<a href="' . e($url) . '" style="display:block; text-decoration:none; border:1px solid ' . $border . '; background:' . $bg . '; border-radius:12px; padding:10px 12px; margin-bottom:10px;">';
            $lijstHtml .= '<div style="font-weight:800; color:#111827;">' . e($titelLinks) . '</div>';
            $ontvangenGmail = $heeftOntvangenGmail && isset($r['ontvangen_op_gmail']) ? trim((string) $r['ontvangen_op_gmail']) : '';
            $laatste = $ontvangenGmail !== '' ? $ontvangenGmail : (isset($r['updated_at']) ? (string) $r['updated_at'] : (string) $r['created_at']);
            $label = $ontvangenGmail !== '' ? 'Ontvangen (Gmail)' : 'Laatste';
            $lijstHtml .= '<div style="margin-top:4px; color:#111827; font-size:13px;">' . e($label) . ': ' . e($laatste) . '</div>';
            $lijstHtml .= '<div style="margin-top:2px; color:#111827; font-size:13px;">Status: concept</div>';
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
        $threadHtml = '';
        $threadErrorHtml = '';
        $origineelOnderwerp = isset($concept['onderwerp']) ? trim((string) $concept['onderwerp']) : '';
        $token = haalGmailAccessTokenOp();
        if (empty($token['ok'])) {
            $errTekst = isset($token['error']) ? trim((string) $token['error']) : 'Gmail token ontbreekt.';
            $authUrl = isset($token['reauth_url']) ? trim((string) $token['reauth_url']) : '';
            if ($authUrl === '') {
                $authUrl = (string) (maakGoogleAuthUrl() ?? '');
            }
            if ($authUrl !== '') {
                $threadErrorHtml = '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between;"><div style="color:#6b7280;">' . e($errTekst) . '</div>' . maakGoogleKoppelKnopHtml($authUrl) . '</div>';
            } else {
                $threadErrorHtml = '<div style="color:#6b7280;">' . e($errTekst) . '</div>';
            }
        } else {
            $accessToken = (string) $token['access_token'];
            $threadId = (string) $concept['gmail_thread_id'];
            $thread = gmailApiRequest('GET', 'users/me/threads/' . rawurlencode($threadId), $accessToken, null, ['format' => 'full']);
            if (!empty($thread['ok']) && isset($thread['data']['messages']) && is_array($thread['data']['messages'])) {
                $messages = $thread['data']['messages'];
                $firstSubject = '';
                $ontvangenOpGmail = '';
                $lastMsg = end($messages);
                if (is_array($lastMsg)) {
                    $lastPayload = $lastMsg['payload'] ?? [];
                    $lastHeaders = (is_array($lastPayload) && isset($lastPayload['headers']) && is_array($lastPayload['headers'])) ? $lastPayload['headers'] : [];
                    $ontvangenOpGmail = formatteerGmailOntvangstTijdVoorDashboard($lastMsg, $lastHeaders);
                }
                foreach ($messages as $m) {
                    if (!is_array($m)) {
                        continue;
                    }
                    $payload = $m['payload'] ?? [];
                    $headers = (is_array($payload) && isset($payload['headers']) && is_array($payload['headers'])) ? $payload['headers'] : [];
                    $sub = haalHeaderOp($headers, 'Subject') ?? '';
                    if ($firstSubject === '' && is_string($sub) && trim($sub) !== '') {
                        $firstSubject = trim((string) $sub);
                    }
                }
                if ($firstSubject !== '') {
                    $origineelOnderwerp = $firstSubject;
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
                if ($ontvangenOpGmail !== '' && tabelHeeftKolom($conn, 'email_concepten', 'ontvangen_op_gmail')) {
                    try {
                        $upd = $conn->prepare("UPDATE email_concepten SET ontvangen_op_gmail = :d WHERE id = :id AND (ontvangen_op_gmail IS NULL OR ontvangen_op_gmail = '')");
                        $upd->execute([
                            ':d' => $ontvangenOpGmail,
                            ':id' => (int) $concept['id'],
                        ]);
                    } catch (Throwable) {
                    }
                }

                $blocks = [];
                foreach (array_reverse($messages) as $m) {
                    if (!is_array($m)) {
                        continue;
                    }
                    $payload = $m['payload'] ?? [];
                    $headers = (is_array($payload) && isset($payload['headers']) && is_array($payload['headers'])) ? $payload['headers'] : [];
                    $from = (string) (haalHeaderOp($headers, 'From') ?? '');
                    $date = (string) (haalHeaderOp($headers, 'Date') ?? '');

                    $messageId = isset($m['id']) ? trim((string) $m['id']) : '';

                    // US24: bijlages pas laden als je de mail opent (detail).
                    // We maken hier alleen links (en voor inline plaatjes een URL); de bytes komen via ?attachment=1.
                    $bijlageHtml = '';
                    $cidToUrl = [];
                    $rawHtml = haalHtmlUitPayload($payload);
                    $cidsInHtml = [];
                    if (is_string($rawHtml) && trim($rawHtml) !== '') {
                        if (preg_match_all('/cid:([^"\'>\s]+)/i', (string) $rawHtml, $cm) > 0) {
                            $found = $cm[1] ?? [];
                            if (is_array($found)) {
                                foreach ($found as $c) {
                                    $cidClean = normaliseerContentId((string) $c);
                                    if ($cidClean !== '') {
                                        $cidsInHtml[$cidClean] = true;
                                    }
                                }
                            }
                        }
                    } else {
                        $rawHtml = null;
                    }
                    if ($messageId !== '') {
                        $bijlages = haalBijlagesUitPayload($payload);
                        if (is_array($bijlages) && !empty($bijlages)) {
                            $btns = [];
                            foreach ($bijlages as $att) {
                                if (!is_array($att)) {
                                    continue;
                                }
                                $attId = isset($att['attachmentId']) ? trim((string) $att['attachmentId']) : '';
                                $fn = isset($att['filename']) ? trim((string) $att['filename']) : '';
                                $mt = isset($att['mimeType']) ? trim((string) $att['mimeType']) : '';
                                $sz = isset($att['size']) ? (int) $att['size'] : 0;
                                $cid = isset($att['contentId']) ? normaliseerContentId((string) $att['contentId']) : '';
                                $partPath = isset($att['partPath']) ? trim((string) $att['partPath']) : '';
                                $hasInlineData = !empty($att['hasInlineData']);

                                $label = $fn !== '' ? $fn : ($mt !== '' ? $mt : 'bijlage');
                                $szText = formatteerBestandsgrootte($sz);
                                if ($szText !== '') {
                                    $label .= ' (' . $szText . ')';
                                }

                                $isImage = ($mt !== '' && preg_match('/^image\//i', $mt) === 1);
                                if ($isImage) {
                                    $u = '';
                                    $dl = '';
                                    if ($attId !== '') {
                                        $u = emailDashboardAttachmentUrl($messageId, $attId, $fn, true);
                                        $dl = emailDashboardAttachmentUrl($messageId, $attId, $fn, false);
                                    } elseif ($hasInlineData && $partPath !== '') {
                                        $u = emailDashboardInlinePartUrl($messageId, $partPath, $fn, true);
                                        $dl = emailDashboardInlinePartUrl($messageId, $partPath, $fn, false);
                                    }
                                    if ($u === '') {
                                        continue;
                                    }
                                    $cidIsUsedInHtml = ($cid !== '' && isset($cidsInHtml[$cid]));
                                    if ($cidIsUsedInHtml) {
                                        // Afbeelding in de mail zelf gebruikt vaak cid:...
                                        $cidToUrl[$cid] = $u;
                                        // Dit plaatje laten we alleen in de mailtekst zien (geen downloadknop nodig).
                                        continue;
                                    }
                                    if ($cid !== '' && !isset($cidToUrl[$cid])) {
                                        $cidToUrl[$cid] = $u;
                                    }
                                    // Losse foto-bijlage: niet groot tonen (zoals Gmail). Alleen knoppen.
                                    if ($dl !== '') {
                                        $btns[] = '<a href="' . e($dl) . '" style="display:inline-flex; align-items:center; gap:8px; padding:8px 10px; border-radius:10px; border:1px solid #9ca3af; background:#e5e7eb; color:#111827; text-decoration:none; font-weight:800; font-size:12px;"><span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:6px; border:1px solid #9ca3af; background:#f3f4f6; font-weight:900;">IMG</span> ' . e($label) . '</a>';
                                    }
                                } else {
                                    if ($attId === '') {
                                        continue;
                                    }
                                    $u = emailDashboardAttachmentUrl($messageId, $attId, $fn, false);
                                    $btns[] = '<a href="' . e($u) . '" style="display:inline-flex; align-items:center; gap:8px; padding:8px 10px; border-radius:10px; border:1px solid #9ca3af; background:#e5e7eb; color:#111827; text-decoration:none; font-weight:800; font-size:12px;"><span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:6px; border:1px solid #9ca3af; background:#f3f4f6; font-weight:900;">DOC</span> ' . e($label) . '</a>';
                                }
                            }

                            if (!empty($btns)) {
                                $bijlageHtml = '<div style="background:#ffffff; border:1px dashed #e5e7eb; border-radius:12px; padding:10px 12px; margin-bottom:10px;">';
                                $bijlageHtml .= '<div style="font-weight:800; font-size:12px; margin-bottom:8px;">Bijlagen</div>';
                                if (!empty($btns)) {
                                    $bijlageHtml .= '<div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px;">' . implode('', $btns) . '</div>';
                                }
                                $bijlageHtml .= '</div>';
                            }
                        }
                    }

                    $bodyHtml = '';
                    if (is_string($rawHtml) && trim($rawHtml) !== '') {
                        // Eerst cid: plaatjes vervangen door onze eigen link, daarna pas HTML schoonmaken.
                        if (!empty($cidToUrl)) {
                            $rawHtml = vervangCidSrcInHtml($rawHtml, $cidToUrl);
                        }
                        $bodyHtml = sanitizeEmailHtmlVoorDashboard($rawHtml);
                    }

                    $text = zoekTekstPlainInPayload($payload);
                    if (!is_string($text) || $text === '') {
                        $text = zoekTekstHtmlInPayload($payload);
                    }
                    if (!is_string($text) || $text === '') {
                        $text = isset($m['snippet']) ? (string) $m['snippet'] : '';
                    }
                    $text = normaliseerTekst($text);

                    $headerLine = e($from !== '' ? $from : 'Onbekend');
                    $metaLine = $date !== '' ? e($date) : '';
                    $contentHtml = '';
                    if ($bodyHtml !== '') {
                        $contentHtml = '<div style="background:#ffffff; color:#111827; font-size:14px; line-height:1.45;">' . $bodyHtml . '</div>';
                    } elseif ($text !== '') {
                        $contentHtml = '<div style="white-space:pre-wrap;">' . e($text) . '</div>';
                    } else {
                        $contentHtml = '<div style="color:#6b7280;">Leeg bericht.</div>';
                    }

                    $b = '<div style="border:1px solid #e5e7eb; border-radius:12px; padding:10px 12px; margin-bottom:10px;">';
                    $b .= '<div style="font-weight:800; color:#111827;">' . $headerLine . '</div>';
                    if ($metaLine !== '') {
                        $b .= '<div style="color:#6b7280; font-size:12px; margin-top:2px;">' . $metaLine . '</div>';
                    }
                    $b .= '<div style="margin-top:10px;">' . $contentHtml . $bijlageHtml . '</div>';
                    $b .= '</div>';
                    $blocks[] = $b;
                }

                $threadHtml = implode('', $blocks);
            } else {
                $errTekst = isset($thread['error']) ? trim((string) $thread['error']) : 'Thread ophalen is niet gelukt.';
                $authUrl = (string) (maakGoogleAuthUrl() ?? '');
                if ($authUrl !== '') {
                    $threadErrorHtml = '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between;"><div style="color:#6b7280;">' . e($errTekst) . '</div>' . maakGoogleKoppelKnopHtml($authUrl) . '</div>';
                } else {
                    $threadErrorHtml = '<div style="color:#6b7280;">' . e($errTekst) . '</div>';
                }
            }
        }

        $kop = $origineelOnderwerp !== '' ? ('Geselecteerd Concept: ' . $origineelOnderwerp) : ('Geselecteerd Concept #' . (string) $concept['id']);
        $detailHtml .= '<div style="font-weight:800; margin-bottom:10px;">' . e($kop) . '</div>';

        $detailHtml .= '<div style="border:1px solid #9ca3af; background:#ffffff; border-radius:12px; padding:10px 12px; margin-bottom:12px;">';
        $detailHtml .= '<div style="font-weight:800; margin-bottom:6px;">Gespreksgeschiedenis:</div>';
        $detailHtml .= '<div style="max-height: var(--thread-max-h); overflow-y:auto; padding-right:10px; -webkit-overflow-scrolling:touch;">';
        if (is_string($threadHtml) && $threadHtml !== '') {
            $detailHtml .= $threadHtml;
        } elseif (is_string($threadErrorHtml) && $threadErrorHtml !== '') {
            $detailHtml .= $threadErrorHtml;
        } else {
            $detailHtml .= '<div style="color:#6b7280;">Niet beschikbaar. OAuth/token of thread ophalen is nog niet gelukt.</div>';
        }
        $detailHtml .= '</div>';
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
}
