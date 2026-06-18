<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/integration.php';
require_once __DIR__ . '/workflow_snapshot_service.php';
require_once __DIR__ . '/../communications/workflow_communication_service.php';
require_once __DIR__ . '/../verifier_routing.php';
require_once __DIR__ . '/../case_management/reference_component_compat.php';

integration_bootstrap_json_api();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Service-Token, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function wvl_json(int $httpCode, array $payload): void
{
    integration_json_response($httpCode, $payload);
}

function wvl_error(int $httpCode, string $code, string $message, array $extra = []): void
{
    wvl_json($httpCode, array_merge([
        'status' => 0,
        'code' => $code,
        'message' => $message,
    ], $extra));
}

function wvl_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        wvl_error(400, 'INVALID_REQUEST', 'JSON request body is required');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        wvl_error(400, 'INVALID_REQUEST', 'Request body must be valid JSON object');
    }
    return $decoded;
}

function wvl_str(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if (is_array($value) || is_object($value)) {
        return '';
    }
    return trim((string)$value);
}

function wvl_int(array $input, string $key): int
{
    $value = $input[$key] ?? 0;
    if (is_array($value) || is_object($value)) {
        return 0;
    }
    return (int)$value;
}

function wvl_norm_component(string $componentKey): string
{
    $key = ws_norm_component_key(reference_compat_norm_key($componentKey));
    if ($key === 'e_court') return 'ecourt';
    if ($key === 'contact_information' || $key === 'address') return 'contact';
    if ($key === 'identification') return 'id';
    return $key;
}

function wvl_norm_role(string $role): string
{
    $role = wc_norm_thread_owner_role($role);
    if ($role === 'db_verifier') return 'verifier';
    return integration_role_normalized($role);
}

function wvl_resolve_case(PDO $pdo, string $applicationId, int $caseId): array
{
    if ($applicationId !== '') {
        $st = $pdo->prepare('SELECT case_id, application_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $st->execute([$applicationId]);
    } elseif ($caseId > 0) {
        $st = $pdo->prepare('SELECT case_id, application_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $st->execute([$caseId]);
    } else {
        return [];
    }
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row) return [];
    return [
        'case_id' => (int)($row['case_id'] ?? 0),
        'application_id' => integration_normalize_application_id((string)($row['application_id'] ?? '')),
    ];
}

function wvl_resolve_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) return [];
    $st = $pdo->prepare("SELECT user_id, LOWER(TRIM(role)) AS role FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row) return [];
    return [
        'user_id' => (int)($row['user_id'] ?? 0),
        'role' => integration_role_normalized((string)($row['role'] ?? '')),
    ];
}

function wvl_lane(string $applicationId, string $componentKey, string $ownerRole, string $visibility, bool $actionable): array
{
    $componentKey = wvl_norm_component($componentKey);
    $ownerRole = wvl_norm_role($ownerRole);
    return [
        'componentKey' => $componentKey,
        'ownerRole' => $ownerRole,
        'threadId' => wc_build_thread_id($applicationId, $componentKey, $ownerRole),
        'visibility' => $visibility,
        'actionable' => $actionable,
    ];
}

function wvl_add_lane(array &$lanes, string $applicationId, string $componentKey, string $ownerRole, string $visibility, bool $actionable): void
{
    $componentKey = wvl_norm_component($componentKey);
    $ownerRole = wvl_norm_role($ownerRole);
    if ($componentKey === '' || $ownerRole === '') return;
    $threadId = wc_build_thread_id($applicationId, $componentKey, $ownerRole);
    $lanes[strtolower($threadId)] = [
        'componentKey' => $componentKey,
        'ownerRole' => $ownerRole,
        'threadId' => $threadId,
        'visibility' => $visibility,
        'actionable' => $actionable,
    ];
}

function wvl_component_lookup(array $components, string $componentKey): array
{
    $componentKey = wvl_norm_component($componentKey);
    return $components[$componentKey] ?? [];
}

function wvl_build_verifier_lanes(PDO $pdo, int $caseId, int $userId, string $applicationId, string $ownerRole): array
{
    $state = verifier_routing_case_state($pdo, $caseId, $userId);
    $components = is_array($state['components'] ?? null) ? $state['components'] : [];
    $lanes = [];

    foreach ((array)($state['visible_sections'] ?? []) as $componentKey) {
        $key = wvl_norm_component((string)$componentKey);
        if ($key === '') continue;
        $component = wvl_component_lookup($components, $key);
        $visibility = $key === 'basic' || strtolower(trim((string)($component['state'] ?? ''))) === 'context'
            ? 'visible_context'
            : 'readonly';
        wvl_add_lane($lanes, $applicationId, $key, $ownerRole, $visibility, false);
    }

    foreach ((array)($state['claimable_next_components'] ?? []) as $componentKey) {
        wvl_add_lane($lanes, $applicationId, (string)$componentKey, $ownerRole, 'claimable', false);
    }

    foreach ((array)($state['owned_active_components'] ?? []) as $componentKey) {
        wvl_add_lane($lanes, $applicationId, (string)$componentKey, $ownerRole, 'owned_active', true);
    }

    foreach ((array)($state['completed_components'] ?? []) as $componentKey) {
        $key = wvl_norm_component((string)$componentKey);
        $component = wvl_component_lookup($components, $key);
        $assignedRole = wvl_norm_role((string)($component['assigned_role'] ?? ''));
        $assignedUserId = (int)($component['assigned_user_id'] ?? 0);
        $actionable = $assignedRole === $ownerRole && $assignedUserId === $userId;
        wvl_add_lane($lanes, $applicationId, $key, $ownerRole, 'completed', $actionable);
    }

    return array_values($lanes);
}

