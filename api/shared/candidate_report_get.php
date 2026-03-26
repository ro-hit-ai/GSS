<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../config/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function workflow_table_available(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM Vati_Payfiller_Case_Component_Workflow LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function norm_component_key(string $k): string {
    $k = strtolower(trim($k));
    if ($k === 'identification') return 'id';
    if ($k === 'social_media' || $k === 'social-media') return 'socialmedia';
    if ($k === 'driving' || $k === 'driving_license') return 'driving_licence';
    return $k;
}

function compute_component_stage_label(array $stages): string {
    $cand = strtolower(trim((string)($stages['candidate'] ?? '')));
    $val = strtolower(trim((string)($stages['validator'] ?? '')));
    $ver = strtolower(trim((string)($stages['verifier'] ?? '')));
    $qa = strtolower(trim((string)($stages['qa'] ?? '')));

    if ($qa === 'rejected') return 'QA Rejected';
    if ($qa === 'approved') return 'Completed';
    if ($ver === 'rejected') return 'Verifier Rejected';
    if ($val === 'rejected') return 'Validator Rejected';
    if ($cand === 'rejected') return 'Candidate Rejected';

    if ($ver === 'approved') return 'Pending QA';
    if ($val === 'approved') return 'Pending Verifier';
    if ($cand === 'approved') return 'Pending Validator';
    return 'Pending Candidate';
}

function parse_allowed_sections(string $raw): array {
    $raw = strtolower(trim($raw));
    if ($raw === '*') return ['*' => true];
    if ($raw === '') return [];
    $parts = preg_split('/[\s,|]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $k = norm_component_key((string)$p);
        if ($k === '') continue;
        $out[$k] = true;
    }
    return $out;
}

function session_allowed_sections(?PDO $pdo = null): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $raw = isset($_SESSION['auth_allowed_sections']) ? (string)$_SESSION['auth_allowed_sections'] : '';

    // Prefer latest DB value (avoids stale session after admin updates).
    if ($pdo) {
        try {
            $uid = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
            if ($uid > 0) {
                $st = $pdo->prepare('SELECT allowed_sections FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1');
                $st->execute([$uid]);
                $dbRaw = (string)($st->fetchColumn() ?: '');
                $raw = $dbRaw;
                $_SESSION['auth_allowed_sections'] = $dbRaw;
            }
        } catch (Throwable $e) {
            // keep session fallback
        }
    }
    return parse_allowed_sections($raw);
}

function can_section(array $allowedSet, string $key): bool {
    if (isset($allowedSet['*'])) return true;
    $k = strtolower(trim($key));
    return $k !== '' && isset($allowedSet[$k]);
}

function group_components(string $groupKey): array {
    $g = strtoupper(trim($groupKey));
    if ($g === 'BASIC') return ['basic', 'id', 'contact'];
    if ($g === 'EDUCATION') return ['education', 'employment', 'reference'];
    if ($g === 'ADDITIONAL') return ['socialmedia', 'ecourt'];
    return [];
}

function str_contains_ci(string $haystack, string $needle): bool {
    return stripos($haystack, $needle) !== false;
}

function map_verification_type_to_components(string $typeName, string $typeCategory): array {
    $typeName = trim($typeName);
    $typeCategory = trim($typeCategory);
    $hay = strtolower(trim(($typeName !== '' ? $typeName : '') . ' ' . ($typeCategory !== '' ? $typeCategory : '')));

    $out = [];

    if (
        str_contains_ci($hay, 'education')
        || str_contains_ci($hay, 'qualification')
        || str_contains_ci($hay, 'degree')
        || str_contains_ci($hay, 'college')
        || str_contains_ci($hay, 'university')
    ) {
        $out[] = 'education';
    }

    if (
        str_contains_ci($hay, 'employment')
        || str_contains_ci($hay, 'employer')
        || str_contains_ci($hay, 'experience')
        || str_contains_ci($hay, 'work history')
    ) {
        $out[] = 'employment';
    }

    if (str_contains_ci($hay, 'reference')) {
        $out[] = 'reference';
    }

    if (
        str_contains_ci($hay, 'social')
        || str_contains_ci($hay, 'linkedin')
        || str_contains_ci($hay, 'facebook')
        || str_contains_ci($hay, 'instagram')
        || str_contains_ci($hay, 'twitter')
        || str_contains_ci($hay, 'world check')
        || str_contains_ci($hay, 'worldcheck')
    ) {
        $out[] = 'socialmedia';
    }

    if (
        str_contains_ci($hay, 'ecourt')
        || str_contains_ci($hay, 'e-court')
        || str_contains_ci($hay, 'court')
        || str_contains_ci($hay, 'litigation')
        || str_contains_ci($hay, 'judis')
        || str_contains_ci($hay, 'judicial')
        || str_contains_ci($hay, 'manupatra')
    ) {
        $out[] = 'ecourt';
    }

    // Backward compatibility for older role assignment setups.
    // UI sections are ecourt/socialmedia; keep database tag only when explicitly tagged.
    if (
        str_contains_ci($hay, 'database')
    ) {
        $out[] = 'database';
    }

    if (
        str_contains_ci($hay, 'driving')
        || str_contains_ci($hay, 'driver')
        || str_contains_ci($hay, 'licence')
        || str_contains_ci($hay, 'license')
        || str_contains_ci($hay, 'dl')
    ) {
        $out[] = 'driving_licence';
    }

    return array_values(array_unique($out));
}

