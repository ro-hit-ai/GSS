<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/template_governance.php';
require_once __DIR__ . '/workflow_communication_service.php';

auth_require_login(null);
auth_session_start();

function svm_norm_role(string $role): string {
    $r = strtolower(trim($role));
    if ($r === 'component verifier' || $r === 'component_verifier') return 'verifier';
    if ($r === 'db verifier' || $r === 'db-verifier' || $r === 'db_verifier') return 'verifier';
    if ($r === 'component validator' || $r === 'component_validator') return 'validator';
    if ($r === 'team lead') return 'team_lead';
    return $r;
}

function svm_template(string $componentKey, array $ctx): array {
    $candidate = trim((string)($ctx['candidate_name'] ?? 'Candidate'));
    $appId = trim((string)($ctx['application_id'] ?? ''));
    $org = trim((string)($ctx['organization_name'] ?? ''));
    $client = trim((string)($ctx['client_name'] ?? ''));
    $actor = trim((string)($ctx['actor_name'] ?? 'Verification Team'));
    if ($componentKey === 'education') {
        return [
            'template_key' => 'education_verification',
            'subject' => 'Education Verification Request - ' . ($candidate !== '' ? $candidate : $appId),
            'body' => "Hello,\n\nPlease verify education details for {$candidate}" . ($appId !== '' ? " (Application ID: {$appId})" : '') . ".\n"
                . ($org !== '' ? "Institution: {$org}\n" : '')
                . ($client !== '' ? "Client: {$client}\n" : '')
                . "\nRegards,\n{$actor}",
        ];
    }
    return [
        'template_key' => 'employment_verification',
        'subject' => 'Employment Verification Request - ' . ($candidate !== '' ? $candidate : $appId),
        'body' => "Hello,\n\nPlease verify employment details for {$candidate}" . ($appId !== '' ? " (Application ID: {$appId})" : '') . ".\n"
            . ($org !== '' ? "Employer: {$org}\n" : '')
            . ($client !== '' ? "Client: {$client}\n" : '')
            . "\nRegards,\n{$actor}",
    ];
}

function svm_ensure_tracking_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS verification_communications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT NULL,
            application_id VARCHAR(64) NOT NULL,
            component_key VARCHAR(64) NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            node_thread_id VARCHAR(191) NULL,
            node_conversation_id VARCHAR(191) NULL,
            communication_status VARCHAR(64) NOT NULL DEFAULT 'sent',
            last_message_at DATETIME NULL,
            created_by BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_vc_app_comp (application_id, component_key),
            KEY idx_vc_case_comp (case_id, component_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $need = [
        'template_key' => "ALTER TABLE verification_communications ADD COLUMN template_key VARCHAR(191) NULL AFTER component_key",
        'subject_snapshot' => "ALTER TABLE verification_communications ADD COLUMN subject_snapshot VARCHAR(500) NULL AFTER communication_status",
        'body_snapshot' => "ALTER TABLE verification_communications ADD COLUMN body_snapshot MEDIUMTEXT NULL AFTER subject_snapshot",
    ];
    foreach ($need as $col => $sql) {
        try {
            $st->execute(['verification_communications', $col]);
            if (!$st->fetchColumn()) $pdo->exec($sql);
        } catch (Throwable $e) {
        }
    }
}

function svm_nullable_string($value): ?string {
    if ($value === null) return null;
    $s = trim((string)$value);
    return $s === '' ? null : $s;
}

