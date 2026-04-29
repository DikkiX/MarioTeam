CREATE TABLE IF NOT EXISTS `email_rules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `condition_type` VARCHAR(32) NOT NULL,
    `condition_value` VARCHAR(255) NOT NULL,
    `action_type` VARCHAR(32) NOT NULL,
    `action_value` LONGTEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_rules_is_enabled` (`is_enabled`),
    KEY `idx_email_rules_condition_type` (`condition_type`),
    KEY `idx_email_rules_action_type` (`action_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
