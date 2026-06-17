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

function wrie_json(int $httpCode, array $payload): void
{
    integration_json_response($httpCode, $payload);
}

function wrie_error(int $httpCode, string $code, string $message, array $extra = []): void
{
    wrie_json($httpCode, array_merge([
        'status' => 0,
        'code' => $code,
        'message' => $message,
    ], $extra));
}

function wrie_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        wrie_error(400, 'INVALID_JSON', 'JSON request body is required');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        wrie_error(400, 'INVALID_JSON', 'Request body must be valid JSON object');
    }
    return $decoded;
}

function wrie_str(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if (is_array($value) || is_object($value)) {
        return '';
    }
    return trim((string)$value);
}

function wrie_norm_component(string $value): string
{
    $value = strtolower(trim($value));
    $value = str_replace(['-', ' '], '_', $value);
    if ($value === 'identification') return 'id';
    if ($value === 'social_media') return 'socialmedia';
    if ($value === 'e_court') return 'ecourt';
    if ($value === 'education reference') return 'education_reference';
    if ($value === 'employment reference') return 'employment_reference';
    return $value;
}

function wrie_empty_match(): array
{
    return [
        'application_id' => '',
        'case_id' => 0,
        'thread_id' => '',
        'component_key' => '',
        'thread_owner_role' => '',
        'root_outgoing_communication_id' => 0,
    ];
}

function wrie_match_has_any(array $match): bool
{
    return trim((string)($match['application_id'] ?? '')) !== ''
        || trim((string)($match['thread_id'] ?? '')) !== ''
        || trim((string)($match['component_key'] ?? '')) !== ''
        || wc_norm_thread_owner_role((string)($match['thread_owner_role'] ?? '')) !== ''
        || (int)($match['root_outgoing_communication_id'] ?? 0) > 0
        || (int)($match['case_id'] ?? 0) > 0;
}

