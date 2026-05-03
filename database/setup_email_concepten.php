<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';

// Dit script maakt de tabellen voor het e-mail dashboard aan.
// Het leest eerst het SQL-bestand en voert dat uit op de database.
$sql = file_get_contents(__DIR__ . '/email_concepten.sql');

if ($sql === false) {
    http_response_code(500);
    exit('SQL-bestand kon niet worden gelezen.');
}

try {
    // Maak de email_concepten tabel (en alles wat in het .sql bestand staat).
    $conn->exec($sql);
    // Deze tabel gebruiken we om kleine instellingen op te slaan (zoals tone of voice).
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `dashboard_settings` (
            `setting_key` VARCHAR(64) NOT NULL,
            `setting_value` LONGTEXT NOT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `email_aliassen` (
            `send_as_email` VARCHAR(255) NOT NULL,
            `display_name` VARCHAR(255) NOT NULL DEFAULT '',
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `is_default` TINYINT(1) NOT NULL DEFAULT 0,
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`send_as_email`),
            INDEX (`is_enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo 'email_concepten tabel is aangemaakt of bestond al.';
} catch (PDOException $e) {
    http_response_code(500);
    exit('Aanmaken van email_concepten is mislukt.');
}
