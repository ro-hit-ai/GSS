<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/integration.php';
require_once __DIR__ . '/../workflow_snapshot_service.php';
require_once __DIR__ . '/../workflow_communication_service.php';
require_once __DIR__ . '/../verifier_routing.php';
require_once __DIR__ . '/../reference_component_compat.php';

integration_bootstrap_json_api();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Service-Token, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function wla_json(int $httpCode, array $payload): void
{
    integration_json_response($httpCode, $payload);
}

function wla_error(int $httpCode, string $code, string $message, array $extra = []): void
{
    wla_json($httpCode, array_merge([
        'status' => 0,
        'code' => $code,
        'message' => $message,
    ], $extra));
}

function wla_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        wla_error(400, 'INVALID_REQUEST', 'JSON request body is required');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        wla_error(400, 'INVALID_REQUEST', 'Request body must be valid JSON object');
    }
    return $decoded;
}

function wla_str(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if (is_array($value) || is_object($value)) {
        return '';
    }
    return trim((string)$value);
}

function wla_int(array $input, string $key): int
{
    $value = $input[$key] ?? 0;
    if (is_array($value) || is_object($value)) {
        return 0;
    }
    return (int)$value;
}

function wla_norm_component(string $componentKey): string
{
    $key = ws_norm_component_key(reference_compat_norm_key($componentKey));
    if ($key === 'e_court') return 'ecourt';
    if ($key === 'contact_information') return 'contact';
    if ($key === 'address') return 'contact';
    return $key;
}

function wla_norm_role(string $role): string
{
    $role = wc_norm_thread_owner_role($role);
    if ($role === 'db_verifier') return 'verifier';
    return integration_role_normalized($role);
}

function wla_resolve_case(PDO $pdo, string $applicationId, int $caseId): array
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

function wla_resolve_user(PDO $pdo, int $userId): array
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

function wla_is_component_assignee(PDO $pdo, string $applicationId, string $componentKey, int $userId): bool
{
    if ($userId <= 0 || $applicationId === '' || $componentKey === '') {
        return false;
    }
    $st = $pdo->prepare(
        "SELECT 1
           FROM Vati_Payfiller_Case_Components
          WHERE application_id = ?
            AND LOWER(TRIM(component_key)) = ?
            AND assigned_user_id = ?
          LIMIT 1"
    );
    $st->execute([$applicationId, $componentKey, $userId]);
    return (bool)$st->fetchColumn();
}

function wla_visibility(bool $visible, bool $actionable, bool $readonly): array
{
    return [
        'visible' => $visible,
        'actionable' => $actionable,
        'readonly' => $readonly,
    ];
}

function wla_response(
    bool $allowed,
    string $reason,
    string $applicationId,
    int $caseId,
    string $componentKey,
    string $ownerRole,
    string $threadId,
    string $accessType,
    array $visibility
): array {
    return [
        'status' => 1,
        'allowed' => $allowed,
        'reason' => $reason,
        'applicationId' => $applicationId,
        'caseId' => $caseId,
        'componentKey' => $componentKey,
        'ownerRole' => $ownerRole,
        'threadId' => $threadId,
        'accessType' => $accessType,
        'visibility' => $visibility,
    ];
}

function wla_has_key(array $keys, string $componentKey): bool
{
    return in_array($componentKey, array_map(static function ($key) {
        return wla_norm_component((string)$key);
    }, $keys), true);
}

