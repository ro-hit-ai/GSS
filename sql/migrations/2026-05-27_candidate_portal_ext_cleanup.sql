-- Candidate portal cleanup: move temporary Ext-table fields into main tables
-- Apply this once on the target database before removing the old Ext tables.

START TRANSACTION;

ALTER TABLE `Vati_Payfiller_Candidate_Basic_details`
    ADD COLUMN IF NOT EXISTS `resume_file` varchar(255) DEFAULT NULL AFTER `photo_path`,
    ADD COLUMN IF NOT EXISTS `resume_original_name` varchar(255) DEFAULT NULL AFTER `resume_file`;

ALTER TABLE `Vati_Payfiller_Candidate_Contact_details`
    ADD COLUMN IF NOT EXISTS `current_proof_type` varchar(120) DEFAULT NULL AFTER `proof_file`,
    ADD COLUMN IF NOT EXISTS `current_proof_file` varchar(255) DEFAULT NULL AFTER `current_proof_type`,
    ADD COLUMN IF NOT EXISTS `current_proof_original_name` varchar(255) DEFAULT NULL AFTER `current_proof_file`,
    ADD COLUMN IF NOT EXISTS `permanent_proof_type` varchar(120) DEFAULT NULL AFTER `current_proof_original_name`,
    ADD COLUMN IF NOT EXISTS `permanent_proof_file` varchar(255) DEFAULT NULL AFTER `permanent_proof_type`,
    ADD COLUMN IF NOT EXISTS `permanent_proof_original_name` varchar(255) DEFAULT NULL AFTER `permanent_proof_file`;

ALTER TABLE `Vati_Payfiller_Candidate_Ecourt_Details`
    ADD COLUMN IF NOT EXISTS `applicant_legal_name` varchar(255) DEFAULT NULL AFTER `application_id`,
    ADD COLUMN IF NOT EXISTS `father_name` varchar(255) DEFAULT NULL AFTER `applicant_legal_name`,
    ADD COLUMN IF NOT EXISTS `current_address_snapshot` text DEFAULT NULL AFTER `father_name`,
    ADD COLUMN IF NOT EXISTS `permanent_address_snapshot` text DEFAULT NULL AFTER `current_address_snapshot`,
    ADD COLUMN IF NOT EXISTS `same_as_current` tinyint(1) NOT NULL DEFAULT '0' AFTER `permanent_address_snapshot`;

ALTER TABLE `Vati_Payfiller_Candidate_Identification_details`
    ADD COLUMN IF NOT EXISTS `proof_group` varchar(32) NOT NULL DEFAULT 'primary' AFTER `document_index`;

UPDATE `Vati_Payfiller_Candidate_Identification_details`
SET `proof_group` = 'primary'
WHERE `proof_group` IS NULL OR TRIM(`proof_group`) = '';

SET @old_unique_idx := (
    SELECT s.INDEX_NAME
    FROM INFORMATION_SCHEMA.STATISTICS s
    WHERE s.TABLE_SCHEMA = DATABASE()
      AND s.TABLE_NAME = 'Vati_Payfiller_Candidate_Identification_details'
      AND s.NON_UNIQUE = 0
    GROUP BY s.INDEX_NAME
    HAVING SUM(CASE WHEN s.COLUMN_NAME = 'application_id' THEN 1 ELSE 0 END) > 0
       AND SUM(CASE WHEN s.COLUMN_NAME = 'document_index' THEN 1 ELSE 0 END) > 0
       AND SUM(CASE WHEN s.COLUMN_NAME = 'proof_group' THEN 1 ELSE 0 END) = 0
    LIMIT 1
);
SET @drop_unique_sql := IF(
    @old_unique_idx IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE `Vati_Payfiller_Candidate_Identification_details` DROP INDEX `', @old_unique_idx, '`')
);
PREPARE stmt_drop_unique FROM @drop_unique_sql;
EXECUTE stmt_drop_unique;
DEALLOCATE PREPARE stmt_drop_unique;

SET @has_new_unique := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'Vati_Payfiller_Candidate_Identification_details'
      AND INDEX_NAME = 'uniq_identification_app_doc_group'
);
SET @create_unique_sql := IF(
    @has_new_unique > 0,
    'SELECT 1',
    'ALTER TABLE `Vati_Payfiller_Candidate_Identification_details` ADD UNIQUE KEY `uniq_identification_app_doc_group` (`application_id`, `document_index`, `proof_group`)'
);
PREPARE stmt_create_unique FROM @create_unique_sql;
EXECUTE stmt_create_unique;
DEALLOCATE PREPARE stmt_create_unique;

SET @has_idx_app_doc := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'Vati_Payfiller_Candidate_Identification_details'
      AND INDEX_NAME = 'idx_identification_app_doc'
);
SET @create_idx_sql := IF(
    @has_idx_app_doc > 0,
    'SELECT 1',
    'ALTER TABLE `Vati_Payfiller_Candidate_Identification_details` ADD KEY `idx_identification_app_doc` (`application_id`, `document_index`)'
);
PREPARE stmt_create_idx FROM @create_idx_sql;
EXECUTE stmt_create_idx;
DEALLOCATE PREPARE stmt_create_idx;

INSERT INTO `Vati_Payfiller_Candidate_Basic_details` (`application_id`, `resume_file`, `resume_original_name`)
SELECT be.`application_id`, be.`resume_file`, be.`resume_original_name`
FROM `Vati_Payfiller_Candidate_Basic_Ext` be
LEFT JOIN `Vati_Payfiller_Candidate_Basic_details` bd
  ON bd.`application_id` = be.`application_id`
WHERE bd.`application_id` IS NULL;

