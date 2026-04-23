<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';

// We geven altijd JSON terug, zodat frontend of Postman dit netjes kan lezen.
header('Content-Type: application/json; charset=utf-8');

function stuurJsonResponse($httpStatus, $data)
{
    http_response_code($httpStatus);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function triggerWorkerOpAchtergrond($berichtId)
{
    $host = $_SERVER['SERVER_NAME'] ?? 'www.marioswitch1.nl';
    $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string) $host);
    if (!is_string($host) || $host === '') {
        $host = 'www.marioswitch1.nl';
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $poort = $isHttps ? 443 : 80;
    $socketHost = ($isHttps ? 'ssl://' : '') . $host;
    $body = http_build_query(['message_id' => $berichtId]);

    // We openen alleen kort een verbinding, sturen het signaal en wachten niet op antwoord.
    $socket = @fsockopen($socketHost, $poort, $errorCode, $errorMessage, 1);

    if ($socket === false) {
        return false;
    }

    $request = "POST /api/chat/worker HTTP/1.1\r\n";
    $request .= "Host: " . $host . "\r\n";
    $request .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $request .= "Content-Length: " . strlen($body) . "\r\n";
    $request .= "Connection: Close\r\n\r\n";
    $request .= $body;

    fwrite($socket, $request);
    fclose($socket);

    return true;
}

// Dit endpoint is alleen bedoeld om een nieuw chatbericht op te slaan.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    stuurJsonResponse(405, [
        'status' => 'error',
        'message' => 'Alleen POST is toegestaan.',
    ]);
}

// We lezen de data uit de aanvraag.
// Hier verwachten we JSON in, dus niet gewone form-data.
$rawInput = file_get_contents('php://input');

if ($rawInput === false || trim($rawInput) === '') {
    stuurJsonResponse(400, [
        'status' => 'error',
        'message' => 'De request bevat geen JSON-data.',
    ]);
}

// We zetten de JSON om naar een gewone PHP-array.
$payload = json_decode($rawInput, true);

if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
    stuurJsonResponse(400, [
        'status' => 'error',
        'message' => 'De JSON-data is ongeldig.',
    ]);
}

// We halen alleen de twee velden op die we nodig hebben.
$cookie = isset($payload['cookie']) ? trim((string) $payload['cookie']) : '';
$userMessage = isset($payload['user_message']) ? trim((string) $payload['user_message']) : '';

// Zonder cookie of bericht kunnen we niets opslaan.
if ($cookie === '' || $userMessage === '') {
    stuurJsonResponse(422, [
        'status' => 'error',
        'message' => 'cookie en user_message zijn verplicht.',
    ]);
}

try {
    // We slaan het bericht alleen op in de wachtrij.
    // OpenAI wordt hier dus nog niet aangeroepen.
    $sql = "INSERT INTO chat_queue (cookie, user_message) VALUES (:cookie, :user_message)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':cookie' => $cookie,
        ':user_message' => $userMessage,
    ]);

    $berichtId = (int) $conn->lastInsertId();

    // Dit start de worker op de achtergrond.
    // Als dit mislukt, blijft het endpoint wel gewoon een response geven.
    triggerWorkerOpAchtergrond($berichtId);

    // We sturen direct terug dat het opslaan gelukt is.
    // bericht_id is handig om later verder mee te werken.
    stuurJsonResponse(201, [
        'status' => 'succes',
        'bericht_id' => $berichtId,
    ]);
} catch (PDOException $e) {
    // We tonen expres geen technische databasefout aan de buitenkant.
    stuurJsonResponse(500, [
        'status' => 'error',
        'message' => 'Opslaan in de wachtrij is mislukt.',
    ]);
}
