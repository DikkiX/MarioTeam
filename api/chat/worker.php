<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';

ignore_user_abort(true);
set_time_limit(0);

function schrijfWorkerLog($message)
{
    $logMap = $_SERVER['DOCUMENT_ROOT'] . '/storage/logs';
    $logBestand = $logMap . '/chat_worker.log';

    if (!is_dir($logMap)) {
        mkdir($logMap, 0775, true);
    }

    $regel = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logBestand, $regel, FILE_APPEND);
}

// De worker mag via POST door de trigger starten.
// GET mag ook, zodat je hem makkelijk handmatig kunt testen.
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) {
    http_response_code(405);
    exit('Alleen GET en POST zijn toegestaan.');
}

try {
    $conn->beginTransaction();

    // We pakken altijd het oudste bericht dat nog op pending staat.
    $selectSql = "
        SELECT id, cookie, user_message, status, created_at
        FROM chat_queue
        WHERE status = :status
        ORDER BY created_at ASC, id ASC
        LIMIT 1
        FOR UPDATE
    ";
    $selectStmt = $conn->prepare($selectSql);
    $selectStmt->execute([
        ':status' => 'pending',
    ]);
    $bericht = $selectStmt->fetch();

    // Als er niets meer in de wachtrij staat, stoppen we meteen netjes.
    if (!$bericht) {
        $conn->commit();
        schrijfWorkerLog('Geen pending berichten gevonden.');
        echo 'Geen pending berichten gevonden.';
        exit;
    }

    // Meteen op processing zetten voorkomt dubbele verwerking.
    $updateSql = "
        UPDATE chat_queue
        SET status = :nieuwe_status
        WHERE id = :id AND status = :oude_status
    ";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([
        ':nieuwe_status' => 'processing',
        ':id' => (int) $bericht['id'],
        ':oude_status' => 'pending',
    ]);

    if ($updateStmt->rowCount() !== 1) {
        $conn->rollBack();
        schrijfWorkerLog('Bericht kon niet op processing worden gezet.');
        http_response_code(409);
        exit('Bericht kon niet op processing worden gezet.');
    }

    $conn->commit();

    schrijfWorkerLog('Bericht ' . $bericht['id'] . ' is op processing gezet.');
    echo 'Bericht ' . $bericht['id'] . ' is op processing gezet.';
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    schrijfWorkerLog('Worker fout tijdens ophalen van pending bericht.');
    http_response_code(500);
    exit('Worker kon de wachtrij niet verwerken.');
}
