<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/env.php';

session_start();

function stuurHtml($httpStatus, $html)
{
    http_response_code($httpStatus);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
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

function vereisDashboardLogin()
{
    $user = getProjectEnvValue('EMAIL_DASHBOARD_USER');
    $pass = getProjectEnvValue('EMAIL_DASHBOARD_PASS');

    if ($user === null || $pass === null) {
        stuurHtml(500, '<h1>Configuratie ontbreekt</h1><p>EMAIL_DASHBOARD_USER en EMAIL_DASHBOARD_PASS ontbreken in .env.</p>');
    }

    $gegeven = haalBasicAuthUitHeaders();
    $gegevenUser = is_array($gegeven) ? ($gegeven['user'] ?? null) : null;
    $gegevenPass = is_array($gegeven) ? ($gegeven['pass'] ?? null) : null;

    $isOk = is_string($gegevenUser)
        && is_string($gegevenPass)
        && hash_equals((string) $user, (string) $gegevenUser)
        && hash_equals((string) $pass, (string) $gegevenPass);

    if (!$isOk) {
        header('WWW-Authenticate: Basic realm="Email Dashboard"');
        stuurHtml(401, '<h1>Niet ingelogd</h1><p>Je hebt geen toegang tot dit dashboard.</p>');
    }
}

function csrfToken()
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function vereisCsrf()
{
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    if (!isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $token)) {
        stuurHtml(400, '<h1>Ongeldige aanvraag</h1><p>CSRF token klopt niet.</p>');
    }
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

function gmailApiRequest($method, $path, $accessToken, $body = null, $query = [])
{
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

function bouwRfc2822Bericht($toEmail, $subject, $bodyText, $inReplyTo = null, $references = null)
{
    $headers = [];
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

vereisDashboardLogin();

$melding = null;
$meldingType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereisCsrf();

    $actie = isset($_POST['actie']) ? (string) $_POST['actie'] : '';
    if ($actie === 'send') {
        $conceptId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $nieuweTekst = isset($_POST['concept_tekst']) ? trim((string) $_POST['concept_tekst']) : '';

        if ($conceptId <= 0 || $nieuweTekst === '') {
            $meldingType = 'error';
            $melding = 'id en concept_tekst zijn verplicht.';
        } else {
            $stmt = $conn->prepare("SELECT id, gmail_thread_id, klant_email, concept_tekst, status FROM email_concepten WHERE id = :id LIMIT 1");
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
                    $melding = isset($token['error']) ? (string) $token['error'] : 'Geen Gmail token.';
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

                    $raw = bouwRfc2822Bericht($toEmail, $subject, $nieuweTekst, $inReplyTo, $references);
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
                        $meldingType = 'ok';
                        $melding = 'Concept is verstuurd en op sent gezet.';
                    }
                }
            }
        }
    }
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$csrf = csrfToken();

function renderLayout($titel, $contentHtml, $melding, $meldingType)
{
    $msgHtml = '';
    if (is_string($melding) && $melding !== '') {
        $bg = $meldingType === 'error' ? '#fee2e2' : '#dcfce7';
        $bd = $meldingType === 'error' ? '#ef4444' : '#22c55e';
        $msgHtml = '<div style="background:' . $bg . '; border:1px solid ' . $bd . '; padding:10px 12px; border-radius:10px; margin-bottom:12px;">' . e($melding) . '</div>';
    }

    $html = '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($titel) . '</title></head><body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#0b1220; color:#e5e7eb; margin:0; padding:20px;">';
    $html .= '<div style="max-width: 1100px; margin:0 auto;">';
    $html .= '<div style="display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:16px;"><h1 style="margin:0; font-size:22px;">' . e($titel) . '</h1><a href="/EmailDashboard.php" style="color:#93c5fd; text-decoration:none;">Overzicht</a></div>';
    $html .= $msgHtml;
    $html .= $contentHtml;
    $html .= '</div></body></html>';
    return $html;
}

if ($id <= 0) {
    $stmt = $conn->prepare("SELECT id, gmail_thread_id, klant_email, created_at FROM email_concepten WHERE status = 'draft' ORDER BY created_at ASC");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $table = '<div style="background:#0f172a; border:1px solid #1f2937; border-radius:14px; overflow:hidden;">';
    $table .= '<div style="padding:14px 16px; border-bottom:1px solid #1f2937; color:#cbd5e1;">Openstaande concepten (draft)</div>';
    if (empty($rows)) {
        $table .= '<div style="padding:16px; color:#9ca3af;">Geen draft concepten gevonden.</div>';
    } else {
        $table .= '<table style="width:100%; border-collapse:collapse;">';
        $table .= '<thead><tr style="text-align:left; color:#cbd5e1; background:#0b1220;"><th style="padding:10px 12px;">ID</th><th style="padding:10px 12px;">Klant</th><th style="padding:10px 12px;">Thread</th><th style="padding:10px 12px;">Aangemaakt</th><th style="padding:10px 12px;"></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $table .= '<tr style="border-top:1px solid #1f2937;">';
            $table .= '<td style="padding:10px 12px; color:#e5e7eb;">' . e($r['id']) . '</td>';
            $table .= '<td style="padding:10px 12px; color:#e5e7eb;">' . e($r['klant_email']) . '</td>';
            $table .= '<td style="padding:10px 12px; color:#9ca3af;">' . e($r['gmail_thread_id']) . '</td>';
            $table .= '<td style="padding:10px 12px; color:#9ca3af;">' . e($r['created_at']) . '</td>';
            $table .= '<td style="padding:10px 12px;"><a href="/EmailDashboard.php?id=' . urlencode((string) $r['id']) . '" style="color:#93c5fd; text-decoration:none;">Open</a></td>';
            $table .= '</tr>';
        }
        $table .= '</tbody></table>';
    }
    $table .= '</div>';

    stuurHtml(200, renderLayout('Email dashboard', $table, $melding, $meldingType));
}

