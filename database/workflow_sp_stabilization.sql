-- Workflow/Queue Stored Procedure Stabilization
-- Date: 2026-05-13
-- Scope: Validator/Verifier queue persistence semantics
-- Goal: prevent legacy queue collapsing and align with evaluation-centric workflow.

-- =========================================================
-- PRE-CHECKS / BACKUP HELPERS
-- =========================================================
-- Run before applying:
-- SHOW CREATE PROCEDURE vati.bgv.payfiller.com.SP_Vati_Payfiller_VAL_EnsureQueue;
-- SHOW CREATE PROCEDURE vati.bgv.payfiller.com.SP_Vati_Payfiller_VAL_ListAvailable;
-- SHOW CREATE PROCEDURE vati.bgv.payfiller.com.SP_Vati_Payfiller_VAL_ListMine;
-- SHOW CREATE PROCEDURE vati.bgv.payfiller.com.SP_Vati_Payfiller_VR_EnsureGroupQueue;
-- SHOW CREATE PROCEDURE vati.bgv.payfiller.com.SP_Vati_Payfiller_VR_ListAvailable;
-- SHOW CREATE PROCEDURE vati.bgv.payfiller.com.SP_Vati_Payfiller_VR_ListMine;

-- =========================================================
-- 1) Validator queue seed should only include submitted candidate cases.
--    Also avoid re-seeding terminally closed case rows.
-- =========================================================
DROP PROCEDURE IF EXISTS `SP_Vati_Payfiller_VAL_EnsureQueue`;
DELIMITER $$
CREATE DEFINER=`grdbuser`@`%` PROCEDURE `SP_Vati_Payfiller_VAL_EnsureQueue`(
    IN p_client_id INT
)
BEGIN
    INSERT IGNORE INTO Vati_Payfiller_Validator_Queue (
        case_id,
        client_id,
        application_id,
        status,
        assigned_user_id,
        claimed_at,
        completed_at
    )
    SELECT c.case_id,
           c.client_id,
           c.application_id,
           'pending' AS status,
           NULL,
           NULL,
           NULL
      FROM Vati_Payfiller_Cases c
      JOIN Vati_Payfiller_Candidate_Applications ca
        ON ca.application_id = c.application_id
     WHERE (p_client_id IS NULL OR p_client_id = 0 OR c.client_id = p_client_id)
       AND LOWER(TRIM(COALESCE(ca.status, ''))) = 'submitted'
       AND UPPER(TRIM(COALESCE(c.case_status, ''))) NOT IN ('APPROVED', 'COMPLETED', 'CLEAR', 'STOP_BGV', 'REJECTED', 'ARCHIVED', 'TERMINATED');
END$$
DELIMITER ;

-- =========================================================
-- 2) Validator available list should show only active queue states.
-- =========================================================
DROP PROCEDURE IF EXISTS `SP_Vati_Payfiller_VAL_ListAvailable`;
DELIMITER $$
CREATE DEFINER=`grdbuser`@`%` PROCEDURE `SP_Vati_Payfiller_VAL_ListAvailable`(
    IN p_client_id INT,
    IN p_search VARCHAR(100)
)
BEGIN
    CALL SP_Vati_Payfiller_VAL_EnsureQueue(p_client_id);

    SELECT q.id,
           q.case_id,
           q.application_id,
           q.client_id,
           q.status,
           q.assigned_user_id,
           q.claimed_at,
           q.completed_at,
           c.candidate_first_name,
           c.candidate_last_name,
           c.candidate_email,
           c.candidate_mobile,
           c.case_status,
           c.created_at
      FROM Vati_Payfiller_Validator_Queue q
      JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id
      JOIN Vati_Payfiller_Candidate_Applications ca ON ca.application_id = c.application_id
     WHERE (p_client_id IS NULL OR p_client_id = 0 OR q.client_id = p_client_id)
       AND q.completed_at IS NULL
       AND q.assigned_user_id IS NULL
       AND LOWER(TRIM(COALESCE(q.status, 'pending'))) IN ('pending','in_progress','waiting_candidate','hold','insufficient_documents','reopened','blocked','followup')
       AND LOWER(TRIM(COALESCE(ca.status, ''))) = 'submitted'
       AND (p_search IS NULL OR p_search = ''
            OR c.application_id LIKE CONCAT('%', p_search, '%')
            OR c.candidate_first_name LIKE CONCAT('%', p_search, '%')
            OR c.candidate_last_name LIKE CONCAT('%', p_search, '%')
            OR c.candidate_email LIKE CONCAT('%', p_search, '%')
            OR c.candidate_mobile LIKE CONCAT('%', p_search, '%'))
     ORDER BY c.created_at ASC
     LIMIT 200;
