<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';

// Dit bestand wordt door de frontend gebruikt om te vragen:
// "Is het antwoord al klaar?"
header('Content-Type: application/json; charset=utf-8');

function stuurJsonResponse($httpStatus, $data)
{
    http_response_code($httpStatus);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Dit endpoint mag alleen gelezen worden.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    stuurJsonResponse(405, [
        'status' => 'error',
        'message' => 'Alleen GET is toegestaan.',
    ]);
}

$berichtId = isset($_GET['bericht_id']) ? (int) $_GET['bericht_id'] : 0;

// Zonder bericht-id weten we niet welk antwoord we moeten controleren.
if ($berichtId <= 0) {
    stuurJsonResponse(422, [
        'status' => 'error',
        'message' => 'bericht_id is verplicht.',
    ]);
}

try {
    // We halen alleen de status en het eventuele antwoord op.
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

    // Als het bericht niet bestaat, kan de frontend daar ook niet op wachten.
    if (!$bericht) {
        stuurJsonResponse(404, [
            'status' => 'error',
            'message' => 'Bericht niet gevonden.',
        ]);
    }

    // Dit krijgt de frontend terug om te bepalen:
    // wachten, antwoord tonen of foutmelding tonen.
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
