<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/mail.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/workflow_communication_service.php';

auth_require_login(null);
auth_session_start();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function nrp_json(int $httpCode, array $payload): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function nrp_first_string(...$values): string
{
    foreach ($values as $value) {
        if (is_array($value)) {
            continue;
        }
        $text = trim((string)$value);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function nrp_node_sender_name(array $message): string
{
    $sender = $message['sender'] ?? null;
    if (is_array($sender)) {
        return nrp_first_string($sender['name'] ?? '', $sender['email'] ?? '');
    }
    return nrp_first_string($sender);
}

function nrp_node_component_key(array $message, string $requestedComponentKey): string
{
    $metadata = is_array($message['metadata'] ?? null) ? $message['metadata'] : [];
    $workflow = is_array($metadata['workflow'] ?? null) ? $metadata['workflow'] : [];
    $key = strtolower(trim(nrp_first_string(
        $workflow['componentKey'] ?? '',
        $workflow['component_key'] ?? '',
        $metadata['componentKey'] ?? '',
        $metadata['component_key'] ?? ''
    )));
    if ($key !== '') {
        return $key;
    }
    $haystack = strtolower(trim(
        nrp_first_string($message['subject'] ?? '') . ' ' .
        nrp_first_string($message['body'] ?? '') . ' ' .
        nrp_first_string($message['bodyHtml'] ?? '')
    ));
    if (strpos($haystack, 'employment reference') !== false || strpos($haystack, 'employment_reference') !== false) {
        return 'employment_reference';
    }
    if (strpos($haystack, 'education reference') !== false || strpos($haystack, 'education_reference') !== false || strpos($haystack, 'academic reference') !== false) {
        return 'education_reference';
    }
    if (strpos($haystack, 'employment verification') !== false || strpos($haystack, 'employer verification') !== false || strpos($haystack, 'employment proof') !== false) {
        return 'employment';
    }
    if (strpos($haystack, 'education verification') !== false || strpos($haystack, 'institution verification') !== false || strpos($haystack, 'college verification') !== false) {
        return 'education';
    }
    return strtolower(trim($requestedComponentKey));
}

function nrp_node_datetime(array $message): string
{
    $value = nrp_first_string($message['createdAt'] ?? '', $message['sentAt'] ?? '', $message['lastMessageAt'] ?? '');
    if ($value !== '') {
        $ts = strtotime($value);
        if ($ts !== false) {
            return date('Y-m-d H:i:s', $ts);
        }
    }
    return date('Y-m-d H:i:s');
}

function nrp_lookup_case_id(PDO $pdo, string $applicationId): int
{
    $st = $pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
    $st->execute([$applicationId]);
    return (int)($st->fetchColumn() ?: 0);
}

function nrp_latest_outgoing(PDO $pdo, string $applicationId, string $componentKey, string $ownerRole): array
{
    $st = $pdo->prepare(
        "SELECT communication_id, case_id, thread_id, thread_owner_role, message_id
           FROM Vati_Payfiller_Workflow_Communications
          WHERE application_id = ?
            AND component_key = ?
            AND direction = 'outgoing'
            AND COALESCE(thread_owner_role, '') = ?
          ORDER BY communication_id DESC
          LIMIT 1"
    );
    $st->execute([$applicationId, $componentKey, $ownerRole]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function nrp_sync_node_messages(PDO $pdo, string $applicationId, string $requestedComponentKey, array $payload): array
{
    $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
    if (!$messages) {
        return ['inserted' => 0, 'duplicates' => 0, 'skipped' => 0];
    }

    $caseId = nrp_lookup_case_id($pdo, $applicationId);
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO Vati_Payfiller_Workflow_Communications
         (application_id, case_id, component_key, role_key, action_key, subject, body, sent_by_name, sent_at,
          delivery_status, communication_type, direction, actor_role, actor_name, workflow_stage, source_table,
          source_message_key, message_id, in_reply_to, references_header, thread_id, thread_owner_role,
          thread_scope, root_outgoing_communication_id, mailbox_uid)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $updateRoot = $pdo->prepare(
        'UPDATE Vati_Payfiller_Workflow_Communications
            SET root_outgoing_communication_id = ?
          WHERE communication_id = ?
            AND COALESCE(root_outgoing_communication_id, 0) = 0'
    );

    $inserted = 0;
    $duplicates = 0;
    $skipped = 0;
    foreach ($messages as $message) {
        if (!is_array($message)) {
            $skipped++;
            continue;
        }
        $subject = nrp_first_string($message['subject'] ?? 'Email Message');
        $body = nrp_first_string($message['body'] ?? '', $message['bodyText'] ?? '', $message['bodyHtml'] ?? '');
        $direction = strtolower(trim(nrp_first_string($message['direction'] ?? '')));
        $direction = $direction === 'inbound' || $direction === 'incoming' ? 'incoming' : 'outgoing';
        $componentKey = nrp_node_component_key($message, $requestedComponentKey);
        if ($componentKey === '') {
            $componentKey = 'timeline';
        }
        $ownerRole = 'verifier';
        $outgoing = nrp_latest_outgoing($pdo, $applicationId, $componentKey, $ownerRole);
        $resolvedCaseId = (int)($outgoing['case_id'] ?? 0);
        if ($resolvedCaseId <= 0) {
            $resolvedCaseId = $caseId;
        }
        $threadId = nrp_first_string($outgoing['thread_id'] ?? '', wc_build_thread_id($applicationId, $componentKey, $ownerRole));
        $rootOutgoingId = (int)($outgoing['communication_id'] ?? 0);
        $externalMessageId = wc_norm_msg_id(nrp_first_string(
            $message['externalMessageId'] ?? '',
            $message['messageId'] ?? '',
            $message['message_id'] ?? ''
        ));
        $nodeId = nrp_first_string($message['id'] ?? '', $message['emailMessageId'] ?? '', $externalMessageId);
        $sourceKey = $nodeId !== '' ? substr('node:' . $nodeId, 0, 191) : sha1($subject . '|' . $body . '|' . nrp_node_datetime($message));
        $actorName = nrp_node_sender_name($message);
        $roleKey = $direction === 'incoming'
            ? 'candidate'
            : strtolower(trim(nrp_first_string($message['sentByRole'] ?? '', $message['actorRole'] ?? '', 'verifier')));
        if ($roleKey === '') {
            $roleKey = $direction === 'incoming' ? 'candidate' : 'verifier';
        }
        $insert->execute([
            $applicationId,
            $resolvedCaseId > 0 ? $resolvedCaseId : null,
            $componentKey,
            $roleKey,
            $direction === 'incoming' ? 'reply' : 'verification_request',
            $subject !== '' ? $subject : ($direction === 'incoming' ? 'Email Reply' : 'Verification Mail'),
            $body,
            $actorName !== '' ? $actorName : ($direction === 'incoming' ? 'Candidate' : 'Verifier'),
            nrp_node_datetime($message),
            $direction === 'incoming' ? 'received' : 'sent',
            $direction === 'incoming' ? 'node_reply' : 'verification_request',
            $direction,
            $roleKey,
            $actorName !== '' ? $actorName : null,
            $roleKey,
            'node_email_messages',
            $sourceKey,
            $externalMessageId !== '' ? $externalMessageId : null,
            wc_norm_msg_id(nrp_first_string($message['inReplyTo'] ?? '', $message['in_reply_to'] ?? '')) ?: null,
            nrp_first_string($message['references'] ?? '', $message['referencesHeader'] ?? '', $message['references_header'] ?? '') ?: null,
            $threadId,
            $ownerRole,
            'component_role',
            $rootOutgoingId > 0 ? $rootOutgoingId : null,
            $nodeId !== '' ? substr($nodeId, 0, 128) : null,
        ]);
        $inc = (int)$insert->rowCount();
        if ($inc > 0) {
            $inserted += $inc;
            $communicationId = (int)$pdo->lastInsertId();
            if ($direction === 'outgoing' && $communicationId > 0) {
                $updateRoot->execute([$communicationId, $communicationId]);
            }
            wc_thread_upsert(
                $pdo,
                $applicationId,
                $resolvedCaseId,
                $threadId,
                wc_norm_msg_id(nrp_first_string($outgoing['message_id'] ?? '', $externalMessageId)),
                $externalMessageId
            );
        } else {
            $duplicates++;
        }
    }

    return ['inserted' => $inserted, 'duplicates' => $duplicates, 'skipped' => $skipped];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        nrp_json(405, [
            'success' => false,
            'source' => 'node',
            'error' => [
                'code' => 'METHOD_NOT_ALLOWED',
                'message' => 'Method not allowed',
            ],
            'meta' => [
                'fallbackRecommended' => true,
            ],
        ]);
    }

    $applicationId = strtoupper(trim((string)($_GET['application_id'] ?? '')));
    if ($applicationId === '') {
        nrp_json(400, [
            'success' => false,
            'source' => 'node',
            'error' => [
                'code' => 'APPLICATION_ID_REQUIRED',
                'message' => 'application_id is required',
            ],
            'meta' => [
                'fallbackRecommended' => true,
            ],
        ]);
    }

    $componentKey = trim((string)($_GET['component_key'] ?? ''));
    $query = [];
    if ($componentKey !== '') {
        $query['componentKey'] = $componentKey;
    }

    $path = '/api/v1/php/applications/' . rawurlencode($applicationId) . '/replies';
    if (!empty($query)) {
        $path .= '?' . http_build_query($query);
    }
    $result = app_node_api_json_request('GET', $path, null, 20);

    if (($result['success'] ?? false) !== true) {
        nrp_json(502, [
            'success' => false,
            'source' => 'node',
            'applicationId' => $applicationId,
            'error' => [
                'code' => 'NODE_REQUEST_FAILED',
                'message' => (string)($result['error'] ?? 'Node request failed'),
            ],
            'meta' => [
                'fallbackRecommended' => true,
                'httpCode' => (int)($result['http_code'] ?? 0),
            ],
        ]);
    }

    $payload = is_array($result['response'] ?? null) ? $result['response'] : null;
    if (!$payload) {
        nrp_json(502, [
            'success' => false,
            'source' => 'node',
            'applicationId' => $applicationId,
            'error' => [
                'code' => 'NODE_INVALID_RESPONSE',
                'message' => 'Node returned an invalid response',
            ],
            'meta' => [
                'fallbackRecommended' => true,
                'httpCode' => (int)($result['http_code'] ?? 0),
            ],
        ]);
    }

    if ((string)($_GET['sync'] ?? '0') === '1') {
        $sync = nrp_sync_node_messages(getDB(), $applicationId, $componentKey, $payload);
        if (!isset($payload['meta']) || !is_array($payload['meta'])) {
            $payload['meta'] = [];
        }
        $payload['meta']['phpPersisted'] = $sync['inserted'];
        $payload['meta']['phpDuplicates'] = $sync['duplicates'];
        $payload['meta']['phpSkipped'] = $sync['skipped'];
    }

    nrp_json(200, $payload);
} catch (Throwable $e) {
    nrp_json(500, [
        'success' => false,
        'source' => 'node',
        'error' => [
            'code' => 'PHP_PROXY_FAILURE',
            'message' => $e->getMessage(),
        ],
        'meta' => [
            'fallbackRecommended' => true,
        ],
    ]);
}
