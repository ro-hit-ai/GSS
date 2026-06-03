<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/workflow_status_semantics.php';
require_once __DIR__ . '/workflow_communication_service.php';

function car_session_role_norm(): string {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $role = strtolower(trim((string)($_SESSION['auth_moduleAccess'] ?? $_SESSION['auth_role'] ?? $_SESSION['role'] ?? '')));
    if ($role === 'customer_admin') return 'client_admin';
    if ($role === 'component verifier' || $role === 'component_verifier' || $role === 'db verifier' || $role === 'db-verifier') return 'verifier';
    if ($role === 'component validator' || $role === 'component_validator') return 'validator';
    if ($role === 'team lead' || $role === 'team_lead') return 'qa';
    if ($role === 'gss admin') return 'gss_admin';
    return $role;
}

function car_new_token(): string {
    try { return bin2hex(random_bytes(16)); } catch (Throwable $e) {}
    return bin2hex(openssl_random_pseudo_bytes(16));
}

function car_ensure_resend_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS Vati_Payfiller_Candidate_Access_Resend_Events (
        resend_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id VARCHAR(128) NOT NULL,
        case_id BIGINT NOT NULL,
        application_id VARCHAR(64) NOT NULL,
        resent_by_user_id BIGINT NULL,
        resent_by_role VARCHAR(64) NULL,
        reason TEXT NULL,
        invite_token VARCHAR(64) NOT NULL,
        invite_url VARCHAR(500) NOT NULL,
        email_sent TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (resend_event_id),
        UNIQUE KEY uq_car_req (request_id),
        KEY idx_car_case_time (case_id, created_at),
        KEY idx_car_app_time (application_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function car_is_case_terminal(string $caseStatus): bool {
    $s = strtoupper(trim($caseStatus));
    return in_array($s, ['APPROVED', 'COMPLETED', 'CLEAR', 'ARCHIVED', 'STOP_BGV', 'TERMINATED'], true);
}

function car_has_operationally_active_component(PDO $pdo, int $caseId): bool {
    $st = $pdo->prepare(
        "SELECT 1
         FROM Vati_Payfiller_Case_Component_Workflow w
         JOIN Vati_Payfiller_Case_Components c
           ON c.case_id = w.case_id
          AND LOWER(TRIM(c.component_key)) = LOWER(TRIM(w.component_key))
         WHERE w.case_id = ?
           AND c.is_required = 1
           AND LOWER(TRIM(c.component_key)) <> 'reports'
           AND LOWER(TRIM(w.status)) IN ('hold','insufficient_documents','waiting_candidate','reopened','blocked')
         LIMIT 1"
    );
    $st->execute([$caseId]);
    return (bool)$st->fetchColumn();
}

function car_user_has_verifier_visibility(PDO $pdo, int $caseId, int $userId): bool {
    if ($userId <= 0) return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM Vati_Payfiller_Case_Components WHERE case_id = ? AND assigned_role = 'verifier' AND assigned_user_id = ? LIMIT 1");
        $st->execute([$caseId, $userId]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) {}
    try {
        $st2 = $pdo->prepare("SELECT 1 FROM Vati_Payfiller_Verifier_Group_Queue WHERE case_id = ? AND assigned_user_id = ? LIMIT 1");
        $st2->execute([$caseId, $userId]);
        return (bool)$st2->fetchColumn();
    } catch (Throwable $e) {}
    return false;
}

function car_user_has_validator_visibility(PDO $pdo, int $caseId, int $userId): bool {
    if ($userId <= 0) return false;
    try {
        $sp = $pdo->prepare('CALL SP_Vati_Payfiller_ReportCheckValidatorAssignment(?, ?)');
        $sp->execute([$caseId, $userId]);
        $ok = (bool)$sp->fetchColumn();
        while ($sp->nextRowset()) {}
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function car_log_timeline(PDO $pdo, string $applicationId, int $actorUserId, string $role, string $msg): void {
    $st = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $st->execute([$applicationId, $actorUserId > 0 ? $actorUserId : null, $role !== '' ? $role : null, 'action', 'candidate_access', $msg]);
}

function car_log_workflow_communication(PDO $pdo, int $caseId, string $applicationId, string $reason, int $userId, string $userName, string $role, string $inviteUrl): void {
    try {
        wc_ensure_tables($pdo);
        $threadId = 'app:' . strtolower($applicationId);
        $messageId = 'wc.' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $applicationId)) . '.' . bin2hex(random_bytes(8)) . '@payfiller.com';
        $subject = 'Background Verification - Candidate Access Resent';
        $body = 'Candidate access resent.' . ($reason !== '' ? (' | reason: ' . $reason) : '') . ' | invite_url: ' . $inviteUrl;
        $requestId = 'car-wc-' . $applicationId . '-' . $caseId . '-' . md5($role . '|' . $reason . '|' . $inviteUrl);
        $st = $pdo->prepare("INSERT IGNORE INTO Vati_Payfiller_Workflow_Communications
            (application_id, case_id, component_key, role_key, action_key, subject, body, notes, sent_by_user_id, sent_by_name, sent_at, delivery_status, communication_type, direction, actor_role, actor_name, workflow_stage, request_id, message_id, thread_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'sent', ?, 'outgoing', ?, ?, ?, ?, ?, ?)");
        $st->execute([
            $applicationId,
            $caseId > 0 ? $caseId : null,
            'candidate_access',
            $role,
            'candidate_access_resend',
            $subject,
            $body,
            $reason !== '' ? $reason : null,
            $userId > 0 ? $userId : null,
            $userName !== '' ? $userName : null,
            'candidate_access_resend',
            $role,
            $userName !== '' ? $userName : null,
            $role,
            $requestId,
            $messageId,
            $threadId
        ]);
        wc_thread_upsert($pdo, $applicationId, $caseId, $threadId, $messageId, $messageId);
    } catch (Throwable $e) {
    }
}

function car_run_resend(PDO $pdo, array $in, string $sessionRole, int $sessionUserId, int $sessionClientId): array {
    car_ensure_resend_table($pdo);
    $role = strtolower(trim((string)($in['role'] ?? $sessionRole)));
    if ($role === '') $role = $sessionRole;
    $requestId = trim((string)($in['request_id'] ?? ''));
    $reason = trim((string)($in['reason'] ?? ''));
    $caseId = (int)($in['case_id'] ?? 0);
    $applicationId = trim((string)($in['application_id'] ?? ''));
    if ($caseId <= 0 && $applicationId === '') {
        return ['http' => 400, 'status' => 0, 'message' => 'case_id or application_id is required'];
    }
    if ($requestId === '') {
        $requestId = 'car-' . ($caseId > 0 ? $caseId : $applicationId) . '-' . $sessionUserId . '-' . time();
    }

    $dup = $pdo->prepare('SELECT resend_event_id, invite_url, email_sent FROM Vati_Payfiller_Candidate_Access_Resend_Events WHERE request_id = ? LIMIT 1');
    $dup->execute([$requestId]);
    $old = $dup->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($old) {
        return ['http' => 200, 'status' => 1, 'message' => 'Already processed', 'data' => ['resend_event_id' => (int)$old['resend_event_id'], 'invite_url' => (string)$old['invite_url'], 'email_sent' => (int)$old['email_sent']]];
    }

    if ($caseId <= 0) {
        $s = $pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $s->execute([$applicationId]);
        $caseId = (int)($s->fetchColumn() ?: 0);
    }
    $st = $pdo->prepare('SELECT case_id, application_id, client_id, case_status, candidate_email, candidate_first_name, candidate_last_name FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
    $st->execute([$caseId]);
    $case = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$case) return ['http' => 404, 'status' => 0, 'message' => 'Case not found'];
    $applicationId = trim((string)$case['application_id']);
    $caseStatus = (string)($case['case_status'] ?? '');
    $caseClientId = (int)($case['client_id'] ?? 0);

    if (!in_array($role, ['client_admin', 'verifier', 'qa', 'gss_admin'], true)) {
        return ['http' => 403, 'status' => 0, 'message' => 'Forbidden role'];
    }

    if ($role === 'client_admin' && ($sessionClientId <= 0 || $sessionClientId !== $caseClientId)) {
        return ['http' => 403, 'status' => 0, 'message' => 'Forbidden'];
    }
    if ($role === 'verifier' && !car_user_has_verifier_visibility($pdo, $caseId, $sessionUserId)) {
        return ['http' => 403, 'status' => 0, 'message' => 'Verifier visibility missing'];
    }

    $active = car_has_operationally_active_component($pdo, $caseId);
    $awaitingCandidate = false;
    try {
        $sa = $pdo->prepare('SELECT LOWER(TRIM(COALESCE(status, \'\'))) FROM Vati_Payfiller_Candidate_Applications WHERE application_id = ? LIMIT 1');
        $sa->execute([$applicationId]);
        $appStatus = (string)($sa->fetchColumn() ?: '');
        $awaitingCandidate = in_array($appStatus, ['waiting_candidate', 'reopened'], true) || in_array(strtoupper(trim($caseStatus)), ['PENDING_CANDIDATE', 'CANDIDATE_PENDING'], true);
    } catch (Throwable $e) {}

    if (car_is_case_terminal($caseStatus)) {
        return ['http' => 409, 'status' => 0, 'message' => 'Case is already closed'];
    }
    if (!$active && !$awaitingCandidate && $role !== 'gss_admin' && $role !== 'client_admin') {
        return ['http' => 409, 'status' => 0, 'message' => 'Resend is allowed only for active unresolved candidate action states'];
    }

    // Cooldown: prevent rapid duplicates for same case/actor (30 seconds)
    $cool = $pdo->prepare('SELECT 1 FROM Vati_Payfiller_Candidate_Access_Resend_Events WHERE case_id = ? AND resent_by_user_id <=> ? AND created_at >= (NOW() - INTERVAL 30 SECOND) LIMIT 1');
    $cool->execute([$caseId, $sessionUserId > 0 ? $sessionUserId : null]);
    if ($cool->fetchColumn()) {
        return ['http' => 429, 'status' => 0, 'message' => 'Please wait before resending again'];
    }

    $token = car_new_token();
    $sp = $pdo->prepare('CALL SP_Vati_Payfiller_SetCaseInvite(?, ?)');
    $sp->execute([$caseId, $token]);
    $row = $sp->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($sp->nextRowset()) {}
    $affected = (int)($row['affected_rows'] ?? 0);
    if ($affected <= 0) {
        return ['http' => 400, 'status' => 0, 'message' => 'Invite token could not be saved'];
    }

    $inviteUrl = app_url('/modules/candidate/login.php?token=' . urlencode($token));
    $to = trim((string)($case['candidate_email'] ?? ''));
    $candidateName = trim((string)($case['candidate_first_name'] ?? '') . ' ' . (string)($case['candidate_last_name'] ?? ''));
    $subject = 'Background Verification - Candidate Access Resent';
    $safeName = htmlspecialchars($candidateName);
    $safeUrl = htmlspecialchars($inviteUrl);
    $body = ''
        . '<div style="font-family:Arial, sans-serif; font-size:14px; color:#0f172a; line-height:1.5;">'
        . '<p>Hello ' . $safeName . ',</p>'
        . '<p>Your Background Verification access has been resent. Please continue your submission.</p>'
        . '<p><a href="' . $safeUrl . '" style="display:inline-block; padding:10px 14px; background:#2563eb; color:#fff; text-decoration:none; border-radius:10px; font-weight:700;">Open Candidate Portal</a></p>'
        . '<p style="font-size:12px; color:#64748b;">If button fails, copy this link:<br><span style="word-break:break-all;">' . $safeUrl . '</span></p>'
        . '<p>Thanks,<br>VATI GSS</p>'
        . '</div>';
    $sent = false;
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        app_mail_set_log_meta([
            'application_id' => $applicationId,
            'case_id' => $caseId,
            'event_type' => 'candidate.access.resend',
            'role' => $role
        ]);
        $sent = send_app_mail($to, $subject, $body, 'VATI GSS', ['application_id' => $applicationId, 'event_type' => 'candidate.access.resend']);
        app_mail_clear_log_meta();
    }

    $ins = $pdo->prepare('INSERT INTO Vati_Payfiller_Candidate_Access_Resend_Events (request_id, case_id, application_id, resent_by_user_id, resent_by_role, reason, invite_token, invite_url, email_sent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $ins->execute([
        $requestId, $caseId, $applicationId, $sessionUserId > 0 ? $sessionUserId : null, $role, ($reason !== '' ? $reason : null),
        $token, $inviteUrl, $sent ? 1 : 0
    ]);
    $eventId = (int)$pdo->lastInsertId();

    $cntQ = $pdo->prepare('SELECT COUNT(*) FROM Vati_Payfiller_Candidate_Access_Resend_Events WHERE case_id = ?');
    $cntQ->execute([$caseId]);
    $resendCount = (int)$cntQ->fetchColumn();
    car_log_timeline($pdo, $applicationId, $sessionUserId, $role, 'Candidate Access Resent | role: ' . strtoupper($role) . ($reason !== '' ? (' | reason: ' . $reason) : ''));
    car_log_workflow_communication($pdo, $caseId, $applicationId, $reason, $sessionUserId, trim((string)($_SESSION['auth_user_name'] ?? '')), $role, $inviteUrl);

    return [
        'http' => 200,
        'status' => 1,
        'message' => $sent ? 'Candidate access resent successfully.' : 'Invite saved. Email sending not configured.',
        'data' => [
            'resend_event_id' => $eventId,
            'case_id' => $caseId,
            'application_id' => $applicationId,
            'invite_token' => $token,
            'invite_url' => $inviteUrl,
            'email_sent' => $sent ? 1 : 0,
            'resend_count' => $resendCount,
            'thread_id' => null
        ]
    ];
}
