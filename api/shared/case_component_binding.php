<?php

function case_component_binding_str_contains_ci(string $haystack, string $needle): bool
{
    return stripos($haystack, $needle) !== false;
}

function case_component_binding_norm_component_key(string $key): string
{
    $key = strtolower(trim($key));
    if ($key === 'identification') {
        return 'id';
    }
    if ($key === 'social_media' || $key === 'social-media') {
        return 'socialmedia';
    }
    if ($key === 'driving' || $key === 'driving_license') {
        return 'driving_licence';
    }
    return $key;
}

function case_component_binding_map_verification_type_to_components(string $typeName, string $typeCategory): array
{
    $hay = strtolower(trim(($typeName !== '' ? $typeName : '') . ' ' . ($typeCategory !== '' ? $typeCategory : '')));
    $out = [];

    if (
        case_component_binding_str_contains_ci($hay, 'identification')
        || case_component_binding_str_contains_ci($hay, 'identity')
        || case_component_binding_str_contains_ci($hay, 'id verification')
        || case_component_binding_str_contains_ci($hay, 'kyc')
        || case_component_binding_str_contains_ci($hay, 'aadhaar')
        || case_component_binding_str_contains_ci($hay, 'aadhar')
        || case_component_binding_str_contains_ci($hay, 'pan')
        || case_component_binding_str_contains_ci($hay, 'passport')
        || case_component_binding_str_contains_ci($hay, 'voter')
        || case_component_binding_str_contains_ci($hay, 'national id')
    ) {
        $out[] = 'id';
    }

    if (
        case_component_binding_str_contains_ci($hay, 'education')
        || case_component_binding_str_contains_ci($hay, 'qualification')
        || case_component_binding_str_contains_ci($hay, 'degree')
        || case_component_binding_str_contains_ci($hay, 'college')
        || case_component_binding_str_contains_ci($hay, 'university')
    ) {
        $out[] = 'education';
    }

    if (
        case_component_binding_str_contains_ci($hay, 'employment')
        || case_component_binding_str_contains_ci($hay, 'employer')
        || case_component_binding_str_contains_ci($hay, 'experience')
        || case_component_binding_str_contains_ci($hay, 'work history')
    ) {
        $out[] = 'employment';
    }

    if (
        case_component_binding_str_contains_ci($hay, 'reference')
        || case_component_binding_str_contains_ci($hay, 'referee')
        || case_component_binding_str_contains_ci($hay, 'ref check')
        || case_component_binding_str_contains_ci($hay, 'ref-check')
    ) {
        $out[] = 'reference';
    }

    if (
        case_component_binding_str_contains_ci($hay, 'social')
        || case_component_binding_str_contains_ci($hay, 'linkedin')
        || case_component_binding_str_contains_ci($hay, 'facebook')
        || case_component_binding_str_contains_ci($hay, 'instagram')
        || case_component_binding_str_contains_ci($hay, 'twitter')
        || case_component_binding_str_contains_ci($hay, 'world check')
        || case_component_binding_str_contains_ci($hay, 'worldcheck')
    ) {
        $out[] = 'socialmedia';
    }

    if (
        case_component_binding_str_contains_ci($hay, 'ecourt')
        || case_component_binding_str_contains_ci($hay, 'e-court')
        || case_component_binding_str_contains_ci($hay, 'court')
        || case_component_binding_str_contains_ci($hay, 'litigation')
        || case_component_binding_str_contains_ci($hay, 'judis')
        || case_component_binding_str_contains_ci($hay, 'judicial')
        || case_component_binding_str_contains_ci($hay, 'manupatra')
    ) {
        $out[] = 'ecourt';
    }

    if (case_component_binding_str_contains_ci($hay, 'database')) {
        $out[] = 'database';
    }

    if (
        case_component_binding_str_contains_ci($hay, 'driving')
        || case_component_binding_str_contains_ci($hay, 'driver')
        || case_component_binding_str_contains_ci($hay, 'licence')
        || case_component_binding_str_contains_ci($hay, 'license')
        || case_component_binding_str_contains_ci($hay, 'dl')
    ) {
        $out[] = 'driving_licence';
    }

    return array_values(array_unique($out));
}

function case_component_binding_fetch_case(PDO $pdo, int $caseId, string $applicationId): ?array
{
    if ($caseId > 0) {
        $stmt = $pdo->prepare('SELECT case_id, client_id, job_role, application_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            return $row;
        }
    }

    if ($applicationId !== '') {
        $stmt = $pdo->prepare('SELECT case_id, client_id, job_role, application_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            return $row;
        }
    }

    return null;
}

function case_component_binding_fetch_job_role_id(PDO $pdo, array $case): int
{
    $clientId = isset($case['client_id']) ? (int)$case['client_id'] : 0;
    $jobRoleName = trim((string)($case['job_role'] ?? ''));
    if ($clientId <= 0 || $jobRoleName === '') {
        return 0;
    }

    $jr = $pdo->prepare('SELECT job_role_id FROM Vati_Payfiller_Job_Roles WHERE client_id = ? AND LOWER(TRIM(role_name)) = LOWER(TRIM(?)) LIMIT 1');
    $jr->execute([$clientId, $jobRoleName]);
    return (int)($jr->fetchColumn() ?: 0);
}