END$$
DELIMITER ;

-- =========================================================
-- 3) Validator mine list should show only active queue states.
-- =========================================================
DROP PROCEDURE IF EXISTS `SP_Vati_Payfiller_VAL_ListMine`;
DELIMITER $$
CREATE DEFINER=`grdbuser`@`%` PROCEDURE `SP_Vati_Payfiller_VAL_ListMine`(
    IN p_user_id INT,
    IN p_client_id INT,
    IN p_search VARCHAR(100)
)
BEGIN
    CALL SP_Vati_Payfiller_VAL_EnsureQueue(p_client_id);

    SELECT q.id,
           q.case_id,
           q.application_id,
           q.client_id,
           q.status,
           q.assigned_user_id,
           q.claimed_at,
           q.completed_at,
           c.candidate_first_name,
           c.candidate_last_name,
           c.candidate_email,
           c.candidate_mobile,
           c.case_status,
           c.created_at
      FROM Vati_Payfiller_Validator_Queue q
      JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id
      JOIN Vati_Payfiller_Candidate_Applications ca ON ca.application_id = c.application_id
     WHERE (p_client_id IS NULL OR p_client_id = 0 OR q.client_id = p_client_id)
       AND q.assigned_user_id = p_user_id
       AND q.completed_at IS NULL
       AND LOWER(TRIM(COALESCE(q.status, 'pending'))) IN ('pending','in_progress','waiting_candidate','hold','insufficient_documents','reopened','blocked','followup')
       AND LOWER(TRIM(COALESCE(ca.status, ''))) = 'submitted'
       AND (p_search IS NULL OR p_search = ''
            OR c.application_id LIKE CONCAT('%', p_search, '%')
            OR c.candidate_first_name LIKE CONCAT('%', p_search, '%')
            OR c.candidate_last_name LIKE CONCAT('%', p_search, '%')
            OR c.candidate_email LIKE CONCAT('%', p_search, '%')
            OR c.candidate_mobile LIKE CONCAT('%', p_search, '%'))
     ORDER BY q.claimed_at DESC, c.created_at ASC
     LIMIT 200;
END$$
DELIMITER ;

