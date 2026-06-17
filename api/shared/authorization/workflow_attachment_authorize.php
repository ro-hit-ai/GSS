<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/integration.php';

define('WMA_LIBRARY_MODE', true);
require_once __DIR__ . '/workflow_message_authorize.php';

integration_bootstrap_json_api();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Service-Token, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function waa_json(int $httpCode, array $payload): void
{
    integration_json_response($httpCode, $payload);
}

function waa_error(int $httpCode, string $code, string $message, array $extra = []): void
{
    waa_json($httpCode, array_merge([
        'status' => 0,
        'code' => $code,
        'message' => $message,
    ], $extra));
}

function waa_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        waa_error(400, 'INVALID_REQUEST', 'JSON request body is required');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        waa_error(400, 'INVALID_REQUEST', 'Request body must be valid JSON object');
    }
    return $decoded;
}

function waa_str(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if (is_array($value) || is_object($value)) {
        return '';
    }
    return trim((string)$value);
}

function waa_attachment_response(
    bool $allowed,
    string $reason,
    bool $messageAllowed,
    string $attachmentId = '',
    string $messageId = '',
    array $messageDecision = []
): array {
    $payload = [
        'status' => 1,
        'allowed' => $allowed,
        'reason' => $reason,
        'messageAllowed' => $messageAllowed,
    ];
    if ($attachmentId !== '') {
        $payload['attachmentId'] = $attachmentId;
    }
    if ($messageId !== '') {
        $payload['messageId'] = $messageId;
    }
    if ($messageDecision) {
        $payload['messageDecision'] = $messageDecision;
    }
    return $payload;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        waa_error(405, 'METHOD_NOT_ALLOWED', 'Method not allowed');
    }

    $actor = integration_resolve_actor(true);
    $input = waa_read_json_body();
    $attachmentId = waa_str($input, 'attachmentId') ?: waa_str($input, 'attachment_id');
    $messageIdRaw = waa_str($input, 'messageId') ?: waa_str($input, 'message_id');
    $messageId = wc_norm_msg_id($messageIdRaw);

    if ($attachmentId === '') {
        waa_json(200, waa_attachment_response(false, 'attachment_not_found', false, '', $messageId));
    }

    $pdo = getDB();
    $messageDecision = wma_authorize_message($pdo, $input, $actor);
    $messageHttp = (int)($messageDecision['http'] ?? 500);
    $messagePayload = is_array($messageDecision['payload'] ?? null) ? $messageDecision['payload'] : [];

    if (($messagePayload['status'] ?? null) === 0) {
        $code = (string)($messagePayload['code'] ?? '');
        if ($code === 'APPLICATION_NOT_FOUND') {
            waa_error(404, 'APPLICATION_NOT_FOUND', 'Application not found');
        }
        waa_json($messageHttp, $messagePayload);
    }

    $messageAllowed = (bool)($messagePayload['allowed'] ?? false);
    $messageReason = (string)($messagePayload['reason'] ?? 'message_not_visible');
    if (!$messageAllowed) {
        $reason = in_array($messageReason, ['message_not_found', 'unsupported_role'], true)
            ? $messageReason
            : 'message_not_visible';
        waa_json(200, waa_attachment_response(false, $reason, false, $attachmentId, $messageId, $messagePayload));
    }

    waa_json(200, waa_attachment_response(true, 'inherits_message_visibility', true, $attachmentId, $messageId, $messagePayload));
} catch (PDOException $e) {
    waa_error(500, 'DATABASE_ERROR', 'Database error');
} catch (Throwable $e) {
    waa_error(500, 'DATABASE_ERROR', 'Database error');
}