function svm_normalize_delivery_status(string $value): string {
    $v = strtolower(trim($value));
    if ($v === '' || $v === '1' || $v === 'true' || $v === 'success' || $v === 'ok') return 'sent';
    if ($v === '0' || $v === 'false' || $v === 'failed' || $v === 'error') return 'failed';
    return $v;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $in = json_decode((string)$raw, true);
    if (!is_array($in)) $in = [];

    $mode = strtolower(trim((string)($in['mode'] ?? 'send')));
    $componentKey = strtolower(trim((string)($in['component_key'] ?? '')));
    $recipientEmail = trim((string)($in['recipient_email'] ?? ''));
    $applicationId = trim((string)($in['application_id'] ?? ''));
    $caseId = isset($in['case_id']) ? (int)$in['case_id'] : 0;
    $subject = trim((string)($in['subject'] ?? ''));
    $messageBody = (string)($in['message_body'] ?? '');
    $remarks = trim((string)($in['remarks'] ?? ''));
    $sessionRole = svm_norm_role((string)($_SESSION['auth_moduleAccess'] ?? ''));
    $senderRole = svm_norm_role((string)($in['sender_role'] ?? $sessionRole));
    $senderUserId = (int)($_SESSION['auth_user_id'] ?? 0);
    $senderName = trim((string)($_SESSION['auth_user_name'] ?? ''));

    if (!in_array($senderRole, ['validator', 'verifier', 'qa'], true)) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Forbidden']);
        exit;
    }
    if (!in_array($componentKey, ['education', 'employment'], true)) {
        if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
            @file_put_contents(__DIR__ . '/../../logs/workflow_transition.log', json_encode([
                'ts' => date('c'),
                'event' => 'verification_mail_component_restricted',
                'source' => 'send_verification_mail',
                'allowed_components' => ['education', 'employment'],
                'requested_component' => $componentKey,
                'sender_role' => $senderRole,
                'application_id' => $applicationId,
                'case_id' => $caseId,
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
        }
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Invalid component']);
        exit;
    }
    if ($applicationId === '' && $caseId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id or case_id is required']);
        exit;
    }

    $pdo = getDB();
    svm_ensure_tracking_table($pdo);

    $caseQuery = $pdo->prepare(
        'SELECT c.case_id, c.application_id, c.client_id,
                c.candidate_first_name, c.candidate_last_name, c.candidate_email
           FROM Vati_Payfiller_Cases c
          WHERE (c.application_id = ? OR (? > 0 AND c.case_id = ?))
          LIMIT 1'
    );
    $caseQuery->execute([$applicationId, $caseId, $caseId]);
    $caseRow = $caseQuery->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$caseRow) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found']);
        exit;
    }
    $caseId = (int)($caseRow['case_id'] ?? $caseId);
    $applicationId = trim((string)($caseRow['application_id'] ?? $applicationId));

    if ($mode === 'status') {
        $compCheck = $pdo->prepare(
            'SELECT 1
               FROM Vati_Payfiller_Case_Components
              WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ?
              LIMIT 1'
        );
        $compCheck->execute([$caseId, $applicationId, $componentKey]);
        $snapshotPresent = !!$compCheck->fetch(PDO::FETCH_ASSOC);
        if (!$snapshotPresent) {
            echo json_encode([
                'status' => 1,
                'message' => 'ok',
                'data' => [
                    'has_previous' => false,
                    'component_supported' => in_array($componentKey, ['education', 'employment'], true),
                    'snapshot_present' => false,
                    'node_thread_id' => null,
                    'node_conversation_id' => null
                ]
            ]);
            exit;
        }

        $st = $pdo->prepare(
            'SELECT id, node_thread_id, node_conversation_id
               FROM verification_communications
              WHERE application_id = ? AND component_key = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $st->execute([$applicationId, $componentKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        echo json_encode([
            'status' => 1,
            'message' => 'ok',
            'data' => [
                'has_previous' => !!$row,
                'node_thread_id' => $row['node_thread_id'] ?? null,
                'node_conversation_id' => $row['node_conversation_id'] ?? null
            ]
        ]);
        exit;
    }

    $compCheck = $pdo->prepare(
        'SELECT 1
           FROM Vati_Payfiller_Case_Components
          WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ?
          LIMIT 1'
    );
    $compCheck->execute([$caseId, $applicationId, $componentKey]);
    if (!$compCheck->fetch(PDO::FETCH_ASSOC)) {
        $available = [];
        try {
            $availSt = $pdo->prepare(
                'SELECT LOWER(TRIM(component_key)) AS component_key
                   FROM Vati_Payfiller_Case_Components
                  WHERE case_id = ? AND application_id = ?
                  ORDER BY component_key'
            );
            $availSt->execute([$caseId, $applicationId]);
            $available = $availSt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $_e) {
            $available = [];
        }
        http_response_code(403);
        echo json_encode([
            'status' => 0,
            'code' => 'COMPONENT_SNAPSHOT_MISSING',
            'message' => 'Component is not part of case snapshot',
            'debug' => [
                'case_id' => $caseId,
                'application_id' => $applicationId,
                'component_key' => $componentKey,
                'allowed_mail_components' => ['education', 'employment'],
                'available_snapshot_components' => $available,
            ],
        ]);
        exit;
    }

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Valid recipient email is required']);
        exit;
    }

    $basicCandidateName = '';
    try {
        $basicSt = $pdo->prepare('SELECT first_name, last_name FROM Vati_Payfiller_Candidate_Basic_details WHERE application_id = ? LIMIT 1');
        $basicSt->execute([$applicationId]);
        $basicRow = $basicSt->fetch(PDO::FETCH_ASSOC) ?: [];
        $basicCandidateName = trim((string)($basicRow['first_name'] ?? '') . ' ' . (string)($basicRow['last_name'] ?? ''));
    } catch (Throwable $e) {
        $basicCandidateName = '';
    }

    $candidateName = trim((string)($caseRow['candidate_first_name'] ?? '') . ' ' . (string)($caseRow['candidate_last_name'] ?? ''));
    if ($candidateName === '') {
        $candidateName = $basicCandidateName;
    }

    $clientName = '';
    $clientId = isset($caseRow['client_id']) ? (int)$caseRow['client_id'] : 0;
    if ($clientId > 0) {
        try {
            $cStmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetClientById(?)');
            $cStmt->execute([$clientId]);
            $cRow = $cStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            while ($cStmt->nextRowset()) {
            }
            if ($cRow) {
                $clientName = trim((string)($cRow['customer_name'] ?? $cRow['client_name'] ?? ''));
            }
        } catch (Throwable $e) {
            $clientName = '';
        }
    }

    $orgName = '';
    if ($componentKey === 'education') {
        $st = $pdo->prepare('SELECT college_name, university_board FROM Vati_Payfiller_Candidate_Education_details WHERE application_id = ? ORDER BY education_index ASC LIMIT 1');
        $st->execute([$applicationId]);
        $rw = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $orgName = trim((string)($rw['college_name'] ?? $rw['university_board'] ?? ''));
    } else {
        $st = $pdo->prepare('SELECT employer_name FROM Vati_Payfiller_Candidate_Employment_details WHERE application_id = ? ORDER BY employment_index ASC LIMIT 1');
        $st->execute([$applicationId]);
        $rw = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $orgName = trim((string)($rw['employer_name'] ?? ''));
    }

    $tpl = svm_template($componentKey, [
        'candidate_name' => $candidateName,
        'application_id' => $applicationId,
        'organization_name' => $orgName,
        'client_name' => $clientName,
        'actor_name' => ($senderName !== '' ? $senderName : ucfirst($senderRole))
    ]);
    $resolvedAction = wc_canonical_action('verification_request', $componentKey);
    $mappedKey = wc_template_key_for_action($resolvedAction, $componentKey);
    $canonicalKey = (string)($mappedKey ?: ($tpl['template_key'] ?? ''));
    $tmplContext = [
        'candidate_name' => $candidateName,
        'client_name' => $clientName,
        'application_id' => $applicationId,
        'component_key' => $componentKey,
        'recipient_name' => $orgName,
        'sender_role' => $senderRole,
        'remarks' => $remarks,
        'organization_name' => $orgName,
        'actor_name' => ($senderName !== '' ? $senderName : ucfirst($senderRole)),
    ];
    $dbTpl = wc_find_template($pdo, $senderRole, $componentKey, $resolvedAction);
    if (!$dbTpl && $canonicalKey !== '') {
        $dbTpl = tmpl_fetch_active_template_by_key($pdo, $canonicalKey, 'email');
    }
    if ($dbTpl) {
        $subMeta = [];
        $bodyMeta = [];
        if ($subject === '') $subject = tmpl_render_text((string)($dbTpl['subject'] ?? ''), $tmplContext, $subMeta);
        if ($messageBody === '') $messageBody = tmpl_render_text((string)($dbTpl['body'] ?? ''), $tmplContext, $bodyMeta);
        if (!empty($subMeta['missing']) || !empty($bodyMeta['missing'])) {
            tmpl_log_warning('verification_template_missing_variables', [
                'template_key' => $canonicalKey,
                'missing_subject' => $subMeta['missing'] ?? [],
                'missing_body' => $bodyMeta['missing'] ?? [],
                'application_id' => $applicationId,
                'component_key' => $componentKey,
            ]);
        }
    } else {
        tmpl_log_warning('verification_template_key_not_found_fallback', [
            'template_key' => $canonicalKey,
            'action' => $resolvedAction,
            'communication_mode' => 'verification',
            'application_id' => $applicationId,
            'case_id' => $caseId,
            'component_key' => $componentKey,
            'sender_role' => $senderRole,
        ]);
        if ($subject === '') $subject = (string)$tpl['subject'];
        if ($messageBody === '') $messageBody = (string)$tpl['body'];
    }

    $existing = $pdo->prepare(
        'SELECT id, node_thread_id, node_conversation_id
           FROM verification_communications
          WHERE application_id = ? AND component_key = ?
          ORDER BY id DESC
          LIMIT 1'
    );
    $existing->execute([$applicationId, $componentKey]);
    $last = $existing->fetch(PDO::FETCH_ASSOC) ?: null;

    $nodePath = trim((string)(env_get('NODE_VERIFICATION_MAIL_PATH', '/api/php/workflow/send-verification-mail') ?? '/api/php/workflow/send-verification-mail'));
    if ($nodePath === '') $nodePath = '/api/php/workflow/send-verification-mail';
    if ($nodePath[0] !== '/') $nodePath = '/' . $nodePath;

    $payload = [
        'case_id' => (string)$caseId,
        'application_id' => (string)$applicationId,
        'component_key' => (string)$componentKey,
        'recipient_email' => (string)$recipientEmail,
        'recipient_name' => (string)$orgName,
        'template_key' => (string)$canonicalKey,
        'sender_role' => (string)$senderRole,
        'sender_user_id' => (string)($senderUserId > 0 ? $senderUserId : 0),
        'remarks' => svm_nullable_string($remarks),
        'subject' => (string)$subject,
        'message_body' => (string)$messageBody,
        'node_thread_id' => svm_nullable_string($last['node_thread_id'] ?? null),
        'node_conversation_id' => svm_nullable_string($last['node_conversation_id'] ?? null)
    ];

    error_log('[send_verification_mail] outbound node payload types: ' . json_encode([
        'case_id_type' => gettype($payload['case_id']),
        'sender_user_id_type' => gettype($payload['sender_user_id']),
        'application_id_type' => gettype($payload['application_id']),
        'component_key_type' => gettype($payload['component_key'])
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $nodeRes = app_node_api_json_request('POST', $nodePath, $payload, 30);
    if (($nodeRes['success'] ?? false) !== true) {
        http_response_code(502);
        echo json_encode(['status' => 0, 'message' => 'Node communication failed', 'error' => (string)($nodeRes['error'] ?? 'Unknown node error')]);
        exit;
    }
    $nodeBody = is_array($nodeRes['response'] ?? null) ? $nodeRes['response'] : [];
    $nodeThreadId = (string)($nodeBody['thread_id']
        ?? $nodeBody['node_thread_id']
        ?? $nodeBody['data']['thread_id']
        ?? $nodeBody['data']['node_thread_id']
        ?? $last['node_thread_id']
        ?? '');
    $nodeConversationId = (string)($nodeBody['conversation_id']
        ?? $nodeBody['node_conversation_id']
        ?? $nodeBody['data']['conversation_id']
        ?? $nodeBody['data']['node_conversation_id']
        ?? $last['node_conversation_id']
        ?? '');
    $status = (string)($nodeBody['status'] ?? 'sent');

    $ins = $pdo->prepare(
        'INSERT INTO verification_communications
            (case_id, application_id, component_key, template_key, recipient_email, node_thread_id, node_conversation_id, communication_status, subject_snapshot, body_snapshot, last_message_at, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())'
    );
    $ins->execute([
        $caseId > 0 ? $caseId : null,
        $applicationId,
        $componentKey,
        $canonicalKey !== '' ? $canonicalKey : null,
        $recipientEmail,
        $nodeThreadId !== '' ? $nodeThreadId : null,
        $nodeConversationId !== '' ? $nodeConversationId : null,
        $status !== '' ? $status : 'sent',
        $subject,
        $messageBody,
        $senderUserId > 0 ? $senderUserId : null
    ]);
    $verificationCommId = (int)$pdo->lastInsertId();

    try {
        wc_ensure_tables($pdo);
        $senderNameResolved = $senderName !== '' ? $senderName : null;
        $threadOwnerRole = wc_norm_thread_owner_role($senderRole);
        $threadId = wc_build_thread_id($applicationId, $componentKey, $threadOwnerRole);
        $messageId = 'verification.' . $applicationId . '.' . $verificationCommId . '@local';
        $sourceKey = 'verification_comm:' . $verificationCommId;
        $wcIns = $pdo->prepare(
            'INSERT IGNORE INTO workflow_communications
             (application_id, case_id, component_key, role_key, action_key, subject, body, sent_by_user_id, sent_by_name, sent_at,
              delivery_status, communication_type, direction, actor_role, actor_name, workflow_stage, source_table, source_message_key,
              thread_id, thread_owner_role, thread_scope, root_outgoing_communication_id, message_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $wcIns->execute([
            $applicationId,
            $caseId > 0 ? $caseId : null,
            $componentKey,
            $senderRole,
            'verification_request',
            $subject,
            $messageBody,
            $senderUserId > 0 ? $senderUserId : null,
            $senderNameResolved,
            svm_normalize_delivery_status($status),
            'verification_request',
            'outgoing',
            $senderRole,
            $senderNameResolved,
            $senderRole,
            'verification_communications',
            $sourceKey,
            $threadId,
            $threadOwnerRole,
            'component_role',
            null,
            $messageId
        ]);
        $canonicalCommunicationId = (int)$pdo->lastInsertId();
        if ($canonicalCommunicationId > 0) {
            $up = $pdo->prepare('UPDATE workflow_communications
                                    SET root_outgoing_communication_id = ?
                                  WHERE communication_id = ?
                                    AND COALESCE(root_outgoing_communication_id, 0) = 0');
            $up->execute([$canonicalCommunicationId, $canonicalCommunicationId]);
        }
        wc_thread_upsert($pdo, $applicationId, $caseId, $threadId, trim($messageId, '<> '), trim($messageId, '<> '));
    } catch (Throwable $e) {
        wc_log_ingest_event($pdo, 'verification_sync', $applicationId, $caseId, 'error', 0, 0, 0, 0, 'send_verification_mail canonical mirror failed: ' . $e->getMessage());
    }

    $timelineMsg = $componentKey === 'education'
        ? 'Verification mail sent to institution'
        : 'Verification mail sent to employer';
    $tl = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $tl->execute([
        $applicationId,
        $senderUserId > 0 ? $senderUserId : null,
        $senderRole,
        'update',
        $componentKey,
        $timelineMsg . ($remarks !== '' ? (' | remarks: ' . $remarks) : '')
    ]);

    echo json_encode([
        'status' => 1,
        'message' => 'Mail sent',
        'data' => [
            'node_thread_id' => $nodeThreadId !== '' ? $nodeThreadId : null,
            'node_conversation_id' => $nodeConversationId !== '' ? $nodeConversationId : null,
            'communication_status' => $status !== '' ? $status : 'sent',
            'resend' => !!$last
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
