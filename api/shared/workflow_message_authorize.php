<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/integration.php';
require_once __DIR__ . '/workflow_communication_service.php';

if (!defined('WMA_LIBRARY_MODE')) {
    integration_bootstrap_json_api();

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Service-Token, X-Requested-With');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

function wma_json(int $httpCode, array $payload): void
{
    integration_json_response($httpCode, $payload);
}

function wma_error(int $httpCode, string $code, string $message, array $extra = []): void
{
    wma_json($httpCode, array_merge([
        'status' => 0,
        'code' => $code,
        'message' => $message,
    ], $extra));
}

function wma_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        wma_error(400, 'INVALID_REQUEST', 'JSON request body is required');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        wma_error(400, 'INVALID_REQUEST', 'Request body must be valid JSON object');
    }
    return $decoded;
}

function wma_str(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if (is_array($value) || is_object($value)) {
        return '';
    }
    return trim((string)$value);
}

function wma_int(array $input, string $key): int
{
    $value = $input[$key] ?? 0;
    if (is_array($value) || is_object($value)) {
        return 0;
    }
    return (int)$value;
}

function wma_normalize_component_key(string $value): string
{
    $value = strtolower(trim($value));
    $value = str_replace(['-', ' '], '_', $value);
    if ($value === 'identification') return 'id';
    if ($value === 'contact_information') return 'contact';
    if ($value === 'education_details') return 'education';
    if ($value === 'employment_details') return 'employment';
    if ($value === 'educationreference' || $value === 'education_ref') return 'education_reference';
    if ($value === 'employmentreference' || $value === 'employment_ref') return 'employment_reference';
    if ($value === 'social_media') return 'socialmedia';
    if ($value === 'e_court') return 'ecourt';
    if ($value === 'references') return 'reference';
    return $value;
}

function wma_normalize_role_key(string $role): string
{
    $role = strtolower(trim($role));
    if ($role === 'customer_admin') return 'client_admin';
    if ($role === 'team_lead') return 'qa';
    if ($role === 'db verifier' || $role === 'db-verifier' || $role === 'db_verifier') return 'verifier';
    return $role;
}

function wma_normalize_thread_owner_role(string $role): string
{
    return wma_normalize_role_key($role);
}

function wma_table_has_column(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

function wma_row_matches_component_scope(array $row, string $componentKey): bool
{
    $rowKey = wma_normalize_component_key((string)($row['component_key'] ?? ''));
    if ($rowKey === $componentKey) return true;
    if ($rowKey !== '' && $rowKey !== 'timeline') return false;
    $subject = (string)($row['subject'] ?? '');
    $body = (string)($row['body'] ?? $row['message'] ?? '');
    return wma_infer_component_from_text($subject, $body) === $componentKey;
}

function wma_infer_component_from_text(string $subject, string $body = ''): string
{
    $haystack = strtolower(trim($subject . ' ' . $body));
    if ($haystack === '') return '';
    $map = [
        'education_reference' => ['education reference', 'education_reference'],
        'employment_reference' => ['employment reference', 'employment_reference'],
        'basic' => ['basic details', 'basic'],
        'id' => ['identification', ' id '],
        'contact' => ['contact information', 'contact'],
        'education' => ['education details', 'education'],
        'employment' => ['employment details', 'employment'],
        'reference' => ['reference', 'references'],
        'socialmedia' => ['social media', 'socialmedia'],
        'ecourt' => ['e-court', 'ecourt', 'e court'],
        'reports' => ['reports', 'report'],
    ];
    foreach ($map as $componentKey => $needles) {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return $componentKey;
            }
        }
    }
    return '';
}

function wma_row_belongs_to_viewer_role(array $row, string $viewerRole, array $allowedThreadIds, array $allowedMessageIds): bool
{
    if ($viewerRole === '') {
        return true;
    }

    $direction = strtolower(trim((string)($row['direction'] ?? '')));
    $actorRole = wma_normalize_role_key((string)($row['actor_role'] ?? ''));
    if ($direction === 'outgoing') {
        return $actorRole === $viewerRole;
    }

    if ($direction !== 'incoming') {
        return true;
    }

    $threadId = strtolower(trim((string)($row['thread_id'] ?? '')));
    if ($threadId !== '' && isset($allowedThreadIds[$threadId])) {
        return true;
    }

    $inReplyTo = wc_norm_msg_id((string)($row['in_reply_to'] ?? ''));
    if ($inReplyTo !== '' && isset($allowedMessageIds[$inReplyTo])) {
        return true;
    }

    $references = wc_extract_msg_ids((string)($row['references_header'] ?? ''));
    foreach ($references as $refId) {
        if ($refId !== '' && isset($allowedMessageIds[$refId])) {
            return true;
        }
    }

    return false;
}