function get_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

function session_role_norm(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $role = isset($_SESSION['auth_moduleAccess']) ? strtolower(trim((string)$_SESSION['auth_moduleAccess'])) : '';
    if ($role === 'customer_admin') $role = 'client_admin';
    return $role;
}

function resolve_client_id(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cid = isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
    if ($cid > 0) return $cid;

    $role = strtolower(get_str('role', ''));
    if ($role === 'customer_admin') {
        $role = 'client_admin';
    }
    if ($role === '') {
        $role = session_role_norm();
    }

    if ($role === 'client_admin') {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $fallback = get_int('client_id', 0);
    if ($fallback > 0) return $fallback;

    return 0;
}

function sp_fetch_one(PDOStatement $stmt): ?array {
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function sp_fetch_all(PDOStatement $stmt): array {
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sp_drain(PDOStatement $stmt): void {
    while ($stmt->nextRowset()) {
    }
}

function sp_call_one(PDO $pdo, string $sql, array $params): ?array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = sp_fetch_one($stmt);
    sp_drain($stmt);
    return $row;
}

function sp_call_exists(PDO $pdo, string $sql, array $params): bool {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ok = (bool)$stmt->fetchColumn();
    sp_drain($stmt);
    return $ok;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $role = strtolower(get_str('role', ''));
    if ($role === 'customer_admin') {
        $role = 'client_admin';
    }
    if ($role === '') {
        $role = session_role_norm();
    }
    if (session_status() === PHP_SESSION_NONE) session_start();
    $userId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
    $clientId = resolve_client_id();
    $applicationId = get_str('application_id', '');
    $caseId = get_int('case_id', 0);
    $groupKey = strtoupper(get_str('group', ''));

    $pdo = getDB();

    if ($applicationId === '' && $caseId > 0) {
        $row = sp_call_one($pdo, 'CALL SP_Vati_Payfiller_ReportResolveApplicationId(?)', [$caseId]);
        $applicationId = $row && isset($row['application_id']) ? trim((string)$row['application_id']) : '';
    }

    if ($applicationId === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id is required']);
        exit;
    }

    // Fetch report bundle (single SP call, multiple result sets)
    $bundle = $pdo->prepare('CALL SP_Vati_Payfiller_ReportBundle(?)');
    $bundle->execute([$applicationId]);

    $case = sp_fetch_one($bundle);
    $bundle->nextRowset();

    $application = sp_fetch_one($bundle);
    $bundle->nextRowset();

    $basic = sp_fetch_one($bundle);
    $bundle->nextRowset();

    $identification = sp_fetch_all($bundle);
    $bundle->nextRowset();

    $contact = sp_fetch_one($bundle);
    $bundle->nextRowset();

    $education = sp_fetch_all($bundle);
    $bundle->nextRowset();

    $employment = sp_fetch_all($bundle);
    $bundle->nextRowset();

    $reference = sp_fetch_one($bundle);
    $bundle->nextRowset();

    $authorization = sp_fetch_one($bundle);
    $bundle->nextRowset();

    $uploadedDocs = sp_fetch_all($bundle);
    sp_drain($bundle);

    $socialMedia = null;
    $ecourt = null;
    try {
        $socialMedia = sp_call_one($pdo, 'CALL SP_Vati_Payfiller_get_social_media_details(?)', [$applicationId]);
    } catch (Throwable $e) {
        $socialMedia = null;
    }
    try {
        $ecourt = sp_call_one($pdo, 'CALL SP_Vati_Payfiller_get_ecourt_details(?)', [$applicationId]);
    } catch (Throwable $e) {
        $ecourt = null;
    }

    try {
        if (!$application || !is_array($application)) {
            $application = [];
        }
        if (!array_key_exists('status', $application) || !array_key_exists('submitted_at', $application)) {
            $appStmt = $pdo->prepare('SELECT status, submitted_at FROM Vati_Payfiller_Candidate_Applications WHERE application_id = ? LIMIT 1');
            $appStmt->execute([$applicationId]);
            $appRow = $appStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!array_key_exists('status', $application) && isset($appRow['status'])) {
                $application['status'] = $appRow['status'];
            }
            if (!array_key_exists('submitted_at', $application) && isset($appRow['submitted_at'])) {
                $application['submitted_at'] = $appRow['submitted_at'];
            }
        }

        if (!$authorization || !is_array($authorization) || (!isset($authorization['digital_signature']) && !isset($authorization['file_name']) && !isset($authorization['uploaded_at']))) {
            $authStmt = $pdo->prepare('SELECT file_name, digital_signature, uploaded_at FROM Vati_Payfiller_Candidate_Authorization_documents WHERE application_id = ? ORDER BY uploaded_at DESC LIMIT 1');
            $authStmt->execute([$applicationId]);
            $authRow = $authStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($authRow) {
                $authorization = $authRow;
            }
        }
    } catch (Throwable $e) {
    }

    if (!$case) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found for this application_id']);
        exit;
    }

    $caseClientId = isset($case['client_id']) ? (int)$case['client_id'] : 0;

    // Component model:
    // Basic + Identification are common (always part of case)
    $requiredComponents = ['basic', 'id'];
    try {
        $jobRoleName = trim((string)($case['job_role'] ?? ''));
        $jobRoleId = 0;
        if ($caseClientId > 0 && $jobRoleName !== '') {
            $jr = $pdo->prepare('SELECT job_role_id FROM Vati_Payfiller_Job_Roles WHERE client_id = ? AND LOWER(TRIM(role_name)) = LOWER(TRIM(?)) LIMIT 1');
            $jr->execute([$caseClientId, $jobRoleName]);
            $jobRoleId = (int)($jr->fetchColumn() ?: 0);
        }

        if ($jobRoleId > 0) {
            $types = [];
            try {
                $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetVerificationTypesByJobRole(?)');
                $stmt->execute([$jobRoleId]);
                $types = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                while ($stmt->nextRowset()) {
                }
            } catch (Throwable $e) {
                $types = [];
            }

            foreach ($types as $t) {
                $name = (string)($t['type_name'] ?? '');
                $cat = (string)($t['type_category'] ?? '');
                $isEnabled = isset($t['is_enabled']) ? (int)$t['is_enabled'] : 1;
                if ($isEnabled !== 1) continue;
                $mapped = map_verification_type_to_components($name, $cat);
                foreach ($mapped as $ck) {
                    $requiredComponents[] = $ck;
                }
            }
        }
    } catch (Throwable $e) {
    }

    $requiredComponents = array_values(array_unique($requiredComponents));

    $allowedSet = session_allowed_sections($pdo);

    // If staff role, also include any components from allowed_sections so UI shows what user can work on
    // even when job role -> verification type mapping doesn't detect them.
    if ($role === 'verifier' || $role === 'db_verifier' || $role === 'validator') {
        if (!isset($allowedSet['*']) && count($allowedSet) > 0) {
            $known = ['basic', 'id', 'education', 'employment', 'reference', 'socialmedia', 'ecourt', 'database', 'driving_licence', 'reports', 'contact'];
            foreach ($known as $k) {
                if (can_section($allowedSet, $k)) {
                    $requiredComponents[] = $k;
                }
            }
            $requiredComponents = array_values(array_unique($requiredComponents));
        }
    }

    // Best-effort: ensure required components exist in DB table (if installed)
    try {
        $caseIdInt = isset($case['case_id']) ? (int)$case['case_id'] : 0;
        if ($caseIdInt > 0) {
            $ins = $pdo->prepare(
                'INSERT IGNORE INTO Vati_Payfiller_Case_Components (case_id, application_id, component_key, is_required, status) '
                . 'VALUES (?, ?, ?, 1, \'pending\')'
            );
            foreach ($requiredComponents as $ck) {
                $k = strtolower(trim((string)$ck));
                if ($k === '') continue;
                $ins->execute([$caseIdInt, $applicationId, $k]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Optional: load assignments/status from component table if available
    $assignedComponents = [];
    try {
        $cc = $pdo->prepare(
            'SELECT component_key, is_required, assigned_role, assigned_user_id, status, completed_at '
            . 'FROM Vati_Payfiller_Case_Components '
            . 'WHERE application_id = ?'
        );
        $cc->execute([$applicationId]);
        $assignedComponents = $cc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $assignedComponents = [];
    }

    // Merge DB component keys into required list so anything that was assigned/inserted shows in UI,
    // even if job role -> verification type mapping didn't detect it.
    foreach ($assignedComponents as $r) {
        $k = strtolower(trim((string)($r['component_key'] ?? '')));
        if ($k === '') continue;
        $requiredComponents[] = $k;
    }
    $requiredComponents = array_values(array_unique($requiredComponents));

    // Ensure all required components exist in response (even if DB table not filled yet)
    $assignedMap = [];
    foreach ($assignedComponents as $r) {
        $k = strtolower(trim((string)($r['component_key'] ?? '')));
        if ($k !== '') $assignedMap[$k] = $r;
    }

    $outAssigned = [];
    foreach ($requiredComponents as $ck) {
        $k = strtolower(trim((string)$ck));
        $row = $assignedMap[$k] ?? null;
        $outAssigned[] = [
            'component_key' => $k,
            'is_required' => $row && isset($row['is_required']) ? (int)$row['is_required'] : 1,
            'assigned_role' => $row ? ($row['assigned_role'] ?? null) : null,
            'assigned_user_id' => $row && isset($row['assigned_user_id']) ? (int)$row['assigned_user_id'] : null,
            'status' => $row ? (string)($row['status'] ?? 'pending') : 'pending',
            'completed_at' => $row ? ($row['completed_at'] ?? null) : null,
        ];
    }

    // Load per-stage workflow status when table exists
    $workflowByComponent = [];
    if (workflow_table_available($pdo)) {
        try {
            $w = $pdo->prepare(
                'SELECT component_key, stage, status, completed_at, updated_at '
                . 'FROM Vati_Payfiller_Case_Component_Workflow '
                . 'WHERE application_id = ?'
            );
            $w->execute([$applicationId]);
            $rows = $w->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $ck = norm_component_key((string)($r['component_key'] ?? ''));
                $st = strtolower(trim((string)($r['stage'] ?? '')));
                if ($ck === '' || $st === '') continue;
                if (!isset($workflowByComponent[$ck])) $workflowByComponent[$ck] = [];
                $workflowByComponent[$ck][$st] = [
                    'status' => (string)($r['status'] ?? ''),
                    'completed_at' => $r['completed_at'] ?? null,
                    'updated_at' => $r['updated_at'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            $workflowByComponent = [];
        }
    }

    // Enrich assigned_components with stage labels
    foreach ($outAssigned as &$it) {
        $ck = norm_component_key((string)($it['component_key'] ?? ''));
        $st = $workflowByComponent[$ck] ?? [];
        $stSimple = [
            'candidate' => isset($st['candidate']['status']) ? (string)$st['candidate']['status'] : '',
            'validator' => isset($st['validator']['status']) ? (string)$st['validator']['status'] : '',
            'verifier' => isset($st['verifier']['status']) ? (string)$st['verifier']['status'] : '',
            'qa' => isset($st['qa']['status']) ? (string)$st['qa']['status'] : '',
        ];
        // Fallback: when workflow row is missing but component row is rejected,
        // treat it as validator rejection for verifier-facing status.
        if ($role === 'verifier') {
            $componentStatus = strtolower(trim((string)($it['status'] ?? '')));
            if ($stSimple['validator'] === '' && $componentStatus === 'rejected') {
                $stSimple['validator'] = 'rejected';
            }
        }
        $it['workflow'] = $stSimple;
        $it['current_stage'] = compute_component_stage_label($stSimple);
    }
    unset($it);

    // Staff views should respect allowed section scope in assigned components payload.
    // Action APIs still enforce assignment/rejection rules.
    $visibleAssigned = $outAssigned;
    if ($role === 'verifier' || $role === 'db_verifier' || $role === 'validator') {
        $visibleAssigned = array_values(array_filter($outAssigned, function ($it) use ($allowedSet) {
            $k = norm_component_key((string)($it['component_key'] ?? ''));
            if ($k === '') return false;
            if (!can_section($allowedSet, $k)) return false;
            return true;
        }));
    }

    // If staff role has no allowed sections configured, block completely
    if (($role === 'verifier' || $role === 'db_verifier' || $role === 'validator') && !isset($allowedSet['*']) && count($allowedSet) === 0) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Access denied']);
        exit;
    }

    if ($role === 'client_admin') {
        if ($caseClientId !== $clientId) {
            http_response_code(403);
            echo json_encode(['status' => 0, 'message' => 'Forbidden']);
            exit;
        }
    }

    // Enforce staff assignment for verifier/db_verifier
    if ($role === 'verifier') {
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
            exit;
        }

        // New component-based assignment: if component table has assignments, enforce that at least one component is assigned to this user.
        // Backward compatible: if no component assignments exist yet, fall back to legacy group-based assignment.
        $hasComponentAssignment = false;
        foreach ($outAssigned as $it) {
            if (($it['assigned_role'] ?? '') === 'verifier' && (int)($it['assigned_user_id'] ?? 0) === (int)$userId) {
                $hasComponentAssignment = true;
                break;
            }
        }

        if (!$hasComponentAssignment) {
            if (!in_array($groupKey, ['BASIC', 'EDUCATION', 'ADDITIONAL'], true)) {
                http_response_code(400);
                echo json_encode(['status' => 0, 'message' => 'Valid group is required']);
                exit;
            }

            $ok = sp_call_exists(
                $pdo,
                'CALL SP_Vati_Payfiller_ReportCheckVerifierAssignment(?, ?, ?)',
                [(int)($case['case_id'] ?? 0), $userId, $groupKey]
            );
            if (!$ok) {
                http_response_code(403);
                echo json_encode(['status' => 0, 'message' => 'Forbidden']);
                exit;
            }
        }
    }

    if ($role === 'db_verifier') {
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
            exit;
        }

        $ok = sp_call_exists(
            $pdo,
            'CALL SP_Vati_Payfiller_ReportCheckDbVerifierAssignment(?, ?)',
            [$applicationId, $userId]
        );
        if (!$ok) {
            http_response_code(403);
            echo json_encode(['status' => 0, 'message' => 'Forbidden']);
            exit;
        }
    }

    // Enforce staff assignment for validator
    if ($role === 'validator') {
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
            exit;
        }

        $ok = sp_call_exists(
            $pdo,
            'CALL SP_Vati_Payfiller_ReportCheckValidatorAssignment(?, ?)',
            [(int)($case['case_id'] ?? 0), $userId]
        );
        if (!$ok) {
            http_response_code(403);
            echo json_encode(['status' => 0, 'message' => 'Forbidden']);
            exit;
        }
    }

    // Redact disallowed sections for verifier/db_verifier/validator
    if ($role === 'verifier' || $role === 'db_verifier' || $role === 'validator') {
        if (!can_section($allowedSet, 'basic')) {
            $basic = null;
        }
        if (!can_section($allowedSet, 'id')) {
            $identification = [];
        }
        if (!can_section($allowedSet, 'contact')) {
            $contact = null;
        }
        if (!can_section($allowedSet, 'education')) {
            $education = [];
        }
        if (!can_section($allowedSet, 'employment')) {
            $employment = [];
        }
        if (!can_section($allowedSet, 'reference')) {
            $reference = null;
        }
        if (!can_section($allowedSet, 'socialmedia')) {
            $socialMedia = null;
        }
        if (!can_section($allowedSet, 'ecourt')) {
            $ecourt = null;
        }
        if (!can_section($allowedSet, 'reports')) {
            $authorization = null;
        }
    }

    // Verifier section payload follows the same visibleAssigned set.
    if ($role === 'verifier') {
        $visibleMap = [];
        foreach ($visibleAssigned as $it) {
            $k = norm_component_key((string)($it['component_key'] ?? ''));
            if ($k !== '') $visibleMap[$k] = true;
        }
        if (!isset($visibleMap['basic'])) {
            $basic = null;
        }
        if (!isset($visibleMap['id'])) {
            $identification = [];
        }
        if (!isset($visibleMap['contact'])) {
            $contact = null;
        }
        if (!isset($visibleMap['education'])) {
            $education = [];
        }
        if (!isset($visibleMap['employment'])) {
            $employment = [];
        }
        if (!isset($visibleMap['reference'])) {
            $reference = null;
        }
        if (!isset($visibleMap['socialmedia'])) {
            $socialMedia = null;
        }
        if (!isset($visibleMap['ecourt'])) {
            $ecourt = null;
        }
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'case' => $case,
            'application' => $application,
            'basic' => $basic,
            'identification' => $identification,
            'contact' => $contact,
            'education' => $education,
            'employment' => $employment,
            'reference' => $reference,
            'social_media' => $socialMedia,
            'ecourt' => $ecourt,
            'authorization' => $authorization,
            'uploaded_docs' => $uploadedDocs,
            'assigned_components' => $visibleAssigned,
            'component_workflow' => $workflowByComponent
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
