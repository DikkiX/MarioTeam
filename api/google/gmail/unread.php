<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/env.php';

session_start();

// Dit is een beveiligde testpagina om te bewijzen dat Gmail API werkt.
// Het haalt ongelezen mails op en laat zien:
// - afzender (From)
// - thread id
// - tekst (zover mogelijk plain-text)

function stuurHtml($httpStatus, $titel, $bodyHtml)
{
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

function haalBasicAuthUitHeaders()
{
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    if (is_string($user) && is_string($pass)) {
        return ['user' => $user, 'pass' => $pass];
    }

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['Authorization'] ?? null);
    if (!is_string($auth) && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $auth = $headers['Authorization'] ?? ($headers['authorization'] ?? null);
        }
    }

    if (!is_string($auth) || $auth === '') {
        return null;
    }

    if (stripos($auth, 'Basic ') !== 0) {
        return null;
    }

    $encoded = trim(substr($auth, 6));
    if ($encoded === '') {
        return null;
    }

    $decoded = base64_decode($encoded, true);
    if ($decoded === false || strpos($decoded, ':') === false) {
        return null;
    }

    [$u, $p] = explode(':', $decoded, 2);
    return ['user' => $u, 'pass' => $p];
}

function vereisLogin()
{
    $user = getProjectEnvValue('EMAIL_DASHBOARD_USER');
    $pass = getProjectEnvValue('EMAIL_DASHBOARD_PASS');

    if ($user === null || $pass === null) {
        stuurHtml(500, 'Configuratie ontbreekt', '<p>EMAIL_DASHBOARD_USER en EMAIL_DASHBOARD_PASS ontbreken in .env.</p>');
    }

    $gegeven = haalBasicAuthUitHeaders();
    $gegevenUser = is_array($gegeven) ? ($gegeven['user'] ?? null) : null;
    $gegevenPass = is_array($gegeven) ? ($gegeven['pass'] ?? null) : null;

    $isOk = is_string($gegevenUser)
        && is_string($gegevenPass)
        && hash_equals((string) $user, (string) $gegevenUser)
        && hash_equals((string) $pass, (string) $gegevenPass);

    if (!$isOk) {
        header('WWW-Authenticate: Basic realm="Gmail API"');
        stuurHtml(401, 'Niet ingelogd', '<p>Je hebt geen toegang tot dit endpoint.</p>');
    }
}

function base64UrlDecode($data)
{
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

function normaliseerTekst($text)
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim((string) $text);
}

function leesTokenBestandVoorHost($host)
{
    $storageDir = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') . '/storage/google';
    $safeHost = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $host);
    $filePath = $storageDir . '/oauth_token_' . $safeHost . '.json';
    if (is_file($filePath)) {
        return $filePath;
    }

    if (substr((string) $host, 0, 4) === 'www.') {
        $without = substr($host, 4);
        $safe2 = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $without);
        $file2 = $storageDir . '/oauth_token_' . $safe2 . '.json';
        if (is_file($file2)) {
            return $file2;
        }
    } else {
        $with = 'www.' . $host;
        $safe2 = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $with);
        $file2 = $storageDir . '/oauth_token_' . $safe2 . '.json';
        if (is_file($file2)) {
            return $file2;
        }
    }

    return null;
}

function laadTokenPayload($tokenFilePath)
{
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
        return ['ok' => false, 'error' => 'Geen tokenbestand gevonden. Doe eerst OAuth via /api/google/oauth/callback.'];
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

vereisLogin();

$max = isset($_GET['max']) ? (int) $_GET['max'] : 5;
if ($max <= 0) {
    $max = 5;
}
if ($max > 20) {
    $max = 20;
}

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

$itemsHtml = '';
foreach ($messages as $m) {
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

    $text = zoekTekstPlainInPayload($payload);
    if (!is_string($text) || $text === '') {
        $text = isset($data['snippet']) ? (string) $data['snippet'] : '';
    }
    $text = normaliseerTekst($text);

    $itemsHtml .= '<div style="border:1px solid #ddd; border-radius:10px; padding:12px; margin:12px 0;">';
    $itemsHtml .= '<div><b>From:</b> ' . e($from) . '</div>';
    $itemsHtml .= '<div><b>Subject:</b> ' . e($subject) . '</div>';
    $itemsHtml .= '<div><b>Thread ID:</b> ' . e($threadId) . '</div>';
    $itemsHtml .= '<div style="margin-top:10px;"><b>Tekst:</b></div>';
    $itemsHtml .= '<pre style="white-space:pre-wrap; margin:6px 0 0;">' . e($text) . '</pre>';
    $itemsHtml .= '</div>';
}

stuurHtml(200, 'Gmail API test', '<p>Dit zijn de nieuwste ongelezen mails (max ' . e($max) . ').</p>' . $itemsHtml);