function wla_authorize_verifier(PDO $pdo, int $caseId, int $userId, string $componentKey, string $accessType): array
{
    $routingState = verifier_routing_case_state($pdo, $caseId, $userId);
    $components = is_array($routingState['components'] ?? null) ? $routingState['components'] : [];
    $component = $components[$componentKey] ?? [];
    $state = strtolower(trim((string)($component['state'] ?? '')));
    $assignedRole = strtolower(trim((string)($component['assigned_role'] ?? '')));
    $assignedUserId = (int)($component['assigned_user_id'] ?? 0);

    $visible = wla_has_key((array)($routingState['visible_sections'] ?? []), $componentKey);
    $ownedActive = wla_has_key((array)($routingState['owned_active_components'] ?? []), $componentKey);
    $claimable = wla_has_key((array)($routingState['claimable_next_components'] ?? []), $componentKey);
    $locked = wla_has_key((array)($routingState['locked_future_components'] ?? []), $componentKey);
    $hidden = wla_has_key((array)($routingState['hidden_unrelated_components'] ?? []), $componentKey);
    $completed = wla_has_key((array)($routingState['completed_components'] ?? []), $componentKey);
    $completedAssigned = $completed && $assignedRole === 'verifier' && $assignedUserId === $userId;
    $actionable = $ownedActive || $completedAssigned;

    if ($componentKey === 'basic') {
        return [
            'allowed' => $accessType === 'read' || $accessType === 'attachment',
            'reason' => 'visible_readonly_component',
            'visibility' => wla_visibility(true, false, true),
        ];
    }
    if ($hidden || $state === 'hidden_unrelated' || empty($component)) {
        return [
            'allowed' => false,
            'reason' => 'hidden_unrelated_component',
            'visibility' => wla_visibility(false, false, true),
        ];
    }
    if ($ownedActive) {
        return [
            'allowed' => true,
            'reason' => 'owned_active_component',
            'visibility' => wla_visibility(true, true, false),
        ];
    }
    if ($completedAssigned) {
        return [
            'allowed' => true,
            'reason' => 'completed_assigned_component',
            'visibility' => wla_visibility(true, true, false),
        ];
    }
    if ($claimable) {
        $readAllowed = $accessType === 'read' || $accessType === 'attachment';
        return [
            'allowed' => $readAllowed,
            'reason' => 'claimable_not_owned',
            'visibility' => wla_visibility(true, false, true),
        ];
    }
    if ($locked) {
        return [
            'allowed' => false,
            'reason' => 'locked_future_component',
            'visibility' => wla_visibility($visible, false, true),
        ];
    }
    if ($completed) {
        $readAllowed = $accessType === 'read' || $accessType === 'attachment';
        return [
            'allowed' => $readAllowed,
            'reason' => 'visible_readonly_component',
            'visibility' => wla_visibility($visible || $completed, false, true),
        ];
    }

    return [
        'allowed' => false,
        'reason' => 'component_not_visible',
        'visibility' => wla_visibility($visible, $actionable, !$actionable),
    ];
}