function wvl_build_operational_lanes(PDO $pdo, string $applicationId, string $role): array
{
    $contract = ws_build_snapshot_contract($pdo, $applicationId);
    $visibleSections = reference_compat_effective_keys((array)($contract['visible_sections'] ?? []));
    $assignedRows = is_array($contract['assigned_components'] ?? null) ? $contract['assigned_components'] : [];
    $workflow = is_array($contract['component_workflow'] ?? null) ? $contract['component_workflow'] : [];
    $assignedByComponent = [];

    foreach ($assignedRows as $row) {
        if (!is_array($row)) continue;
        $key = wvl_norm_component((string)($row['component_key'] ?? ''));
        if ($key !== '') $assignedByComponent[$key] = $row;
    }

    $lanes = [];
    wvl_add_lane($lanes, $applicationId, 'basic', $role, 'visible_context', false);

    foreach ($visibleSections as $componentKey) {
        $key = wvl_norm_component((string)$componentKey);
        if ($key === '' || $key === 'basic') continue;

        $row = $assignedByComponent[$key] ?? [];
        $workflowExists = isset($workflow[$key]) && is_array($workflow[$key]) && !empty($workflow[$key]);
        $isRequired = isset($row['is_required']) ? (int)$row['is_required'] === 1 : false;
        $assignedRole = wvl_norm_role((string)($row['assigned_role'] ?? ''));
        $assignedUserId = isset($row['assigned_user_id']) ? (int)$row['assigned_user_id'] : 0;
        $actionable = $role === 'validator'
            ? ($isRequired || $assignedRole !== '' || $assignedUserId > 0 || $workflowExists)
            : true;

        wvl_add_lane($lanes, $applicationId, $key, $role, $actionable ? 'owned_active' : 'readonly', $actionable);
    }

    return array_values($lanes);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wvl_error(405, 'METHOD_NOT_ALLOWED', 'Method not allowed');
    }

    $actor = integration_resolve_actor(true);
    $input = wvl_read_json_body();
    $pdo = getDB();

    $userId = wvl_int($input, 'userId') ?: wvl_int($input, 'user_id');
    $role = wvl_norm_role(wvl_str($input, 'role'));
    $applicationId = integration_normalize_application_id(wvl_str($input, 'applicationId') ?: wvl_str($input, 'application_id'));
    $caseId = wvl_int($input, 'caseId') ?: wvl_int($input, 'case_id');

    if ($userId <= 0 || $role === '') {
        wvl_error(400, 'INVALID_REQUEST', 'userId and role are required');
    }
    if ($applicationId === '' && $caseId <= 0) {
        wvl_error(400, 'INVALID_REQUEST', 'applicationId or caseId is required');
    }

    $user = wvl_resolve_user($pdo, $userId);
    if (!$user) {
        wvl_error(401, 'UNAUTHORIZED', 'Unauthorized');
    }
    $resolvedUserRole = wvl_norm_role((string)($user['role'] ?? ''));
    if ($resolvedUserRole !== '' && $role !== $resolvedUserRole && !($role === 'verifier' && $resolvedUserRole === 'db_verifier')) {
        wvl_error(401, 'UNAUTHORIZED', 'Unauthorized');
    }
    if (($actor['service'] ?? false) !== true && (int)($actor['userId'] ?? 0) > 0 && (int)$actor['userId'] !== $userId) {
        wvl_error(401, 'UNAUTHORIZED', 'Unauthorized');
    }

    $case = wvl_resolve_case($pdo, $applicationId, $caseId);
    if (!$case) {
        wvl_error(404, 'APPLICATION_NOT_FOUND', 'Application not found');
    }

    $caseId = (int)$case['case_id'];
    $applicationId = (string)$case['application_id'];

    $lanes = $role === 'verifier'
        ? wvl_build_verifier_lanes($pdo, $caseId, $userId, $applicationId, $role)
        : wvl_build_operational_lanes($pdo, $applicationId, $role);

    wvl_json(200, [
        'status' => 1,
        'applicationId' => $applicationId,
        'caseId' => $caseId,
        'lanes' => $lanes,
    ]);
} catch (PDOException $e) {
    wvl_error(500, 'DATABASE_ERROR', 'Database error');
} catch (Throwable $e) {
    wvl_error(500, 'DATABASE_ERROR', 'Database error');
}