function wma_row_is_outgoing_for_viewer(array $row, string $viewerRole): bool
{
    $direction = strtolower(trim((string)($row['direction'] ?? '')));
    $actorRole = wma_normalize_role_key((string)($row['actor_role'] ?? ''));
    return $direction === 'outgoing' && $viewerRole !== '' && $actorRole === $viewerRole;
}

function wma_resolve_user(PDO $pdo, int $userId): array
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

function wma_is_component_assignee(PDO $pdo, string $applicationId, string $componentKey, int $userId): bool
{
    if (function_exists('wla_is_component_assignee')) {
        return wla_is_component_assignee($pdo, $applicationId, $componentKey, $userId);
    }
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

function wma_application_exists(PDO $pdo, string $applicationId): bool
{
    if ($applicationId === '') return false;
    $st = $pdo->prepare('SELECT 1 FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
    $st->execute([$applicationId]);
    return (bool)$st->fetchColumn();
}

function wma_load_communication_rows(PDO $pdo, string $applicationId): array
{
    $wcTable = 'Vati_Payfiller_Workflow_Communications';
    $hasDirection = wma_table_has_column($pdo, $wcTable, 'direction');
    $hasActorRole = wma_table_has_column($pdo, $wcTable, 'actor_role');
    $hasDelivery = wma_table_has_column($pdo, $wcTable, 'delivery_status');
    $hasType = wma_table_has_column($pdo, $wcTable, 'communication_type');
    $hasActionKey = wma_table_has_column($pdo, $wcTable, 'action_key');
    $hasMessageId = wma_table_has_column($pdo, $wcTable, 'message_id');
    $hasInReplyTo = wma_table_has_column($pdo, $wcTable, 'in_reply_to');
    $hasReferences = wma_table_has_column($pdo, $wcTable, 'references_header');
    $hasThreadId = wma_table_has_column($pdo, $wcTable, 'thread_id');
    $hasThreadOwnerRole = wma_table_has_column($pdo, $wcTable, 'thread_owner_role');
    $hasRootOutgoingCommunicationId = wma_table_has_column($pdo, $wcTable, 'root_outgoing_communication_id');

    $sql = 'SELECT communication_id, component_key, role_key, subject, body, notes, sent_at'
        . ($hasDirection ? ', direction' : ", 'outgoing' AS direction")
        . ($hasActorRole ? ', actor_role' : ', role_key AS actor_role')
        . ($hasDelivery ? ', delivery_status' : ", 'sent' AS delivery_status")
        . ($hasType ? ', communication_type' : ", '' AS communication_type")
        . ($hasActionKey ? ', action_key' : ", '' AS action_key")
        . ($hasMessageId ? ', message_id' : ", '' AS message_id")
        . ($hasInReplyTo ? ', in_reply_to' : ", '' AS in_reply_to")
        . ($hasReferences ? ', references_header' : ", '' AS references_header")
        . ($hasThreadId ? ', thread_id' : ", '' AS thread_id")
        . ($hasThreadOwnerRole ? ', thread_owner_role' : ", '' AS thread_owner_role")
        . ($hasRootOutgoingCommunicationId ? ', root_outgoing_communication_id' : ", 0 AS root_outgoing_communication_id")
        . ' FROM Vati_Payfiller_Workflow_Communications'
        . " WHERE application_id = ?";

    if ($hasDirection) {
        $sql .= " AND direction IN ('incoming','outgoing')";
    }
    $sql .= ' ORDER BY sent_at DESC, communication_id DESC LIMIT 120';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$applicationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'communication_id' => (int)($row['communication_id'] ?? 0),
            'component_key' => wma_normalize_component_key((string)($row['component_key'] ?? '')),
            'subject' => (string)($row['subject'] ?? ''),
            'body' => (string)($row['body'] ?? ''),
            'message' => trim((string)($row['body'] ?? '')) !== '' ? (string)$row['body'] : (string)($row['notes'] ?? ''),
            'direction' => (string)($row['direction'] ?? ''),
            'delivery_status' => (string)($row['delivery_status'] ?? ''),
            'communication_type' => (string)($row['communication_type'] ?? ''),
            'action_key' => (string)($row['action_key'] ?? ''),
            'actor_role' => (string)($row['actor_role'] ?? $row['role_key'] ?? ''),
            'message_id' => (string)($row['message_id'] ?? ''),
            'in_reply_to' => (string)($row['in_reply_to'] ?? ''),
            'references_header' => (string)($row['references_header'] ?? ''),
            'thread_id' => (string)($row['thread_id'] ?? ''),
            'thread_owner_role' => wma_normalize_thread_owner_role((string)($row['thread_owner_role'] ?? '')),
            'root_outgoing_communication_id' => (int)($row['root_outgoing_communication_id'] ?? 0),
        ];
    }, $rows);
}

