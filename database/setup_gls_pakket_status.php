<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';

$sql = file_get_contents(__DIR__ . '/gls_pakket_status.sql');

if ($sql === false) {
    http_response_code(500);
    exit('SQL-bestand kon niet worden gelezen.');
}

try {
    $conn->exec($sql);
    echo 'gls_pakket_status tabel is aangemaakt of bestond al.';
} catch (PDOException $e) {
    http_response_code(500);
    exit('Aanmaken van gls_pakket_status is mislukt: ' . $e->getMessage());
}
