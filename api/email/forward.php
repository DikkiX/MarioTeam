<?php
// Dit endpoint stuurt een concept-mail door naar een intern adres.
// Het gebruikt dezelfde Gmail API-koppeling als EmailDashboard en zet daarna de status van het concept op "doorgestuurd".
// Beveiliging: alleen toegestaan als iemand is ingelogd in het dashboard + geldig CSRF token.
if ((string) ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Alleen POST is toegestaan.');
}

// We gebruiken pad-trucjes zodat dit endpoint ook werkt als DOCUMENT_ROOT niet netjes staat (bijv. lokaal).
$projectRoot = dirname(__DIR__, 2);
if (!isset($_SERVER['DOCUMENT_ROOT']) || !is_string($_SERVER['DOCUMENT_ROOT']) || trim((string) $_SERVER['DOCUMENT_ROOT']) === '') {
    $_SERVER['DOCUMENT_ROOT'] = $projectRoot;
}

// Session is nodig voor:
// - login-check (email_dashboard_authed)
// - CSRF token check
// - flash melding na redirect
session_start();

// CSRF check: alleen requests die via het dashboard-form komen zijn toegestaan.
$token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
if (!isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $token)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('CSRF token klopt niet.');
}

// Alleen ingelogde medewerkers mogen dit gebruiken.
if (empty($_SESSION['email_dashboard_authed'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Niet ingelogd.');
}

// We laden EmailDashboard in "lib mode":
// - dan worden er geen headers/HTML gestuurd
// - en worden DB/session/login stukken niet opnieuw uitgevoerd
// We hergebruiken puur de Gmail helper-functies die daar al in staan.
if (!defined('EMAIL_DASHBOARD_LIB_ONLY')) {
    define('EMAIL_DASHBOARD_LIB_ONLY', true);
}
require_once $projectRoot . '/EmailDashboard.php';

// DB connectie is nodig om:
// - concept op te halen (thread id + status)
// - status te updaten na succesvolle forward
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';
$conn = $conn ?? null;
if (!($conn instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Database verbinding ontbreekt.');
}
assert($conn instanceof PDO);

$conceptId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$forwardTo = isset($_POST['forward_to']) ? trim((string) $_POST['forward_to']) : '';
$note = isset($_POST['forward_note']) ? trim((string) $_POST['forward_note']) : '';

// Input validatie.
if ($conceptId <= 0) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    exit('id is verplicht.');
}
if ($forwardTo === '' || !filter_var($forwardTo, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    exit('forward_to moet een geldig e-mailadres zijn.');
}

try {
    // Concept ophalen uit de database.
    $stmt = $conn->prepare("
        SELECT id, gmail_thread_id, klant_email, onderwerp, ontvangen_op_email, afzender_alias_email, status
        FROM email_concepten
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $conceptId]);
    $concept = $stmt->fetch();
    if (!$concept || !is_array($concept)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Concept niet gevonden.');
    }
} catch (Throwable) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Concept ophalen is mislukt.');
}

// Alleen draft concepten kunnen doorgestuurd worden (anders blijft status verwarrend).
if ((string) ($concept['status'] ?? '') !== 'draft') {
    http_response_code(409);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Dit concept kan niet doorgestuurd worden.');
}

$threadId = isset($concept['gmail_thread_id']) ? trim((string) $concept['gmail_thread_id']) : '';
if ($threadId === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Thread id ontbreekt.');
}

// OAuth token ophalen (gedeelde mailbox koppeling).
$token = haalGmailAccessTokenOp();
if (empty($token['ok'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Gmail is niet gekoppeld.');
}
$accessToken = (string) $token['access_token'];

// We halen de originele mail op (laatste bericht in de thread) en gebruiken die als "forward" inhoud.
$thread = gmailApiRequest('GET', 'users/me/threads/' . rawurlencode($threadId), $accessToken, null, ['format' => 'full']);
if (empty($thread['ok']) || !isset($thread['data']['messages']) || !is_array($thread['data']['messages'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Originele mail ophalen is mislukt.');
}

$messages = $thread['data']['messages'];
$last = end($messages);
$payload = (is_array($last) && isset($last['payload']) && is_array($last['payload'])) ? $last['payload'] : [];
$headers = (isset($payload['headers']) && is_array($payload['headers'])) ? $payload['headers'] : [];

// Basis-info van het originele bericht (wordt in de forward-tekst gezet).
$origFrom = (string) (haalHeaderOp($headers, 'From') ?? '');
$origDate = (string) (haalHeaderOp($headers, 'Date') ?? '');
$origSubject = (string) (haalHeaderOp($headers, 'Subject') ?? '');

// Originele tekst: liever text/plain, anders HTML omgezet naar tekst.
$origText = zoekTekstPlainInPayload($payload);
if (!is_string($origText) || trim((string) $origText) === '') {
    $origText = zoekTekstHtmlInPayload($payload);
}
if (!is_string($origText) || trim((string) $origText) === '') {
    $origText = '(Geen tekst gevonden in de mail.)';
}

$subject = $origSubject !== '' ? $origSubject : (string) ($concept['onderwerp'] ?? 'Klantenservice');
$subject = preg_match('/^Fwd:/i', $subject) === 1 ? $subject : ('Fwd: ' . $subject);

// Bericht opbouwen:
// - bovenaan interne notitie (optioneel)
// - daarna een simpele "forward" header block
// - daarna de originele tekst
$body = '';
if ($note !== '') {
    $body .= $note . "\n\n";
}
$body .= "----\n";
$body .= "Doorgestuurd bericht\n";
if ($origFrom !== '') {
    $body .= 'Van: ' . $origFrom . "\n";
}
if ($origDate !== '') {
    $body .= 'Datum: ' . $origDate . "\n";
}
if ($origSubject !== '') {
    $body .= 'Onderwerp: ' . $origSubject . "\n";
}
$body .= "\n" . (string) $origText;

// Bijlagen en inline-plaatjes meesturen (US24/US27).
$messageId = (is_array($last) && isset($last['id'])) ? trim((string) $last['id']) : '';
$bijlageData = ['attachments' => [], 'mislukt' => []];
if ($messageId !== '') {
    $bijlageData = verzamelBijlagenVoorForward($accessToken, $messageId, $payload);
}
if (!empty($bijlageData['mislukt']) && is_array($bijlageData['mislukt'])) {
    $body .= "\n\n(Let op: deze bijlagen konden niet worden meegestuurd: " . implode(', ', $bijlageData['mislukt']) . ')';
}
if (!empty($bijlageData['attachments']) && is_array($bijlageData['attachments'])) {
    $namen = [];
    foreach ($bijlageData['attachments'] as $a) {
        if (is_array($a) && isset($a['filename'])) {
            $namen[] = (string) $a['filename'];
        }
    }
    if (!empty($namen)) {
        $body .= "\n\nMeegestuurde bijlagen: " . implode(', ', $namen);
    }
}

// Afzender header bepalen (zelfde send-as logica als normale send).
$ontvangenOp = isset($concept['ontvangen_op_email']) ? (string) $concept['ontvangen_op_email'] : '';
$conceptAlias = isset($concept['afzender_alias_email']) ? (string) $concept['afzender_alias_email'] : '';
$gekozenAlias = bepaalAfzenderAliasVoorOntvanger($conn, $conceptAlias !== '' ? $conceptAlias : $ontvangenOp);
$fromHeader = bouwFromHeaderVoorAlias($conn, $gekozenAlias);

// Gmail send: we sturen een nieuw bericht naar het interne adres.
// Daardoor verschijnt dit netjes in "Verzonden items" van de mailbox.
$attachments = isset($bijlageData['attachments']) && is_array($bijlageData['attachments']) ? $bijlageData['attachments'] : [];
$raw = !empty($attachments)
    ? bouwRfc2822BerichtMetBijlages($forwardTo, $subject, $body, $attachments, null, null, $fromHeader)
    : bouwRfc2822Bericht($forwardTo, $subject, $body, null, null, $fromHeader);
$send = gmailApiRequest('POST', 'users/me/messages/send', $accessToken, [
    'raw' => $raw,
]);

if (empty($send['ok'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    $err = isset($send['error']) ? (string) $send['error'] : 'Doorsturen via Gmail API is mislukt.';
    exit($err);
}

zorgEmailConceptenStatusDoorgestuurd($conn);

$statusOk = false;
try {
    // Na succesvolle forward zetten we de status zodat het team ziet dat dit is opgepakt.
    $upd = $conn->prepare("UPDATE email_concepten SET status = 'doorgestuurd' WHERE id = :id AND status = 'draft'");
    $upd->execute([':id' => $conceptId]);
    $statusOk = $upd->rowCount() > 0;
} catch (Throwable) {
    $statusOk = false;
}

// Mail is al verstuurd: altijd terug naar dashboard (nooit kale foutpagina).
if ($statusOk) {
    $_SESSION['email_dashboard_flash'] = [
        'type' => 'ok',
        'melding' => 'Mail is doorgestuurd en status is op doorgestuurd gezet.',
    ];
} else {
    $_SESSION['email_dashboard_flash'] = [
        'type' => 'error',
        'melding' => 'Mail is doorgestuurd, maar de status kon niet worden bijgewerkt. Vernieuw de pagina of neem contact op met beheer.',
    ];
}

header('Location: /EmailDashboard.php', true, 303);
exit;
