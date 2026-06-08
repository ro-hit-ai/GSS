<?php
require_once __DIR__ . '/workflow_semantics.php';

function case_component_binding_norm_stage_key(string $stage): string
{
    $s = strtolower(trim($stage));
    if (strpos($s, '__') !== false) {
        $s = trim((string)explode('__', $s, 2)[0]);
    }
    if ($s === 'p1' || $s === 'pre interview') return 'pre_interview';
    if ($s === 'p2' || $s === 'post interview') return 'post_interview';
    if ($s === 'p3' || $s === 'employee pool') return 'employee_pool';
    return $s;
}

function case_component_binding_parse_stage_level(string $stage, string $level = ''): array
{
    $rawStage = trim($stage);
    $rawLevel = trim($level);
    if ($rawStage !== '' && strpos($rawStage, '__') !== false) {
        $parts = explode('__', $rawStage, 2);
        $rawStage = trim((string)($parts[0] ?? ''));
        if ($rawLevel === '') {
            $rawLevel = trim((string)($parts[1] ?? ''));
        }
    }
    return [case_component_binding_norm_stage_key($rawStage), strtoupper(trim($rawLevel))];
}

function case_component_binding_str_contains_ci(string $haystack, string $needle): bool
{
    return stripos($haystack, $needle) !== false;
}

function case_component_binding_text_has_any(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        $needle = trim((string)$needle);
        if ($needle !== '' && case_component_binding_str_contains_ci($haystack, $needle)) {
            return true;
        }
    }
    return false;
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
    $name = strtolower(trim($typeName));
    $category = strtolower(trim($typeCategory));

    $exactTypeMap = [
        'education' => 'education',
        'employment' => 'employment',
        'employement' => 'employment',
        'education reference' => 'education_reference',
        'employment reference' => 'employment_reference',
        'identity proof' => 'id',
        'current address' => 'contact',
        'permanent address' => 'contact',
        'current or permanent address' => 'contact',
        'ecourt' => 'ecourt',
        'e-court' => 'ecourt',
        'social media' => 'socialmedia',
    ];

    if ($name !== '' && isset($exactTypeMap[$name])) {
        return [$exactTypeMap[$name]];
    }

    $exactCategoryMap = [
        'education' => 'education',
        'employment' => 'employment',
        'reference' => 'reference',
        'identity' => 'id',
        'identification' => 'id',
        'address' => 'contact',
        'address verification' => 'contact',
        'contact' => 'contact',
        'ecourt' => 'ecourt',
        'social media' => 'socialmedia',
    ];
    if ($category !== '' && isset($exactCategoryMap[$category])) {
        return [$exactCategoryMap[$category]];
    }

    return [];
}

function case_component_binding_detect_contact_sections(string $typeName, string $typeCategory): array
{
    $name = strtolower(trim($typeName));
    $category = strtolower(trim($typeCategory));
    $hay = trim($name . ' ' . $category);
    if (!case_component_binding_text_has_any($hay, ['address', 'contact'])) {
        return [];
    }

    $out = [];
    if (case_component_binding_text_has_any($name, ['current or permanent', 'current/permanent', 'full address details'])) {
        $out[] = 'current_address';
        $out[] = 'permanent_address';
    } else {
        if (case_component_binding_text_has_any($name, ['current', 'present'])) {
            $out[] = 'current_address';
        }
        if (case_component_binding_text_has_any($name, ['permanent'])) {
            $out[] = 'permanent_address';
        }
    }

    if (empty($out) && case_component_binding_text_has_any($category, ['address verification'])) {
        $out[] = 'current_address';
        $out[] = 'permanent_address';
    }

    if (empty($out) && case_component_binding_text_has_any($hay, ['address', 'contact'])) {
        $out[] = 'current_address';
    }

    return array_values(array_unique($out));
}

function case_component_binding_contact_subsection(string $typeName, string $typeCategory): string
{
    $sections = case_component_binding_detect_contact_sections($typeName, $typeCategory);
    $hasCurrent = in_array('current_address', $sections, true);
    $hasPermanent = in_array('permanent_address', $sections, true);

    if ($hasCurrent && $hasPermanent) {
        return 'current_and_permanent_address';
    }
    if ($hasPermanent) {
        return 'permanent_address';
    }
    if ($hasCurrent) {
        return 'current_address';
    }
    return '';
}

