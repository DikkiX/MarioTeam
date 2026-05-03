CREATE TABLE IF NOT EXISTS `email_concepten` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `gmail_thread_id` VARCHAR(255) NOT NULL,
    `onderwerp` VARCHAR(255) NULL,
    `klant_email` VARCHAR(255) NOT NULL,
    `ontvangen_op_email` VARCHAR(255) NULL,
    `afzender_alias_email` VARCHAR(255) NULL,
    `concept_tekst` LONGTEXT NOT NULL,
    `status` ENUM('draft', 'sent', 'error') NOT NULL DEFAULT 'draft',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_concepten_status_created_at` (`status`, `created_at`),
    KEY `idx_email_concepten_gmail_thread_id` (`gmail_thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
