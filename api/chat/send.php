<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';

header('Content-Type: application/json; charset=utf-8');

function stuurJsonResponse($httpStatus, $data)
{
    http_response_code($httpStatus);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    stuurJsonResponse(405, [
        'status' => 'error',
        'message' => 'Alleen POST is toegestaan.',
    ]);
}

// We lezen de ruwe request body zodat alleen JSON wordt geaccepteerd.
$rawInput = file_get_contents('php://input');

if ($rawInput === false || trim($rawInput) === '') {
    stuurJsonResponse(400, [
        'status' => 'error',
        'message' => 'De request bevat geen JSON-data.',
    ]);
}

$payload = json_decode($rawInput, true);

if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
    stuurJsonResponse(400, [
        'status' => 'error',
        'message' => 'De JSON-data is ongeldig.',
    ]);
}

$cookie = isset($payload['cookie']) ? trim((string) $payload['cookie']) : '';
$userMessage = isset($payload['user_message']) ? trim((string) $payload['user_message']) : '';

if ($cookie === '' || $userMessage === '') {
    stuurJsonResponse(422, [
        'status' => 'error',
        'message' => 'cookie en user_message zijn verplicht.',
    ]);
}

try {
    $sql = "INSERT INTO chat_queue (cookie, user_message) VALUES (:cookie, :user_message)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':cookie' => $cookie,
        ':user_message' => $userMessage,
    ]);

    stuurJsonResponse(201, [
        'status' => 'succes',
        'bericht_id' => (int) $conn->lastInsertId(),
    ]);
} catch (PDOException $e) {
    stuurJsonResponse(500, [
        'status' => 'error',
        'message' => 'Opslaan in de wachtrij is mislukt.',
    ]);
}
