-- ============================================================
-- Migration: SP_Vati_Payfiller_GSS_Report_Cases_List
-- Replaces inline SQL in:
--   api/gssadmin/reports/candidate_component_report.php
-- All output column names are preserved exactly.
-- ============================================================

DROP PROCEDURE IF EXISTS SP_Vati_Payfiller_GSS_Report_Cases_List;

DELIMITER $$

CREATE PROCEDURE SP_Vati_Payfiller_GSS_Report_Cases_List(
    IN p_client_id  INT,
    IN p_search     VARCHAR(255)
)
BEGIN
    -- p_client_id : 0 = all clients, > 0 = filter to specific client
    -- p_search    : '' or NULL = no search filter, otherwise LIKE %p_search%

    DECLARE v_like VARCHAR(259);
    SET v_like = CONCAT('%', COALESCE(p_search, ''), '%');

    SELECT
        c.case_id,
        c.client_id,
        c.application_id,
        COALESCE(NULLIF(TRIM(c.workflow_mode), ''), 'validator_first')  AS workflow_mode,
        c.candidate_first_name,
        c.candidate_last_name,
        c.candidate_email,
        c.candidate_mobile,
        c.case_status,
        app.status                                                       AS application_status,
        c.created_at,
        comp.component_key,

        COALESCE(LOWER(TRIM(vv.status)), 'pending')                     AS validator_status,
        COALESCE(LOWER(TRIM(vr.status)), 'pending')                     AS verifier_status,
        COALESCE(LOWER(TRIM(vq.status)), 'pending')                     AS qa_status,

        COALESCE(vv.updated_at, vv.completed_at, NULL)                  AS validator_at,
        COALESCE(vr.updated_at, vr.completed_at, NULL)                  AS verifier_at,
        COALESCE(vq.updated_at, vq.completed_at, NULL)                  AS qa_at,

        CASE
            WHEN vq.status IS NOT NULL
                 AND GREATEST(
                     COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00'),
                     COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00'),
                     COALESCE(vv.updated_at, vv.completed_at, '1970-01-01 00:00:00')
                 ) = COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00')
                THEN 'qa'
            WHEN vr.status IS NOT NULL
                 AND GREATEST(
                     COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00'),
                     COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00'),
                     COALESCE(vv.updated_at, vv.completed_at, '1970-01-01 00:00:00')
                 ) = COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00')
                THEN 'verifier'
            WHEN COALESCE(NULLIF(TRIM(c.workflow_mode), ''), 'validator_first') = 'verifier_first'
                 AND vv.status IS NOT NULL
                THEN 'verifier'
            WHEN vv.status IS NOT NULL
                THEN 'validator'
            ELSE ''
        END                                                              AS latest_stage,

        CASE
            WHEN vq.status IS NOT NULL
                 AND GREATEST(
                     COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00'),
                     COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00'),
                     COALESCE(vv.updated_at, vv.completed_at, '1970-01-01 00:00:00')
                 ) = COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00')
                THEN LOWER(TRIM(vq.status))
            WHEN vr.status IS NOT NULL
                 AND GREATEST(
                     COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00'),
                     COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00'),
                     COALESCE(vv.updated_at, vv.completed_at, '1970-01-01 00:00:00')
                 ) = COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00')
                THEN LOWER(TRIM(vr.status))
            WHEN COALESCE(NULLIF(TRIM(c.workflow_mode), ''), 'validator_first') = 'verifier_first'
                 AND vv.status IS NOT NULL
                THEN 'pending'
            WHEN vv.status IS NOT NULL
                THEN LOWER(TRIM(vv.status))
            ELSE 'pending'
        END                                                              AS latest_status,

        CASE
            WHEN LOWER(TRIM(COALESCE(stoplog.actor_role, ''))) = 'gss_admin'
                THEN 'GA'
            WHEN LOWER(TRIM(COALESCE(stoplog.actor_role, ''))) IN ('client_admin', 'customer_admin')
                THEN 'CA'
            ELSE ''
        END                                                              AS stopped_by_short,

        COALESCE(stoplog.actor_role, '')                                 AS stopped_by_role,
        stoplog.created_at                                               AS stopped_at

    FROM Vati_Payfiller_Cases c

    CROSS JOIN (
        SELECT 'basic'       AS component_key UNION ALL
        SELECT 'id'                           UNION ALL
        SELECT 'contact'                      UNION ALL
        SELECT 'education'                    UNION ALL
        SELECT 'employment'                   UNION ALL
        SELECT 'reference'                    UNION ALL
        SELECT 'socialmedia'                  UNION ALL
        SELECT 'ecourt'                       UNION ALL
        SELECT 'reports'
    ) comp

    LEFT JOIN Vati_Payfiller_Candidate_Applications app
           ON app.application_id = c.application_id

    LEFT JOIN Vati_Payfiller_Case_Component_Workflow vv
           ON vv.case_id  = c.case_id
          AND REPLACE(LOWER(TRIM(vv.component_key)), '_', '') = REPLACE(comp.component_key, '_', '')
          AND LOWER(TRIM(vv.stage)) = 'validator'

    LEFT JOIN Vati_Payfiller_Case_Component_Workflow vr
           ON vr.case_id  = c.case_id
          AND REPLACE(LOWER(TRIM(vr.component_key)), '_', '') = REPLACE(comp.component_key, '_', '')
          AND LOWER(TRIM(vr.stage)) = 'verifier'

    LEFT JOIN Vati_Payfiller_Case_Component_Workflow vq
           ON vq.case_id  = c.case_id
          AND REPLACE(LOWER(TRIM(vq.component_key)), '_', '') = REPLACE(comp.component_key, '_', '')
          AND LOWER(TRIM(vq.stage)) = 'qa'

    LEFT JOIN Vati_Payfiller_Case_Timeline stoplog
           ON stoplog.timeline_id = (
               SELECT t2.timeline_id
                 FROM Vati_Payfiller_Case_Timeline t2
                WHERE t2.application_id = c.application_id
                  AND LOWER(TRIM(COALESCE(t2.actor_role, '')))
                          IN ('gss_admin', 'client_admin', 'customer_admin')
                  AND (
                          LOWER(TRIM(COALESCE(t2.message,     ''))) LIKE '%stop%'
                       OR LOWER(TRIM(COALESCE(t2.section_key, '')))
                                  IN ('case_status', 'stop_bgv', 'stop')
                      )
                ORDER BY t2.created_at DESC, t2.timeline_id DESC
                LIMIT 1
           )

    WHERE 1 = 1
      AND (p_client_id IS NULL OR p_client_id = 0 OR c.client_id = p_client_id)
      AND (
              p_search IS NULL
           OR p_search = ''
           OR c.candidate_first_name LIKE v_like
           OR c.candidate_last_name  LIKE v_like
           OR c.candidate_email      LIKE v_like
           OR c.candidate_mobile     LIKE v_like
           OR c.application_id       LIKE v_like
           OR c.case_status          LIKE v_like
          )

    ORDER BY c.created_at DESC, c.case_id DESC, comp.component_key ASC
    LIMIT 3500;

END$$

DELIMITER ;
