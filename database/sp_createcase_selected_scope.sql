-- Canonical create-case SP upgrade
-- Adds selected_level and selected_stage to create transaction boundary.

DROP PROCEDURE IF EXISTS `SP_Vati_Payfiller_CreateCase`;
DELIMITER $$
CREATE DEFINER=`grdbuser`@`%` PROCEDURE `SP_Vati_Payfiller_CreateCase`(
    p_client_id INT,
    p_created_by_user_id BIGINT,
    p_application_id VARCHAR(50),

    p_candidate_first_name VARCHAR(100),
    p_candidate_middle_name VARCHAR(100),
    p_candidate_last_name VARCHAR(100),
    p_candidate_dob DATE,
    p_candidate_father_name VARCHAR(150),

    p_candidate_mobile VARCHAR(30),
    p_candidate_email VARCHAR(255),
    p_candidate_state VARCHAR(100),
    p_candidate_city VARCHAR(100),

    p_joining_location VARCHAR(100),
    p_job_role VARCHAR(100),
    p_selected_level VARCHAR(30),
    p_selected_stage VARCHAR(30),

    p_recruiter_name VARCHAR(150),
    p_recruiter_email VARCHAR(255),

    p_candidate_reference_id VARCHAR(100),
    p_requisition_id VARCHAR(100),
    p_customer_cost_center VARCHAR(100),
    p_rehire_candidate TINYINT(1)
)
BEGIN
    DECLARE v_case_id BIGINT UNSIGNED;

    INSERT INTO Vati_Payfiller_Cases (
        client_id,
        created_by_user_id,
        application_id,
        candidate_first_name,
        candidate_middle_name,
        candidate_last_name,
        candidate_dob,
        candidate_father_name,
        candidate_mobile,
        candidate_email,
        candidate_state,
        candidate_city,
        joining_location,
        job_role,
        selected_level,
        selected_stage,
        recruiter_name,
        recruiter_email,
        candidate_reference_id,
        requisition_id,
        customer_cost_center,
        rehire_candidate,
        case_status
    ) VALUES (
        NULLIF(p_client_id, 0),
        NULLIF(p_created_by_user_id, 0),
        p_application_id,
        p_candidate_first_name,
        NULLIF(p_candidate_middle_name, ''),
        p_candidate_last_name,
        p_candidate_dob,
        p_candidate_father_name,
        p_candidate_mobile,
        p_candidate_email,
        p_candidate_state,
        p_candidate_city,
        p_joining_location,
        p_job_role,
        NULLIF(p_selected_level, ''),
        NULLIF(p_selected_stage, ''),
        p_recruiter_name,
        p_recruiter_email,
        NULLIF(p_candidate_reference_id, ''),
        NULLIF(p_requisition_id, ''),
        NULLIF(p_customer_cost_center, ''),
        IFNULL(p_rehire_candidate, 0),
        'DRAFT'
    );

    SET v_case_id = LAST_INSERT_ID();

    INSERT IGNORE INTO Vati_Payfiller_Case_Components
    (case_id, application_id, component_key, is_required, status, created_at, updated_at)
    VALUES
    (v_case_id, p_application_id, 'basic', 1, 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'id', 1, 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'education', 1, 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'employment', 1, 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'reference', 1, 'pending', NOW(), NOW());

    INSERT IGNORE INTO Vati_Payfiller_Case_Component_Workflow
    (case_id, application_id, component_key, stage, status, created_at, updated_at)
    VALUES
    (v_case_id, p_application_id, 'basic', 'candidate', 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'basic', 'validator', 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'id', 'candidate', 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'id', 'validator', 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'education', 'candidate', 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'education', 'validator', 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'employment', 'candidate', 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'employment', 'validator', 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'reference', 'candidate', 'pending', NOW(), NOW()),
    (v_case_id, p_application_id, 'reference', 'validator', 'pending', NOW(), NOW());

    INSERT INTO Vati_Payfiller_Case_Timeline
    (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at)
    VALUES
    (
        p_application_id,
        NULLIF(p_created_by_user_id,0),
        'client_admin',
        'create',
        'case',
        'Case created and workflow initialized',
        NOW()
    );

    SELECT v_case_id AS case_id;
END$$
DELIMITER ;

