-- Component Binding Stabilization
-- Purpose:
-- 1) Audit wrongly seeded required components in Vati_Payfiller_Case_Components
-- 2) Safely convert mismatched rows to non-required (readonly-visible via UI template)
-- 3) Preserve internal operational component `reports`

-- ------------------------------------------------------------
-- A) Audit: expected vs actual required components per case
-- ------------------------------------------------------------
SELECT
    c.case_id,
    c.application_id,
    LOWER(TRIM(cc.component_key)) AS component_key,
    cc.is_required,
    cc.assigned_role,
    cc.assigned_user_id,
    CASE
        WHEN LOWER(TRIM(cc.component_key)) = 'reports' THEN 1
        WHEN EXISTS (
            SELECT 1
            FROM Vati_Payfiller_Job_Roles jr
            JOIN Vati_Payfiller_Job_Role_Verification_Types jv
              ON jv.job_role_id = jr.job_role_id
            WHERE jr.client_id = c.client_id
              AND LOWER(TRIM(jr.role_name)) = LOWER(TRIM(c.job_role))
              AND COALESCE(jv.is_enabled, 1) = 1
              AND LOWER(TRIM(COALESCE(jv.level_key, ''))) = LOWER(TRIM(COALESCE(c.selected_level, '')))
              AND (
                    CASE LOWER(TRIM(COALESCE(jv.stage_key, '')))
                      WHEN 'pre_interview' THEN 'p1'
                      WHEN 'post_interview' THEN 'p2'
                      WHEN 'employee_pool' THEN 'p3'
                      ELSE LOWER(TRIM(COALESCE(jv.stage_key, '')))
                    END
                  ) = (
                    CASE LOWER(TRIM(COALESCE(c.selected_stage, '')))
                      WHEN 'pre_interview' THEN 'p1'
                      WHEN 'post_interview' THEN 'p2'
                      WHEN 'employee_pool' THEN 'p3'
                      ELSE LOWER(TRIM(COALESCE(c.selected_stage, '')))
                    END
                  )
              AND LOWER(TRIM(COALESCE(jv.component_key, ''))) = LOWER(TRIM(cc.component_key))
        ) THEN 1
        ELSE 0
    END AS expected_required
FROM Vati_Payfiller_Case_Components cc
JOIN Vati_Payfiller_Cases c ON c.case_id = cc.case_id
WHERE cc.is_required = 1
ORDER BY c.case_id DESC, component_key;

-- ------------------------------------------------------------
-- B) Backup candidate rows before cleanup
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Vati_Payfiller_Case_Components_Backup_Stabilization AS
SELECT * FROM Vati_Payfiller_Case_Components WHERE 1 = 0;

INSERT INTO Vati_Payfiller_Case_Components_Backup_Stabilization
SELECT cc.*
FROM Vati_Payfiller_Case_Components cc
JOIN Vati_Payfiller_Cases c ON c.case_id = cc.case_id
WHERE cc.is_required = 1
  AND LOWER(TRIM(cc.component_key)) <> 'reports'
  AND NOT EXISTS (
        SELECT 1
        FROM Vati_Payfiller_Job_Roles jr
        JOIN Vati_Payfiller_Job_Role_Verification_Types jv
          ON jv.job_role_id = jr.job_role_id
        WHERE jr.client_id = c.client_id
          AND LOWER(TRIM(jr.role_name)) = LOWER(TRIM(c.job_role))
          AND COALESCE(jv.is_enabled, 1) = 1
          AND LOWER(TRIM(COALESCE(jv.level_key, ''))) = LOWER(TRIM(COALESCE(c.selected_level, '')))
          AND (
                CASE LOWER(TRIM(COALESCE(jv.stage_key, '')))
                  WHEN 'pre_interview' THEN 'p1'
                  WHEN 'post_interview' THEN 'p2'
                  WHEN 'employee_pool' THEN 'p3'
                  ELSE LOWER(TRIM(COALESCE(jv.stage_key, '')))
                END
              ) = (
                CASE LOWER(TRIM(COALESCE(c.selected_stage, '')))
                  WHEN 'pre_interview' THEN 'p1'
                  WHEN 'post_interview' THEN 'p2'
                  WHEN 'employee_pool' THEN 'p3'
                  ELSE LOWER(TRIM(COALESCE(c.selected_stage, '')))
                END
              )
          AND LOWER(TRIM(COALESCE(jv.component_key, ''))) = LOWER(TRIM(cc.component_key))
    );

-- ------------------------------------------------------------
-- C) Cleanup: make mismatched rows readonly/passive
-- ------------------------------------------------------------
UPDATE Vati_Payfiller_Case_Components cc
JOIN Vati_Payfiller_Cases c ON c.case_id = cc.case_id
SET
    cc.is_required = 0,
    cc.assigned_role = NULL,
    cc.assigned_user_id = NULL,
    cc.status = 'pending',
    cc.completed_at = NULL,
    cc.updated_at = NOW()
WHERE cc.is_required = 1
  AND LOWER(TRIM(cc.component_key)) <> 'reports'
  AND NOT EXISTS (
        SELECT 1
        FROM Vati_Payfiller_Job_Roles jr
        JOIN Vati_Payfiller_Job_Role_Verification_Types jv
          ON jv.job_role_id = jr.job_role_id
        WHERE jr.client_id = c.client_id
          AND LOWER(TRIM(jr.role_name)) = LOWER(TRIM(c.job_role))
          AND COALESCE(jv.is_enabled, 1) = 1
          AND LOWER(TRIM(COALESCE(jv.level_key, ''))) = LOWER(TRIM(COALESCE(c.selected_level, '')))
          AND (
                CASE LOWER(TRIM(COALESCE(jv.stage_key, '')))
                  WHEN 'pre_interview' THEN 'p1'
                  WHEN 'post_interview' THEN 'p2'
                  WHEN 'employee_pool' THEN 'p3'
                  ELSE LOWER(TRIM(COALESCE(jv.stage_key, '')))
                END
              ) = (
                CASE LOWER(TRIM(COALESCE(c.selected_stage, '')))
                  WHEN 'pre_interview' THEN 'p1'
                  WHEN 'post_interview' THEN 'p2'
                  WHEN 'employee_pool' THEN 'p3'
                  ELSE LOWER(TRIM(COALESCE(c.selected_stage, '')))
                END
              )
          AND LOWER(TRIM(COALESCE(jv.component_key, ''))) = LOWER(TRIM(cc.component_key))
    );

-- ------------------------------------------------------------
-- D) Verification query after cleanup
-- ------------------------------------------------------------
SELECT
    c.case_id,
    c.application_id,
    LOWER(TRIM(cc.component_key)) AS component_key,
    cc.is_required,
    cc.assigned_role,
    cc.assigned_user_id
FROM Vati_Payfiller_Case_Components cc
JOIN Vati_Payfiller_Cases c ON c.case_id = cc.case_id
WHERE cc.is_required = 1
ORDER BY c.case_id DESC, component_key;