function case_component_binding_fetch_types(PDO $pdo, int $jobRoleId): array
{
    if ($jobRoleId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT j.verification_type_id, j.is_enabled, t.type_name, t.type_category
               FROM Vati_Payfiller_Job_Role_Verification_Types j
               LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
              WHERE j.job_role_id = ?
              ORDER BY COALESCE(j.stage_key, "") ASC, COALESCE(j.level_key, "") ASC, COALESCE(j.sort_order, 0) ASC, COALESCE(t.type_name, "") ASC'
        );
        $stmt->execute([$jobRoleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetVerificationTypesByJobRole(?)');
            $stmt->execute([$jobRoleId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            while ($stmt->nextRowset()) {
            }
            return $rows;
        } catch (Throwable $e2) {
            return [];
        }
    }
}

function case_component_binding_fetch_stage_steps(PDO $pdo, int $jobRoleId): array
{
    if ($jobRoleId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT verification_type_id, assigned_role, is_active
               FROM Vati_Payfiller_Job_Role_Stage_Steps
              WHERE job_role_id = ?
              ORDER BY stage_key ASC, execution_group ASC, verification_type_id ASC'
        );
        $stmt->execute([$jobRoleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function case_component_binding_build_for_case(PDO $pdo, int $caseId, string $applicationId = ''): array
{
    $config = [
        'case' => null,
        'job_role_id' => 0,
        'required_components' => ['basic', 'id'],
        'component_roles' => [],
        'has_role_binding' => false,
    ];

    $case = case_component_binding_fetch_case($pdo, $caseId, $applicationId);
    if (!$case) {
        return $config;
    }

    $config['case'] = $case;
    $config['job_role_id'] = case_component_binding_fetch_job_role_id($pdo, $case);
    if ($config['job_role_id'] <= 0) {
        $config['required_components'] = array_values(array_unique($config['required_components']));
        return $config;
    }

    $types = case_component_binding_fetch_types($pdo, $config['job_role_id']);
    $steps = case_component_binding_fetch_stage_steps($pdo, $config['job_role_id']);
    $typeComponentsById = [];

    foreach ($types as $t) {
        $vtId = isset($t['verification_type_id']) ? (int)$t['verification_type_id'] : 0;
        $isEnabled = isset($t['is_enabled']) ? (int)$t['is_enabled'] : 1;
        if ($vtId <= 0 || $isEnabled !== 1) {
            continue;
        }

        $components = case_component_binding_map_verification_type_to_components(
            (string)($t['type_name'] ?? ''),
            (string)($t['type_category'] ?? '')
        );
        if (!$components) {
            continue;
        }

        $typeComponentsById[$vtId] = $components;
        foreach ($components as $componentKey) {
            $config['required_components'][] = case_component_binding_norm_component_key($componentKey);
        }
    }

    foreach ($steps as $step) {
        $vtId = isset($step['verification_type_id']) ? (int)$step['verification_type_id'] : 0;
        $isActive = isset($step['is_active']) ? (int)$step['is_active'] : 1;
        $assignedRole = strtolower(trim((string)($step['assigned_role'] ?? '')));
        if ($vtId <= 0 || $isActive !== 1 || $assignedRole === '' || !isset($typeComponentsById[$vtId])) {
            continue;
        }

        foreach ($typeComponentsById[$vtId] as $componentKey) {
            $componentKey = case_component_binding_norm_component_key($componentKey);
            if ($componentKey === '') {
                continue;
            }
            if (!isset($config['component_roles'][$componentKey])) {
                $config['component_roles'][$componentKey] = [];
            }
            $config['component_roles'][$componentKey][$assignedRole] = true;
        }
    }

    $config['required_components'] = array_values(array_unique($config['required_components']));
    foreach ($config['component_roles'] as $roles) {
        if (!empty($roles)) {
            $config['has_role_binding'] = true;
            break;
        }
    }

    return $config;
}

function case_component_binding_sync_case_components(PDO $pdo, int $caseId, string $applicationId = ''): array
{
    $config = case_component_binding_build_for_case($pdo, $caseId, $applicationId);
    $case = $config['case'];
    if (!$case) {
        return $config;
    }

    $caseId = isset($case['case_id']) ? (int)$case['case_id'] : $caseId;
    $applicationId = $applicationId !== '' ? $applicationId : (string)($case['application_id'] ?? '');
    if ($caseId <= 0 || $applicationId === '') {
        return $config;
    }

    try {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO Vati_Payfiller_Case_Components (case_id, application_id, component_key, is_required, status) '
            . 'VALUES (?, ?, ?, 1, \'pending\')'
        );
        $upd = $pdo->prepare(
            'UPDATE Vati_Payfiller_Case_Components
             SET is_required = 1
             WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ?'
        );

        foreach ($config['required_components'] as $componentKey) {
            $componentKey = case_component_binding_norm_component_key((string)$componentKey);
            if ($componentKey === '') {
                continue;
            }
            $ins->execute([$caseId, $applicationId, $componentKey]);
            $upd->execute([$caseId, $applicationId, $componentKey]);
        }
    } catch (Throwable $e) {
        // Keep best-effort behavior; older environments may not have the table.
    }

    return $config;
}

function case_component_binding_role_allowed(PDO $pdo, int $caseId, string $applicationId, string $componentKey, string $role): ?bool
{
    $componentKey = case_component_binding_norm_component_key($componentKey);
    $role = strtolower(trim($role));
    if ($caseId <= 0 || $componentKey === '' || $role === '') {
        return null;
    }

    $config = case_component_binding_build_for_case($pdo, $caseId, $applicationId);
    if (empty($config['has_role_binding'])) {
        return null;
    }

    $componentRoles = $config['component_roles'][$componentKey] ?? [];
    if (empty($componentRoles)) {
        return null;
    }

    return isset($componentRoles[$role]);
}
