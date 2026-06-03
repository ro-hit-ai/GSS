CREATE TABLE IF NOT EXISTS `candidate_references` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_id` VARCHAR(50) NOT NULL,
    `reference_type` ENUM('education','employment') NOT NULL,
    `reference_index` INT UNSIGNED NOT NULL,
    `reference_name` VARCHAR(255) NOT NULL,
    `designation` VARCHAR(255) NOT NULL,
    `company` VARCHAR(255) NOT NULL,
    `relationship` VARCHAR(255) NOT NULL,
    `years_known` INT UNSIGNED NOT NULL,
    `mobile` VARCHAR(20) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_candidate_references_app_type_index` (`application_id`, `reference_type`, `reference_index`),
    KEY `idx_candidate_references_application` (`application_id`),
    KEY `idx_candidate_references_type` (`reference_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `candidate_references` (
    `application_id`,
    `reference_type`,
    `reference_index`,
    `reference_name`,
    `designation`,
    `company`,
    `relationship`,
    `years_known`,
    `mobile`,
    `email`,
    `created_at`,
    `updated_at`
)
SELECT
    r.`application_id`,
    'education',
    1,
    COALESCE(NULLIF(r.`education_reference_name`, ''), ''),
    COALESCE(NULLIF(r.`education_reference_designation`, ''), ''),
    COALESCE(NULLIF(r.`education_reference_company`, ''), ''),
    COALESCE(NULLIF(r.`education_reference_relationship`, ''), ''),
    COALESCE(NULLIF(r.`education_reference_years_known`, ''), 0),
    COALESCE(NULLIF(r.`education_reference_mobile`, ''), ''),
    COALESCE(NULLIF(r.`education_reference_email`, ''), ''),
    COALESCE(r.`created_at`, NOW()),
    COALESCE(r.`updated_at`, NOW())
FROM `Vati_Payfiller_Candidate_Reference_details` r
WHERE COALESCE(NULLIF(r.`education_reference_name`, ''), '') <> ''
ON DUPLICATE KEY UPDATE
    `reference_name` = VALUES(`reference_name`),
    `designation` = VALUES(`designation`),
    `company` = VALUES(`company`),
    `relationship` = VALUES(`relationship`),
    `years_known` = VALUES(`years_known`),
    `mobile` = VALUES(`mobile`),
    `email` = VALUES(`email`),
    `updated_at` = VALUES(`updated_at`);

INSERT INTO `candidate_references` (
    `application_id`,
    `reference_type`,
    `reference_index`,
    `reference_name`,
    `designation`,
    `company`,
    `relationship`,
    `years_known`,
    `mobile`,
    `email`,
    `created_at`,
    `updated_at`
)
SELECT
    r.`application_id`,
    'employment',
    1,
    COALESCE(NULLIF(r.`employment_reference_name`, ''), NULLIF(r.`reference_name`, ''), ''),
    COALESCE(NULLIF(r.`employment_reference_designation`, ''), NULLIF(r.`reference_designation`, ''), ''),
    COALESCE(NULLIF(r.`employment_reference_company`, ''), NULLIF(r.`reference_company`, ''), ''),
    COALESCE(NULLIF(r.`employment_reference_relationship`, ''), NULLIF(r.`relationship`, ''), ''),
    COALESCE(NULLIF(r.`employment_reference_years_known`, ''), NULLIF(r.`years_known`, ''), 0),
    COALESCE(NULLIF(r.`employment_reference_mobile`, ''), NULLIF(r.`reference_mobile`, ''), ''),
    COALESCE(NULLIF(r.`employment_reference_email`, ''), NULLIF(r.`reference_email`, ''), ''),
    COALESCE(r.`created_at`, NOW()),
    COALESCE(r.`updated_at`, NOW())
FROM `Vati_Payfiller_Candidate_Reference_details` r
WHERE COALESCE(NULLIF(r.`employment_reference_name`, ''), NULLIF(r.`reference_name`, ''), '') <> ''
ON DUPLICATE KEY UPDATE
    `reference_name` = VALUES(`reference_name`),
    `designation` = VALUES(`designation`),
    `company` = VALUES(`company`),
    `relationship` = VALUES(`relationship`),
    `years_known` = VALUES(`years_known`),
    `mobile` = VALUES(`mobile`),
    `email` = VALUES(`email`),
    `updated_at` = VALUES(`updated_at`);

DROP PROCEDURE IF EXISTS `SP_Vati_Payfiller_candidate_reference_list`;
DELIMITER $$
CREATE PROCEDURE `SP_Vati_Payfiller_candidate_reference_list`(
    IN p_application_id VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
)
BEGIN
    SELECT
        `id`,
        `application_id`,
        `reference_type`,
        `reference_index`,
        `reference_name`,
        `designation`,
        `company`,
        `relationship`,
        `years_known`,
        `mobile`,
        `email`,
        `created_at`,
        `updated_at`
    FROM `candidate_references`
    WHERE `application_id` = p_application_id
    ORDER BY `reference_type`, `reference_index`;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `SP_Vati_Payfiller_candidate_reference_delete_type`;
DELIMITER $$
CREATE PROCEDURE `SP_Vati_Payfiller_candidate_reference_delete_type`(
    IN p_application_id VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_reference_type VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
)
BEGIN
    DELETE FROM `candidate_references`
    WHERE `application_id` = p_application_id
      AND `reference_type` = p_reference_type;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `SP_Vati_Payfiller_candidate_reference_upsert`;
DELIMITER $$
CREATE PROCEDURE `SP_Vati_Payfiller_candidate_reference_upsert`(
    IN p_application_id VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_reference_type VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_reference_index INT,
    IN p_reference_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_designation VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_company VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_relationship VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_years_known INT,
    IN p_mobile VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
)
BEGIN
    INSERT INTO `candidate_references` (
        `application_id`,
        `reference_type`,
        `reference_index`,
        `reference_name`,
        `designation`,
        `company`,
        `relationship`,
        `years_known`,
        `mobile`,
        `email`,
        `created_at`,
        `updated_at`
    ) VALUES (
        p_application_id,
        p_reference_type,
        p_reference_index,
        p_reference_name,
        p_designation,
        p_company,
        p_relationship,
        p_years_known,
        p_mobile,
        p_email,
        NOW(),
        NOW()
    )
    ON DUPLICATE KEY UPDATE
        `reference_name` = VALUES(`reference_name`),
        `designation` = VALUES(`designation`),
        `company` = VALUES(`company`),
        `relationship` = VALUES(`relationship`),
        `years_known` = VALUES(`years_known`),
        `mobile` = VALUES(`mobile`),
        `email` = VALUES(`email`),
        `updated_at` = NOW();
END$$
DELIMITER ;
