<?php
require __DIR__ . '/../../config/db.php';
$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$procedures = [];

$procedures['SP_Vati_Payfiller_get_basic_details'] = <<<'SQL'
CREATE PROCEDURE `SP_Vati_Payfiller_get_basic_details`(
    IN p_application_id VARCHAR(50)
)
BEGIN
    SELECT
        first_name,
        middle_name,
        last_name,
        gender,
        dob,
        blood_group,
        father_name,
        mother_name,
        mobile,
        landline,
        email,
        marital_status,
        spouse_name,
        other_name,
        country,
        state,
        city_village,
        district,
        pincode,
        nationality,
        photo_path,
        resume_file,
        resume_original_name,
        created_at,
        updated_at
    FROM Vati_Payfiller_Candidate_Basic_details
    WHERE application_id = p_application_id;
END
SQL;

$procedures['SP_Vati_Payfiller_save_basic_details'] = <<<'SQL'
CREATE PROCEDURE `SP_Vati_Payfiller_save_basic_details`(
    IN p_first_name VARCHAR(100),
    IN p_middle_name VARCHAR(100),
    IN p_last_name VARCHAR(100),
    IN p_gender VARCHAR(10),
    IN p_dob DATE,
    IN p_blood_group VARCHAR(10),
    IN p_father_name VARCHAR(255),
    IN p_mother_name VARCHAR(255),
    IN p_mobile VARCHAR(20),
    IN p_landline VARCHAR(20),
    IN p_email VARCHAR(255),
    IN p_marital_status VARCHAR(20),
    IN p_spouse_name VARCHAR(255),
    IN p_other_name VARCHAR(255),
    IN p_country VARCHAR(100),
    IN p_state VARCHAR(100),
    IN p_city_village VARCHAR(100),
    IN p_district VARCHAR(100),
    IN p_pincode VARCHAR(20),
    IN p_nationality VARCHAR(100),
    IN p_application_id VARCHAR(50),
    IN p_photo_path VARCHAR(255),
    IN p_resume_file VARCHAR(255),
    IN p_resume_original_name VARCHAR(255)
)
BEGIN
    INSERT INTO Vati_Payfiller_Candidate_Basic_details (
        application_id,
        first_name, middle_name, last_name, gender, dob, blood_group,
        father_name, mother_name, mobile, landline, email,
        marital_status, spouse_name, other_name,
        country, state, city_village, district, pincode, nationality,
        photo_path, resume_file, resume_original_name,
        created_at, updated_at
    ) VALUES (
        p_application_id,
        p_first_name, p_middle_name, p_last_name, p_gender, p_dob, p_blood_group,
        p_father_name, p_mother_name, p_mobile, p_landline, p_email,
        p_marital_status, p_spouse_name, p_other_name,
        p_country, p_state, p_city_village, p_district, p_pincode, p_nationality,
        p_photo_path, p_resume_file, p_resume_original_name,
        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
    ON DUPLICATE KEY UPDATE
        first_name = VALUES(first_name),
        middle_name = VALUES(middle_name),
        last_name = VALUES(last_name),
        gender = VALUES(gender),
        dob = VALUES(dob),
        blood_group = VALUES(blood_group),
        father_name = VALUES(father_name),
        mother_name = VALUES(mother_name),
        mobile = VALUES(mobile),
        landline = VALUES(landline),
        email = VALUES(email),
        marital_status = VALUES(marital_status),
        spouse_name = VALUES(spouse_name),
        other_name = VALUES(other_name),
        country = VALUES(country),
        state = VALUES(state),
        city_village = VALUES(city_village),
        district = VALUES(district),
        pincode = VALUES(pincode),
        nationality = VALUES(nationality),
        photo_path = COALESCE(VALUES(photo_path), photo_path),
        resume_file = COALESCE(VALUES(resume_file), resume_file),
        resume_original_name = COALESCE(VALUES(resume_original_name), resume_original_name),
        updated_at = CURRENT_TIMESTAMP;
END
SQL;

$procedures['SP_Vati_Payfiller_get_contact_details'] = <<<'SQL'
CREATE PROCEDURE `SP_Vati_Payfiller_get_contact_details`(
    IN p_application_id VARCHAR(50)
)
BEGIN
    SELECT
        application_id,
        mobile_country_code,
        mobile,
        alternative_mobile,
        email,
        alternative_email,
        address1,
        address2,
        city,
        state,
        country,
        postal_code,
        permanent_address1,
        permanent_address2,
        permanent_city,
        permanent_state,
        permanent_country,
        permanent_postal_code,
        proof_type,
        proof_file,
        current_proof_type,
        current_proof_file,
        current_proof_original_name,
        permanent_proof_type,
        permanent_proof_file,
        permanent_proof_original_name,
        same_as_current,
        insufficient_documents,
        created_at,
        updated_at
    FROM Vati_Payfiller_Candidate_Contact_details
    WHERE application_id = p_application_id;
END
SQL;

$procedures['SP_Vati_Payfiller_save_contact_details'] = <<<'SQL'
CREATE PROCEDURE `SP_Vati_Payfiller_save_contact_details`(
    IN p_mobile_country_code VARCHAR(10),
    IN p_mobile VARCHAR(20),
    IN p_alternative_mobile VARCHAR(20),
    IN p_email VARCHAR(150),
    IN p_alternative_email VARCHAR(150),
    IN p_address1 VARCHAR(255),
    IN p_address2 VARCHAR(255),
    IN p_city VARCHAR(150),
    IN p_state VARCHAR(150),
    IN p_country VARCHAR(150),
    IN p_postal_code VARCHAR(20),
    IN p_proof_type VARCHAR(100),
    IN p_proof_file VARCHAR(255),
    IN p_current_proof_original_name VARCHAR(255),
    IN p_application_id VARCHAR(50),
    IN p_same_as_current TINYINT(1),
    IN p_insufficient_documents TINYINT(1),
    IN p_permanent_address1 VARCHAR(255),
    IN p_permanent_address2 VARCHAR(255),
    IN p_permanent_city VARCHAR(150),
    IN p_permanent_state VARCHAR(150),
    IN p_permanent_country VARCHAR(150),
    IN p_permanent_postal_code VARCHAR(20),
    IN p_permanent_proof_type VARCHAR(120),
    IN p_permanent_proof_file VARCHAR(255),
    IN p_permanent_proof_original_name VARCHAR(255)
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    DECLARE v_old_current_file VARCHAR(255);
    DECLARE v_old_permanent_file VARCHAR(255);
    DECLARE v_current_file VARCHAR(255);
    DECLARE v_permanent_file VARCHAR(255);

    SET v_current_file = p_proof_file;
    SET v_permanent_file = p_permanent_proof_file;

    SELECT COUNT(*) INTO v_exists
    FROM Vati_Payfiller_Candidate_Contact_details
    WHERE application_id = p_application_id;

    IF p_insufficient_documents = 1 THEN
        SET v_current_file = NULL;
    END IF;

    IF v_exists = 1 THEN
        IF p_insufficient_documents = 0 THEN
            SELECT current_proof_file, permanent_proof_file
            INTO v_old_current_file, v_old_permanent_file
            FROM Vati_Payfiller_Candidate_Contact_details
            WHERE application_id = p_application_id;

            IF (v_current_file IS NULL OR v_current_file = '') AND v_old_current_file IS NOT NULL THEN
                SET v_current_file = v_old_current_file;
            END IF;
        END IF;

        IF (v_permanent_file IS NULL OR v_permanent_file = '') AND v_old_permanent_file IS NOT NULL THEN
            SET v_permanent_file = v_old_permanent_file;
        END IF;

        UPDATE Vati_Payfiller_Candidate_Contact_details
        SET
            mobile_country_code = p_mobile_country_code,
            mobile = p_mobile,
            alternative_mobile = p_alternative_mobile,
            email = p_email,
            alternative_email = p_alternative_email,
            address1 = p_address1,
            address2 = p_address2,
            city = p_city,
            state = p_state,
            country = p_country,
            postal_code = p_postal_code,
            proof_type = p_proof_type,
            proof_file = v_current_file,
            current_proof_type = p_proof_type,
            current_proof_file = v_current_file,
            current_proof_original_name = p_current_proof_original_name,
            same_as_current = p_same_as_current,
            insufficient_documents = p_insufficient_documents,
            permanent_address1 = p_permanent_address1,
            permanent_address2 = p_permanent_address2,
            permanent_city = p_permanent_city,
            permanent_state = p_permanent_state,
            permanent_country = p_permanent_country,
            permanent_postal_code = p_permanent_postal_code,
            permanent_proof_type = p_permanent_proof_type,
            permanent_proof_file = v_permanent_file,
            permanent_proof_original_name = p_permanent_proof_original_name,
            updated_at = CURRENT_TIMESTAMP
        WHERE application_id = p_application_id;
    ELSE
        INSERT INTO Vati_Payfiller_Candidate_Contact_details (
            application_id,
            mobile_country_code,
            mobile,
            alternative_mobile,
            email,
            alternative_email,
            address1,
            address2,
            city,
            state,
            country,
            postal_code,
            proof_type,
            proof_file,
            current_proof_type,
            current_proof_file,
            current_proof_original_name,
            same_as_current,
            insufficient_documents,
            permanent_address1,
            permanent_address2,
            permanent_city,
            permanent_state,
            permanent_country,
            permanent_postal_code,
            permanent_proof_type,
            permanent_proof_file,
            permanent_proof_original_name,
            created_at,
            updated_at
        ) VALUES (
            p_application_id,
            p_mobile_country_code,
            p_mobile,
            p_alternative_mobile,
            p_email,
            p_alternative_email,
            p_address1,
            p_address2,
            p_city,
            p_state,
            p_country,
            p_postal_code,
            p_proof_type,
            v_current_file,
            p_proof_type,
            v_current_file,
            p_current_proof_original_name,
            p_same_as_current,
            p_insufficient_documents,
            p_permanent_address1,
            p_permanent_address2,
            p_permanent_city,
            p_permanent_state,
            p_permanent_country,
            p_permanent_postal_code,
            p_permanent_proof_type,
            v_permanent_file,
            p_permanent_proof_original_name,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        );
    END IF;
END
SQL;

$procedures['SP_Vati_Payfiller_get_ecourt_details'] = <<<'SQL'
CREATE PROCEDURE `SP_Vati_Payfiller_get_ecourt_details`(
    IN p_application_id VARCHAR(50)
)
BEGIN
    SELECT 
        current_address,
        permanent_address,
        evidence_document,
        period_from_date,
        period_to_date,
        period_duration_years,
        dob,
        on_hold,
        not_applicable,
        comments,
        verification_status,
        verification_notes,
        verification_date,
        application_id,
        applicant_legal_name,
        father_name,
        current_address_snapshot,
        permanent_address_snapshot,
        same_as_current,
        created_at,
        updated_at
    FROM Vati_Payfiller_Candidate_Ecourt_Details
    WHERE application_id = p_application_id;
END
SQL;

$procedures['SP_Vati_Payfiller_save_ecourt_details'] = <<<'SQL'
CREATE PROCEDURE `SP_Vati_Payfiller_save_ecourt_details`(
    IN p_current_address TEXT,
    IN p_permanent_address TEXT,
    IN p_evidence_document VARCHAR(255),
    IN p_period_from_date DATE,
    IN p_period_to_date DATE,
    IN p_period_duration_years DECIMAL(5,2),
    IN p_dob DATE,
    IN p_on_hold TINYINT(1),
    IN p_not_applicable TINYINT(1),
    IN p_comments TEXT,
    IN p_application_id VARCHAR(50),
    IN p_applicant_legal_name VARCHAR(255),
    IN p_father_name VARCHAR(255),
    IN p_current_address_snapshot TEXT,
    IN p_permanent_address_snapshot TEXT,
    IN p_same_as_current TINYINT(1),
    IN p_verification_action VARCHAR(50),
    IN p_verification_notes TEXT
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    DECLARE v_old_file VARCHAR(255);
    DECLARE v_action_result VARCHAR(50);

    SELECT COUNT(*) INTO v_exists
    FROM Vati_Payfiller_Candidate_Ecourt_Details
    WHERE application_id = p_application_id;

    IF v_exists = 1 THEN
        SELECT evidence_document INTO v_old_file
        FROM Vati_Payfiller_Candidate_Ecourt_Details
        WHERE application_id = p_application_id;

        IF (p_evidence_document IS NULL OR p_evidence_document = '') AND v_old_file IS NOT NULL THEN
            SET p_evidence_document = v_old_file;
        END IF;

        UPDATE Vati_Payfiller_Candidate_Ecourt_Details
        SET 
            current_address = p_current_address,
            permanent_address = p_permanent_address,
            evidence_document = p_evidence_document,
            period_from_date = p_period_from_date,
            period_to_date = p_period_to_date,
            period_duration_years = p_period_duration_years,
            dob = p_dob,
            on_hold = p_on_hold,
            not_applicable = p_not_applicable,
            comments = p_comments,
            applicant_legal_name = p_applicant_legal_name,
            father_name = p_father_name,
            current_address_snapshot = p_current_address_snapshot,
            permanent_address_snapshot = p_permanent_address_snapshot,
            same_as_current = p_same_as_current,
            verification_status = CASE 
                WHEN p_verification_action = 'approve' THEN 'approved'
                WHEN p_verification_action = 'reject' THEN 'rejected'
                WHEN p_verification_action = 'objection' THEN 'objection_raised'
                ELSE verification_status
            END,
            verification_notes = COALESCE(p_verification_notes, verification_notes),
            verification_date = CASE 
                WHEN p_verification_action IS NOT NULL THEN CURRENT_TIMESTAMP
                ELSE verification_date
            END,
            updated_at = CURRENT_TIMESTAMP
        WHERE application_id = p_application_id;

        SET v_action_result = 'updated';
    ELSE
        INSERT INTO Vati_Payfiller_Candidate_Ecourt_Details (
            current_address,
            permanent_address,
            evidence_document,
            period_from_date,
            period_to_date,
            period_duration_years,
            dob,
            on_hold,
            not_applicable,
            comments,
            verification_status,
            verification_notes,
            verification_date,
            application_id,
            applicant_legal_name,
            father_name,
            current_address_snapshot,
            permanent_address_snapshot,
            same_as_current,
            created_at,
            updated_at
        ) VALUES (
            p_current_address,
            p_permanent_address,
            p_evidence_document,
            p_period_from_date,
            p_period_to_date,
            p_period_duration_years,
            p_dob,
            p_on_hold,
            p_not_applicable,
            p_comments,
            CASE 
                WHEN p_verification_action = 'approve' THEN 'approved'
                WHEN p_verification_action = 'reject' THEN 'rejected'
                WHEN p_verification_action = 'objection' THEN 'objection_raised'
                ELSE 'pending'
            END,
            p_verification_notes,
            CASE 
                WHEN p_verification_action IS NOT NULL THEN CURRENT_TIMESTAMP
                ELSE NULL
            END,
            p_application_id,
            p_applicant_legal_name,
            p_father_name,
            p_current_address_snapshot,
            p_permanent_address_snapshot,
            p_same_as_current,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        );

        SET v_action_result = 'created';
    END IF;

    SELECT v_action_result AS action,
           p_verification_action AS verification_action,
           CASE 
               WHEN p_verification_action = 'approve' THEN 'approved'
               WHEN p_verification_action = 'reject' THEN 'rejected'
               WHEN p_verification_action = 'objection' THEN 'objection_raised'
               ELSE 'pending'
           END AS verification_status;
END
SQL;

$procedures['SP_Vati_Payfiller_get_identification_details'] = <<<'SQL'
CREATE PROCEDURE `SP_Vati_Payfiller_get_identification_details`(
    IN p_application_id VARCHAR(50)
)
BEGIN
    SELECT 
        id,
        application_id,
        document_index,
        proof_group,
        documentId_type,
        id_number,
        name,
        upload_document,
        country,
        issue_date,
        expiry_date,
        is_complete,
        created_at,
        updated_at
    FROM Vati_Payfiller_Candidate_Identification_details
    WHERE application_id = p_application_id
    ORDER BY document_index ASC, proof_group ASC;
END
SQL;

$procedures['SP_Vati_Payfiller_store_identification_details'] = <<<'SQL'
CREATE PROCEDURE `SP_Vati_Payfiller_store_identification_details`(
    IN p_application_id VARCHAR(50),
    IN p_document_index INT,
    IN p_proof_group VARCHAR(32),
    IN p_documentId_type VARCHAR(50),
    IN p_id_number VARCHAR(100),
    IN p_name VARCHAR(255),
    IN p_upload_document VARCHAR(255),
    IN p_country VARCHAR(100),
    IN p_issue_date DATE,
    IN p_expiry_date DATE
)
BEGIN
    DECLARE v_old_file VARCHAR(255);
    DECLARE v_group VARCHAR(32);

    SET v_group = COALESCE(NULLIF(TRIM(p_proof_group), ''), 'primary');

    SELECT upload_document
    INTO v_old_file
    FROM Vati_Payfiller_Candidate_Identification_details
    WHERE application_id = p_application_id
      AND document_index = p_document_index
      AND proof_group = v_group
    LIMIT 1;

    IF p_upload_document IS NULL OR p_upload_document = '' THEN
        SET p_upload_document = v_old_file;
    END IF;

    SET @is_complete = 0;
    IF p_documentId_type IS NOT NULL AND p_documentId_type <> '' AND
       p_id_number IS NOT NULL AND p_id_number <> '' AND
       p_name IS NOT NULL AND p_name <> '' AND
       p_upload_document IS NOT NULL AND p_upload_document <> '' THEN
        SET @is_complete = 1;
    END IF;

    INSERT INTO Vati_Payfiller_Candidate_Identification_details
    (
        application_id,
        document_index,
        proof_group,
        documentId_type,
        id_number,
        name,
        upload_document,
        country,
        issue_date,
        expiry_date,
        is_complete,
        created_at,
        updated_at
    )
    VALUES
    (
        p_application_id,
        p_document_index,
        v_group,
        p_documentId_type,
        p_id_number,
        p_name,
        p_upload_document,
        p_country,
        p_issue_date,
        p_expiry_date,
        @is_complete,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    )
    ON DUPLICATE KEY UPDATE
        documentId_type = VALUES(documentId_type),
        id_number = VALUES(id_number),
        name = VALUES(name),
        upload_document = COALESCE(VALUES(upload_document), upload_document),
        country = VALUES(country),
        issue_date = VALUES(issue_date),
        expiry_date = VALUES(expiry_date),
        is_complete = VALUES(is_complete),
        updated_at = CURRENT_TIMESTAMP;
END
SQL;

foreach ($procedures as $name => $sql) {
    $pdo->exec("DROP PROCEDURE IF EXISTS `{$name}`");
    $pdo->exec($sql);
    echo "Updated {$name}\n";
}

echo "procedures-ok\n";
