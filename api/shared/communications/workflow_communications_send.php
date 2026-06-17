<?php
header('Content-Type: application/json');
require_once __DIR__ . '/workflow_communication_service.php';

auth_require_login(null);
auth_session_start();

function read_json_body_wc(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function wc_outgoing_message_id(string $applicationId): string {
    $domain = 'localhost.localdomain';
    $from = trim((string)(env_get('APP_MAIL_FROM', '') ?? ''));
    if ($from !== '' && strpos($from, '@') !== false) {
        $parts = explode('@', $from);
        $d = strtolower(trim((string)end($parts)));
        if ($d !== '') $domain = $d;
    }
    $token = bin2hex(random_bytes(8));
    return '<wc.' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $applicationId)) . '.' . $token . '@' . $domain . '>';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }
    $in = read_json_body_wc();
    $pdo = getDB();
    wc_ensure_tables($pdo);

    $role = wc_norm_role((string)($in['role'] ?? wc_session_role()));
    $component = strtolower(trim((string)($in['component'] ?? '')));
    $action = strtolower(trim((string)($in['action'] ?? '')));
    $to = trim((string)($in['to_email'] ?? ''));
    $caseId = (int)($in['case_id'] ?? 0);
    $applicationId = wc_resolve_application_id($pdo, (string)($in['application_id'] ?? ''), $caseId);
    $templateId = isset($in['template_id']) ? (int)$in['template_id'] : null;
    $subject = trim((string)($in['subject'] ?? ''));
    $body = (string)($in['body'] ?? '');
    $notes = trim((string)($in['notes'] ?? ''));
    $deadline = trim((string)($in['deadline'] ?? ''));
    $checklist = isset($in['checklist']) && is_array($in['checklist']) ? $in['checklist'] : [];
    $mode = strtolower(trim((string)($in['mode'] ?? 'send')));
    $requestId = trim((string)($in['request_id'] ?? ''));
    if ($requestId === '') {
        $requestId = 'wc-' . $applicationId . '-' . md5(json_encode([$role, $component, $action, $subject, $body, $notes, $deadline, $mode, $to]));
    }

    if ($applicationId === '' || $component === '' || $action === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id/component/action required']);
        exit;
    }
    if ($mode !== 'draft' && ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL))) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Valid to_email is required']);
        exit;
    }
    if ($subject === '') $subject = 'Workflow Communication';
    if ($body === '') $body = 'Please review the requested action.';
    $threadOwnerRole = wc_norm_thread_owner_role($role);
    $threadId = trim((string)($in['thread_id'] ?? ''));
    if ($threadId === '') $threadId = wc_build_thread_id($applicationId, $component, $threadOwnerRole);
    $messageId = trim((string)($in['message_id'] ?? ''));
    if ($messageId === '') $messageId = wc_outgoing_message_id($applicationId);
    $referencesHeader = trim((string)($in['references_header'] ?? ''));

    $dup = $pdo->prepare('SELECT communication_id FROM Vati_Payfiller_Workflow_Communications WHERE request_id = ? LIMIT 1');
    $dup->execute([$requestId]);
    $existing = $dup->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($existing) {
        echo json_encode(['status' => 1, 'message' => 'Already processed', 'data' => [
            'communication_id' => (int)$existing['communication_id'],
            'delivery_status' => ($mode === 'draft' ? 'draft' : 'sent'),
            'deduped' => true
        ]]);
        exit;
    }

    $sendOk = true;
    if ($mode !== 'draft') {
        app_mail_set_log_meta([
            'application_id' => $applicationId,
            'case_id' => $caseId,
            'component' => $component,
            'role' => $role,
            'action' => $action,
            'event_type' => 'workflow.communication'
        ]);
        $sendOk = send_app_mail($to, $subject, wc_format_html($body), null, [
            'application_id' => $applicationId,
            'event_type' => 'workflow.communication',
            'headers' => [
                'Message-ID' => $messageId,
                'X-Workflow-Thread-Id' => $threadId,
                'References' => $referencesHeader,
            ]
        ]);
        app_mail_clear_log_meta();
        if (!$sendOk) {
            http_response_code(400);
            echo json_encode(['status' => 0, 'message' => 'Mail send failed. Check SMTP settings.']);
            exit;
        }
    }

    $uid = (int)($_SESSION['auth_user_id'] ?? 0);
    $uname = trim((string)($_SESSION['auth_user_name'] ?? ''));
    $workflowVersion = isset($in['workflow_version']) ? (int)$in['workflow_version'] : null;
    $delivery = $mode === 'draft' ? 'draft' : 'sent';
    $insJson = !empty($checklist) ? json_encode(array_values($checklist), JSON_UNESCAPED_UNICODE) : null;

    $st = $pdo->prepare('INSERT INTO Vati_Payfiller_Workflow_Communications
        (application_id, case_id, component_key, role_key, action_key, template_id, subject, body, checklist_json, notes, deadline_label, sent_by_user_id, sent_by_name, sent_at, delivery_status, workflow_version, communication_type, direction, actor_role, actor_name, workflow_stage, request_id, message_id, references_header, thread_id, thread_owner_role, thread_scope, root_outgoing_communication_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
        $applicationId,
        $caseId > 0 ? $caseId : null,
        $component,
        $role,
        $action,
        $templateId,
        $subject,
        $body,
        $insJson,
        $notes !== '' ? $notes : null,
        $deadline !== '' ? $deadline : null,
        $uid > 0 ? $uid : null,
        $uname !== '' ? $uname : null,
        $delivery,
        $workflowVersion,
        $action,
        'outgoing',
        $role,
        $uname !== '' ? $uname : null,
        $role,
        $requestId
        ,
        trim($messageId, '<> '),
        $referencesHeader !== '' ? $referencesHeader : null,
        $threadId,
        $threadOwnerRole,
        'component_role',
        null
    ]);
    $communicationId = (int)$pdo->lastInsertId();
    if ($communicationId > 0) {
        $up = $pdo->prepare('UPDATE Vati_Payfiller_Workflow_Communications
                                SET root_outgoing_communication_id = ?
                              WHERE communication_id = ?
                                AND COALESCE(root_outgoing_communication_id, 0) = 0');
        $up->execute([$communicationId, $communicationId]);
    }
    wc_thread_upsert($pdo, $applicationId, $caseId, $threadId, trim($messageId, '<> '), trim($messageId, '<> '));

    $msg = strtoupper($role) . ' communication: ' . $action . ' | component: ' . $component;
    if (!empty($checklist)) $msg .= ' | checklist: ' . implode(', ', $checklist);
    if ($notes !== '') $msg .= ' | notes: ' . $notes;
    wc_log_timeline($pdo, $applicationId, $role, $component, $msg);

    echo json_encode(['status' => 1, 'message' => ($mode === 'draft' ? 'Draft saved' : 'Sent'), 'data' => [
        'communication_id' => $communicationId,
        'delivery_status' => $delivery
    ]]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
