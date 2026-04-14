CREATE TABLE IF NOT EXISTS `chat_queue` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cookie` VARCHAR(64) NOT NULL,
    `user_message` TEXT NOT NULL,
    `ai_response` LONGTEXT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'error') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_chat_queue_status_created_at` (`status`, `created_at`),
    KEY `idx_chat_queue_cookie` (`cookie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
