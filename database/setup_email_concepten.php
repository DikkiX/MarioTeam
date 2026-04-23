<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';

$sql = file_get_contents(__DIR__ . '/email_concepten.sql');

if ($sql === false) {
    http_response_code(500);
    exit('SQL-bestand kon niet worden gelezen.');
}

try {
    $conn->exec($sql);
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `dashboard_settings` (
            `setting_key` VARCHAR(64) NOT NULL,
            `setting_value` LONGTEXT NOT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo 'email_concepten tabel is aangemaakt of bestond al.';
} catch (PDOException $e) {
    http_response_code(500);
    exit('Aanmaken van email_concepten is mislukt.');
}
