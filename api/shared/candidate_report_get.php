<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../includes/integration.php';
require_once __DIR__ . '/case_component_binding.php';
require_once __DIR__ . '/workflow_snapshot_service.php';

integration_bootstrap_json_api();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function shared_endpoint_log(string $msg): void {
    error_log('[candidate_report_get] ' . $msg);
}

function get_header_value(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$key])) return trim((string)$_SERVER[$key]);
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strcasecmp((string)$k, $name) === 0) return trim((string)$v);
            }
        }
    }
    return '';
}

function shared_api_key_valid(): bool {
    $incoming = get_header_value('X-API-Key');
    if ($incoming === '') return false;
    $expected = (string)(env_get('PHP_API_KEY', env_get('SHARED_API_KEY', '')) ?? '');
    if ($expected === '') return false;
    return hash_equals($expected, $incoming);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Candidate portal uses a separate session model (logged_in + application_id).
// Staff sessions can coexist in same browser, so candidate mode must NOT override
// when staff auth is present.
$hasStaffSession = !empty($_SESSION['auth_user_id'])
    || !empty($_SESSION['auth_moduleAccess'])
    || !empty($_SESSION['auth_role'])
    || !empty($_SESSION['role']);
$isCandidatePortalSession = !$hasStaffSession && !empty($_SESSION['logged_in']) && !empty($_SESSION['application_id']);

$incomingApiKey = get_header_value('X-API-Key');
$hasApiKey = $incomingApiKey !== '';
$apiKeyOk = shared_api_key_valid();
if ($hasApiKey && !$apiKeyOk) {
    shared_endpoint_log('auth failure method=api-key');
    http_response_code(401);
    echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
    exit;
}

$authViaApiKey = $apiKeyOk;
shared_endpoint_log('hit auth_method=' . ($authViaApiKey ? 'api-key' : 'session') . ' auth=' . ($authViaApiKey ? 'success' : 'pending'));

if (!$isCandidatePortalSession && !$authViaApiKey) {
    integration_resolve_actor(true);
}

function norm_item_key(string $k): string {
    $k = strtolower(trim($k));
    if ($k === '') return '';
    if (strlen($k) > 191) {
        $k = substr($k, 0, 191);
    }
    return $k;
}

function item_key_for_row(string $componentKey, array $row, int $idx): string {
    $k = ws_norm_component_key($componentKey);
    $seq = $idx + 1;
    if ($k === 'id') {
        $v = $row['document_index'] ?? '';
        if ($v !== '' && $v !== null) return 'id:' . norm_item_key((string)$v);
    }
    if ($k === 'education') {
        $v = $row['education_index'] ?? '';
        if ($v !== '' && $v !== null) return 'education:' . norm_item_key((string)$v);
    }
    if ($k === 'employment') {
        $v = $row['employment_index'] ?? '';
        if ($v !== '' && $v !== null) return 'employment:' . norm_item_key((string)$v);
    }
    return $k . ':' . (string)$seq;
}

function parse_allowed_sections(string $raw): array {
    $raw = strtolower(trim($raw));
    if ($raw === '*') return ['*' => true];
    if ($raw === '') return [];
    $parts = preg_split('/[\s,|]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $k = ws_norm_component_key((string)$p);
        if ($k === '') continue;
        $out[$k] = true;
    }
    return $out;
}

function session_allowed_sections(?PDO $pdo = null): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $role = session_role_norm();
    if ($role === 'validator' || $role === 'qa') {
        return ['*' => true];
    }
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

function get_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

function norm_case_stage(string $stage): string {
    $s = strtolower(trim($stage));
    if ($s === 'p1' || $s === 'pre_interview') return 'p1';
    if ($s === 'p2' || $s === 'post_interview') return 'p2';
    if ($s === 'p3' || $s === 'employee_pool') return 'p3';
    return $s;
}

function normalize_staff_role(string $role): string {
    $r = strtolower(trim($role));
    if ($r === 'customer_admin') return 'client_admin';
    if ($r === 'component verifier' || $r === 'component_verifier') return 'verifier';
    if ($r === 'component validator' || $r === 'component_validator') return 'validator';
    if ($r === 'db verifier' || $r === 'db-verifier') return 'db_verifier';
    if ($r === 'gss admin') return 'gss_admin';
    if ($r === 'team lead' || $r === 'team_lead') return 'team_lead';
    return $r;
}

function session_role_norm(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    // Staff session must take priority when both staff and candidate keys coexist.
    $role = isset($_SESSION['auth_moduleAccess']) ? normalize_staff_role((string)$_SESSION['auth_moduleAccess']) : '';
    if ($role === '' && isset($_SESSION['auth_role'])) {
        $role = normalize_staff_role((string)$_SESSION['auth_role']);
    }
    if ($role === '' && isset($_SESSION['role'])) {
        $role = normalize_staff_role((string)$_SESSION['role']);
    }
    if ($role !== '') {
        return $role;
    }
    if (!empty($_SESSION['logged_in']) && !empty($_SESSION['application_id'])) {
        return 'candidate';
    }
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

function build_file_url(string $file, string $folder = ''): ?string {
    $file = trim($file);
    if ($file === '') return null;
    $normalized = str_replace('\\', '/', $file);
    if (preg_match('~^https?://~i', $normalized)) return $normalized;

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $base = $scheme . '://' . $host;

    if (strpos($normalized, '/GSS/uploads/') === 0) return $base . $normalized;
    if (strpos($normalized, '/uploads/') === 0) return $base . '/GSS' . $normalized;
    if (strpos($normalized, 'uploads/') === 0) return $base . '/GSS/' . ltrim($normalized, '/');
    if (strpos($normalized, '/') !== false) return $base . '/GSS/' . ltrim($normalized, '/');

    $folder = trim($folder, '/');
    if ($folder !== '') {
        return $base . '/GSS/uploads/' . $folder . '/' . rawurlencode($normalized);
    }
    return $base . '/GSS/uploads/' . rawurlencode($normalized);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    // Authorization role must come from server-side session only unless trusted API-key is used.
    $role = $authViaApiKey ? 'service' : session_role_norm();
    if (session_status() === PHP_SESSION_NONE) session_start();
    $userId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
    $clientId = resolve_client_id();
    $applicationId = integration_normalize_application_id(get_str('application_id', ''));
    $caseId = get_int('case_id', 0);

    $pdo = getDB();

    if ($applicationId === '' && $caseId > 0) {
        $row = sp_call_one($pdo, 'CALL SP_Vati_Payfiller_ReportResolveApplicationId(?)', [$caseId]);
        $applicationId = $row && isset($row['application_id']) ? integration_normalize_application_id((string)$row['application_id']) : '';
    }

    if ($applicationId === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id is required']);
        exit;
    }

    // Candidate must be logged in via candidate session and can access only own application.
    if (!$authViaApiKey && $role === 'candidate') {
        $isLoggedIn = !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
        $sessionAppId = integration_normalize_application_id((string)($_SESSION['application_id'] ?? ''));
        if (!$isLoggedIn || $sessionAppId === '' || $sessionAppId !== $applicationId) {
            http_response_code(401);
            echo json_encode(['status' => 0, 'message' => 'Unauthorized access to application']);
            exit;
        }
    } elseif (!$authViaApiKey) {
        // Staff: enforce authenticated session and strict role allowlist.
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
            exit;
        }
        if (!in_array($role, ['verifier', 'validator', 'qa', 'client_admin', 'gss_admin', 'db_verifier'], true)) {
            http_response_code(403);
            echo json_encode(['status' => 0, 'message' => 'Invalid role']);
            exit;
        }
    } else {
        shared_endpoint_log('auth success method=api-key');
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

    // Stage/level context for item-level and presentation filtering.
    $selectedLevel = strtolower(trim((string)($case['selected_level'] ?? '')));
    $selectedStage = norm_case_stage((string)($case['selected_stage'] ?? ''));

    // Item-level filter for reference content to avoid cross-type mixing.
    if (is_array($reference) && isset($reference[0]) && is_array($reference[0])) {
        $reference = array_values(array_filter($reference, function ($item) use ($selectedStage) {
            $type = strtolower(trim((string)($item['reference_type'] ?? ($item['type'] ?? ''))));
            if ($selectedStage === 'p1') {
                return $type === '' || $type === 'education reference';
            }
            return true;
        }));
    } elseif (is_array($reference)) {
        $type = strtolower(trim((string)($reference['reference_type'] ?? ($reference['type'] ?? ''))));
        if ($selectedStage === 'p1' && $type !== '' && $type !== 'education reference') {
            $reference = [];
        }
    }

    // Presentation-only dynamic label for the contact/address section by case level.
    $contactLabel = 'Address Details';
    if ($selectedLevel === 'l1') {
        $contactLabel = 'Current Address';
    } elseif ($selectedLevel === 'l2') {
        $contactLabel = 'Current OR Permanent Address';
    } elseif ($selectedLevel === 'l3') {
        $contactLabel = 'Full Address Details';
    }
    if (is_array($contact)) {
        $contact['label'] = $contactLabel;
        $contact['address_proof_url'] = build_file_url((string)($contact['address_proof_file'] ?? ($contact['proof_file'] ?? '')), 'address');
        error_log('Address label set: ' . $contactLabel . ' for level ' . $selectedLevel);
    }

    foreach ($education as $i => $row) {
        if (!is_array($row)) continue;
        $education[$i]['marksheet_url'] = build_file_url((string)($row['marksheet_file'] ?? ''), 'education');
        $education[$i]['degree_url'] = build_file_url((string)($row['degree_file'] ?? ''), 'education');
    }

    if (is_array($ecourt)) {
        $ecourt['document_url'] = build_file_url((string)($ecourt['document'] ?? ($ecourt['evidence_document'] ?? '')), 'ecourt');
    }

    if (is_array($authorization)) {
        $authorization['file_url'] = build_file_url((string)($authorization['authorization_file'] ?? ($authorization['file_name'] ?? '')), 'verification');
    }

    foreach ($identification as $i => $row) {
        if (!is_array($row)) continue;
        $identification[$i]['item_key'] = item_key_for_row('id', $row, (int)$i);
    }
    foreach ($education as $i => $row) {
        if (!is_array($row)) continue;
        $education[$i]['item_key'] = item_key_for_row('education', $row, (int)$i);
    }
    foreach ($employment as $i => $row) {
        if (!is_array($row)) continue;
        $employment[$i]['item_key'] = item_key_for_row('employment', $row, (int)$i);
    }

    $caseClientId = isset($case['client_id']) ? (int)$case['client_id'] : 0;

    // Snapshot model: report view must read only existing case component snapshot rows.
    // visible_sections must be derived strictly from this snapshot.
    $contract = ws_build_snapshot_contract($pdo, $applicationId);
    $requiredComponents = $contract['visible_sections'];
    $outAssigned = $contract['assigned_components'];
    $componentWorkflowOut = $contract['component_workflow'];
    $mappingStatus = (string)($contract['mapping_status'] ?? 'ok');
    error_log('SNAPSHOT_COMPONENTS: ' . json_encode($requiredComponents));
    if ($mappingStatus !== 'ok') {
        error_log('WARNING: Minimal components detected. Possible missing DB mapping for this case.');
    }

    // Legacy self-heal: if snapshot is still minimal for an existing case,
    // rebuild snapshot/workflow using current binding logic.
    try {
        $caseIdForHeal = isset($case['case_id']) ? (int)$case['case_id'] : 0;
        if ($caseIdForHeal > 0 && count($requiredComponents) <= 2) {
            error_log('SNAPSHOT_HEAL: triggering sync for application_id=' . $applicationId . ', case_id=' . $caseIdForHeal);
            case_component_binding_sync_case_components($pdo, $caseIdForHeal, $applicationId);

            $healedKeys = [];
            $ckStmt2 = $pdo->prepare(
                'SELECT DISTINCT LOWER(TRIM(component_key)) AS component_key '
                . 'FROM Vati_Payfiller_Case_Components '
                . 'WHERE application_id = ?'
            );
            $ckStmt2->execute([$applicationId]);
            $ckRows2 = $ckStmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($ckRows2 as $r2) {
                $k2 = ws_norm_component_key((string)($r2['component_key'] ?? ''));
                if ($k2 !== '') $healedKeys[] = $k2;
            }
            $healedKeys = array_values(array_unique($healedKeys));
            if (count($healedKeys) > count($requiredComponents)) {
                $requiredComponents = $healedKeys;
                $mappingStatus = 'ok';
                error_log('SNAPSHOT_HEAL: success components=' . json_encode($requiredComponents));
            } else {
                error_log('SNAPSHOT_HEAL: no expansion after sync');
            }
        }
    } catch (Throwable $e) {
        error_log('SNAPSHOT_HEAL: failed ' . $e->getMessage());
    }

    $allowedSet = session_allowed_sections($pdo);
    $assignedComponents = $outAssigned;

    // Enrich assigned_components with stage labels
    foreach ($outAssigned as &$it) {
        $ck = ws_norm_component_key((string)($it['component_key'] ?? ''));
        $st = $componentWorkflowOut[$ck] ?? [];
        $stSimple = [
            'candidate' => isset($st['candidate']['status']) ? (string)$st['candidate']['status'] : 'pending',
            'validator' => isset($st['validator']['status']) ? (string)$st['validator']['status'] : 'pending',
            'verifier' => isset($st['verifier']['status']) ? (string)$st['verifier']['status'] : 'pending',
            'qa' => isset($st['qa']['status']) ? (string)$st['qa']['status'] : 'pending',
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
        $it['current_stage'] = ws_compute_component_stage_label($stSimple);
    }

    $itemWorkflowByComponent = [];
    if (ws_component_item_workflow_table_available($pdo)) {
        try {
            $iw = $pdo->prepare(
                'SELECT component_key, item_key, stage, status, completed_at, updated_at '
                . 'FROM Vati_Payfiller_Case_Component_Item_Workflow '
                . 'WHERE application_id = ?'
            );
            $iw->execute([$applicationId]);
            $rows = $iw->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $ck = ws_norm_component_key((string)($r['component_key'] ?? ''));
                $ik = norm_item_key((string)($r['item_key'] ?? ''));
                $st = strtolower(trim((string)($r['stage'] ?? '')));
                if ($ck === '' || $ik === '' || $st === '') continue;
                if (!isset($itemWorkflowByComponent[$ck])) $itemWorkflowByComponent[$ck] = [];
                if (!isset($itemWorkflowByComponent[$ck][$ik])) $itemWorkflowByComponent[$ck][$ik] = [];
                $itemWorkflowByComponent[$ck][$ik][$st] = [
                    'status' => (string)($r['status'] ?? ''),
                    'completed_at' => $r['completed_at'] ?? null,
                    'updated_at' => $r['updated_at'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            $itemWorkflowByComponent = [];
        }
    }

    $applyItemWorkflow = function (array $rows, string $componentKey) use ($itemWorkflowByComponent): array {
        $ck = ws_norm_component_key($componentKey);
        $out = [];
        foreach ($rows as $idx => $row) {
            if (!is_array($row)) {
                $out[] = $row;
                continue;
            }
            $itemKey = norm_item_key((string)($row['item_key'] ?? item_key_for_row($ck, $row, (int)$idx)));
            if ($itemKey === '') $itemKey = $ck . ':' . (string)($idx + 1);
            $row['item_key'] = $itemKey;
            $st = isset($itemWorkflowByComponent[$ck][$itemKey]) && is_array($itemWorkflowByComponent[$ck][$itemKey])
                ? $itemWorkflowByComponent[$ck][$itemKey]
                : [];
            $stSimple = [
                'candidate' => isset($st['candidate']['status']) ? (string)$st['candidate']['status'] : '',
                'validator' => isset($st['validator']['status']) ? (string)$st['validator']['status'] : '',
                'verifier' => isset($st['verifier']['status']) ? (string)$st['verifier']['status'] : '',
                'qa' => isset($st['qa']['status']) ? (string)$st['qa']['status'] : '',
            ];
            $row['workflow'] = $stSimple;
            $row['current_stage'] = ws_compute_component_stage_label($stSimple);
            $out[] = $row;
        }
        return $out;
    };

    $identification = $applyItemWorkflow($identification, 'id');
    $education = $applyItemWorkflow($education, 'education');
    $employment = $applyItemWorkflow($employment, 'employment');
    unset($it);

    $clientRequiredMap = [];
    foreach ($requiredComponents as $ck) {
        $k = ws_norm_component_key((string)$ck);
        if ($k === '') continue;
        $clientRequiredMap[$k] = true;
    }

    // Snapshot integrity for report payload: workflow keys must be subset of snapshot keys.
    if (!empty($componentWorkflowOut)) {
        foreach (array_keys($componentWorkflowOut) as $wk) {
            $nk = ws_norm_component_key((string)$wk);
            if ($nk === '' || !isset($clientRequiredMap[$nk])) {
                unset($componentWorkflowOut[$wk]);
            }
        }
    }

    $visibleSections = [];
    if ($role === 'verifier') {
        $verifierAssignedMap = [];
        foreach ($assignedComponents as $r) {
            $k = ws_norm_component_key((string)($r['component_key'] ?? ''));
            if ($k === '') continue;
            $ar = strtolower(trim((string)($r['assigned_role'] ?? '')));
            $au = isset($r['assigned_user_id']) ? (int)$r['assigned_user_id'] : 0;
            if ($ar === 'verifier' && $userId > 0 && $au === $userId) {
                $verifierAssignedMap[$k] = true;
            }
        }

        if (count($verifierAssignedMap) > 0) {
            foreach ($clientRequiredMap as $k => $_v) {
                if (!isset($verifierAssignedMap[$k])) continue;
                if (!can_section($allowedSet, $k)) continue;
                $visibleSections[] = $k;
            }
        } else {
            // Backward compatibility: when explicit component assignments are absent,
            // expose all snapshot components within allowed_sections scope.
            foreach ($clientRequiredMap as $k => $_v) {
                if (!can_section($allowedSet, $k)) continue;
                $visibleSections[] = $k;
            }
        }
    } elseif ($role === 'validator' || $role === 'db_verifier') {
        foreach ($clientRequiredMap as $k => $_v) {
            if (!can_section($allowedSet, $k)) continue;
            $visibleSections[] = $k;
        }
    } else {
        foreach ($clientRequiredMap as $k => $_v) {
            $visibleSections[] = $k;
        }
    }
    $visibleSections = array_values(array_unique($visibleSections));
    if ($role === 'candidate') {
        // Candidate review must show full submitted profile data.
        $visibleSections = ['basic', 'id', 'contact', 'education', 'employment', 'reference', 'socialmedia', 'ecourt', 'reports'];
    }
    $visibleSectionsMap = [];
    foreach ($visibleSections as $k) {
        $visibleSectionsMap[$k] = true;
    }

    // Staff views should respect allowed section scope in assigned components payload.
    // Action APIs still enforce assignment/rejection rules.
    $visibleAssigned = $outAssigned;
    if ($role === 'verifier' || $role === 'db_verifier') {
        $visibleAssigned = array_values(array_filter($outAssigned, function ($it) use ($visibleSectionsMap) {
            $k = ws_norm_component_key((string)($it['component_key'] ?? ''));
            if ($k === '') return false;
            return isset($visibleSectionsMap[$k]);
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

        // Snapshot-driven assignment: verifier should have explicit component assignments when configured.
        // If component assignments are not configured in this environment, allow snapshot fallback.
        $hasComponentAssignment = false;
        foreach ($outAssigned as $it) {
            if (($it['assigned_role'] ?? '') === 'verifier' && (int)($it['assigned_user_id'] ?? 0) === (int)$userId) {
                $hasComponentAssignment = true;
                break;
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
        if (!isset($visibleSectionsMap['basic'])) {
            $basic = null;
        }
        if (!isset($visibleSectionsMap['id'])) {
            $identification = [];
        }
        if (!isset($visibleSectionsMap['contact'])) {
            $contact = null;
        }
        if (!isset($visibleSectionsMap['education'])) {
            $education = [];
        }
        if (!isset($visibleSectionsMap['employment'])) {
            $employment = [];
        }
        if (!isset($visibleSectionsMap['reference'])) {
            $reference = null;
        }
        if (!isset($visibleSectionsMap['socialmedia'])) {
            $socialMedia = null;
        }
        if (!isset($visibleSectionsMap['ecourt'])) {
            $ecourt = null;
        }
        if (!isset($visibleSectionsMap['reports'])) {
            $authorization = null;
        }
    }

    // Verifier section payload follows the same visibleAssigned set.
    if ($role === 'verifier') {
        $visibleMap = [];
        foreach ($visibleAssigned as $it) {
            $k = ws_norm_component_key((string)($it['component_key'] ?? ''));
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

    // Final strict clamp: response payload must never include sections outside visible_sections.
    $allowed = array_flip($visibleSections ?? []);
    if (!isset($allowed['basic'])) $basic = null;
    if (!isset($allowed['id'])) $identification = [];
    if (!isset($allowed['contact'])) $contact = null;
    if (!isset($allowed['education'])) $education = [];
    if (!isset($allowed['employment'])) $employment = [];
    if (!isset($allowed['reference'])) $reference = null;
    if (!isset($allowed['socialmedia'])) $socialMedia = null;
    if (!isset($allowed['ecourt'])) $ecourt = null;

    $case['application_id'] = integration_normalize_application_id((string)($case['application_id'] ?? $applicationId));
    if (isset($case['candidate_email'])) {
        $case['candidate_email'] = integration_normalize_email((string)$case['candidate_email']);
    }
    if (isset($application['application_id'])) {
        $application['application_id'] = integration_normalize_application_id((string)$application['application_id']);
    }
    if (isset($basic['email'])) {
        $basic['email'] = integration_normalize_email((string)$basic['email']);
    }
    $links = integration_deep_links($case['application_id'], isset($case['case_id']) ? (int)$case['case_id'] : null);

    $isStaffDebugRole = in_array($role, ['gss_admin', 'client_admin', 'verifier', 'validator', 'db_verifier'], true);
    $response = [
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'applicationId' => $case['application_id'],
            'caseId' => isset($case['case_id']) ? (int)$case['case_id'] : null,
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
            'visible_sections' => $visibleSections,
            'visibleSections' => $visibleSections,
            'assigned_components' => $visibleAssigned,
            'assignedComponents' => $visibleAssigned,
            'component_workflow' => $componentWorkflowOut,
            'componentWorkflow' => $componentWorkflowOut,
            'component_item_workflow' => $itemWorkflowByComponent,
            'componentItemWorkflow' => $itemWorkflowByComponent,
            'applicationUrl' => $links['applicationUrl'],
            'candidateUrl' => $links['candidateUrl'],
            'timelineUrl' => $links['timelineUrl']
        ]
    ];
    $appEnv = function_exists('env_get') ? strtolower(trim((string)(env_get('APP_ENV', 'production') ?? 'production'))) : 'production';
    if ($isStaffDebugRole && in_array($appEnv, ['dev', 'development', 'local'], true)) {
        $response['debug'] = [
            'visible_sections' => $visibleSections,
            'mapping_status' => $mappingStatus
        ];
    }
    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