-- =========================================================
-- 4) Verifier queue seeding must not depend on validator_queue.completed_at.
--    Use workflow evaluator semantics instead.
-- =========================================================
DROP PROCEDURE IF EXISTS `SP_Vati_Payfiller_VR_EnsureGroupQueue`;
DELIMITER $$
CREATE DEFINER=`grdbuser`@`%` PROCEDURE `SP_Vati_Payfiller_VR_EnsureGroupQueue`(
    IN p_client_id INT
)
BEGIN
    /*
      Evaluation-centric gate:
      Seed verifier group rows when all required validator-stage components
      are evaluated (approved/rejected/hold/insufficient_documents/completed/clear/verified).
      Do not require validator queue completed_at.
    */

    INSERT IGNORE INTO Vati_Payfiller_Verifier_Group_Queue (
        case_id,
        client_id,
        application_id,
        group_key,
        status,
        assigned_user_id,
        dedicated_user_id,
        claimed_at,
        completed_at
    )
    SELECT c.case_id,
           c.client_id,
           c.application_id,
           'BASIC' AS group_key,
           'pending' AS status,
           NULL,
           NULL,
           NULL,
           NULL
      FROM Vati_Payfiller_Cases c
     WHERE (p_client_id IS NULL OR p_client_id = 0 OR c.client_id = p_client_id)
       AND NOT EXISTS (
            SELECT 1
              FROM Vati_Payfiller_Case_Components cc
              LEFT JOIN Vati_Payfiller_Case_Component_Workflow w
                ON w.case_id = cc.case_id
               AND LOWER(TRIM(w.component_key)) = LOWER(TRIM(cc.component_key))
               AND w.stage = 'validator'
             WHERE cc.case_id = c.case_id
               AND cc.is_required = 1
               AND LOWER(TRIM(cc.component_key)) <> 'reports'
               AND COALESCE(LOWER(TRIM(w.status)), 'pending')
                   NOT IN ('approved','rejected','hold','insufficient_documents','completed','clear','verified')
       );

    INSERT IGNORE INTO Vati_Payfiller_Verifier_Group_Queue (
        case_id,
        client_id,
        application_id,
        group_key,
        status,
        assigned_user_id,
        dedicated_user_id,
        claimed_at,
        completed_at
    )
    SELECT c.case_id,
           c.client_id,
           c.application_id,
           'EDUCATION' AS group_key,
           'pending' AS status,
           NULL,
           NULL,
           NULL,
           NULL
      FROM Vati_Payfiller_Cases c
     WHERE (p_client_id IS NULL OR p_client_id = 0 OR c.client_id = p_client_id)
       AND NOT EXISTS (
            SELECT 1
              FROM Vati_Payfiller_Case_Components cc
              LEFT JOIN Vati_Payfiller_Case_Component_Workflow w
                ON w.case_id = cc.case_id
               AND LOWER(TRIM(w.component_key)) = LOWER(TRIM(cc.component_key))
               AND w.stage = 'validator'
             WHERE cc.case_id = c.case_id
               AND cc.is_required = 1
               AND LOWER(TRIM(cc.component_key)) <> 'reports'
               AND COALESCE(LOWER(TRIM(w.status)), 'pending')
                   NOT IN ('approved','rejected','hold','insufficient_documents','completed','clear','verified')
       );

    -- Apply dedicated assignment rules (client_id + group_key)
    UPDATE Vati_Payfiller_Verifier_Group_Queue q
    JOIN Vati_Payfiller_VR_Assignment_Rules r
      ON (r.client_id <=> q.client_id)
     AND UPPER(TRIM(r.group_key)) = UPPER(TRIM(q.group_key))
     AND r.is_active = 1
     AND LOWER(TRIM(r.mode)) = 'dedicated'
     AND r.dedicated_user_id IS NOT NULL
     SET q.dedicated_user_id = r.dedicated_user_id
   WHERE (p_client_id IS NULL OR p_client_id = 0 OR q.client_id = p_client_id)
     AND q.completed_at IS NULL
     AND q.assigned_user_id IS NULL;

    -- Clear dedicated_user_id when rule is not dedicated
    UPDATE Vati_Payfiller_Verifier_Group_Queue q
    LEFT JOIN Vati_Payfiller_VR_Assignment_Rules r
      ON (r.client_id <=> q.client_id)
     AND UPPER(TRIM(r.group_key)) = UPPER(TRIM(q.group_key))
     AND r.is_active = 1
   SET q.dedicated_user_id = NULL
 WHERE (p_client_id IS NULL OR p_client_id = 0 OR q.client_id = p_client_id)
   AND q.completed_at IS NULL
   AND q.assigned_user_id IS NULL
   AND (r.rule_id IS NULL OR LOWER(TRIM(r.mode)) <> 'dedicated');
END$$
DELIMITER ;