function wma_load_legacy_incoming_replies(PDO $pdo, string $applicationId, int $limit = 120): array
{
    $table = wc_resolve_replies_table($pdo);
    if ($table === '') return [];
    $cols = wc_resolve_reply_columns($pdo, $table);
    if (empty($cols['ok'])) return [];

    $sql = 'SELECT `'.str_replace('`','``',$cols['sender']).'` AS sender, '
        . '`'.str_replace('`','``',$cols['message']).'` AS message, '
        . '`'.str_replace('`','``',$cols['created_at']).'` AS created_at '
        . ($cols['subject'] !== '' ? ', `'.str_replace('`','``',$cols['subject']).'` AS subject ' : ", '' AS subject ")
        . ($cols['message_id'] !== '' ? ', `'.str_replace('`','``',$cols['message_id']).'` AS message_id ' : ", '' AS message_id ")
        . ($cols['in_reply_to'] !== '' ? ', `'.str_replace('`','``',$cols['in_reply_to']).'` AS in_reply_to ' : ", '' AS in_reply_to ")
        . ($cols['references_header'] !== '' ? ', `'.str_replace('`','``',$cols['references_header']).'` AS references_header ' : ", '' AS references_header ")
        . ($cols['thread_id'] !== '' ? ', `'.str_replace('`','``',$cols['thread_id']).'` AS thread_id ' : ", '' AS thread_id ")
        . 'FROM `'.str_replace('`','``',$table).'` '
        . "WHERE REPLACE(LOWER(TRIM(application_id)), ' ', '') = REPLACE(LOWER(TRIM(?)), ' ', '') "
        . 'ORDER BY `'.str_replace('`','``',$cols['created_at']).'` DESC '
        . 'LIMIT ' . max(1, (int)$limit);
    $st = $pdo->prepare($sql);
    $st->execute([$applicationId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'communication_id' => 0,
            'component_key' => 'timeline',
            'subject' => (string)($row['subject'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'direction' => 'incoming',
            'delivery_status' => 'received',
            'communication_type' => 'manual_message',
            'actor_role' => 'candidate',
            'message_id' => wc_norm_msg_id((string)($row['message_id'] ?? '')),
            'in_reply_to' => wc_norm_msg_id((string)($row['in_reply_to'] ?? '')),
            'references_header' => (string)($row['references_header'] ?? ''),
            'thread_id' => (string)($row['thread_id'] ?? ''),
            'thread_owner_role' => '',
            'root_outgoing_communication_id' => 0,
        ];
    }
    return $out;
}

function wma_response(bool $allowed, string $reason, string $messageId, string $componentKey = '', string $threadOwnerRole = ''): array
{
    return [
        'status' => 1,
        'allowed' => $allowed,
        'reason' => $reason,
        'messageId' => $messageId,
        'componentKey' => $componentKey,
        'threadOwnerRole' => $threadOwnerRole,
    ];
}

function wma_decision(int $httpCode, array $payload): array
{
    return ['http' => $httpCode, 'payload' => $payload];
}

function wma_authorize_message(PDO $pdo, array $input, array $actor = []): array
{
    $userId = wma_int($input, 'userId') ?: wma_int($input, 'user_id');
    $applicationId = integration_normalize_application_id(wma_str($input, 'applicationId') ?: wma_str($input, 'application_id'));
    $componentKey = wma_normalize_component_key(wma_str($input, 'componentKey') ?: wma_str($input, 'component_key'));
    $ownerRole = wma_normalize_thread_owner_role(wma_str($input, 'ownerRole') ?: wma_str($input, 'owner_role'));
    $threadId = strtolower(trim(wma_str($input, 'threadId') ?: wma_str($input, 'thread_id')));
    $messageIdRaw = wma_str($input, 'messageId') ?: wma_str($input, 'message_id');
    $messageId = wc_norm_msg_id($messageIdRaw);
    $roleInput = wma_str($input, 'role');

    if ($userId <= 0 || $applicationId === '' || $messageId === '') {
        return wma_decision(400, [
            'status' => 0,
            'code' => 'INVALID_REQUEST',
            'message' => 'userId, applicationId and messageId are required',
        ]);
    }

    $user = wma_resolve_user($pdo, $userId);
    if (!$user) {
        return wma_decision(404, [
            'status' => 0,
            'code' => 'USER_NOT_FOUND',
            'message' => 'User not found',
        ]);
    }
    $viewerRole = wma_normalize_role_key($roleInput !== '' ? $roleInput : (string)($user['role'] ?? ''));
    $resolvedUserRole = wma_normalize_role_key((string)($user['role'] ?? ''));
    if ($viewerRole === '') $viewerRole = $resolvedUserRole;
    if ($resolvedUserRole !== '' && $viewerRole !== $resolvedUserRole && !($viewerRole === 'verifier' && $resolvedUserRole === 'db_verifier')) {
        return wma_decision(403, [
            'status' => 0,
            'code' => 'UNAUTHORIZED',
            'message' => 'Role does not match user',
        ]);
    }
    if (!empty($actor) && ($actor['service'] ?? false) !== true && (int)($actor['userId'] ?? 0) > 0 && (int)$actor['userId'] !== $userId) {
        return wma_decision(403, [
            'status' => 0,
            'code' => 'UNAUTHORIZED',
            'message' => 'Session user does not match requested user',
        ]);
    }
    if ($viewerRole === 'candidate') {
        return wma_decision(200, wma_response(false, 'unsupported_role', $messageId));
    }
    if (!wma_application_exists($pdo, $applicationId)) {
        return wma_decision(404, [
            'status' => 0,
            'code' => 'APPLICATION_NOT_FOUND',
            'message' => 'Application not found',
        ]);
    }

    $rows = wma_load_communication_rows($pdo, $applicationId);

    $target = null;
    foreach ($rows as $row) {
        if (wc_norm_msg_id((string)($row['message_id'] ?? '')) === $messageId) {
            $target = $row;
            break;
        }
    }
    if (!$target) {
        $preScopedRows = $rows;
        if ($componentKey !== '') {
            $preScopedRows = array_values(array_filter($rows, static function (array $row) use ($componentKey): bool {
                return wma_row_matches_component_scope($row, $componentKey);
            }));
        }
        $preAllowedThreadIds = [];
        $preAllowedMessageIds = [];
        foreach ($preScopedRows as $row) {
            if (!wma_row_is_outgoing_for_viewer($row, $viewerRole)) {
                continue;
            }
            $rowThreadId = strtolower(trim((string)($row['thread_id'] ?? '')));
            if ($rowThreadId !== '') {
                $preAllowedThreadIds[$rowThreadId] = true;
            }
            $rowMessageId = wc_norm_msg_id((string)($row['message_id'] ?? ''));
            if ($rowMessageId !== '') {
                $preAllowedMessageIds[$rowMessageId] = true;
            }
        }

        $legacyRows = wma_load_legacy_incoming_replies($pdo, $applicationId, 120);
        if ($componentKey !== '') {
            $legacyRows = array_values(array_filter($legacyRows, static function (array $row) use ($componentKey): bool {
                return wma_row_matches_component_scope($row, $componentKey);
            }));
        }
        foreach ($legacyRows as $legacyRow) {
            if (wc_norm_msg_id((string)($legacyRow['message_id'] ?? '')) !== $messageId) {
                continue;
            }
            $legacyThreadId = strtolower(trim((string)($legacyRow['thread_id'] ?? '')));
            $legacyComponent = $componentKey !== ''
                ? $componentKey
                : wma_infer_component_from_text((string)($legacyRow['subject'] ?? ''), (string)($legacyRow['message'] ?? ''));
            if ($threadId !== '' && $legacyThreadId !== '' && $legacyThreadId !== $threadId) {
                return wma_decision(200, wma_response(false, 'thread_not_visible', $messageId, $legacyComponent, ''));
            }
            $legacyVisible = wma_row_belongs_to_viewer_role($legacyRow, $viewerRole, $preAllowedThreadIds, $preAllowedMessageIds);
            return wma_decision(200, wma_response($legacyVisible, $legacyVisible ? 'component_scope_match' : 'message_not_visible', $messageId, $legacyComponent, ''));
        }
        return wma_decision(200, wma_response(false, 'message_not_found', $messageId));
    }

    $targetComponent = wma_normalize_component_key((string)($target['component_key'] ?? ''));
    $targetOwnerRole = wma_normalize_thread_owner_role((string)($target['thread_owner_role'] ?? ''));
    $targetThreadId = strtolower(trim((string)($target['thread_id'] ?? '')));

    if ($componentKey !== '' && !wma_row_matches_component_scope($target, $componentKey)) {
        return wma_decision(200, wma_response(false, 'component_not_visible', $messageId, $targetComponent, $targetOwnerRole));
    }

    $scopedRows = $rows;
    if ($componentKey !== '') {
        $scopedRows = array_values(array_filter($rows, static function (array $row) use ($componentKey): bool {
            return wma_row_matches_component_scope($row, $componentKey);
        }));
    }
    if ($threadId !== '' && $targetThreadId !== '' && $targetThreadId !== $threadId) {
        return wma_decision(200, wma_response(false, 'thread_not_visible', $messageId, $targetComponent, $targetOwnerRole));
    }
    if ($ownerRole !== '' && $targetOwnerRole !== '' && $targetOwnerRole !== $ownerRole) {
        return wma_decision(200, wma_response(false, 'thread_not_visible', $messageId, $targetComponent, $targetOwnerRole));
    }

    $viewerThreadRole = wma_normalize_thread_owner_role($viewerRole);
    $isVerifierLaneAssignee = $targetOwnerRole === 'verifier'
        && $targetComponent !== ''
        && wma_is_component_assignee($pdo, $applicationId, $targetComponent, $userId);
    $explicitOwnershipSupported = false;
    foreach ($scopedRows as $row) {
        if (wma_normalize_thread_owner_role((string)($row['thread_owner_role'] ?? '')) !== '') {
            $explicitOwnershipSupported = true;
            break;
        }
    }

    $allowedThreadIds = [];
    $allowedMessageIds = [];
    foreach ($scopedRows as $row) {
        if (!wma_row_is_outgoing_for_viewer($row, $viewerRole)) {
            continue;
        }
        $rowThreadId = strtolower(trim((string)($row['thread_id'] ?? '')));
        if ($rowThreadId !== '') {
            $allowedThreadIds[$rowThreadId] = true;
        }
        $rowMessageId = wc_norm_msg_id((string)($row['message_id'] ?? ''));
        if ($rowMessageId !== '') {
            $allowedMessageIds[$rowMessageId] = true;
        }
    }

    $visible = false;
    $reason = 'message_not_visible';
    $ownerForTarget = wma_normalize_thread_owner_role((string)($target['thread_owner_role'] ?? ''));
    if ($explicitOwnershipSupported && $ownerForTarget !== '') {
        $visible = ($viewerThreadRole !== '' && $ownerForTarget === $viewerThreadRole)
            || ($ownerForTarget === 'verifier' && $isVerifierLaneAssignee);
        $reason = $visible ? 'viewer_thread_owner_match' : 'thread_not_visible';
    } elseif (wma_row_belongs_to_viewer_role($target, $viewerRole, $allowedThreadIds, $allowedMessageIds)) {
        $visible = true;
        $direction = strtolower(trim((string)($target['direction'] ?? '')));
        $reason = $direction === 'incoming' ? 'component_scope_match' : 'owner_role_match';
    }

    return wma_decision(200, wma_response($visible, $reason, $messageId, $targetComponent, $targetOwnerRole));
}

if (defined('WMA_LIBRARY_MODE')) {
    return;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wma_error(405, 'METHOD_NOT_ALLOWED', 'Method not allowed');
    }

    $actor = integration_resolve_actor(true);
    $input = wma_read_json_body();
    $pdo = getDB();
    $decision = wma_authorize_message($pdo, $input, $actor);
    wma_json((int)$decision['http'], (array)$decision['payload']);
} catch (PDOException $e) {
    wma_error(500, 'DATABASE_ERROR', 'Database error');
} catch (Throwable $e) {
    wma_error(500, 'DATABASE_ERROR', 'Database error');
}
