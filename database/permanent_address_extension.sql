-- Safe Permanent Address extension.
-- Keeps address verification inside the existing `contact` workflow component.

INSERT INTO Vati_Payfiller_Verification_Types (type_name, type_category, is_active)
SELECT 'Permanent Address', 'Address Verification', 1
WHERE NOT EXISTS (
    SELECT 1
      FROM Vati_Payfiller_Verification_Types
     WHERE LOWER(TRIM(type_name)) = 'permanent address'
);

INSERT INTO Vati_Payfiller_Job_Role_Verification_Types
    (job_role_id, verification_type_id, stage_key, level_key, sort_order, is_enabled, required_count, component_key)
SELECT
    src.job_role_id,
    perm.verification_type_id,
    src.stage_key,
    src.level_key,
    src.sort_order + 1,
    src.is_enabled,
    src.required_count,
    'contact'
FROM Vati_Payfiller_Job_Role_Verification_Types src
JOIN Vati_Payfiller_Verification_Types src_vt
  ON src_vt.verification_type_id = src.verification_type_id
JOIN Vati_Payfiller_Verification_Types perm
  ON LOWER(TRIM(perm.type_name)) = 'permanent address'
WHERE LOWER(TRIM(src_vt.type_name)) = 'current or permanent address'
  AND NOT EXISTS (
      SELECT 1
        FROM Vati_Payfiller_Job_Role_Verification_Types existing
       WHERE existing.job_role_id = src.job_role_id
         AND existing.verification_type_id = perm.verification_type_id
         AND existing.stage_key = src.stage_key
         AND existing.level_key = src.level_key
  );

INSERT INTO Vati_Payfiller_Job_Role_Stage_Steps
    (job_role_id, stage_key, verification_type_id, execution_group, assigned_role, is_active)
SELECT
    src.job_role_id,
    src.stage_key,
    perm.verification_type_id,
    src.execution_group,
    src.assigned_role,
    src.is_active
FROM Vati_Payfiller_Job_Role_Stage_Steps src
JOIN Vati_Payfiller_Verification_Types src_vt
  ON src_vt.verification_type_id = src.verification_type_id
JOIN Vati_Payfiller_Verification_Types perm
  ON LOWER(TRIM(perm.type_name)) = 'permanent address'
WHERE LOWER(TRIM(src_vt.type_name)) = 'current or permanent address'
  AND NOT EXISTS (
      SELECT 1
        FROM Vati_Payfiller_Job_Role_Stage_Steps existing
       WHERE existing.job_role_id = src.job_role_id
         AND existing.stage_key = src.stage_key
         AND existing.verification_type_id = perm.verification_type_id
  );