function wla_authorize_snapshot_role(PDO $pdo, string $applicationId, string $role, string $componentKey, string $accessType): array
{
    $contract = ws_build_snapshot_contract($pdo, $applicationId);
    $visibleSections = reference_compat_effective_keys((array)($contract['visible_sections'] ?? []));
    $assignedRows = is_array($contract['assigned_components'] ?? null) ? $contract['assigned_components'] : [];
    $workflow = is_array($contract['component_workflow'] ?? null) ? $contract['component_workflow'] : [];
    $visible = wla_has_key($visibleSections, $componentKey);
    $actionable = false;

    if ($role === 'validator') {
        foreach ($assignedRows as $row) {
            if (!is_array($row)) continue;
            $key = wla_norm_component((string)($row['component_key'] ?? ''));
            if ($key !== $componentKey) continue;
            $isRequired = isset($row['is_required']) ? (int)$row['is_required'] === 1 : false;
            $assignedRole = strtolower(trim((string)($row['assigned_role'] ?? '')));
            $assignedUserId = (int)($row['assigned_user_id'] ?? 0);
            $workflowExists = isset($workflow[$key]) && is_array($workflow[$key]) && !empty($workflow[$key]);
            $actionable = $isRequired || $assignedRole !== '' || $assignedUserId > 0 || $workflowExists;
            break;
        }
    } elseif (in_array($role, ['qa', 'team_lead', 'gss_admin', 'client_admin', 'db_verifier'], true)) {
        $actionable = $visible;
    }

    if (!$visible) {
        return [
            'allowed' => false,
            'reason' => 'component_not_visible',
            'visibility' => wla_visibility(false, false, true),
        ];
    }

    $allowed = ($accessType === 'read' || $accessType === 'attachment') || $actionable;
    return [
        'allowed' => $allowed,
        'reason' => $actionable ? 'owned_active_component' : 'visible_readonly_component',
        'visibility' => wla_visibility(true, $actionable, !$actionable),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wla_error(405, 'METHOD_NOT_ALLOWED', 'Method not allowed');
    }

    $actor = integration_resolve_actor(true);
    $input = wla_read_json_body();
    $pdo = getDB();

    $userId = wla_int($input, 'userId') ?: wla_int($input, 'user_id');
    $roleInput = wla_str($input, 'role');
    $applicationId = integration_normalize_application_id(wla_str($input, 'applicationId') ?: wla_str($input, 'application_id'));
    $caseId = wla_int($input, 'caseId') ?: wla_int($input, 'case_id');
    $componentKey = wla_norm_component(wla_str($input, 'componentKey') ?: wla_str($input, 'component_key'));
    $ownerRole = wla_norm_role(wla_str($input, 'ownerRole') ?: wla_str($input, 'owner_role'));
    $threadId = trim(wla_str($input, 'threadId') ?: wla_str($input, 'thread_id'));
    $accessType = strtolower(trim(wla_str($input, 'accessType') ?: wla_str($input, 'access_type')));

    if ($userId <= 0 || $componentKey === '' || $ownerRole === '' || $accessType === '') {
        wla_error(400, 'INVALID_REQUEST', 'userId, componentKey, ownerRole and accessType are required');
    }
    if ($applicationId === '' && $caseId <= 0) {
        wla_error(400, 'INVALID_REQUEST', 'applicationId or caseId is required');
    }
    if (!in_array($accessType, ['read', 'write', 'reply', 'attachment'], true)) {
        wla_error(400, 'INVALID_REQUEST', 'accessType must be read, write, reply or attachment');
    }

    $user = wla_resolve_user($pdo, $userId);
    if (!$user) {
        wla_error(404, 'USER_NOT_FOUND', 'User not found');
    }
    $role = wla_norm_role($roleInput !== '' ? $roleInput : (string)($user['role'] ?? ''));
    $resolvedUserRole = wla_norm_role((string)($user['role'] ?? ''));
    if ($role === '') $role = $resolvedUserRole;
    if ($resolvedUserRole !== '' && $role !== $resolvedUserRole && !($role === 'verifier' && $resolvedUserRole === 'db_verifier')) {
        wla_error(403, 'UNAUTHORIZED', 'Role does not match user');
    }

    if (($actor['service'] ?? false) !== true && (int)($actor['userId'] ?? 0) > 0 && (int)$actor['userId'] !== $userId) {
        wla_error(403, 'UNAUTHORIZED', 'Session user does not match requested user');
    }

    $case = wla_resolve_case($pdo, $applicationId, $caseId);
    if (!$case) {
        wla_error(404, 'APPLICATION_NOT_FOUND', 'Application not found');
    }
    $caseId = (int)$case['case_id'];
    $applicationId = (string)$case['application_id'];
    $expectedThreadId = wc_build_thread_id($applicationId, $componentKey, $ownerRole);

    if ($threadId !== '' && strtolower(trim($threadId)) !== strtolower(trim($expectedThreadId))) {
        wla_json(200, wla_response(
            false,
            'thread_id_mismatch',
            $applicationId,
            $caseId,
            $componentKey,
            $ownerRole,
            $expectedThreadId,
            $accessType,
            wla_visibility(false, false, true)
        ));
    }

    if ($role === 'candidate') {
        wla_json(200, wla_response(
            false,
            'unsupported_role',
            $applicationId,
            $caseId,
            $componentKey,
            $ownerRole,
            $expectedThreadId,
            $accessType,
            wla_visibility(false, false, true)
        ));
    }

    if (
        $ownerRole !== wla_norm_role($role)
        && !($ownerRole === 'verifier' && wla_is_component_assignee($pdo, $applicationId, $componentKey, $userId))
    ) {
        wla_json(200, wla_response(
            false,
            'owner_role_mismatch',
            $applicationId,
            $caseId,
            $componentKey,
            $ownerRole,
            $expectedThreadId,
            $accessType,
            wla_visibility(false, false, true)
        ));
    }

    $isVerifierLaneAssignee = $ownerRole === 'verifier' && wla_is_component_assignee($pdo, $applicationId, $componentKey, $userId);
    $decision = ($role === 'verifier' || $isVerifierLaneAssignee)
        ? wla_authorize_verifier($pdo, $caseId, $userId, $componentKey, $accessType)
        : wla_authorize_snapshot_role($pdo, $applicationId, $role, $componentKey, $accessType);

    wla_json(200, wla_response(
        (bool)($decision['allowed'] ?? false),
        (string)($decision['reason'] ?? 'component_not_visible'),
        $applicationId,
        $caseId,
        $componentKey,
        $ownerRole,
        $expectedThreadId,
        $accessType,
        is_array($decision['visibility'] ?? null) ? $decision['visibility'] : wla_visibility(false, false, true)
    ));
} catch (PDOException $e) {
    wla_error(500, 'DATABASE_ERROR', 'Database error');
} catch (Throwable $e) {
    wla_error(500, 'DATABASE_ERROR', 'Database error');
}