$stmt = $conn->prepare("SELECT id, gmail_thread_id, klant_email, concept_tekst, status, created_at FROM email_concepten WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$concept = $stmt->fetch();

if (!$concept) {
    stuurHtml(404, renderLayout('Email dashboard', '<p>Concept niet gevonden.</p>', $melding, $meldingType));
}

$origineelTekst = null;
$origineelMeta = null;
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
            $origineelMeta = trim('Subject: ' . ($sub ?? '') . "\nFrom: " . ($from ?? '') . "\nDate: " . ($date ?? ''));
            $text = zoekTekstPlainInPayload($gevonden['payload'] ?? []);
            if (!is_string($text) || $text === '') {
                $text = isset($gevonden['snippet']) ? (string) $gevonden['snippet'] : '';
            }
            $origineelTekst = normaliseerTekst($text);
        }
    }
}

$kolommen = '<div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">';
$kolommen .= '<div style="background:#0f172a; border:1px solid #1f2937; border-radius:14px; padding:14px 16px; min-height:220px;">';
$kolommen .= '<div style="color:#cbd5e1; margin-bottom:10px;">Originele klantmail</div>';
if (is_string($origineelTekst) && $origineelTekst !== '') {
    $meta = is_string($origineelMeta) && $origineelMeta !== '' ? ($origineelMeta . "\n\n") : '';
    $kolommen .= '<pre style="white-space:pre-wrap; margin:0; color:#e5e7eb;">' . e($meta . $origineelTekst) . '</pre>';
} else {
    $kolommen .= '<div style="color:#9ca3af;">Niet beschikbaar. OAuth/token of thread ophalen is nog niet gelukt.</div>';
}
$kolommen .= '</div>';

$kolommen .= '<div style="background:#0f172a; border:1px solid #1f2937; border-radius:14px; padding:14px 16px;">';
$kolommen .= '<div style="color:#cbd5e1; margin-bottom:10px;">AI concept (aanpasbaar)</div>';
$kolommen .= '<form method="post" action="/EmailDashboard.php?id=' . urlencode((string) $concept['id']) . '">';
$kolommen .= '<input type="hidden" name="csrf" value="' . e($csrf) . '">';
$kolommen .= '<input type="hidden" name="actie" value="send">';
$kolommen .= '<input type="hidden" name="id" value="' . e($concept['id']) . '">';
$kolommen .= '<textarea name="concept_tekst" rows="14" style="width:100%; box-sizing:border-box; border-radius:10px; border:1px solid #334155; background:#0b1220; color:#e5e7eb; padding:10px 12px; resize:vertical;">' . e((string) $concept['concept_tekst']) . '</textarea>';
$kolommen .= '<div style="display:flex; gap:10px; align-items:center; justify-content:space-between; margin-top:10px;">';
$kolommen .= '<div style="color:#9ca3af; font-size:13px;">Status: ' . e((string) $concept['status']) . '</div>';
$disabled = ((string) $concept['status'] !== 'draft') ? 'disabled' : '';
$btnStyle = 'background:#22c55e; border:none; color:#052e16; font-weight:700; padding:10px 14px; border-radius:10px; cursor:pointer;';
$btnStyleDisabled = 'background:#334155; color:#94a3b8; cursor:not-allowed;';
$kolommen .= '<button type="submit" ' . $disabled . ' style="' . ($disabled ? $btnStyleDisabled : $btnStyle) . '">Versturen</button>';
$kolommen .= '</div>';
$kolommen .= '</form>';
$kolommen .= '</div>';
$kolommen .= '</div>';

$info = '<div style="margin-bottom:12px; color:#cbd5e1;">';
$info .= '<div style="color:#9ca3af; font-size:13px;">Concept ID: ' . e($concept['id']) . ' | Klant: ' . e($concept['klant_email']) . ' | Thread: ' . e($concept['gmail_thread_id']) . '</div>';
$info .= '</div>';

$content = $info . $kolommen;
stuurHtml(200, renderLayout('Email dashboard', $content, $melding, $meldingType));