function wrie_resolved_payload(array $match, string $method): array
{
    $applicationId = integration_normalize_application_id((string)($match['application_id'] ?? ''));
    $componentKey = wrie_norm_component((string)($match['component_key'] ?? ''));
    $ownerRole = wc_norm_thread_owner_role((string)($match['thread_owner_role'] ?? ''));
    $threadId = trim((string)($match['thread_id'] ?? ''));
    if ($threadId === '' && $applicationId !== '' && $componentKey !== '' && $ownerRole !== '') {
        $threadId = wc_build_thread_id($applicationId, $componentKey, $ownerRole);
    }

    return [
        'status' => 1,
        'resolved' => true,
        'resolutionMethod' => $method,
        'applicationId' => $applicationId,
        'caseId' => (int)($match['case_id'] ?? 0),
        'componentKey' => $componentKey,
        'ownerRole' => $ownerRole,
        'threadId' => $threadId,
        'rootOutgoingCommunicationId' => (int)($match['root_outgoing_communication_id'] ?? 0),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wrie_error(405, 'METHOD_NOT_ALLOWED', 'Method not allowed');
    }

    integration_resolve_actor(true);
    $input = wrie_read_json_body();

    $messageId = wc_norm_msg_id(wrie_str($input, 'messageId') ?: wrie_str($input, 'message_id'));
    $inReplyTo = wc_norm_msg_id(wrie_str($input, 'inReplyTo') ?: wrie_str($input, 'in_reply_to'));
    $references = wrie_str($input, 'references') ?: wrie_str($input, 'referencesHeader') ?: wrie_str($input, 'references_header');
    $threadId = wrie_str($input, 'threadId') ?: wrie_str($input, 'thread_id');
    $applicationId = integration_normalize_application_id(wrie_str($input, 'applicationId') ?: wrie_str($input, 'application_id'));
    $componentKey = wrie_norm_component(wrie_str($input, 'componentKey') ?: wrie_str($input, 'component_key'));
    $subject = wrie_str($input, 'subject');
    $body = wrie_str($input, 'body') ?: wrie_str($input, 'bodyText') ?: wrie_str($input, 'bodyHtml');
    $fromEmail = integration_normalize_email(wrie_str($input, 'fromEmail') ?: wrie_str($input, 'from_email'));

    $hasHeaders = $inReplyTo !== '' || trim($references) !== '';
    $hasExistingThreadInput = $applicationId !== '' && $componentKey !== '' && $threadId !== '';
    $hasSubjectFallbackInput = $applicationId !== '' && ($subject !== '' || $body !== '');

    if ($messageId === '' && !$hasHeaders && !$hasExistingThreadInput && !$hasSubjectFallbackInput) {
        wrie_error(400, 'ROUTING_INPUT_REQUIRED', 'At least one routing signal is required');
    }

    $pdo = getDB();

    $headerMatch = wrie_empty_match();
    if ($hasHeaders) {
        $headerMatch = wc_try_thread_by_headers($pdo, $inReplyTo, $references);
        if (wc_is_strong_thread_match($headerMatch)) {
            wrie_json(200, wrie_resolved_payload($headerMatch, 'headers'));
        }
    }

    $threadMatch = wrie_empty_match();
    if ($hasExistingThreadInput) {
        $threadMatch = wc_try_thread_by_existing_thread($pdo, $applicationId, $componentKey, $threadId);
        $ambiguousThread = $threadId !== ''
            && trim((string)($threadMatch['thread_id'] ?? '')) !== ''
            && wc_norm_thread_owner_role((string)($threadMatch['thread_owner_role'] ?? '')) === '';

        if ($ambiguousThread) {
            wrie_error(409, 'AMBIGUOUS_THREAD', 'Inbound email matches multiple workflow lanes', [
                'candidates' => [],
            ]);
        }

        if (wc_is_strong_thread_match($threadMatch)) {
            wrie_json(200, wrie_resolved_payload($threadMatch, 'thread'));
        }
    }

    $subjectMatch = wrie_empty_match();
    if ($hasSubjectFallbackInput) {
        $subjectMatch = wc_try_thread_by_subject($pdo, $applicationId, $subject, $body);
        if (wc_is_strong_thread_match($subjectMatch)) {
            wrie_json(200, wrie_resolved_payload($subjectMatch, 'subject'));
        }
    }

    foreach ([$headerMatch, $threadMatch, $subjectMatch] as $partialMatch) {
        if (wrie_match_has_any($partialMatch)) {
            $partialApp = integration_normalize_application_id((string)($partialMatch['application_id'] ?? ''));
            if ($partialApp === '' && $applicationId !== '') {
                $partialMatch['application_id'] = $applicationId;
            }
            wrie_json(200, [
                'status' => 1,
                'resolved' => false,
                'resolutionMethod' => 'unresolved',
                'reason' => 'PARTIAL_MATCH',
                'applicationId' => integration_normalize_application_id((string)($partialMatch['application_id'] ?? '')),
                'caseId' => (int)($partialMatch['case_id'] ?? 0),
                'componentKey' => wrie_norm_component((string)($partialMatch['component_key'] ?? '')),
                'ownerRole' => wc_norm_thread_owner_role((string)($partialMatch['thread_owner_role'] ?? '')),
                'threadId' => trim((string)($partialMatch['thread_id'] ?? '')),
                'rootOutgoingCommunicationId' => (int)($partialMatch['root_outgoing_communication_id'] ?? 0),
            ]);
        }
    }

    wrie_json(200, [
        'status' => 1,
        'resolved' => false,
        'resolutionMethod' => 'unresolved',
        'reason' => 'NO_MATCH',
        'applicationId' => $applicationId,
        'caseId' => 0,
        'componentKey' => $componentKey,
        'ownerRole' => '',
        'threadId' => $threadId,
        'rootOutgoingCommunicationId' => 0,
    ]);
} catch (PDOException $e) {
    wrie_error(500, 'DATABASE_ERROR', 'Database error');
} catch (Throwable $e) {
    wrie_error(500, 'SERVER_ERROR', 'Server error');
}
