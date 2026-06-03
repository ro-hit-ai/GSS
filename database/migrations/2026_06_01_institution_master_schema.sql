CREATE TABLE IF NOT EXISTS Vati_Payfiller_Institution_Master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    institution_code VARCHAR(64) NULL,
    canonical_name VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    institution_type VARCHAR(80) NOT NULL,
    institution_level VARCHAR(60) NOT NULL,
    category VARCHAR(80) NULL,
    city VARCHAR(120) NULL,
    district VARCHAR(120) NULL,
    state VARCHAR(120) NULL,
    country VARCHAR(120) NOT NULL DEFAULT 'India',
    university_name VARCHAR(255) NULL,
    board_name VARCHAR(255) NULL,
    website VARCHAR(255) NULL,
    email_domain VARCHAR(160) NULL,
    verification_email VARCHAR(180) NULL,
    phone VARCHAR(60) NULL,
    address TEXT NULL,
    normalized_name VARCHAR(255) NOT NULL,
    search_keywords TEXT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    source VARCHAR(60) NOT NULL DEFAULT 'dummy',
    match_status VARCHAR(40) NOT NULL DEFAULT 'verified_master',
    confidence_score DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    verification_supported TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_institution_code (institution_code),
    KEY idx_institution_type (institution_type),
    KEY idx_institution_level (institution_level),
    KEY idx_city (city),
    KEY idx_state (state),
    KEY idx_country (country),
    KEY idx_university_name (university_name),
    KEY idx_status_source (status, source),
    FULLTEXT KEY ft_institution_search (canonical_name, display_name, normalized_name, search_keywords)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS Vati_Payfiller_Iinstitution_Aliases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    institution_id INT NOT NULL,
    alias_name VARCHAR(255) NOT NULL,
    normalized_alias VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_institution_alias_id (institution_id),
    KEY idx_normalized_alias (normalized_alias),
    CONSTRAINT fk_institution_alias_master
        FOREIGN KEY (institution_id) REFERENCES Vati_Payfiller_Institution_Master(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS Vati_Payfiller_Institution_Source_Map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    institution_id INT NOT NULL,
    external_source VARCHAR(80) NOT NULL,
    external_id VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_source_external (external_source, external_id),
    KEY idx_source_institution (institution_id),
    CONSTRAINT fk_institution_source_master
        FOREIGN KEY (institution_id) REFERENCES Vati_Payfiller_Institution_Master(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS Vati_Payfiller_Institution_Verification_Contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    institution_id INT NOT NULL,
    contact_type VARCHAR(80) NOT NULL,
    email VARCHAR(180) NULL,
    phone VARCHAR(60) NULL,
    department VARCHAR(120) NULL,
    priority INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_contact_institution (institution_id),
    KEY idx_contact_type (contact_type),
    CONSTRAINT fk_institution_contact_master
        FOREIGN KEY (institution_id) REFERENCES Vati_Payfiller_Institution_Master(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS Vati_Payfiller_Institution_Manual_Suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id VARCHAR(80) NULL,
    institution_name VARCHAR(255) NOT NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(120) NULL,
    country VARCHAR(120) NOT NULL DEFAULT 'India',
    university_or_board VARCHAR(255) NULL,
    institution_type VARCHAR(80) NULL,
    qualification VARCHAR(80) NULL,
    match_status VARCHAR(40) NOT NULL DEFAULT 'manual_pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_manual_application (application_id),
    KEY idx_manual_status (match_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