UPDATE `Vati_Payfiller_Candidate_Basic_details` bd
JOIN `Vati_Payfiller_Candidate_Basic_Ext` be
  ON be.`application_id` = bd.`application_id`
SET bd.`resume_file` = COALESCE(NULLIF(be.`resume_file`, ''), bd.`resume_file`),
    bd.`resume_original_name` = COALESCE(NULLIF(be.`resume_original_name`, ''), bd.`resume_original_name`);

UPDATE `Vati_Payfiller_Candidate_Contact_details` cd
JOIN `Vati_Payfiller_Candidate_Contact_Ext` ce
  ON ce.`application_id` = cd.`application_id`
SET cd.`current_proof_type` = COALESCE(NULLIF(ce.`current_proof_type`, ''), cd.`current_proof_type`, cd.`proof_type`),
    cd.`current_proof_file` = COALESCE(NULLIF(ce.`current_proof_file`, ''), cd.`current_proof_file`, cd.`proof_file`),
    cd.`current_proof_original_name` = COALESCE(NULLIF(ce.`current_proof_original_name`, ''), cd.`current_proof_original_name`),
    cd.`permanent_proof_type` = COALESCE(NULLIF(ce.`permanent_proof_type`, ''), cd.`permanent_proof_type`),
    cd.`permanent_proof_file` = COALESCE(NULLIF(ce.`permanent_proof_file`, ''), cd.`permanent_proof_file`),
    cd.`permanent_proof_original_name` = COALESCE(NULLIF(ce.`permanent_proof_original_name`, ''), cd.`permanent_proof_original_name`);

UPDATE `Vati_Payfiller_Candidate_Contact_details`
SET `current_proof_type` = COALESCE(NULLIF(`current_proof_type`, ''), `proof_type`),
    `current_proof_file` = COALESCE(NULLIF(`current_proof_file`, ''), `proof_file`)
WHERE `application_id` IS NOT NULL;

INSERT INTO `Vati_Payfiller_Candidate_Ecourt_Details` (
    `application_id`, `applicant_legal_name`, `father_name`, `current_address_snapshot`, `permanent_address_snapshot`, `same_as_current`
)
SELECT ee.`application_id`, ee.`applicant_legal_name`, ee.`father_name`, ee.`current_address_snapshot`, ee.`permanent_address_snapshot`, ee.`same_as_current`
FROM `Vati_Payfiller_Candidate_Ecourt_Ext` ee
LEFT JOIN `Vati_Payfiller_Candidate_Ecourt_Details` ed
  ON ed.`application_id` = ee.`application_id`
WHERE ed.`application_id` IS NULL;

UPDATE `Vati_Payfiller_Candidate_Ecourt_Details` ed
JOIN `Vati_Payfiller_Candidate_Ecourt_Ext` ee
  ON ee.`application_id` = ed.`application_id`
SET ed.`applicant_legal_name` = COALESCE(NULLIF(ee.`applicant_legal_name`, ''), ed.`applicant_legal_name`),
    ed.`father_name` = COALESCE(NULLIF(ee.`father_name`, ''), ed.`father_name`),
    ed.`current_address_snapshot` = COALESCE(NULLIF(ee.`current_address_snapshot`, ''), ed.`current_address_snapshot`),
    ed.`permanent_address_snapshot` = COALESCE(NULLIF(ee.`permanent_address_snapshot`, ''), ed.`permanent_address_snapshot`),
    ed.`same_as_current` = COALESCE(ee.`same_as_current`, ed.`same_as_current`);

UPDATE `Vati_Payfiller_Candidate_Identification_details` d
JOIN `Vati_Payfiller_Candidate_Identification_Ext` e
  ON e.`application_id` = d.`application_id`
 AND e.`document_index` = d.`document_index`
 AND e.`proof_group` = 'primary'
SET d.`proof_group` = 'primary',
    d.`documentId_type` = COALESCE(NULLIF(e.`document_type`, ''), d.`documentId_type`),
    d.`id_number` = COALESCE(NULLIF(e.`id_number`, ''), d.`id_number`),
    d.`name` = COALESCE(NULLIF(e.`name`, ''), d.`name`),
    d.`issue_date` = COALESCE(e.`issue_date`, d.`issue_date`),
    d.`expiry_date` = COALESCE(e.`expiry_date`, d.`expiry_date`),
    d.`upload_document` = COALESCE(NULLIF(e.`upload_document`, ''), d.`upload_document`);

INSERT INTO `Vati_Payfiller_Candidate_Identification_details` (
    `application_id`, `document_index`, `proof_group`, `documentId_type`, `id_number`, `name`, `upload_document`, `country`, `issue_date`, `expiry_date`
)
SELECT e.`application_id`, e.`document_index`, e.`proof_group`, e.`document_type`, e.`id_number`, e.`name`, e.`upload_document`, NULL, e.`issue_date`, e.`expiry_date`
FROM `Vati_Payfiller_Candidate_Identification_Ext` e
LEFT JOIN `Vati_Payfiller_Candidate_Identification_details` d
  ON d.`application_id` = e.`application_id`
 AND d.`document_index` = e.`document_index`
 AND d.`proof_group` = e.`proof_group`
WHERE d.`id` IS NULL;

DROP TABLE IF EXISTS `Vati_Payfiller_Candidate_Identification_Ext`;
DROP TABLE IF EXISTS `Vati_Payfiller_Candidate_Ecourt_Ext`;
DROP TABLE IF EXISTS `Vati_Payfiller_Candidate_Contact_Ext`;
DROP TABLE IF EXISTS `Vati_Payfiller_Candidate_Basic_Ext`;

COMMIT;
