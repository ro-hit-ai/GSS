CREATE TABLE IF NOT EXISTS `Vati_Payfiller_Case_Component_Item_Workflow` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int NOT NULL,
  `application_id` varchar(64) NOT NULL,
  `component_key` varchar(64) NOT NULL,
  `item_key` varchar(191) NOT NULL,
  `stage` varchar(32) NOT NULL,
  `status` varchar(64) NOT NULL,
  `updated_by_user_id` int DEFAULT NULL,
  `updated_by_role` varchar(64) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_case_component_item_stage` (`case_id`,`component_key`,`item_key`,`stage`),
  KEY `idx_item_app` (`application_id`,`component_key`,`item_key`),
  KEY `idx_item_stage_status` (`case_id`,`component_key`,`stage`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
