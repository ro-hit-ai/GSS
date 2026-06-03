-- Candidate runtime schema stabilization
-- Apply from MySQL Workbench/DBA process only. Do not execute from PHP runtime.

CREATE TABLE IF NOT EXISTS Vati_Payfiller_Candidate_Mobile_Photo_Sessions (
    session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id VARCHAR(64) NOT NULL,
    token VARCHAR(96) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    photo_path VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    uploaded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id),
    UNIQUE KEY uq_mobile_photo_token (token),
    KEY idx_mobile_photo_app_status (application_id, status),
    KEY idx_mobile_photo_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS Vati_Payfiller_Candidate_Education_Documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id VARCHAR(64) NOT NULL,
    education_index INT NOT NULL,
    document_slot VARCHAR(32) NOT NULL DEFAULT 'supporting',
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_education_docs_app_idx (application_id, education_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE Vati_Payfiller_Candidate_Education_details
    ADD COLUMN IF NOT EXISTS ca_membership_number VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS year_of_passing VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS education_gap_reason VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS education_gap_explanation TEXT NULL,
    ADD COLUMN IF NOT EXISTS education_order_explanation TEXT NULL,
    ADD COLUMN IF NOT EXISTS institution_id INT NULL,
    ADD COLUMN IF NOT EXISTS institution_display_name VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS manual_institution_name VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS institution_match_status VARCHAR(40) NULL;

ALTER TABLE Vati_Payfiller_Candidate_Employment_details
    ADD COLUMN IF NOT EXISTS employment_status VARCHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS tentative_relieving_date DATE NULL,
    ADD COLUMN IF NOT EXISTS tentative_relieving_note TEXT NULL,
    ADD COLUMN IF NOT EXISTS gap_reason VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS gap_explanation TEXT NULL,
    ADD COLUMN IF NOT EXISTS overlap_explanation TEXT NULL;
