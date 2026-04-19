<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';

// Dit endpoint geeft de huidige status van 1 queue-bericht terug.
header('Content-Type: application/json; charset=utf-8');

function stuurJsonResponse($httpStatus, $data)
{
    http_response_code($httpStatus);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    stuurJsonResponse(405, [
        'status' => 'error',
        'message' => 'Alleen GET is toegestaan.',
    ]);
}

$berichtId = isset($_GET['bericht_id']) ? (int) $_GET['bericht_id'] : 0;

if ($berichtId <= 0) {
    stuurJsonResponse(422, [
        'status' => 'error',
        'message' => 'bericht_id is verplicht.',
    ]);
}

try {
    $stmt = $conn->prepare("
        SELECT id, status, ai_response
        FROM chat_queue
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $berichtId,
    ]);
    $bericht = $stmt->fetch();

    if (!$bericht) {
        stuurJsonResponse(404, [
            'status' => 'error',
            'message' => 'Bericht niet gevonden.',
        ]);
    }

    stuurJsonResponse(200, [
        'status' => 'succes',
        'bericht' => [
            'id' => (int) $bericht['id'],
            'queue_status' => $bericht['status'],
            'ai_response' => $bericht['ai_response'],
        ],
    ]);
} catch (PDOException $e) {
    stuurJsonResponse(500, [
        'status' => 'error',
        'message' => 'Status ophalen is mislukt.',
    ]);
}
