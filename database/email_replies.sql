CREATE TABLE IF NOT EXISTS `email_replies` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `application_id` VARCHAR(50) NOT NULL,
    `sender` VARCHAR(255) DEFAULT NULL,
    `subject` VARCHAR(255) DEFAULT NULL,
    `message` TEXT,
    `message_id` VARCHAR(255) DEFAULT NULL,
    `message_hash` VARCHAR(255) DEFAULT NULL,
    `received_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_app` (`application_id`),
    UNIQUE KEY `uniq_msg` (`message_id`),
    UNIQUE KEY `uniq_hash` (`message_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