function case_component_binding_contact_display_label(string $typeName, string $typeCategory): string
{
    $subsection = case_component_binding_contact_subsection($typeName, $typeCategory);
    if ($subsection === 'permanent_address') {
        return 'Permanent Address';
    }
    if ($subsection === 'current_address') {
        return 'Current Address';
    }
    if ($subsection === 'current_and_permanent_address') {
        return 'Current OR Permanent Address';
    }
    return 'Contact';
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
    [$selectedStage, $selectedLevel] = case_component_binding_parse_stage_level($selectedStage, $selectedLevel);

    // Strict stage-scoped resolution: do not materialize mixed-stage components.
    if ($selectedLevel === '' || $selectedStage === '') {
        error_log('DEBUG_STAGE: level=' . $selectedLevel . ', stage=' . $selectedStage . ' (strict mode: empty filter -> no types)');
        return [];
    }
    error_log('DEBUG_STAGE: level=' . $selectedLevel . ', stage=' . $selectedStage);

    try {
        $params = [$jobRoleId, $selectedLevel, $selectedLevel, $selectedStage];

        // Use DB-driven component_key from mapping table.
        // Important: `Vati_Payfiller_Verification_Types` may not have `component_key`
        // in several environments. Referencing it causes SQL errors and forces
        // fallback heuristic mapping, which can incorrectly seed extra components.
        try {
            $sql = 'SELECT j.verification_type_id, j.is_enabled, t.type_name, t.type_category, j.level_key, j.stage_key,
                           COALESCE(NULLIF(TRIM(j.component_key), \'\'), \'\') AS component_key
                      FROM Vati_Payfiller_Job_Role_Verification_Types j
                 LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
                     WHERE j.job_role_id = ?
                       AND (
                            LOWER(TRIM(COALESCE(j.level_key, ""))) = LOWER(?)
                            OR (
                                TRIM(COALESCE(j.level_key, "")) = ""
                                AND LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE CONCAT("%__", LOWER(?))
                            )
                       )
                       AND (
                            CASE
                                WHEN LOWER(TRIM(COALESCE(j.stage_key, ""))) IN ("pre_interview", "p1") OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "pre_interview__%" OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "p1__%" THEN "pre_interview"
                                WHEN LOWER(TRIM(COALESCE(j.stage_key, ""))) IN ("post_interview", "p2") OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "post_interview__%" OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "p2__%" THEN "post_interview"
                                WHEN LOWER(TRIM(COALESCE(j.stage_key, ""))) IN ("employee_pool", "p3") OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "employee_pool__%" OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "p3__%" THEN "employee_pool"
                                ELSE LOWER(TRIM(COALESCE(j.stage_key, "")))
                            END
                       ) = ?
                  ORDER BY COALESCE(j.stage_key, "") ASC, COALESCE(j.level_key, "") ASC, COALESCE(j.sort_order, 0) ASC, COALESCE(t.type_name, "") ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            error_log('FETCHED TYPES: ' . json_encode($rows));
            if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
                error_log('CONFIG_AUTH_READ: ' . json_encode([
                    'resolver' => 'case_component_binding_fetch_types',
                    'job_role_id' => $jobRoleId,
                    'selected_stage' => $selectedStage,
                    'selected_level' => $selectedLevel,
                    'matched_rows' => count($rows),
                ]));
            }
            return $rows;
        } catch (Throwable $e1) {
            // Last-resort compatibility query for older schemas: return empty component_key
            // so caller can map by type text.
            $sql = 'SELECT j.verification_type_id, j.is_enabled, t.type_name, t.type_category, j.level_key, j.stage_key,
                           \'\' AS component_key
                      FROM Vati_Payfiller_Job_Role_Verification_Types j
                 LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
                     WHERE j.job_role_id = ?
                       AND (
                            LOWER(TRIM(COALESCE(j.level_key, ""))) = LOWER(?)
                            OR (
                                TRIM(COALESCE(j.level_key, "")) = ""
                                AND LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE CONCAT("%__", LOWER(?))
                            )
                       )
                       AND (
                            CASE
                                WHEN LOWER(TRIM(COALESCE(j.stage_key, ""))) IN ("pre_interview", "p1") OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "pre_interview__%" OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "p1__%" THEN "pre_interview"
                                WHEN LOWER(TRIM(COALESCE(j.stage_key, ""))) IN ("post_interview", "p2") OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "post_interview__%" OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "p2__%" THEN "post_interview"
                                WHEN LOWER(TRIM(COALESCE(j.stage_key, ""))) IN ("employee_pool", "p3") OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "employee_pool__%" OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "p3__%" THEN "employee_pool"
                                ELSE LOWER(TRIM(COALESCE(j.stage_key, "")))
                            END
                       ) = ?
                  ORDER BY COALESCE(j.stage_key, "") ASC, COALESCE(j.level_key, "") ASC, COALESCE(j.sort_order, 0) ASC, COALESCE(t.type_name, "") ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            error_log('FETCHED TYPES: ' . json_encode($rows));
            if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
                error_log('CONFIG_AUTH_READ_FALLBACK: ' . json_encode([
                    'resolver' => 'case_component_binding_fetch_types',
                    'job_role_id' => $jobRoleId,
                    'selected_stage' => $selectedStage,
                    'selected_level' => $selectedLevel,
                    'matched_rows' => count($rows),
                ]));
            }
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
    [$selectedStage, ] = case_component_binding_parse_stage_level($selectedStage, '');

    try {
        $sql = 'SELECT verification_type_id, assigned_role, is_active, stage_key
                  FROM Vati_Payfiller_Job_Role_Stage_Steps
                 WHERE job_role_id = ?';
        $params = [$jobRoleId];
        if ($selectedStage !== '') {
            $sql .= ' AND (
                CASE
                    WHEN LOWER(TRIM(COALESCE(stage_key, ""))) IN ("pre_interview", "p1") OR LOWER(TRIM(COALESCE(stage_key, ""))) LIKE "pre_interview__%" OR LOWER(TRIM(COALESCE(stage_key, ""))) LIKE "p1__%" THEN "pre_interview"
                    WHEN LOWER(TRIM(COALESCE(stage_key, ""))) IN ("post_interview", "p2") OR LOWER(TRIM(COALESCE(stage_key, ""))) LIKE "post_interview__%" OR LOWER(TRIM(COALESCE(stage_key, ""))) LIKE "p2__%" THEN "post_interview"
                    WHEN LOWER(TRIM(COALESCE(stage_key, ""))) IN ("employee_pool", "p3") OR LOWER(TRIM(COALESCE(stage_key, ""))) LIKE "employee_pool__%" OR LOWER(TRIM(COALESCE(stage_key, ""))) LIKE "p3__%" THEN "employee_pool"
                    ELSE LOWER(TRIM(COALESCE(stage_key, "")))
                END
            ) = ?';
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
        // Canonical internal operational section: reports.
        // It must exist in snapshot participation so strict workflow validation
        // can accept reports transitions deterministically.
        'required_components' => ['basic', 'id', 'reports'],
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

    [$selectedStage, $selectedLevel] = case_component_binding_parse_stage_level(
        (string)($case['selected_stage'] ?? ''),
        (string)($case['selected_level'] ?? '')
    );
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
        error_log('MAP_TYPE_DEBUG: ' . json_encode([
            'raw_type_name' => (string)($t['type_name'] ?? ''),
            'type_category' => (string)($t['type_category'] ?? ''),
            'resolved_component_key' => implode(',', $components),
            'mapping_source' => ($dbComponentKey !== '' ? 'db_component_key' : 'explicit_type_map'),
            'heuristic_used' => false,
        ]));
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

    // Ownership-governance fallback:
    // If explicit stage-step ownership mapping is absent, derive verifier ownership
    // deterministically from required components that belong to canonical verifier groups.
    // This prevents static theoretical members (e.g. non-required address) from blocking closure.
    if (empty($config['component_roles'])) {
        $requiredSet = [];
        foreach ((array)$config['required_components'] as $c) {
            $k = case_component_binding_norm_component_key((string)$c);
            if ($k !== '') $requiredSet[$k] = true;
        }
        $verifierOwned = [];
        foreach (wf_verifier_group_map() as $group => $components) {
            foreach ((array)$components as $componentKey) {
                $k = case_component_binding_norm_component_key((string)$componentKey);
                if ($k === '' || !isset($requiredSet[$k])) continue;
                $verifierOwned[$k] = true;
            }
        }
        foreach (array_keys($verifierOwned) as $componentKey) {
            if (!isset($config['component_roles'][$componentKey])) {
                $config['component_roles'][$componentKey] = [];
            }
            $config['component_roles'][$componentKey]['verifier'] = true;
        }
        if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
            @file_put_contents(
                __DIR__ . '/../../logs/workflow_transition.log',
                json_encode([
                    'ts' => date('c'),
                    'event' => 'ownership_roles_fallback_applied',
                    'case_id' => $caseId,
                    'application_id' => (string)($case['application_id'] ?? ''),
                    'selected_stage' => $selectedStage,
                    'component_roles' => $config['component_roles'],
                    'required_components' => array_values(array_keys($requiredSet)),
                    'resolver_owner' => 'case_component_binding_build_for_case',
                    'mapping_source' => 'wf_verifier_group_map_required_intersection',
                ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND
            );
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
