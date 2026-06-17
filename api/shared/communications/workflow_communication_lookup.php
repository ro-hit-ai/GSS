<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/integration.php';
require_once __DIR__ . '/workflow_communication_service.php';

integration_bootstrap_json_api();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Service-Token, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function wcl_json(int $httpCode, array $payload): void
{
    integration_json_response($httpCode, $payload);
}

function wcl_error(int $httpCode, string $code, string $message): void
{
    wcl_json($httpCode, [
        'status' => 0,
        'code' => $code,
        'message' => $message,
    ]);
}

function wcl_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        wcl_error(400, 'INVALID_REQUEST', 'JSON request body is required');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        wcl_error(400, 'INVALID_REQUEST', 'Request body must be valid JSON object');
    }
    return $decoded;
}

function wcl_str(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if (is_array($value) || is_object($value)) {
        return '';
    }
    return trim((string)$value);
}

function wcl_table_has_column(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

function wcl_normalize_role(string $role): string
{
    return wc_norm_thread_owner_role($role);
}

function wcl_resolve_lookup_actor(): void
{
    if (function_exists('integration_is_valid_service_token') && integration_is_valid_service_token()) {
        return;
    }

    $provided = function_exists('integration_get_service_token') ? integration_get_service_token() : '';
    $legacy = trim((string)(function_exists('env_get') ? (env_get('PHP_API_KEY', '') ?? '') : getenv('PHP_API_KEY')));
    if ($provided !== '' && $legacy !== '' && hash_equals($legacy, $provided)) {
        return;
    }

    integration_resolve_actor(true);
}

function wcl_lane_key(array $row): string
{
    return strtoupper(trim((string)($row['application_id'] ?? ''))) . '|'
        . strtolower(trim((string)($row['component_key'] ?? ''))) . '|'
        . wcl_normalize_role((string)($row['thread_owner_role'] ?? ''));
}

function wcl_map_row(array $row): array
{
    $applicationId = strtoupper(trim((string)($row['application_id'] ?? '')));
    $componentKey = strtolower(trim((string)($row['component_key'] ?? '')));
    $ownerRole = wcl_normalize_role((string)($row['thread_owner_role'] ?? ''));
    $threadId = trim((string)($row['thread_id'] ?? ''));
    if ($threadId === '' && $applicationId !== '' && $componentKey !== '' && $ownerRole !== '') {
        $threadId = wc_build_thread_id($applicationId, $componentKey, $ownerRole);
    }

    return [
        'communicationId' => (int)($row['communication_id'] ?? 0),
        'communication_id' => (int)($row['communication_id'] ?? 0),
        'applicationId' => $applicationId,
        'application_id' => $applicationId,
        'caseId' => isset($row['case_id']) ? (int)$row['case_id'] : 0,
        'case_id' => isset($row['case_id']) ? (int)$row['case_id'] : 0,
        'componentKey' => $componentKey,
        'component_key' => $componentKey,
        'ownerRole' => $ownerRole,
        'owner_role' => $ownerRole,
        'threadOwnerRole' => $ownerRole,
        'thread_owner_role' => $ownerRole,
        'threadId' => $threadId,
        'thread_id' => $threadId,
        'phpThreadId' => $threadId,
        'php_thread_id' => $threadId,
        'messageId' => wc_norm_msg_id((string)($row['message_id'] ?? '')),
        'message_id' => wc_norm_msg_id((string)($row['message_id'] ?? '')),
        'sourceMessageKey' => (string)($row['source_message_key'] ?? ''),
        'source_message_key' => (string)($row['source_message_key'] ?? ''),
        'rootOutgoingCommunicationId' => (int)($row['root_outgoing_communication_id'] ?? 0),
        'root_outgoing_communication_id' => (int)($row['root_outgoing_communication_id'] ?? 0),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wcl_error(405, 'METHOD_NOT_ALLOWED', 'Method not allowed');
    }

    wcl_resolve_lookup_actor();
    $input = wcl_read_json_body();
    $pdo = getDB();

    $applicationId = integration_normalize_application_id(
        wcl_str($input, 'applicationId') ?: wcl_str($input, 'application_id')
    );
    $lookupType = strtolower(wcl_str($input, 'lookupType') ?: wcl_str($input, 'lookup_type'));
    $allowed = ['message_id', 'source_message_key', 'communication_id', 'root_outgoing_communication_id', 'thread_id'];
    if (!in_array($lookupType, $allowed, true)) {
        wcl_error(400, 'INVALID_REQUEST', 'Unsupported lookupType');
    }

    $value = wcl_str($input, $lookupType);
    if ($value === '') {
        wcl_error(400, 'INVALID_REQUEST', 'Lookup value is required');
    }

    $table = 'Vati_Payfiller_Workflow_Communications';
    if (!wcl_table_has_column($pdo, $table, $lookupType)) {
        wcl_error(400, 'INVALID_REQUEST', 'Lookup column is not available');
    }

    $where = [];
    $params = [];
    if ($applicationId !== '') {
        $where[] = 'application_id = ?';
        $params[] = $applicationId;
    }

    if (in_array($lookupType, ['communication_id', 'root_outgoing_communication_id'], true)) {
        $where[] = $lookupType . ' = ?';
        $params[] = (int)$value;
    } elseif ($lookupType === 'message_id') {
        $where[] = 'LOWER(TRIM(message_id)) = LOWER(TRIM(?))';
        $params[] = wc_norm_msg_id($value);
    } else {
        $where[] = $lookupType . ' = ?';
        $params[] = $value;
    }

    $sql = 'SELECT communication_id, application_id, case_id, component_key, message_id, source_message_key,
                   thread_id, thread_owner_role, root_outgoing_communication_id
              FROM Vati_Payfiller_Workflow_Communications
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY communication_id DESC
             LIMIT 20';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        wcl_json(200, ['status' => 1, 'data' => null, 'matches' => []]);
    }

    $mapped = array_map('wcl_map_row', $rows);
    $lanes = [];
    foreach ($mapped as $row) {
        $key = wcl_lane_key($row);
        if ($key !== '||') {
            $lanes[$key] = true;
        }
    }

    if (count($lanes) > 1) {
        wcl_json(200, [
            'status' => 1,
            'data' => null,
            'matches' => $mapped,
            'ambiguous' => true,
            'reason' => 'PHP_LOOKUP_MULTIPLE_LANES',
        ]);
    }

    wcl_json(200, [
        'status' => 1,
        'data' => $mapped[0],
        'matches' => [$mapped[0]],
        'ambiguous' => false,
    ]);
} catch (PDOException $e) {
    wcl_error(500, 'DATABASE_ERROR', 'Database error');
} catch (Throwable $e) {
    wcl_error(500, 'DATABASE_ERROR', 'Database error');
}
