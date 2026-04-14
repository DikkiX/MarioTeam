<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';

$sql = file_get_contents(__DIR__ . '/chat_queue.sql');

if ($sql === false) {
    http_response_code(500);
    exit('SQL-bestand kon niet worden gelezen.');
}

try {
    $conn->exec($sql);
    echo 'chat_queue tabel is aangemaakt of bestond al.';
} catch (PDOException $e) {
    http_response_code(500);
    exit('Aanmaken van chat_queue is mislukt.');
}
