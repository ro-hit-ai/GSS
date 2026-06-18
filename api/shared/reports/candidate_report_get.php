<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../includes/integration.php';
require_once __DIR__ . '/../case_management/case_component_binding.php';
require_once __DIR__ . '/../case_management/reference_component_compat.php';
require_once __DIR__ . '/../workflow/workflow_snapshot_service.php';
require_once __DIR__ . '/../workflow/workflow_semantics.php';
require_once __DIR__ . '/../workflow/workflow_status_semantics.php';
require_once __DIR__ . '/../workflow/WorkflowLockService.php';
require_once __DIR__ . '/../authorization/workflow_mode.php';
require_once __DIR__ . '/../verifier_case_queue.php';
require_once __DIR__ . '/../../../services/candidate/ReferenceService.php';

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

function candidate_fetch_education_documents(PDO $pdo, string $applicationId): array {
    $stmt = $pdo->prepare("
        SELECT education_index, document_slot, file_name, original_name, created_at
        FROM Vati_Payfiller_Candidate_Education_Documents
        WHERE application_id = ?
        ORDER BY education_index ASC, id ASC
    ");
    $stmt->execute([$applicationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $idx = (int)($row['education_index'] ?? 0);
        if ($idx <= 0) continue;
        $out[$idx][] = $row;
    }
    return $out;
}

function candidate_fetch_identification_rows(PDO $pdo, string $applicationId): array {
    $stmt = $pdo->prepare("
        SELECT
            document_index,
            COALESCE(NULLIF(proof_group, ''), 'primary') AS proof_group,
            documentId_type AS document_type,
            id_number,
            name,
            issue_date,
            expiry_date,
            upload_document
        FROM Vati_Payfiller_Candidate_Identification_details
        WHERE application_id = ?
        ORDER BY document_index ASC, proof_group ASC, id ASC
    ");
    $stmt->execute([$applicationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $idx = (int)($row['document_index'] ?? 0);
        $group = trim((string)($row['proof_group'] ?? ''));
        if ($idx <= 0 || $group === '') continue;
        $out[$idx][$group] = $row;
    }
    return $out;
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
        $documentIndex = norm_item_key((string)($row['document_index'] ?? ''));
        $proofGroup = norm_item_key((string)($row['proof_group'] ?? ''));
        if ($documentIndex !== '' && $proofGroup !== '') return 'id:' . $documentIndex . ':' . $proofGroup;
        $rowId = norm_item_key((string)($row['id'] ?? ''));
        if ($rowId !== '') return 'id:row:' . $rowId;
        if ($documentIndex !== '') return 'id:' . $documentIndex;
    }
    if ($k === 'education') {
        $educationIndex = norm_item_key((string)($row['education_index'] ?? ''));
        if ($educationIndex !== '') return 'education:' . $educationIndex;
        $rowId = norm_item_key((string)($row['id'] ?? ''));
        if ($rowId !== '') return 'education:row:' . $rowId;
    }
    if ($k === 'employment') {
        $employmentIndex = norm_item_key((string)($row['employment_index'] ?? ''));
        if ($employmentIndex !== '') return 'employment:' . $employmentIndex;
        $rowId = norm_item_key((string)($row['id'] ?? ''));
        if ($rowId !== '') return 'employment:row:' . $rowId;
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
    if (in_array($role, ['validator', 'qa', 'team_lead', 'gss_admin', 'client_admin'], true)) {
        return ['*' => true];
    }
    $raw = isset($_SESSION['auth_allowed_sections']) ? (string)$_SESSION['auth_allowed_sections'] : '';

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

function downstream_stage_for_role(string $role): string {
    $r = strtolower(trim($role));
    if ($r === 'validator') return 'verifier';
    if ($r === 'verifier' || $r === 'db_verifier') return 'qa';
    return '';
}

function has_downstream_activity_db(PDO $pdo, int $caseId, string $componentKey, string $downstreamStage): bool {
    $caseId = (int)$caseId;
    $ck = ws_norm_component_key($componentKey);
    $stage = strtolower(trim($downstreamStage));
    if ($caseId <= 0 || $ck === '' || $stage === '') return false;

    try {
        $st = $pdo->prepare(
            "SELECT 1 FROM Vati_Payfiller_Workflow_Transitions
             WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND LOWER(TRIM(stage)) = ?
             LIMIT 1"
        );
        $st->execute([$caseId, $ck, $stage]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) {
    }

    try {
        $st = $pdo->prepare(
            "SELECT 1 FROM Vati_Payfiller_Case_Component_Workflow
             WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND LOWER(TRIM(stage)) = ?
               AND (
                    COALESCE(updated_by_user_id,0) > 0
                    OR LOWER(TRIM(COALESCE(status,''))) NOT IN ('', 'pending', 'in_progress', 'submitted', 'correction_submitted')
                    OR completed_at IS NOT NULL
               )
             LIMIT 1"
        );
        $st->execute([$caseId, $ck, $stage]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) {
    }

    return false;
}

function component_stage_surface($componentWorkflowOut, string $componentKey): array {
    if (!is_array($componentWorkflowOut)) {
        $componentWorkflowOut = [];
    }
    $ck = ws_norm_component_key($componentKey);
    $st = isset($componentWorkflowOut[$ck]) && is_array($componentWorkflowOut[$ck]) ? $componentWorkflowOut[$ck] : [];
    if (!$st && ws_is_split_reference_key($ck) && isset($componentWorkflowOut['reference']) && is_array($componentWorkflowOut['reference'])) {
        $st = $componentWorkflowOut['reference'];
    }
    return ws_component_stage_surface($st, $ck);
}

function report_component_workflow_display_status(array $componentWorkflowOut, string $componentKey, string $role, string $state = ''): string {
    $roleNorm = strtolower(trim($role));
    $stage = $roleNorm;
    if ($roleNorm === 'db_verifier') $stage = 'verifier';
    if ($roleNorm === 'team_lead') $stage = 'qa';
    $surface = component_stage_surface($componentWorkflowOut, $componentKey);
    $status = $stage !== '' ? strtolower(trim((string)($surface[$stage] ?? ''))) : '';
    if ($status !== '' && $status !== 'pending') {
        return wf_role_label_from_status($status, $stage);
    }
    $s = strtolower(trim($state));
    if ($s === 'context') return 'Context';
    if ($s === 'completed') return 'Completed';
    if ($s === 'owned_active') return $status !== '' ? wf_role_label_from_status($status, $stage) : 'Active';
    if ($s === 'claimable_next') return 'Ready';
    if ($s === 'locked_future') return 'Locked';
    return $status !== '' ? wf_role_label_from_status($status, $stage) : '';
}

function get_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

function report_debug_enabled(): bool {
    return get_str('debug', '') === '1';
}

function registered_section_keys(): array {
    return ['basic', 'id', 'contact', 'education', 'education_reference', 'employment', 'employment_reference', 'reference', 'socialmedia', 'ecourt', 'reports'];
}

function report_reference_component_key(array $referenceRow): string {
    $type = strtolower(trim((string)($referenceRow['reference_type'] ?? ($referenceRow['type'] ?? ''))));
    if ($type === '') return '';
    if (strpos($type, 'education') !== false) return 'education_reference';
    if (strpos($type, 'employment') !== false) return 'employment_reference';
    return '';
}

function report_filter_reference_payload_by_components($reference, array $visibleSections) {
    if (!is_array($reference)) return $reference;
    $visible = [];
    foreach (reference_compat_effective_keys($visibleSections) as $section) {
        $key = ws_norm_component_key((string)$section);
        if ($key !== '') $visible[$key] = true;
    }
    if (isset($visible['reference']) || (isset($visible['education_reference']) && isset($visible['employment_reference']))) {
        return $reference;
    }
    $wantsEducation = isset($visible['education_reference']);
    $wantsEmployment = isset($visible['employment_reference']);
    if (!$wantsEducation && !$wantsEmployment) return $reference;

    if (isset($reference[0]) && is_array($reference[0])) {
        return array_values(array_filter($reference, function ($item) use ($wantsEducation, $wantsEmployment) {
            $key = report_reference_component_key((array)$item);
            if ($key === '') return true;
            if ($key === 'education_reference') return $wantsEducation;
            if ($key === 'employment_reference') return $wantsEmployment;
            return true;
        }));
    }

    $key = report_reference_component_key($reference);
    if ($key === 'education_reference' && !$wantsEducation) return [];
    if ($key === 'employment_reference' && !$wantsEmployment) return [];
    return $reference;
}

function report_component_key_for_timeline(string $key): string {
    $k = strtolower(trim($key));
    $k = str_replace(['-', ' '], '_', $k);
    if ($k === 'identification') return 'id';
    if ($k === 'address') return 'contact';
    if ($k === 'social_media') return 'socialmedia';
    return $k;
}

function report_status_from_timeline_event(string $eventType, string $message): string {
    return '';
}

function report_component_timeline_history(PDO $pdo, string $applicationId): array {
    $out = [];
    if ($applicationId === '') return $out;
    try {
        $st = $pdo->prepare(
            "SELECT section_key, event_type, message, created_at
               FROM Vati_Payfiller_Case_Timeline
              WHERE application_id = ?
              ORDER BY created_at ASC"
        );
        $st->execute([$applicationId]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $key = report_component_key_for_timeline((string)($row['section_key'] ?? ''));
            if ($key === '' || $key === 'timeline') continue;
            $eventType = (string)($row['event_type'] ?? '');
            $message = (string)($row['message'] ?? '');
            $out[$key][] = [
                'at' => (string)($row['created_at'] ?? ''),
                'event' => $eventType,
                'message' => $message,
                'status' => report_status_from_timeline_event($eventType, $message),
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function report_component_history_for_key(array $history, string $componentKey): array {
    $key = report_component_key_for_timeline($componentKey);
    $items = $history[$key] ?? [];
    if (!$items && ($key === 'education_reference' || $key === 'employment_reference')) {
        $items = $history['reference'] ?? [];
    } elseif (!$items && $key === 'contact') {
        $items = $history['address'] ?? [];
    } elseif (!$items && $key === 'id') {
        $items = $history['identification'] ?? [];
    }
    return $items;
}

function report_component_display_status(array $history, string $state): string {
    for ($i = count($history) - 1; $i >= 0; $i--) {
        $status = trim((string)($history[$i]['status'] ?? ''));
        if ($status !== '') return $status;
    }
    $s = strtolower(trim($state));
    if ($s === 'context') return 'Context';
    if ($s === 'completed') return 'Completed';
    if ($s === 'owned_active') return 'Active';
    if ($s === 'claimable_next') return 'Ready';
    if ($s === 'locked_future') return 'Locked';
    return '';
}

function validator_operational_template_sections(): array {
    return ['basic', 'id', 'contact', 'education', 'employment', 'reference', 'socialmedia', 'ecourt', 'reports'];
}

function wf_candidate_visible_sections(array $candidateEntitlement, array $correctionSet = []): array {
    $out = [];
    foreach ($candidateEntitlement as $k => $_v) {
        $nk = ws_norm_component_key((string)$k);
        if ($nk !== '') $out[$nk] = true;
    }
    if (!empty($correctionSet)) {
        $filtered = [];
        foreach ($out as $k => $_v) {
            if (isset($correctionSet[$k])) $filtered[$k] = true;
        }
        $out = $filtered;
    }
    return array_values(array_keys($out));
}

function wf_operational_visible_sections(string $role, array $allowedSet, array $candidateEntitlement, array $operationalPool): array {
    $role = strtolower(trim($role));
    $out = [];
    if (in_array($role, ['validator', 'verifier', 'db_verifier', 'qa', 'team_lead', 'gss_admin', 'client_admin'], true)) {
        foreach ($operationalPool as $k => $_v) {
            $nk = ws_norm_component_key((string)$k);
            if ($nk === '') continue;
            if (!can_section($allowedSet, $nk)) continue;
            $out[$nk] = true;
        }
        return array_values(array_keys($out));
    }
    foreach ($candidateEntitlement as $k => $_v) {
        $nk = ws_norm_component_key((string)$k);
        if ($nk !== '') $out[$nk] = true;
    }
    return array_values(array_keys($out));
}

function request_role_hint(): string {
    $r = strtolower(trim(get_str('role', '')));
    if ($r === 'component validator' || $r === 'component_validator') return 'validator';
    if ($r === 'component verifier' || $r === 'component_verifier') return 'verifier';
    if ($r === 'customer_admin') return 'client_admin';
    return $r;
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

function candidate_profile_submitted(array $application, array $case): bool {
    $submittedAt = trim((string)($application['submitted_at'] ?? ''));
    if ($submittedAt !== '' && $submittedAt !== '0000-00-00 00:00:00' && $submittedAt !== '0000-00-00') {
        return true;
    }

    $appStatus = strtolower(trim((string)($application['status'] ?? '')));
    if (in_array($appStatus, ['submitted', 'approved', 'completed', 'in_review', 'under_review', 'pending_validator', 'pending_verifier', 'pending_qa'], true)) {
        return true;
    }

    $caseStatus = strtolower(trim((string)($case['case_status'] ?? '')));
    if (in_array($caseStatus, ['pending_validator', 'pending_verifier', 'pending_qa', 'approved', 'completed', 'in_progress', 'under_review'], true)) {
        return true;
    }

    return false;
}

function candidate_authorization_completed(PDO $pdo, string $applicationId): bool {
    try {
        $st = $pdo->prepare(
            "SELECT 1
               FROM Vati_Payfiller_Candidate_Authorization_documents
              WHERE application_id = ?
                AND TRIM(COALESCE(digital_signature, '')) <> ''
              LIMIT 1"
        );
        $st->execute([$applicationId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
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

   
    $role = $authViaApiKey ? 'service' : session_role_norm();
    if (session_status() === PHP_SESSION_NONE) session_start();
    $roleHint = request_role_hint();
    if (!$authViaApiKey && $roleHint === 'candidate' && !empty($_SESSION['logged_in']) && !empty($_SESSION['application_id'])) {
        $role = 'candidate';
    }
    $userId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
    $clientId = ($authViaApiKey || $role === 'candidate') ? 0 : resolve_client_id();
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

    if (!$authViaApiKey && $role === 'candidate') {
        $isLoggedIn = !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
        $sessionAppId = integration_normalize_application_id((string)($_SESSION['application_id'] ?? ''));
        if (!$isLoggedIn || $sessionAppId === '' || $sessionAppId !== $applicationId) {
            http_response_code(401);
            echo json_encode(['status' => 0, 'message' => 'Unauthorized access to application']);
            exit;
        }
    } elseif (!$authViaApiKey) {
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

    try {
        $reference = ReferenceService::fetchGrouped($pdo, $applicationId);
    } catch (Throwable $e) {
    }

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
        shared_endpoint_log('case lookup failed application_id=' . $applicationId . ' auth_method=' . ($authViaApiKey ? 'api-key' : 'session'));
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found for this application_id']);
        exit;
    }
    $case['workflow_mode'] = wf_mode_get_case_mode($pdo, (int)($case['case_id'] ?? 0), $applicationId);

    $isReviewerRole = in_array($role, ['validator', 'verifier', 'db_verifier', 'qa', 'team_lead'], true);
    $isSubmitted = candidate_profile_submitted((array)$application, (array)$case);
    $isAuthorizationCompleted = candidate_authorization_completed($pdo, $applicationId);
    if ($isReviewerRole && (!$isSubmitted || !$isAuthorizationCompleted)) {
        http_response_code(409);
        $msg = !$isSubmitted
            ? 'Candidate has not submitted details yet.'
            : 'Candidate authorization is not completed yet.';
        echo json_encode([
            'status' => 0,
            'message' => $msg,
            'debug' => report_debug_enabled() ? [
                'review_page_entered' => true,
                'final_submit_completed' => $isSubmitted ? 1 : 0,
                'authorization_completed' => $isAuthorizationCompleted ? 1 : 0,
                'queue_activation_allowed' => 0
            ] : null
        ]);
        exit;
    }

    $selectedLevel = strtolower(trim((string)($case['selected_level'] ?? '')));
    $selectedStage = norm_case_stage((string)($case['selected_stage'] ?? ''));

    $referencePriorityBucketParam = strtolower(trim(get_str('priority_bucket', '')));
    $referenceHasPriorityBucket = preg_match('/^p?([1-9][0-9]*)$/', $referencePriorityBucketParam) === 1;
    if (!$referenceHasPriorityBucket && is_array($reference) && isset($reference[0]) && is_array($reference[0])) {
        $reference = array_values(array_filter($reference, function ($item) use ($selectedStage) {
            $type = strtolower(trim((string)($item['reference_type'] ?? ($item['type'] ?? ''))));
            if ($selectedStage === 'p1') {
                return $type === '' || strpos($type, 'education') !== false;
            }
            return true;
        }));
    } elseif (!$referenceHasPriorityBucket && is_array($reference)) {
        $type = strtolower(trim((string)($reference['reference_type'] ?? ($reference['type'] ?? ''))));
        if ($selectedStage === 'p1' && $type !== '' && strpos($type, 'education') === false) {
            $reference = [];
        }
    }

    // Presentation-only dynamic label for the contact/address section.
    // Prefer configured verification types when available so Current/Permanent
    // address selections stay semantically honest while remaining inside contact.
    $contactLabel = 'Address Details';
    try {
        $jobRoleIdForContact = case_component_binding_fetch_job_role_id($pdo, $case);
        [$bindingStage, $bindingLevel] = case_component_binding_parse_stage_level(
            (string)($case['selected_stage'] ?? ''),
            (string)($case['selected_level'] ?? '')
        );
        $bindingTypes = $jobRoleIdForContact > 0
            ? case_component_binding_fetch_types($pdo, $jobRoleIdForContact, $bindingLevel, $bindingStage)
            : [];
        $contactSections = [];
        foreach ($bindingTypes as $bindingType) {
            $isEnabledRaw = $bindingType['is_enabled'] ?? 1;
            $isEnabled = ($isEnabledRaw === null || $isEnabledRaw === '') ? 1 : (int)$isEnabledRaw;
            if ($isEnabled !== 1) {
                continue;
            }

            $mappedComponents = [];
            $dbComponentKey = case_component_binding_norm_component_key((string)($bindingType['component_key'] ?? ''));
            if ($dbComponentKey !== '') {
                $mappedComponents[] = $dbComponentKey;
            } else {
                $mappedComponents = case_component_binding_map_verification_type_to_components(
                    (string)($bindingType['type_name'] ?? ''),
                    (string)($bindingType['type_category'] ?? '')
                );
            }
            $mappedComponents = array_values(array_unique(array_filter(array_map('case_component_binding_norm_component_key', $mappedComponents))));
            if (!in_array('contact', $mappedComponents, true)) {
                continue;
            }

            foreach (case_component_binding_detect_contact_sections(
                (string)($bindingType['type_name'] ?? ''),
                (string)($bindingType['type_category'] ?? '')
            ) as $sectionKey) {
                $contactSections[$sectionKey] = true;
            }
        }

        $hasCurrentAddress = isset($contactSections['current_address']);
        $hasPermanentAddress = isset($contactSections['permanent_address']);
        if ($hasCurrentAddress && $hasPermanentAddress) {
            $contactLabel = 'Current OR Permanent Address';
        } elseif ($hasPermanentAddress) {
            $contactLabel = 'Permanent Address';
        } elseif ($hasCurrentAddress) {
            $contactLabel = 'Current Address';
        } elseif ($selectedLevel === 'l1') {
            $contactLabel = 'Current Address';
        } elseif ($selectedLevel === 'l2') {
            $contactLabel = 'Current OR Permanent Address';
        } elseif ($selectedLevel === 'l3') {
            $contactLabel = 'Full Address Details';
        }
    } catch (Throwable $e) {
        if ($selectedLevel === 'l1') {
            $contactLabel = 'Current Address';
        } elseif ($selectedLevel === 'l2') {
            $contactLabel = 'Current OR Permanent Address';
        } elseif ($selectedLevel === 'l3') {
            $contactLabel = 'Full Address Details';
        }
    }
    $educationDocumentsByIndex = candidate_fetch_education_documents($pdo, $applicationId);

    if (is_array($basic)) {
        $basic['resume_file'] = (string)($basic['resume_file'] ?? '');
        $basic['resume_original_name'] = (string)($basic['resume_original_name'] ?? '');
        if ($basic['resume_file'] === '' || $basic['resume_original_name'] === '') {
            try {
                $resumeStmt = $pdo->prepare("
                    SELECT resume_file, resume_original_name
                    FROM Vati_Payfiller_Candidate_Basic_details
                    WHERE application_id = ?
                    LIMIT 1
                ");
                $resumeStmt->execute([$applicationId]);
                $resumeRow = $resumeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $basic['resume_file'] = (string)($resumeRow['resume_file'] ?? $basic['resume_file']);
                $basic['resume_original_name'] = (string)($resumeRow['resume_original_name'] ?? $basic['resume_original_name']);
            } catch (Throwable $e) {
            }
        }
        $basic['resume_url'] = build_file_url((string)($basic['resume_file'] ?? ''), 'resume');
    }

    if (is_array($contact)) {
        $contact['label'] = $contactLabel;
        $contact['address_proof_url'] = build_file_url((string)($contact['current_proof_file'] ?? ($contact['address_proof_file'] ?? ($contact['proof_file'] ?? ''))), 'address');
        $contact['current_proof_url'] = build_file_url((string)($contact['current_proof_file'] ?? ''), 'address');
        $contact['permanent_proof_url'] = build_file_url((string)($contact['permanent_proof_file'] ?? ''), 'address');
        error_log('Address label set: ' . $contactLabel . ' for level ' . $selectedLevel);
    }

    $educationMetaMap = [];
    try {
        $educationMetaStmt = $pdo->prepare("
            SELECT education_index, ca_membership_number, year_of_passing
            FROM Vati_Payfiller_Candidate_Education_details
            WHERE application_id = ?
        ");
        $educationMetaStmt->execute([$applicationId]);
        foreach (($educationMetaStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $metaRow) {
            $educationMetaMap[(int)($metaRow['education_index'] ?? 0)] = $metaRow;
        }
    } catch (Throwable $e) {
        $educationMetaMap = [];
    }

    foreach ($education as $i => $row) {
        if (!is_array($row)) continue;
        $educationIndex = (int)($row['education_index'] ?? ($i + 1));
        if (isset($educationMetaMap[$educationIndex])) {
            $education[$i]['ca_membership_number'] = $educationMetaMap[$educationIndex]['ca_membership_number'] ?? ($education[$i]['ca_membership_number'] ?? '');
            $education[$i]['year_of_passing'] = $educationMetaMap[$educationIndex]['year_of_passing'] ?? ($education[$i]['year_of_passing'] ?? '');
        }
        $allEducationDocs = array_values($educationDocumentsByIndex[$educationIndex] ?? []);
        $education[$i]['marksheet_documents'] = array_values(array_filter($allEducationDocs, static function ($doc) {
            return strtolower((string)($doc['document_slot'] ?? '')) === 'marksheet';
        }));
        foreach ($education[$i]['marksheet_documents'] as $docIdx => $doc) {
            $education[$i]['marksheet_documents'][$docIdx]['url'] = build_file_url((string)($doc['file_name'] ?? ''), 'education');
        }
        $education[$i]['marksheet_url'] = build_file_url((string)($row['marksheet_file'] ?? ''), 'education');
        $education[$i]['degree_url'] = build_file_url((string)($row['degree_file'] ?? ''), 'education');
        $education[$i]['supporting_documents'] = array_values(array_filter($allEducationDocs, static function ($doc) {
            return strtolower((string)($doc['document_slot'] ?? '')) !== 'marksheet';
        }));
        foreach ($education[$i]['supporting_documents'] as $docIdx => $doc) {
            $education[$i]['supporting_documents'][$docIdx]['url'] = build_file_url((string)($doc['file_name'] ?? ''), 'education');
        }
    }

    if (is_array($ecourt)) {
        $ecourt['document_url'] = build_file_url((string)($ecourt['document'] ?? ($ecourt['evidence_document'] ?? '')), 'ecourt');
        $ecourt['applicant_legal_name'] = (string)($ecourt['applicant_legal_name'] ?? '');
        $ecourt['father_name'] = (string)($ecourt['father_name'] ?? '');
        $ecourt['current_address_snapshot'] = (string)($ecourt['current_address_snapshot'] ?? '');
        $ecourt['permanent_address_snapshot'] = (string)($ecourt['permanent_address_snapshot'] ?? '');
        $ecourt['same_as_current'] = (int)($ecourt['same_as_current'] ?? 0);
    }

    if (is_array($authorization)) {
        $authorization['file_url'] = build_file_url((string)($authorization['authorization_file'] ?? ($authorization['file_name'] ?? '')), 'verification');
    }

    $identificationRowsByDocument = candidate_fetch_identification_rows($pdo, (string)$applicationId);
    if (!empty($identificationRowsByDocument)) {
        $identification = [];
        foreach ($identificationRowsByDocument as $docIndex => $groupRows) {
            foreach (['primary', 'secondary'] as $groupKey) {
                if (empty($groupRows[$groupKey]) || !is_array($groupRows[$groupKey])) {
                    continue;
                }
                $row = $groupRows[$groupKey];
                $row['document_index'] = $docIndex;
                $row['proof_group'] = (string)($row['proof_group'] ?? $groupKey);
                $row['document_type'] = (string)($row['document_type'] ?? '');
                $row['upload_document'] = (string)($row['upload_document'] ?? '');
                $identification[] = $row;
            }
        }
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
    $requiredComponents = reference_compat_effective_keys($contract['visible_sections'] ?? []);
    $outAssigned = reference_compat_filter_rows(is_array($contract['assigned_components'] ?? null) ? $contract['assigned_components'] : [], 'component_key');
    $componentWorkflowOut = $contract['component_workflow'];
    $mappingStatus = (string)($contract['mapping_status'] ?? 'ok');
    if (isset($componentWorkflowOut['reports']) && is_array($componentWorkflowOut['reports'])) {
        foreach (['verifier', 'qa'] as $legacyStage) {
            if (isset($componentWorkflowOut['reports'][$legacyStage]) && is_array($componentWorkflowOut['reports'][$legacyStage])) {
                $componentWorkflowOut['reports'][$legacyStage]['status'] = '';
            }
        }
    }
    error_log('SNAPSHOT_COMPONENTS: ' . json_encode($requiredComponents));
    if ($mappingStatus !== 'ok') {
        error_log('WARNING: Minimal components detected. Possible missing DB mapping for this case.');
    }

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
                $requiredComponents = reference_compat_effective_keys($healedKeys);
                $mappingStatus = 'ok';
                error_log('SNAPSHOT_HEAL: success components=' . json_encode($requiredComponents));
            } else {
                error_log('SNAPSHOT_HEAL: no expansion after sync');
            }
        }
    } catch (Throwable $e) {
        error_log('SNAPSHOT_HEAL: failed ' . $e->getMessage());
    }
    $requiredComponents = reference_compat_effective_keys($requiredComponents);

    $allowedSet = session_allowed_sections($pdo);
    $assignedComponents = $outAssigned;

    foreach ($outAssigned as &$it) {
        $ck = ws_norm_component_key((string)($it['component_key'] ?? ''));
        $stSimple = component_stage_surface($componentWorkflowOut, $ck);
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

    $applyItemWorkflow = function (array $rows, string $componentKey) use ($itemWorkflowByComponent, $componentWorkflowOut): array {
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
            $itemStageSet = isset($itemWorkflowByComponent[$ck][$itemKey]) && is_array($itemWorkflowByComponent[$ck][$itemKey])
                ? $itemWorkflowByComponent[$ck][$itemKey]
                : [];
            $row['workflow'] = component_stage_surface($componentWorkflowOut, $ck);
            $row['current_stage'] = ws_compute_component_stage_label($row['workflow']);
            $itemStageSimple = [
                'candidate' => isset($itemStageSet['candidate']['status']) ? (string)$itemStageSet['candidate']['status'] : '',
                'validator' => isset($itemStageSet['validator']['status']) ? (string)$itemStageSet['validator']['status'] : '',
                'verifier' => isset($itemStageSet['verifier']['status']) ? (string)$itemStageSet['verifier']['status'] : '',
                'qa' => isset($itemStageSet['qa']['status']) ? (string)$itemStageSet['qa']['status'] : '',
            ];
            $row['item_workflow'] = $itemStageSimple;
            $row['item_current_stage'] = ws_compute_component_stage_label($itemStageSimple);
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

    $registeredSectionsMap = array_fill_keys(registered_section_keys(), true);
    $normalizedComponentKeys = [];
    foreach (array_keys($clientRequiredMap) as $k) {
        $nk = ws_norm_component_key((string)$k);
        if ($nk !== '') $normalizedComponentKeys[$nk] = true;
    }
    $workflowStageVisibilityMap = [];
    foreach ($componentWorkflowOut as $wk => $wst) {
        $nk = ws_norm_component_key((string)$wk);
        if ($nk === '' || !isset($registeredSectionsMap[$nk])) continue;
        $workflowStageVisibilityMap[$nk] = true;
        $normalizedComponentKeys[$nk] = true;
    }
    $operationalPoolMap = $clientRequiredMap;
    foreach ($workflowStageVisibilityMap as $k => $_v) {
        $operationalPoolMap[$k] = true;
    }
    $ecourtVisibilityReason = 'not_applicable';
    $ecourtDataPresent = false;
    if (is_array($ecourt)) {
        $ecourtDataPresent =
            trim((string)($ecourt['current_address'] ?? '')) !== ''
            || trim((string)($ecourt['permanent_address'] ?? '')) !== ''
            || trim((string)($ecourt['evidence_document'] ?? '')) !== ''
            || trim((string)($ecourt['document'] ?? '')) !== ''
            || trim((string)($ecourt['document_url'] ?? '')) !== ''
            || trim((string)($ecourt['period_from_date'] ?? '')) !== ''
            || trim((string)($ecourt['period_to_date'] ?? '')) !== ''
            || trim((string)($ecourt['comments'] ?? '')) !== '';
    }
    if ($ecourtDataPresent) {
        $normalizedComponentKeys['ecourt'] = true;
    }
    // Reviewer operational visibility: ecourt may be internally managed and not candidate-required.
    if (in_array($role, ['validator', 'verifier', 'db_verifier', 'qa', 'team_lead', 'gss_admin', 'client_admin'], true)) {
        if (isset($workflowStageVisibilityMap['ecourt'])) {
            $operationalPoolMap['ecourt'] = true;
            $ecourtVisibilityReason = 'workflow_stage_visible';
        } elseif ($ecourtDataPresent) {
            $operationalPoolMap['ecourt'] = true;
            $ecourtVisibilityReason = 'operational_data_present';
        } else {
            $ecourtVisibilityReason = 'not_in_snapshot_or_workflow_or_data';
        }
    } else {
        $ecourtVisibilityReason = 'candidate_entitlement_mode';
    }
    // Internal reviewer-managed section: reports (candidate authorization review).
    $hasReportsData = is_array($authorization) && (
        trim((string)($authorization['digital_signature'] ?? '')) !== ''
        || trim((string)($authorization['file_name'] ?? '')) !== ''
        || trim((string)($authorization['authorization_file'] ?? '')) !== ''
        || trim((string)($authorization['uploaded_at'] ?? '')) !== ''
    );
    if ($hasReportsData) {
        $operationalPoolMap['reports'] = true;
    }

    if (!empty($componentWorkflowOut) && $role === 'candidate') {
        foreach (array_keys($componentWorkflowOut) as $wk) {
            $nk = ws_norm_component_key((string)$wk);
            if ($nk === '' || !isset($clientRequiredMap[$nk])) {
                unset($componentWorkflowOut[$wk]);
            }
        }
    }

    // Reports is an internal operational validator component and can be visible
    // even when not present in case snapshot. Ensure workflow projection includes
    // reports stage states so sidebar badges do not fall back to stale VA Pending.
    try {
        if ($role === 'validator') {
            $hasReportsWorkflow = isset($componentWorkflowOut['reports']) && is_array($componentWorkflowOut['reports']);
            if (!$hasReportsWorkflow) {
                $rw = $pdo->prepare(
                    'SELECT stage, status, completed_at, updated_at '
                    . 'FROM Vati_Payfiller_Case_Component_Workflow '
                    . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ?'
                );
                $rw->execute([(int)($case['case_id'] ?? 0), 'reports']);
                $rows = $rw->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($rows) {
                    $stages = [];
                    foreach ($rows as $rr) {
                        $sg = strtolower(trim((string)($rr['stage'] ?? '')));
                        if ($sg === '') continue;
                        $stages[$sg] = [
                            'status' => (string)($rr['status'] ?? ''),
                            'completed_at' => $rr['completed_at'] ?? null,
                            'updated_at' => $rr['updated_at'] ?? null,
                        ];
                    }
                    if (!empty($stages)) {
                        $componentWorkflowOut['reports'] = $stages;
                    }
                }
            }
        }
    } catch (Throwable $e) {
    }

    $visibleSections = [];
    $validatorTemplateSections = validator_operational_template_sections();
    $actionableMap = [];
    $readonlyMap = [];
    $lockedMap = [];
    $lockReasons = [];
    $verifierRoutingState = [];
    $selectedPriorityBucket = '';
    $reportMode = strtolower(trim(get_str('report_mode', '')));
    $isVerifierReadonlyReport = ($role === 'verifier' && in_array($reportMode, ['readonly', 'read_only', 'view_only'], true));
    foreach ($outAssigned as $it0) {
        $k0 = ws_norm_component_key((string)($it0['component_key'] ?? ''));
        if ($k0 === '') continue;
        $actionableMap[$k0] = true;
    }
    if ($role === 'verifier') {
        $rawPriorityBucket = strtolower(trim(get_str('priority_bucket', '')));
        if (preg_match('/^p?([1-9][0-9]*)$/', $rawPriorityBucket, $priorityMatch)) {
            $selectedPriorityBucket = 'p' . $priorityMatch[1];
        }
        $verifierRoutingState = verifier_routing_case_state($pdo, (int)($case['case_id'] ?? 0), (int)$userId);
        $reportTimelineHistory = report_component_timeline_history($pdo, $applicationId);
        foreach (($verifierRoutingState['components'] ?? []) as $componentKey => $componentState) {
            $history = report_component_history_for_key($reportTimelineHistory, (string)$componentKey);
            $verifierRoutingState['components'][$componentKey]['history'] = $history;
            $verifierRoutingState['components'][$componentKey]['display_status'] = report_component_workflow_display_status(
                $componentWorkflowOut,
                (string)$componentKey,
                $role,
                (string)($componentState['state'] ?? '')
            );
        }
        $verifierVisibleRaw = $verifierRoutingState['visible_sections'] ?? ['basic'];
        $visibleSections = array_values(array_filter(array_map(static function ($key) {
            return ws_norm_component_key((string)$key);
        }, $verifierVisibleRaw), static function ($key) use ($registeredSectionsMap) {
            $nk = ws_norm_component_key((string)$key);
            return $nk !== '' && isset($registeredSectionsMap[$nk]);
        }));
        $actionableMap = [];
        foreach (($verifierRoutingState['owned_active_components'] ?? []) as $ownedKey) {
            $nk = ws_norm_component_key((string)$ownedKey);
            if ($nk !== '') $actionableMap[$nk] = true;
        }
        foreach (($verifierRoutingState['completed_components'] ?? []) as $completedKey) {
            $nk = ws_norm_component_key((string)$completedKey);
            if ($nk === '') continue;
            $componentState = $verifierRoutingState['components'][$completedKey]
                ?? $verifierRoutingState['components'][$nk]
                ?? [];
            $assignedRole = strtolower(trim((string)($componentState['assigned_role'] ?? '')));
            $assignedUserId = (int)($componentState['assigned_user_id'] ?? 0);
            if (!$isVerifierReadonlyReport && $assignedRole === 'verifier' && $assignedUserId === (int)$userId) {
                $actionableMap[$nk] = true;
                unset($readonlyMap[$nk]);
            } else {
                $readonlyMap[$nk] = true;
            }
        }
        foreach (($verifierRoutingState['claimable_next_components'] ?? []) as $claimableKey) {
            $nk = ws_norm_component_key((string)$claimableKey);
            if ($nk !== '') {
                $visibleSections[] = $nk;
                $lockedMap[$nk] = true;
                $readonlyMap[$nk] = true;
                $lockReasons[$nk] = (string)(($verifierRoutingState['components'][$claimableKey]['reason'] ?? 'Claimable after explicit claim'));
            }
        }
        foreach (($verifierRoutingState['locked_future_components'] ?? []) as $lockedKey) {
            $nk = ws_norm_component_key((string)$lockedKey);
            if ($nk !== '') {
                $lockedMap[$nk] = true;
                $lockReasons[$nk] = (string)(($verifierRoutingState['components'][$lockedKey]['reason'] ?? 'Locked by routing priority'));
            }
        }
        if ($selectedPriorityBucket !== '') {
            $selectedPriority = (int)substr($selectedPriorityBucket, 1);
            $bucketComponentMap = [];
            $filteredRoutingComponents = [];

            foreach (($verifierRoutingState['components'] ?? []) as $componentKey => $componentState) {
                $nk = ws_norm_component_key((string)$componentKey);
                if ($nk === '') {
                    continue;
                }
                $stateNow = strtolower(trim((string)($componentState['state'] ?? '')));
                $priorityNow = isset($componentState['priority']) ? (int)$componentState['priority'] : 0;

                if ($nk === 'basic') {
                    $filteredRoutingComponents[$componentKey] = $componentState;
                    continue;
                }
                if ($priorityNow === $selectedPriority && $stateNow !== 'hidden_unrelated' && isset($registeredSectionsMap[$nk])) {
                    $bucketComponentMap[$nk] = true;
                    $filteredRoutingComponents[$componentKey] = $componentState;
                }
            }

            $visibleSections = array_merge(['basic'], array_keys($bucketComponentMap));
            $actionableMap = array_intersect_key($actionableMap, $bucketComponentMap);
            $readonlyMap = array_intersect_key($readonlyMap, $bucketComponentMap);
            $lockedMap = array_intersect_key($lockedMap, $bucketComponentMap);
            $lockReasons = array_intersect_key($lockReasons, $bucketComponentMap);
            $verifierRoutingState['components'] = $filteredRoutingComponents;
            $verifierRoutingState['visible_sections'] = $visibleSections;
            $verifierRoutingState['selected_priority_bucket'] = $selectedPriorityBucket;
        }
        $visibleSections = reference_compat_effective_keys($visibleSections);
        $actionableMap = reference_compat_effective_component_map($actionableMap);
        $readonlyMap = reference_compat_effective_component_map($readonlyMap);
        $lockedMap = reference_compat_effective_component_map($lockedMap);
        $lockReasons = reference_compat_effective_component_map($lockReasons);
        $verifierRoutingState = reference_compat_apply_to_routing_state($verifierRoutingState);
        if ($isVerifierReadonlyReport) {
            $actionableMap = [];
            $readonlyMap = [];
            foreach ($visibleSections as $visibleKey) {
                $nk = ws_norm_component_key((string)$visibleKey);
                if ($nk !== '') $readonlyMap[$nk] = true;
            }
        }
        unset($actionableMap['basic']);
        $readonlyMap['basic'] = true;
        $reference = report_filter_reference_payload_by_components($reference, $visibleSections);
    } else {
        $visibleSections = wf_operational_visible_sections($role, $allowedSet, $clientRequiredMap, $operationalPoolMap);
    }
    if ($role === 'validator') {
        $actionableMap = [];
        foreach ($outAssigned as $asRow) {
            $nkReq = ws_norm_component_key((string)($asRow['component_key'] ?? ''));
            if ($nkReq === '' || $nkReq === 'reports') continue;
            $isRequired = isset($asRow['is_required']) ? ((int)$asRow['is_required'] === 1) : false;
            $assignedRole = strtolower(trim((string)($asRow['assigned_role'] ?? '')));
            $assignedUserId = isset($asRow['assigned_user_id']) ? (int)$asRow['assigned_user_id'] : 0;
            $workflowExists = isset($componentWorkflowOut[$nkReq]) && is_array($componentWorkflowOut[$nkReq]) && !empty($componentWorkflowOut[$nkReq]);

            if ($isRequired || $assignedRole !== '' || $assignedUserId > 0 || $workflowExists) {
                $actionableMap[$nkReq] = true;
            }
        }
        $actionableMap['reports'] = true;
        $readonlyMap = [];
        $visibleSections = [];
        foreach ($validatorTemplateSections as $k) {
            $nk = ws_norm_component_key((string)$k);
            if ($nk === '') continue;
            if (!can_section($allowedSet, $nk)) continue;
            $visibleSections[] = $nk;
            if (!isset($actionableMap[$nk])) {
                $readonlyMap[$nk] = true;
            }
        }
    }
    if ($role === 'validator' || $role === 'verifier' || $role === 'db_verifier') {
        // User-facing report actionability should stay reconciliation-friendly.
        // We intentionally do not pre-freeze sections here based on downstream activity.
        // Canonical transition/lock governance still executes inside the workflow engine.
    }
    if ($role === 'candidate') {
        $corrSet = [];
        if (!empty($_SESSION['candidate_correction_mode'])) {
            $rawAllowed = (string)($_SESSION['candidate_correction_allowed_components'] ?? '');
            $arrAllowed = json_decode($rawAllowed, true);
            if (is_array($arrAllowed) && !empty($arrAllowed)) {
                foreach ($arrAllowed as $ak) {
                    $nk = ws_norm_component_key((string)$ak);
                    if ($nk !== '') $corrSet[$nk] = true;
                }
            }
        }
        $corrSet = reference_compat_effective_component_map($corrSet);
        $visibleSections = wf_candidate_visible_sections($clientRequiredMap, $corrSet);
    }
    $visibleSections = reference_compat_effective_keys(array_values(array_unique($visibleSections)));
    $actionableMap = reference_compat_effective_component_map($actionableMap);
    $readonlyMap = reference_compat_effective_component_map($readonlyMap);
    $lockedMap = reference_compat_effective_component_map($lockedMap);
    $lockReasons = reference_compat_effective_component_map($lockReasons);
    $visibleSectionsMap = [];
    foreach ($visibleSections as $k) {
        $visibleSectionsMap[$k] = true;
    }

    $visibleAssigned = $outAssigned;
    if ($role === 'verifier' || $role === 'db_verifier') {
        $visibleAssigned = array_values(array_filter($outAssigned, function ($it) use ($visibleSectionsMap) {
            $k = ws_norm_component_key((string)($it['component_key'] ?? ''));
            if ($k === '') return false;
            return isset($visibleSectionsMap[$k]);
        }));
    }
    if ($role === 'validator') {
        $visibleAssigned = array_values(array_filter($outAssigned, function ($it) use ($visibleSectionsMap) {
            $k = ws_norm_component_key((string)($it['component_key'] ?? ''));
            if ($k === '') return false;
            return isset($visibleSectionsMap[$k]);
        }));
    }
    $visibleAssigned = reference_compat_filter_rows($visibleAssigned, 'component_key');

    $verifierCaseOwnedForAccess = ($role === 'verifier') && !empty($verifierRoutingState['can_open']);
    if ($verifierCaseOwnedForAccess || $role === 'verifier') {
        $visibleSectionsMap['basic'] = true;
        if (!in_array('basic', $visibleSections, true)) {
            $visibleSections[] = 'basic';
        }
    }

    if (($role === 'verifier' || $role === 'db_verifier' || $role === 'validator')
        && !isset($allowedSet['*'])
        && count($allowedSet) === 0
        && !$verifierCaseOwnedForAccess) {
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

        $hasComponentAssignment = false;
        foreach ($outAssigned as $it) {
            if (($it['assigned_role'] ?? '') === 'verifier' && (int)($it['assigned_user_id'] ?? 0) === (int)$userId) {
                $hasComponentAssignment = true;
                break;
            }
        }

        $caseIdForVerifier = (int)($case['case_id'] ?? 0);
        if (verifier_case_queue_is_case_model($pdo, $caseIdForVerifier, $applicationId)) {
            $readonlyVisibleSections = array_values(array_filter($visibleSections, static function ($key) {
                return ws_norm_component_key((string)$key) !== 'basic';
            }));
            if (empty($verifierRoutingState['can_open']) && !($isVerifierReadonlyReport && !empty($readonlyVisibleSections))) {
                http_response_code(403);
                echo json_encode(['status' => 0, 'message' => 'Forbidden']);
                exit;
            }
            $hasComponentAssignment = true;
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

        $caseIdForValidator = (int)($case['case_id'] ?? 0);
        if ($caseIdForValidator > 0) {
            $ok = sp_call_exists(
                $pdo,
                'CALL SP_Vati_Payfiller_ReportCheckValidatorAssignment(?, ?)',
                [$caseIdForValidator, $userId]
            );
            if (!$ok) {
                shared_endpoint_log('validator assignment soft-bypass case_id=' . $caseIdForValidator . ' user_id=' . $userId);
            }
        }
    }

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
        if (!isset($visibleSectionsMap['reference']) && !isset($visibleSectionsMap['education_reference']) && !isset($visibleSectionsMap['employment_reference'])) {
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

    if ($role === 'verifier') {
        $visibleMap = [];
        foreach ($visibleAssigned as $it) {
            $k = ws_norm_component_key((string)($it['component_key'] ?? ''));
            if ($k !== '') $visibleMap[$k] = true;
        }
        $visibleMap['basic'] = true;
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
        $referenceVisible = isset($visibleMap['reference'])
            || isset($visibleMap['education_reference'])
            || isset($visibleMap['employment_reference'])
            || isset($visibleSectionsMap['reference'])
            || isset($visibleSectionsMap['education_reference'])
            || isset($visibleSectionsMap['employment_reference']);
        if (!$referenceVisible) {
            $reference = null;
        }
        if (!isset($visibleMap['socialmedia'])) {
            $socialMedia = null;
        }
        if (!isset($visibleMap['ecourt'])) {
            $ecourt = null;
        }
    }

    $allowed = array_flip($visibleSections ?? []);
    if (!isset($allowed['basic'])) $basic = null;
    if (!isset($allowed['id'])) $identification = [];
    if (!isset($allowed['contact'])) $contact = null;
    if (!isset($allowed['education'])) $education = [];
    if (!isset($allowed['employment'])) $employment = [];
    if (!isset($allowed['reference']) && !isset($allowed['education_reference']) && !isset($allowed['employment_reference'])) $reference = null;
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
            'workflow_mode' => $case['workflow_mode'],
            'workflowMode' => $case['workflow_mode'],
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
            'actionability' => [
                'actionable_components' => array_values(array_keys($actionableMap)),
                'readonly_components' => array_values(array_keys($readonlyMap)),
                'locked_components' => array_values(array_keys($lockedMap)),
                'lock_reasons' => $lockReasons,
                'report_mode' => $isVerifierReadonlyReport ? 'readonly' : $reportMode,
                'internal_operational_components' => ($role === 'validator') ? ['reports'] : [],
                'visibility_mode' => ($role === 'verifier') ? 'priority_gated_component_state' : (($role === 'validator') ? 'validator_operational_template' : (($role === 'candidate') ? 'candidate_entitlement' : 'operational')),
                'actionability_reason' => $isVerifierReadonlyReport ? 'report_readonly' : (($role === 'verifier') ? 'owned_active_only' : (($role === 'validator') ? 'visible_not_equal_actionable' : 'default'))
            ],
            'permissions' => [
                'can_take_action' => $isVerifierReadonlyReport ? 0 : 1,
                'report_mode' => $isVerifierReadonlyReport ? 'readonly' : $reportMode,
            ],
            'verifier_routing_state' => $role === 'verifier' ? $verifierRoutingState : null,
            'component_item_workflow' => $itemWorkflowByComponent,
            'componentItemWorkflow' => $itemWorkflowByComponent,
            'applicationUrl' => $links['applicationUrl'],
            'candidateUrl' => $links['candidateUrl'],
            'timelineUrl' => $links['timelineUrl']
            ,
            'activation_state' => [
                'review_page_entered' => $isReviewerRole ? 1 : 0,
                'final_submit_completed' => $isSubmitted ? 1 : 0,
                'authorization_completed' => $isAuthorizationCompleted ? 1 : 0,
                'queue_activation_allowed' => (!$isReviewerRole || ($isSubmitted && $isAuthorizationCompleted)) ? 1 : 0
            ]
        ]
    ];
    $appEnv = function_exists('env_get') ? strtolower(trim((string)(env_get('APP_ENV', 'production') ?? 'production'))) : 'production';
    if (($isStaffDebugRole && in_array($appEnv, ['dev', 'development', 'local'], true)) || report_debug_enabled()) {
        $registeredSections = registered_section_keys();
        $enabledComponents = array_values(array_keys($clientRequiredMap));
        $blockedSections = array_values(array_diff($registeredSections, $visibleSections));
        $correctionAllowed = [];
        if (!empty($_SESSION['candidate_correction_mode'])) {
            $corrRaw = (string)($_SESSION['candidate_correction_allowed_components'] ?? '');
            $corrArr = json_decode($corrRaw, true);
            if (is_array($corrArr)) {
                foreach ($corrArr as $ck) {
                    $nk = ws_norm_component_key((string)$ck);
                    if ($nk !== '') $correctionAllowed[$nk] = true;
                }
            }
        }
        $response['debug'] = [
            'visible_sections' => $visibleSections,
            'mapping_status' => $mappingStatus,
            'candidate_entitlement_sections' => $enabledComponents,
            'operational_visible_sections' => array_values(array_keys($operationalPoolMap)),
            'workflow_snapshot_sections' => $enabledComponents,
            'registered_sections' => $registeredSections,
            'filtered_sections' => $visibleSections,
            'hidden_sections' => $blockedSections,
            'blocked_sections' => $blockedSections,
            'correction_allowed_sections' => array_values(array_keys($correctionAllowed)),
            'role_visibility_mode' => ($role === 'candidate') ? 'candidate_entitlement' : 'operational',
            'workflow_stage_visibility' => array_values(array_keys($workflowStageVisibilityMap)),
            'normalized_component_keys' => array_values(array_keys($normalizedComponentKeys)),
            'ecourt_visibility_reason' => $ecourtVisibilityReason,
            'validator_template_sections' => $validatorTemplateSections,
            'assigned_components' => array_values(array_map(function ($it) {
                return ws_norm_component_key((string)($it['component_key'] ?? ''));
            }, $outAssigned)),
            'actionable_components' => array_values(array_keys($actionableMap)),
            'readonly_components' => array_values(array_keys($readonlyMap)),
            'locked_components' => array_values(array_keys($lockedMap)),
            'lock_reasons' => $lockReasons,
            'verifier_routing_state' => $verifierRoutingState,
            'component_binding_debug' => array_values(array_map(function ($row) use ($clientRequiredMap, $actionableMap, $readonlyMap) {
                $k = ws_norm_component_key((string)($row['component_key'] ?? ''));
                $workflowExists = isset($row['workflow']) && is_array($row['workflow']) ? 1 : 0;
                return [
                    'component_key' => $k,
                    'assigned_by_client' => isset($clientRequiredMap[$k]) ? 1 : 0,
                    'is_required' => isset($row['is_required']) ? (int)$row['is_required'] : 0,
                    'assigned_role' => strtolower(trim((string)($row['assigned_role'] ?? ''))),
                    'assigned_user_id' => isset($row['assigned_user_id']) ? (int)$row['assigned_user_id'] : 0,
                    'workflow_exists' => $workflowExists,
                    'validator_template_only' => isset($readonlyMap[$k]) ? 1 : 0,
                    'actionable' => isset($actionableMap[$k]) ? 1 : 0,
                    'readonly' => isset($readonlyMap[$k]) ? 1 : 0,
                    'actionable_reason' => isset($actionableMap[$k])
                        ? (($k === 'reports') ? 'internal_operational_component' : 'required_or_workflow_or_assignment')
                        : (isset($readonlyMap[$k]) ? 'validator_template_visibility_only' : 'not_visible_or_not_allowed')
                ];
            }, $outAssigned)),
            'internal_operational_components' => ($role === 'validator') ? ['reports'] : [],
            'hidden_components' => array_values(array_diff(registered_section_keys(), $visibleSections)),
            'visibility_mode' => ($role === 'validator') ? 'validator_operational_template' : (($role === 'candidate') ? 'candidate_entitlement' : 'operational'),
            'actionability_reason' => ($role === 'validator') ? 'visible_not_equal_actionable' : 'default'
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
