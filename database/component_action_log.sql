CREATE TABLE IF NOT EXISTS `Vati_Payfiller_Component_Action_Log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_id` INT NOT NULL,
    `application_id` VARCHAR(64) NOT NULL,
    `component_key` VARCHAR(64) NOT NULL,
    `stage` VARCHAR(32) NOT NULL,
    `action_type` VARCHAR(64) NOT NULL,
    `status` VARCHAR(64) NOT NULL,
    `reason` TEXT NULL,
    `actor_user_id` INT NOT NULL,
    `actor_role` VARCHAR(64) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_component_action_app` (`application_id`),
    KEY `idx_component_action_case` (`case_id`),
    KEY `idx_component_action_component` (`component_key`),
    KEY `idx_component_action_stage` (`stage`),
    KEY `idx_component_action_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
