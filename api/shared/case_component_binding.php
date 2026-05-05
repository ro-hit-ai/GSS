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
        case_component_binding_str_contains_ci($hay, 'contact')
        || case_component_binding_str_contains_ci($hay, 'address')
        || case_component_binding_str_contains_ci($hay, 'current address')
        || case_component_binding_str_contains_ci($hay, 'permanent address')
        || case_component_binding_str_contains_ci($hay, 'residence')
    ) {
        $out[] = 'contact';
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
    $withLevelSqlByCaseId = 'SELECT case_id, client_id, job_role, application_id, selected_level, selected_stage FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1';
    $withLevelSqlByAppId = 'SELECT case_id, client_id, job_role, application_id, selected_level, selected_stage FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1';
    $fallbackSqlByCaseId = 'SELECT case_id, client_id, job_role, application_id, "" AS selected_level, "" AS selected_stage FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1';
    $fallbackSqlByAppId = 'SELECT case_id, client_id, job_role, application_id, "" AS selected_level, "" AS selected_stage FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1';

    if ($caseId > 0) {
        try {
            $stmt = $pdo->prepare($withLevelSqlByCaseId);
            $stmt->execute([$caseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            $stmt = $pdo->prepare($fallbackSqlByCaseId);
            $stmt->execute([$caseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                return $row;
            }
        }
    }

    if ($applicationId !== '') {
        try {
            $stmt = $pdo->prepare($withLevelSqlByAppId);
            $stmt->execute([$applicationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            $stmt = $pdo->prepare($fallbackSqlByAppId);
            $stmt->execute([$applicationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                return $row;
            }
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

function case_component_binding_fetch_types(PDO $pdo, int $jobRoleId, string $selectedLevel = '', string $selectedStage = ''): array
{
    if ($jobRoleId <= 0) {
        return [];
    }
    $selectedLevel = strtolower(trim($selectedLevel));
    $selectedStage = strtolower(trim($selectedStage));
    if (strpos($selectedStage, '__') !== false) {
        $selectedStage = explode('__', $selectedStage, 2)[0];
    }
    if ($selectedStage === 'pre_interview') $selectedStage = 'p1';
    if ($selectedStage === 'post_interview') $selectedStage = 'p2';
    if ($selectedStage === 'employee_pool') $selectedStage = 'p3';

    // Strict stage-scoped resolution: do not materialize mixed-stage components.
    if ($selectedLevel === '' || $selectedStage === '') {
        error_log('DEBUG_STAGE: level=' . $selectedLevel . ', stage=' . $selectedStage . ' (strict mode: empty filter -> no types)');
        return [];
    }
    error_log('DEBUG_STAGE: level=' . $selectedLevel . ', stage=' . $selectedStage);

    try {
        $params = [$jobRoleId, $selectedLevel, $selectedStage];

        // Prefer DB-driven component_key from mapping table; fallback to type table if available.
        try {
            $sql = 'SELECT j.verification_type_id, j.is_enabled, t.type_name, t.type_category, j.level_key, j.stage_key,
                           COALESCE(NULLIF(TRIM(j.component_key), \'\'), NULLIF(TRIM(t.component_key), \'\'), \'\') AS component_key
                      FROM Vati_Payfiller_Job_Role_Verification_Types j
                 LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
                     WHERE j.job_role_id = ?
                       AND LOWER(TRIM(COALESCE(j.level_key, ""))) = ?
                       AND (
                            CASE LOWER(TRIM(COALESCE(j.stage_key, "")))
                                WHEN "pre_interview" THEN "p1"
                                WHEN "post_interview" THEN "p2"
                                WHEN "employee_pool" THEN "p3"
                                ELSE LOWER(TRIM(COALESCE(j.stage_key, "")))
                            END
                       ) = ?
                  ORDER BY COALESCE(j.stage_key, "") ASC, COALESCE(j.level_key, "") ASC, COALESCE(j.sort_order, 0) ASC, COALESCE(t.type_name, "") ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            error_log('FETCHED TYPES: ' . json_encode($rows));
            return $rows;
        } catch (Throwable $e1) {
            $sql = 'SELECT j.verification_type_id, j.is_enabled, t.type_name, t.type_category, j.level_key, j.stage_key,
                           \'\' AS component_key
                      FROM Vati_Payfiller_Job_Role_Verification_Types j
                 LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
                     WHERE j.job_role_id = ?
                       AND LOWER(TRIM(COALESCE(j.level_key, ""))) = ?
                       AND (
                            CASE LOWER(TRIM(COALESCE(j.stage_key, "")))
                                WHEN "pre_interview" THEN "p1"
                                WHEN "post_interview" THEN "p2"
                                WHEN "employee_pool" THEN "p3"
                                ELSE LOWER(TRIM(COALESCE(j.stage_key, "")))
                            END
                       ) = ?
                  ORDER BY COALESCE(j.stage_key, "") ASC, COALESCE(j.level_key, "") ASC, COALESCE(j.sort_order, 0) ASC, COALESCE(t.type_name, "") ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            error_log('FETCHED TYPES: ' . json_encode($rows));
            return $rows;
        }
    } catch (Throwable $e) {
        return [];
    }
}

function case_component_binding_fetch_stage_steps(PDO $pdo, int $jobRoleId, string $selectedStage = ''): array
{
    if ($jobRoleId <= 0) {
        return [];
    }
    $selectedStage = strtolower(trim($selectedStage));

    try {
        $sql = 'SELECT verification_type_id, assigned_role, is_active, stage_key
                  FROM Vati_Payfiller_Job_Role_Stage_Steps
                 WHERE job_role_id = ?';
        $params = [$jobRoleId];
        if ($selectedStage !== '') {
            $sql .= ' AND LOWER(TRIM(COALESCE(stage_key, ""))) = ?';
            $params[] = $selectedStage;
        }
        $sql .= ' ORDER BY stage_key ASC, execution_group ASC, verification_type_id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
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

    $selectedLevel = strtolower(trim((string)($case['selected_level'] ?? '')));
    $selectedStage = strtolower(trim((string)($case['selected_stage'] ?? '')));
    if (strpos($selectedStage, '__') !== false) {
        $selectedStage = explode('__', $selectedStage, 2)[0];
    }
    $types = case_component_binding_fetch_types($pdo, $config['job_role_id'], $selectedLevel, $selectedStage);
    if (empty($types)) {
        error_log('NO_MAPPING_FOUND: role_id=' . (string)$config['job_role_id'] . ', level=' . $selectedLevel . ', stage=' . $selectedStage);
        throw new RuntimeException('Snapshot mapping missing — cannot proceed');
    }
    $steps = case_component_binding_fetch_stage_steps($pdo, $config['job_role_id'], $selectedStage);
    $typeComponentsById = [];

    foreach ($types as $t) {
        $vtId = isset($t['verification_type_id']) ? (int)$t['verification_type_id'] : 0;
        $isEnabledRaw = $t['is_enabled'] ?? 1;
        $isEnabled = ($isEnabledRaw === null || $isEnabledRaw === '') ? 1 : (int)$isEnabledRaw;
        if ($vtId <= 0 || $isEnabled !== 1) {
            continue;
        }

        $dbComponentKey = case_component_binding_norm_component_key((string)($t['component_key'] ?? ''));
        $components = [];
        if ($dbComponentKey !== '') {
            $components[] = $dbComponentKey;
        } else {
            $components = case_component_binding_map_verification_type_to_components(
                (string)($t['type_name'] ?? ''),
                (string)($t['type_category'] ?? '')
            );
        }
        error_log('MAP_TYPE: ' . (string)($t['type_name'] ?? '') . ' -> ' . implode(',', $components));
        if (!$components) {
            error_log('MAP_TYPE: skipped verification_type_id=' . (string)$vtId . ' (no component mapping)');
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
        // Deterministic snapshot: reset stale components before inserting stage-specific rows.
        $del = $pdo->prepare(
            'DELETE FROM Vati_Payfiller_Case_Components WHERE application_id = ?'
        );
        $del->execute([$applicationId]);

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

        // Force workflow mirror from snapshot (no filtering).
        FORCE_SEED_WORKFLOW($pdo, $applicationId);

        // Enforce workflow integrity: remove workflow rows for components not present in snapshot.
        $pruneWf = $pdo->prepare(
            'DELETE w
               FROM Vati_Payfiller_Case_Component_Workflow w
              WHERE w.application_id = ?
                AND LOWER(TRIM(w.component_key)) NOT IN (
                    SELECT LOWER(TRIM(c.component_key))
                      FROM Vati_Payfiller_Case_Components c
                     WHERE c.application_id = ?
                )'
        );
        $pruneWf->execute([$applicationId, $applicationId]);

        error_log('FINAL_COMPONENTS: ' . json_encode(array_values($config['required_components'])));
    } catch (Throwable $e) {
        // Keep best-effort behavior; older environments may not have the table.
    }

    return $config;
}

function FORCE_SEED_WORKFLOW(PDO $pdo, string $application_id): void
{
    $application_id = trim((string)$application_id);
    if ($application_id === '') {
        return;
    }

    $st = $pdo->prepare(
        'SELECT case_id
           FROM Vati_Payfiller_Cases
          WHERE application_id = ?
          LIMIT 1'
    );
    $st->execute([$application_id]);
    $caseId = (int)($st->fetchColumn() ?: 0);
    if ($caseId <= 0) {
        error_log('FORCE_SEED_WORKFLOW: no case_id for ' . $application_id);
        return;
    }

    $st = $pdo->prepare(
        'SELECT LOWER(TRIM(component_key)) AS component_key
           FROM Vati_Payfiller_Case_Components
          WHERE application_id = ?'
    );
    $st->execute([$application_id]);
    $components = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!$components) {
        error_log('FORCE_SEED_WORKFLOW: no components for ' . $application_id);
        return;
    }

    $components = array_values(array_unique(array_filter(array_map(function ($c) {
        return case_component_binding_norm_component_key((string)$c);
    }, $components), function ($c) {
        return $c !== '';
    })));

    if (!$components) {
        error_log('FORCE_SEED_WORKFLOW: normalized components empty for ' . $application_id);
        return;
    }

    $stages = ['candidate', 'validator', 'verifier', 'qa'];
    $ins = $pdo->prepare(
        "INSERT INTO Vati_Payfiller_Case_Component_Workflow
            (case_id, application_id, component_key, stage, status, created_at)
         SELECT ?, ?, ?, ?, 'pending', NOW()
         WHERE NOT EXISTS (
            SELECT 1
              FROM Vati_Payfiller_Case_Component_Workflow
             WHERE application_id = ?
               AND LOWER(TRIM(component_key)) = ?
               AND LOWER(TRIM(stage)) = ?
         )"
    );

    foreach ($components as $component) {
        foreach ($stages as $stage) {
            $ins->execute([
                $caseId,
                $application_id,
                $component,
                $stage,
                $application_id,
                $component,
                $stage
            ]);
        }
    }
}

function case_component_binding_seed_stage_workflow_rows(PDO $pdo, int $caseId, string $applicationId = '', array $stages = ['candidate', 'validator']): void
{
    if ($caseId <= 0) {
        return;
    }

    $appId = trim((string)$applicationId);
    if ($appId === '') {
        try {
            $q = $pdo->prepare('SELECT application_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
            $q->execute([$caseId]);
            $appId = trim((string)($q->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $appId = '';
        }
    }
    if ($appId === '') {
        return;
    }

    $normalizedStages = [];
    foreach ($stages as $stage) {
        $s = strtolower(trim((string)$stage));
        if ($s === 'candidate' || $s === 'validator' || $s === 'verifier' || $s === 'qa') {
            $normalizedStages[$s] = true;
        }
    }
    if (!$normalizedStages) {
        $normalizedStages = ['candidate' => true, 'validator' => true];
    }

    foreach (array_keys($normalizedStages) as $stage) {
        try {
            $ins = $pdo->prepare(
                "INSERT INTO Vati_Payfiller_Case_Component_Workflow
                    (case_id, application_id, component_key, stage, status, updated_by_user_id, updated_by_role, completed_at)
                 SELECT c.case_id, c.application_id, LOWER(TRIM(c.component_key)), ?, 'pending', NULL, ?, NULL
                 FROM Vati_Payfiller_Case_Components c
                 WHERE c.case_id = ?
                   AND c.application_id = ?
                 ON DUPLICATE KEY UPDATE
                   status = CASE
                       WHEN COALESCE(TRIM(status), '') = '' THEN 'pending'
                       ELSE status
                   END,
                   updated_at = NOW()"
            );
            $ins->execute([$stage, $stage, $caseId, $appId]);
        } catch (Throwable $e) {
            // best-effort only
        }
    }
}

function case_component_binding_has_stage_coverage(PDO $pdo, int $caseId, string $applicationId, array $stages = ['candidate', 'validator']): bool
{
    if ($caseId <= 0 || trim($applicationId) === '') {
        return false;
    }

    try {
        $totalStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT LOWER(TRIM(component_key)))
             FROM Vati_Payfiller_Case_Components
             WHERE case_id = ? AND application_id = ?"
        );
        $totalStmt->execute([$caseId, $applicationId]);
        $componentCount = (int)($totalStmt->fetchColumn() ?: 0);
        if ($componentCount <= 0) {
            return true;
        }

        foreach ($stages as $stage) {
            $s = strtolower(trim((string)$stage));
            if ($s === '') continue;
            $wfStmt = $pdo->prepare(
                "SELECT COUNT(DISTINCT LOWER(TRIM(component_key)))
                 FROM Vati_Payfiller_Case_Component_Workflow
                 WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(stage)) = ?"
            );
            $wfStmt->execute([$caseId, $applicationId, $s]);
            $wfCount = (int)($wfStmt->fetchColumn() ?: 0);
            if ($wfCount < $componentCount) {
                return false;
            }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function case_component_binding_seed_stage_workflow_rows_until_stable(PDO $pdo, int $caseId, string $applicationId = '', array $stages = ['candidate', 'validator'], int $maxPasses = 6, int $sleepMs = 250): void
{
    if ($maxPasses < 1) $maxPasses = 1;
    if ($sleepMs < 0) $sleepMs = 0;

    $appId = trim((string)$applicationId);
    if ($appId === '') {
        try {
            $q = $pdo->prepare('SELECT application_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
            $q->execute([$caseId]);
            $appId = trim((string)($q->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $appId = '';
        }
    }
    if ($caseId <= 0 || $appId === '') {
        return;
    }

    for ($i = 0; $i < $maxPasses; $i++) {
        case_component_binding_seed_stage_workflow_rows($pdo, $caseId, $appId, $stages);
        if (case_component_binding_has_stage_coverage($pdo, $caseId, $appId, $stages)) {
            return;
        }
        if ($i < ($maxPasses - 1) && $sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }
}

function ensure_component_workflow_rows(PDO $pdo, string $applicationId, string $componentKey): void
{
    $appId = trim((string)$applicationId);
    $ck = case_component_binding_norm_component_key((string)$componentKey);
    if ($appId === '' || $ck === '') {
        return;
    }

    $caseId = 0;
    try {
        $q = $pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $q->execute([$appId]);
        $caseId = (int)($q->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $caseId = 0;
    }
    if ($caseId <= 0) {
        return;
    }

    // Snapshot integrity: external writers may only seed workflow for existing snapshot components.
    try {
        $existsStmt = $pdo->prepare(
            "SELECT 1 FROM Vati_Payfiller_Case_Components
             WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ?
             LIMIT 1"
        );
        $existsStmt->execute([$caseId, $appId, strtolower($ck)]);
        $exists = (int)($existsStmt->fetchColumn() ?: 0);
        if ($exists !== 1) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    foreach (['candidate', 'validator'] as $stage) {
        try {
            $insWf = $pdo->prepare(
                "INSERT INTO Vati_Payfiller_Case_Component_Workflow
                    (case_id, application_id, component_key, stage, status, updated_by_user_id, updated_by_role, completed_at)
                 VALUES (?, ?, ?, ?, 'pending', NULL, ?, NULL)
                 ON DUPLICATE KEY UPDATE
                    status = CASE
                        WHEN COALESCE(TRIM(status), '') = '' THEN 'pending'
                        ELSE status
                    END,
                    updated_at = NOW()"
            );
            $insWf->execute([$caseId, $appId, $ck, $stage, $stage]);
        } catch (Throwable $e) {
            // best-effort
        }
    }
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