-- =========================================================
-- 5) Verifier available list should only surface active queue rows.
-- =========================================================
DROP PROCEDURE IF EXISTS `SP_Vati_Payfiller_VR_ListAvailable`;
DELIMITER $$
CREATE DEFINER=`grdbuser`@`%` PROCEDURE `SP_Vati_Payfiller_VR_ListAvailable`(
    IN p_user_id INT,
    IN p_client_id INT,
    IN p_group_key VARCHAR(30),
    IN p_search VARCHAR(100)
)
BEGIN
    CALL SP_Vati_Payfiller_VR_EnsureGroupQueue(p_client_id);

    SELECT q.id,
           q.case_id,
           q.application_id,
           q.client_id,
           q.group_key,
           q.status,
           q.assigned_user_id,
           q.claimed_at,
           q.completed_at,
           c.candidate_first_name,
           c.candidate_last_name,
           c.candidate_email,
           c.candidate_mobile,
           c.case_status,
           c.created_at
      FROM Vati_Payfiller_Verifier_Group_Queue q
      JOIN Vati_Payfiller_Cases c
        ON c.case_id = q.case_id
     WHERE (p_client_id IS NULL OR p_client_id = 0 OR q.client_id = p_client_id)
       AND (p_group_key IS NULL OR p_group_key = '' OR q.group_key = p_group_key)
       AND (q.dedicated_user_id IS NULL OR q.dedicated_user_id = p_user_id)
       AND q.completed_at IS NULL
       AND (q.assigned_user_id IS NULL)
       AND LOWER(TRIM(COALESCE(q.status, 'pending'))) IN ('pending','in_progress','waiting_candidate','hold','insufficient_documents','reopened','blocked','followup')
       AND (p_search IS NULL OR p_search = ''
            OR c.application_id LIKE CONCAT('%', p_search, '%')
            OR c.candidate_first_name LIKE CONCAT('%', p_search, '%')
            OR c.candidate_last_name LIKE CONCAT('%', p_search, '%')
            OR c.candidate_email LIKE CONCAT('%', p_search, '%')
            OR c.candidate_mobile LIKE CONCAT('%', p_search, '%'))
     ORDER BY c.created_at ASC
     LIMIT 200;
END$$
DELIMITER ;

-- =========================================================
-- 6) Verifier mine list should only surface active queue rows.
-- =========================================================
DROP PROCEDURE IF EXISTS `SP_Vati_Payfiller_VR_ListMine`;
DELIMITER $$
CREATE DEFINER=`grdbuser`@`%` PROCEDURE `SP_Vati_Payfiller_VR_ListMine`(
    IN p_user_id INT,
    IN p_client_id INT,
    IN p_group_key VARCHAR(30),
    IN p_search VARCHAR(100)
)
BEGIN
    CALL SP_Vati_Payfiller_VR_EnsureGroupQueue(p_client_id);

    SELECT q.id,
           q.case_id,
           q.application_id,
           q.client_id,
           q.group_key,
           q.status,
           q.assigned_user_id,
           q.claimed_at,
           q.completed_at,
           c.candidate_first_name,
           c.candidate_last_name,
           c.candidate_email,
           c.candidate_mobile,
           c.case_status,
           c.created_at
      FROM Vati_Payfiller_Verifier_Group_Queue q
      JOIN Vati_Payfiller_Cases c
        ON c.case_id = q.case_id
     WHERE (p_client_id IS NULL OR p_client_id = 0 OR q.client_id = p_client_id)
       AND (p_group_key IS NULL OR p_group_key = '' OR q.group_key = p_group_key)
       AND q.assigned_user_id = p_user_id
       AND q.completed_at IS NULL
       AND LOWER(TRIM(COALESCE(q.status, 'pending'))) IN ('pending','in_progress','waiting_candidate','hold','insufficient_documents','reopened','blocked','followup')
       AND (p_search IS NULL OR p_search = ''
            OR c.application_id LIKE CONCAT('%', p_search, '%')
            OR c.candidate_first_name LIKE CONCAT('%', p_search, '%')
            OR c.candidate_last_name LIKE CONCAT('%', p_search, '%')
            OR c.candidate_email LIKE CONCAT('%', p_search, '%')
            OR c.candidate_mobile LIKE CONCAT('%', p_search, '%'))
     ORDER BY q.claimed_at DESC, c.created_at ASC
     LIMIT 200;
END$$
DELIMITER ;

-- =========================================================
-- OPTIONAL DEBUG CHECKS (manual run)
-- =========================================================
-- SELECT status, COUNT(*) FROM Vati_Payfiller_Validator_Queue GROUP BY status;
-- SELECT status, COUNT(*) FROM Vati_Payfiller_Verifier_Group_Queue GROUP BY status;
-- SELECT q.case_id, q.status, q.completed_at FROM Vati_Payfiller_Validator_Queue q WHERE q.case_id = ?;
-- SELECT q.case_id, q.group_key, q.status, q.completed_at FROM Vati_Payfiller_Verifier_Group_Queue q WHERE q.case_id = ?;

