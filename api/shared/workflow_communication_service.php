<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/template_governance.php';

function wc_norm_role(string $role): string {
    $r = strtolower(trim($role));
    if ($r === 'component verifier' || $r === 'component_verifier') return 'verifier';
    if ($r === 'db verifier' || $r === 'db-verifier' || $r === 'db_verifier') return 'verifier';
    if ($r === 'component validator' || $r === 'component_validator') return 'validator';
    if ($r === 'team lead' || $r === 'team_lead') return 'team_lead';
    return $r;
}

function wc_norm_thread_owner_role(string $role): string {
    $r = wc_norm_role($role);
    if ($r === 'team_lead') return 'qa';
    return $r;
}

function wc_build_thread_id(string $applicationId, string $componentKey, string $ownerRole): string {
    $app = strtolower(trim($applicationId));
    $component = strtolower(trim($componentKey));
    $owner = strtolower(trim(wc_norm_thread_owner_role($ownerRole)));
    if ($app === '') $app = 'unknown-app';
    if ($component === '') $component = 'timeline';
    if ($owner === '') $owner = 'system';
    return 'wf:' . $app . ':' . $component . ':' . $owner;
}

function wc_session_role(): string {
    auth_session_start();
    $role = wc_norm_role((string)($_SESSION['auth_moduleAccess'] ?? ''));
    if ($role === '') $role = wc_norm_role((string)($_SESSION['auth_role'] ?? ''));
    if ($role === '') $role = wc_norm_role((string)($_SESSION['role'] ?? ''));
    return $role;
}

function wc_resolve_application_id(PDO $pdo, string $applicationId, int $caseId): string {
    $applicationId = trim($applicationId);
    if ($applicationId !== '') return $applicationId;
    if ($caseId <= 0) return '';
    $st = $pdo->prepare('CALL SP_Vati_Payfiller_ReportResolveApplicationId(?)');
    $st->execute([$caseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    while ($st->nextRowset()) {}
    return $row ? trim((string)($row['application_id'] ?? '')) : '';
}

function wc_read_case_bundle(PDO $pdo, string $applicationId): array {
    $bundle = $pdo->prepare('CALL SP_Vati_Payfiller_ReportBundle(?)');
    $bundle->execute([$applicationId]);
    $case = $bundle->fetch(PDO::FETCH_ASSOC) ?: [];
    $bundle->nextRowset();
    $application = $bundle->fetch(PDO::FETCH_ASSOC) ?: [];
    $bundle->nextRowset();
    $basic = $bundle->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($bundle->nextRowset()) {}
    return [$case, $application, $basic];
}

function wc_component_checklist_map(): array {
    return [
        'employment' => ['Relieving Letter', 'Salary Slips', 'Offer Letter', 'UAN Mismatch Clarification', 'HR Details', 'Overlap Clarification'],
        'education' => ['Marksheets', 'Degree Certificate', 'Provisional Certificate', 'University Clarification'],
        'id' => ['Aadhaar Mismatch', 'PAN Mismatch', 'Blurred Document', 'DOB Mismatch'],
        'contact' => ['Proof Missing', 'Address Mismatch', 'Utility Bill Expired'],
        'reference' => ['Reference Unreachable', 'Incomplete Reference Details'],
        'socialmedia' => ['Profile Clarification', 'Content Clarification', 'Consent Confirmation'],
        'basic' => ['Name Clarification', 'DOB Clarification', 'Identity Consistency'],
        'ecourt' => ['Address Period Clarification', 'Court Match Clarification'],
        'reports' => ['Authorization File', 'Signature Clarification']
    ];
}

function wc_action_catalog(string $role): array {
    $base = [
        ['action' => 'insufficient_documents', 'label' => 'Missing Documents'],
        ['action' => 'clarification_required', 'label' => 'Clarification Required'],
        ['action' => 'hold', 'label' => 'Verification Hold'],
        ['action' => 'rejected', 'label' => 'Rejected'],
        ['action' => 'reopen_request', 'label' => 'Reopen Request'],
        ['action' => 'escalation', 'label' => 'Escalation'],
        ['action' => 'additional_proof_required', 'label' => 'Additional Proof Required'],
    ];
    if ($role === 'qa' || $role === 'team_lead') {
        return $base;
    }
    if ($role === 'validator') {
        return array_values(array_filter($base, function ($a) {
            return in_array($a['action'], ['insufficient_documents', 'clarification_required', 'hold', 'rejected', 'additional_proof_required'], true);
        }));
    }
    return $base;
}

function wc_canonical_action(string $action, string $component = ''): string {
    $a = strtolower(trim($action));
    $c = strtolower(trim($component));
    if ($a === 'need_docs' || $a === 'need docs' || $a === 'missing_documents') return 'insufficient_documents';
    if ($a === 'hold_mail' || $a === 'verification_hold') return 'hold';
    if ($a === 'verification_rejected') return 'rejected';
    if ($a === 'candidate_correction_mail') return 'candidate_correction';
    if ($a === 'send_mail' || $a === 'resend_mail' || $a === 'verification_request') {
        if ($c === 'education') return 'verification_request_education';
        if ($c === 'employment') return 'verification_request_employment';
        return 'verification_request';
    }
    return $a;
}

function wc_template_key_for_action(string $action, string $component = ''): ?string {
    $a = wc_canonical_action($action, $component);
    if ($a === 'insufficient_documents') return 'candidate_missing_docs';
    if ($a === 'clarification_required') return 'clarification_required';
    if ($a === 'hold') return 'verification_hold';
    if ($a === 'rejected') return 'verification_rejected';
    if ($a === 'candidate_correction') return 'candidate_correction';
    if ($a === 'verification_completed') return 'verification_completed';
    if ($a === 'verification_request_education') return 'education_verification';
    if ($a === 'verification_request_employment') return 'employment_verification';
    return null;
}

function wc_template_candidates(string $role, string $component, string $action): array {
    $role = strtoupper(trim($role));
    $component = strtoupper(trim($component));
    $action = strtoupper(trim($action));
    $compPrefix = preg_replace('/[^A-Z0-9]+/', '_', $component);
    $actPrefix = preg_replace('/[^A-Z0-9]+/', '_', $action);
    return [
        $role . '_' . $compPrefix . '_' . $actPrefix,
        $compPrefix . '_' . $actPrefix,
        $role . '_' . $actPrefix,
        $actPrefix,
    ];
}

function wc_find_template(PDO $pdo, string $role, string $component, string $action): ?array {
    $role = strtolower(trim($role));
    $component = strtolower(trim($component));
    $action = wc_canonical_action($action, $component);
    $keys = [];
    $mapped = wc_template_key_for_action($action, $component);
    if ($mapped) $keys[] = $mapped;
    if ($action === 'additional_proof_required') $keys[] = 'additional_proof_required';
    $keys[] = $role . '_' . $component . '_' . $action;
    $keys[] = $component . '_' . $action;
    $keys[] = $role . '_' . $action;
    $keys[] = $action;

    $seen = [];
    foreach ($keys as $k) {
        $nk = tmpl_normalize_key($k);
        if ($nk === '' || isset($seen[$nk])) continue;
        $seen[$nk] = true;
        $tpl = tmpl_fetch_active_template_by_key($pdo, $nk, 'email');
        if ($tpl) return $tpl;
    }
    tmpl_log_warning('template_key_not_found', [
        'communication_mode' => 'workflow',
        'role' => $role,
        'component' => $component,
        'action' => $action,
        'keys' => array_keys($seen),
    ]);
    return null;
}

function wc_render_placeholders(string $tpl, array $map): string {
    $meta = [];
    $out = tmpl_render_text($tpl, $map, $meta);
    if (!empty($meta['missing'])) {
        tmpl_log_warning('unresolved_placeholders', [
            'missing' => array_values($meta['missing']),
            'template_preview' => mb_substr((string)$tpl, 0, 200),
        ]);
    }
    return $out;
}

function wc_format_html(string $body): string {
    if (preg_match('/<\s*[a-zA-Z][^>]*>/', $body)) return $body;
    $safe = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<div style="font-family:Arial,sans-serif;font-size:13px;line-height:1.45;color:#0f172a;">' . nl2br($safe) . '</div>';
}

function wc_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS Vati_Payfiller_Workflow_Communications (
        communication_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        application_id VARCHAR(64) NOT NULL,
        case_id BIGINT NULL,
        component_key VARCHAR(64) NOT NULL,
        role_key VARCHAR(32) NOT NULL,
        action_key VARCHAR(64) NOT NULL,
        template_id BIGINT NULL,
        subject VARCHAR(500) NOT NULL,
        body MEDIUMTEXT NULL,
        checklist_json JSON NULL,
        notes TEXT NULL,
        deadline_label VARCHAR(64) NULL,
        sent_by_user_id BIGINT NULL,
        sent_by_name VARCHAR(255) NULL,
        sent_at DATETIME NOT NULL,
        delivery_status VARCHAR(32) NOT NULL DEFAULT 'sent',
        workflow_version INT NULL,
        communication_type VARCHAR(64) NULL,
        direction VARCHAR(16) NOT NULL DEFAULT 'outgoing',
        actor_role VARCHAR(64) NULL,
        actor_name VARCHAR(255) NULL,
        workflow_stage VARCHAR(32) NULL,
        request_id VARCHAR(128) NULL,
        parent_communication_id BIGINT NULL,
        source_table VARCHAR(64) NULL,
        source_message_key VARCHAR(191) NULL,
        PRIMARY KEY (communication_id),
        KEY idx_wc_app (application_id),
        KEY idx_wc_case (case_id),
        KEY idx_wc_component (component_key),
        KEY idx_wc_sent_at (sent_at),
        KEY idx_wc_dir (direction),
        KEY idx_wc_req (request_id),
        UNIQUE KEY uq_wc_src (application_id, direction, source_table, source_message_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS Vati_Payfiller_Workflow_Mail_Threads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        application_id VARCHAR(64) NOT NULL,
        case_id BIGINT NULL,
        workflow_thread_id VARCHAR(128) NULL,
        root_message_id VARCHAR(255) NULL,
        latest_message_id VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_wmt_app_thread (application_id, workflow_thread_id),
        KEY idx_wmt_root_msg (root_message_id),
        KEY idx_wmt_latest_msg (latest_message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS Vati_Payfiller_Workflow_Mail_Ingest_Events (
        ingest_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source_key VARCHAR(64) NOT NULL,
        application_id VARCHAR(64) NULL,
        case_id BIGINT NULL,
        event_status VARCHAR(32) NOT NULL DEFAULT 'ok',
        inserted_count INT NOT NULL DEFAULT 0,
        duplicate_count INT NOT NULL DEFAULT 0,
        skipped_count INT NOT NULL DEFAULT 0,
        unmatched_count INT NOT NULL DEFAULT 0,
        note TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ingest_event_id),
        KEY idx_wmie_source_time (source_key, created_at),
        KEY idx_wmie_app_time (application_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS Vati_Payfiller_Workflow_Mail_Runtime_State (
        source_key VARCHAR(64) NOT NULL,
        application_id VARCHAR(64) NULL,
        case_id BIGINT NULL,
        last_status VARCHAR(32) NOT NULL DEFAULT 'ok',
        last_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        inserted_count INT NOT NULL DEFAULT 0,
        duplicate_count INT NOT NULL DEFAULT 0,
        skipped_count INT NOT NULL DEFAULT 0,
        unmatched_count INT NOT NULL DEFAULT 0,
        note TEXT NULL,
        PRIMARY KEY (source_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    wc_ensure_columns($pdo);
}

function wc_ensure_columns(PDO $pdo): void {
    $table = 'Vati_Payfiller_Workflow_Communications';
    $need = [
        'communication_type' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN communication_type VARCHAR(64) NULL AFTER workflow_version",
        'direction' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN direction VARCHAR(16) NOT NULL DEFAULT 'outgoing' AFTER communication_type",
        'actor_role' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN actor_role VARCHAR(64) NULL AFTER direction",
        'actor_name' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN actor_name VARCHAR(255) NULL AFTER actor_role",
        'workflow_stage' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN workflow_stage VARCHAR(32) NULL AFTER actor_name",
        'request_id' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN request_id VARCHAR(128) NULL AFTER workflow_stage",
        'parent_communication_id' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN parent_communication_id BIGINT NULL AFTER request_id",
        'source_table' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN source_table VARCHAR(64) NULL AFTER parent_communication_id",
        'source_message_key' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN source_message_key VARCHAR(191) NULL AFTER source_table",
        'message_id' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN message_id VARCHAR(255) NULL AFTER source_message_key",
        'in_reply_to' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN in_reply_to VARCHAR(255) NULL AFTER message_id",
        'references_header' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN references_header TEXT NULL AFTER in_reply_to",
        'thread_id' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN thread_id VARCHAR(128) NULL AFTER references_header",
        'thread_owner_role' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN thread_owner_role VARCHAR(32) NULL AFTER thread_id",
        'thread_scope' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN thread_scope VARCHAR(32) NULL AFTER thread_owner_role",
        'root_outgoing_communication_id' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN root_outgoing_communication_id BIGINT NULL AFTER thread_scope",
        'mailbox_uid' => "ALTER TABLE Vati_Payfiller_Workflow_Communications ADD COLUMN mailbox_uid VARCHAR(128) NULL AFTER thread_id",
    ];
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    foreach ($need as $col => $sql) {
        try {
            $st->execute([$table, $col]);
            if (!$st->fetchColumn()) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
        }
    }
    try { $pdo->exec('CREATE INDEX idx_wc_dir ON Vati_Payfiller_Workflow_Communications(direction)'); } catch (Throwable $e) {}
    try { $pdo->exec('CREATE INDEX idx_wc_req ON Vati_Payfiller_Workflow_Communications(request_id)'); } catch (Throwable $e) {}
    try { $pdo->exec('CREATE INDEX idx_wc_mid ON Vati_Payfiller_Workflow_Communications(message_id)'); } catch (Throwable $e) {}
    try { $pdo->exec('CREATE INDEX idx_wc_tid ON Vati_Payfiller_Workflow_Communications(thread_id)'); } catch (Throwable $e) {}
    try { $pdo->exec('CREATE INDEX idx_wc_tor ON Vati_Payfiller_Workflow_Communications(thread_owner_role)'); } catch (Throwable $e) {}
    try { $pdo->exec('CREATE INDEX idx_wc_uid ON Vati_Payfiller_Workflow_Communications(mailbox_uid)'); } catch (Throwable $e) {}
    try { $pdo->exec('CREATE UNIQUE INDEX uq_wc_src ON Vati_Payfiller_Workflow_Communications(application_id, direction, source_table, source_message_key)'); } catch (Throwable $e) {}
    try { $pdo->exec('CREATE UNIQUE INDEX uq_wc_msgid ON Vati_Payfiller_Workflow_Communications(message_id)'); } catch (Throwable $e) {}
}

function wc_resolve_replies_table(PDO $pdo): string {
    $candidates = ['Vati_Payfiller_GSS_Email_Replies', 'email_replies'];
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    foreach ($candidates as $table) {
        $stmt->execute([$table]);
        if ($stmt->fetchColumn()) return $table;
    }
    return '';
}

function wc_resolve_reply_columns(PDO $pdo, string $table): array {
    if ($table === '') return ['ok' => false];
    $st = $pdo->query('DESCRIBE `' . str_replace('`', '``', $table) . '`');
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $have = [];
    foreach ($rows as $row) {
        $name = isset($row['Field']) ? strtolower(trim((string)$row['Field'])) : '';
        if ($name !== '') $have[$name] = true;
    }
    $pick = function (array $choices) use ($have): string {
        foreach ($choices as $c) {
            $k = strtolower(trim((string)$c));
            if ($k !== '' && isset($have[$k])) return $k;
        }
        return '';
    };
    $senderCol = $pick(['sender', 'from_email', 'from_address', 'sender_email', 'sender_name', 'from_name']);
    $messageCol = $pick(['message', 'body', 'reply_text', 'reply_message', 'content', 'mail_body']);
    $createdCol = $pick(['created_at', 'received_at', 'reply_at', 'created_on', 'timestamp']);
    if (!isset($have['application_id']) || $senderCol === '' || $messageCol === '' || $createdCol === '') {
        return ['ok' => false];
    }
    $subjectCol = $pick(['subject', 'mail_subject', 'email_subject']);
    $messageIdCol = $pick(['message_id', 'messageid']);
    $inReplyToCol = $pick(['in_reply_to', 'inreplyto']);
    $referencesCol = $pick(['references_header', 'references', 'mail_references']);
    $mailboxUidCol = $pick(['mailbox_uid', 'uid', 'imap_uid']);
    $threadIdCol = $pick(['thread_id', 'workflow_thread_id']);
    return [
        'ok' => true,
        'sender' => $senderCol,
        'message' => $messageCol,
        'created_at' => $createdCol,
        'subject' => $subjectCol,
        'message_id' => $messageIdCol,
        'in_reply_to' => $inReplyToCol,
        'references_header' => $referencesCol,
        'mailbox_uid' => $mailboxUidCol,
        'thread_id' => $threadIdCol,
    ];
}

function wc_norm_msg_id(?string $v): string {
    $s = trim((string)$v);
    if ($s === '') return '';
    $s = trim($s, "<> \t\r\n");
    return strtolower($s);
}

function wc_extract_msg_ids(string $refs): array {
    $refs = trim($refs);
    if ($refs === '') return [];
    preg_match_all('/<([^>]+)>/', $refs, $m);
    $ids = [];
    if (!empty($m[1]) && is_array($m[1])) {
        foreach ($m[1] as $id) {
            $n = wc_norm_msg_id((string)$id);
            if ($n !== '') $ids[$n] = true;
        }
    } else {
        foreach (preg_split('/\s+/', $refs) as $part) {
            $n = wc_norm_msg_id((string)$part);
            if ($n !== '') $ids[$n] = true;
        }
    }
    return array_keys($ids);
}

function wc_first_non_empty_string(...$values): string {
    foreach ($values as $value) {
        $s = trim((string)$value);
        if ($s !== '') return $s;
    }
    return '';
}

function wc_first_positive_int(...$values): int {
    foreach ($values as $value) {
        $n = (int)$value;
        if ($n > 0) return $n;
    }
    return 0;
}

function wc_try_thread_by_headers(PDO $pdo, string $inReplyTo, string $references): array {
    $ids = [];
    if ($inReplyTo !== '') $ids[] = wc_norm_msg_id($inReplyTo);
    $ids = array_merge($ids, wc_extract_msg_ids($references));
    $ids = array_values(array_unique(array_filter($ids, static function ($x) { return $x !== ''; })));
    if (!$ids) return ['application_id' => '', 'case_id' => 0, 'thread_id' => '', 'component_key' => '', 'thread_owner_role' => '', 'root_outgoing_communication_id' => 0];

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $attempts = [
        "SELECT application_id, case_id, thread_id, component_key, thread_owner_role, root_outgoing_communication_id
           FROM Vati_Payfiller_Workflow_Communications
          WHERE message_id IN ($ph)
            AND direction = 'outgoing'
          ORDER BY communication_id DESC
          LIMIT 1",
        "SELECT application_id, case_id, thread_id, component_key, thread_owner_role, root_outgoing_communication_id
           FROM Vati_Payfiller_Workflow_Communications
          WHERE message_id IN ($ph)
            AND COALESCE(thread_owner_role, '') <> ''
          ORDER BY communication_id DESC
          LIMIT 1",
        "SELECT application_id, case_id, thread_id, component_key, thread_owner_role, root_outgoing_communication_id
           FROM Vati_Payfiller_Workflow_Communications
          WHERE message_id IN ($ph)
          ORDER BY communication_id DESC
          LIMIT 1",
    ];
    foreach ($attempts as $sql) {
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            continue;
        }
        return [
            'application_id' => trim((string)($row['application_id'] ?? '')),
            'case_id' => (int)($row['case_id'] ?? 0),
            'thread_id' => trim((string)($row['thread_id'] ?? '')),
            'component_key' => strtolower(trim((string)($row['component_key'] ?? ''))),
            'thread_owner_role' => wc_norm_thread_owner_role((string)($row['thread_owner_role'] ?? '')),
            'root_outgoing_communication_id' => (int)($row['root_outgoing_communication_id'] ?? 0),
        ];
    }
    return ['application_id' => '', 'case_id' => 0, 'thread_id' => '', 'component_key' => '', 'thread_owner_role' => '', 'root_outgoing_communication_id' => 0];
}

function wc_normalize_delivery_status(string $value): string
{
    $v = strtolower(trim($value));
    if ($v === '' || $v === '1' || $v === 'true' || $v === 'success' || $v === 'ok') return 'sent';
    if ($v === '0' || $v === 'false' || $v === 'failed' || $v === 'error') return 'failed';
    return $v;
}

function wc_reply_component_from_subject(string $subject, string $message = ''): string
{
    $haystack = strtolower(trim($subject . ' ' . $message));
    if ($haystack === '') return '';

    $map = [
        'basic' => ['basic details', 'basic'],
        'id' => ['identification', 'id'],
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

function wc_try_thread_by_subject(PDO $pdo, string $applicationId, string $subject, string $message = ''): array
{
    $applicationId = trim($applicationId);
    if ($applicationId === '') {
        return ['application_id' => '', 'case_id' => 0, 'thread_id' => '', 'component_key' => '', 'thread_owner_role' => '', 'root_outgoing_communication_id' => 0];
    }

    $normalizedSubject = strtolower(trim(preg_replace('/^\s*re\s*:\s*/i', '', $subject) ?? $subject));
    if ($normalizedSubject !== '') {
        $st = $pdo->prepare(
            "SELECT application_id, case_id, thread_id, component_key, thread_owner_role, root_outgoing_communication_id
               FROM Vati_Payfiller_Workflow_Communications
              WHERE application_id = ?
                AND direction = 'outgoing'
                AND (
                    LOWER(TRIM(COALESCE(subject, ''))) = ?
                    OR LOWER(TRIM(COALESCE(subject, ''))) = CONCAT('re: ', ?)
                )
              ORDER BY communication_id DESC
              LIMIT 1"
        );
        $st->execute([$applicationId, $normalizedSubject, $normalizedSubject]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            return [
                'application_id' => trim((string)($row['application_id'] ?? '')),
                'case_id' => (int)($row['case_id'] ?? 0),
                'thread_id' => trim((string)($row['thread_id'] ?? '')),
                'component_key' => strtolower(trim((string)($row['component_key'] ?? ''))),
                'thread_owner_role' => wc_norm_thread_owner_role((string)($row['thread_owner_role'] ?? '')),
                'root_outgoing_communication_id' => (int)($row['root_outgoing_communication_id'] ?? 0),
            ];
        }
    }

    $componentKey = wc_reply_component_from_subject($subject, $message);
    if ($componentKey === '') {
        return ['application_id' => $applicationId, 'case_id' => 0, 'thread_id' => '', 'component_key' => '', 'thread_owner_role' => '', 'root_outgoing_communication_id' => 0];
    }

    $st = $pdo->prepare(
        "SELECT application_id, case_id, thread_id, component_key, thread_owner_role, root_outgoing_communication_id
           FROM Vati_Payfiller_Workflow_Communications
          WHERE application_id = ?
            AND LOWER(TRIM(COALESCE(component_key, ''))) = ?
            AND direction = 'outgoing'
          ORDER BY communication_id DESC
          LIMIT 1"
    );
    $st->execute([$applicationId, $componentKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row) {
        return [
            'application_id' => trim((string)($row['application_id'] ?? '')),
            'case_id' => (int)($row['case_id'] ?? 0),
            'thread_id' => trim((string)($row['thread_id'] ?? '')),
            'component_key' => strtolower(trim((string)($row['component_key'] ?? ''))),
            'thread_owner_role' => wc_norm_thread_owner_role((string)($row['thread_owner_role'] ?? '')),
            'root_outgoing_communication_id' => (int)($row['root_outgoing_communication_id'] ?? 0),
        ];
    }

    return [
        'application_id' => $applicationId,
        'case_id' => 0,
        'thread_id' => '',
        'component_key' => $componentKey,
        'thread_owner_role' => '',
        'root_outgoing_communication_id' => 0,
    ];
}

function wc_is_strong_thread_match(array $match): bool
{
    return trim((string)($match['thread_id'] ?? '')) !== ''
        && trim((string)($match['component_key'] ?? '')) !== ''
        && wc_norm_thread_owner_role((string)($match['thread_owner_role'] ?? '')) !== '';
}

function wc_try_thread_by_existing_thread(PDO $pdo, string $applicationId, string $componentKey, string $threadId): array
{
    $applicationId = trim($applicationId);
    $componentKey = strtolower(trim($componentKey));
    $threadId = trim($threadId);
    if ($applicationId === '' || $componentKey === '' || $threadId === '') {
        return ['application_id' => '', 'case_id' => 0, 'thread_id' => '', 'component_key' => '', 'thread_owner_role' => '', 'root_outgoing_communication_id' => 0];
    }

    $st = $pdo->prepare(
        "SELECT case_id, thread_owner_role, root_outgoing_communication_id
           FROM Vati_Payfiller_Workflow_Communications
          WHERE application_id = ?
            AND LOWER(TRIM(COALESCE(component_key, ''))) = ?
            AND COALESCE(thread_id, '') = ?
            AND direction = 'outgoing'
            AND COALESCE(thread_owner_role, '') <> ''
          ORDER BY communication_id DESC"
    );
    $st->execute([$applicationId, $componentKey, $threadId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return ['application_id' => '', 'case_id' => 0, 'thread_id' => '', 'component_key' => '', 'thread_owner_role' => '', 'root_outgoing_communication_id' => 0];
    }

    $owners = [];
    foreach ($rows as $row) {
        $owner = wc_norm_thread_owner_role((string)($row['thread_owner_role'] ?? ''));
        if ($owner !== '') $owners[$owner] = true;
    }
    if (count($owners) !== 1) {
        return [
            'application_id' => $applicationId,
            'case_id' => 0,
            'thread_id' => $threadId,
            'component_key' => $componentKey,
            'thread_owner_role' => '',
            'root_outgoing_communication_id' => 0,
        ];
    }

    $latest = $rows[0];
    return [
        'application_id' => $applicationId,
        'case_id' => (int)($latest['case_id'] ?? 0),
        'thread_id' => $threadId,
        'component_key' => $componentKey,
        'thread_owner_role' => (string)array_key_first($owners),
        'root_outgoing_communication_id' => (int)($latest['root_outgoing_communication_id'] ?? 0),
    ];
}

function wc_thread_upsert(PDO $pdo, string $applicationId, int $caseId, string $threadId, string $rootMessageId, string $latestMessageId): void {
    if ($applicationId === '') return;
    $threadId = trim($threadId);
    if ($threadId === '') $threadId = wc_build_thread_id($applicationId, 'timeline', 'system');
    $sql = 'INSERT INTO Vati_Payfiller_Workflow_Mail_Threads (application_id, case_id, workflow_thread_id, root_message_id, latest_message_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              case_id = COALESCE(VALUES(case_id), case_id),
              root_message_id = COALESCE(VALUES(root_message_id), root_message_id),
              latest_message_id = COALESCE(VALUES(latest_message_id), latest_message_id),
              updated_at = NOW()';
    $st = $pdo->prepare($sql);
    $st->execute([
        $applicationId,
        $caseId > 0 ? $caseId : null,
        $threadId,
        $rootMessageId !== '' ? $rootMessageId : null,
        $latestMessageId !== '' ? $latestMessageId : null
    ]);
}

function wc_log_ingest_event(
    PDO $pdo,
    string $sourceKey,
    string $applicationId = '',
    int $caseId = 0,
    string $status = 'ok',
    int $insertedCount = 0,
    int $duplicateCount = 0,
    int $skippedCount = 0,
    int $unmatchedCount = 0,
    string $note = ''
): void {
    try {
        wc_ensure_tables($pdo);
        $ins = $pdo->prepare(
            'INSERT INTO Vati_Payfiller_Workflow_Mail_Ingest_Events
             (source_key, application_id, case_id, event_status, inserted_count, duplicate_count, skipped_count, unmatched_count, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute([
            $sourceKey,
            $applicationId !== '' ? $applicationId : null,
            $caseId > 0 ? $caseId : null,
            $status !== '' ? $status : 'ok',
            $insertedCount,
            $duplicateCount,
            $skippedCount,
            $unmatchedCount,
            $note !== '' ? $note : null,
        ]);

        $up = $pdo->prepare(
            'INSERT INTO Vati_Payfiller_Workflow_Mail_Runtime_State
             (source_key, application_id, case_id, last_status, last_run_at, inserted_count, duplicate_count, skipped_count, unmatched_count, note)
             VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               application_id = VALUES(application_id),
               case_id = VALUES(case_id),
               last_status = VALUES(last_status),
               last_run_at = NOW(),
               inserted_count = VALUES(inserted_count),
               duplicate_count = VALUES(duplicate_count),
               skipped_count = VALUES(skipped_count),
               unmatched_count = VALUES(unmatched_count),
               note = VALUES(note)'
        );
        $up->execute([
            $sourceKey,
            $applicationId !== '' ? $applicationId : null,
            $caseId > 0 ? $caseId : null,
            $status !== '' ? $status : 'ok',
            $insertedCount,
            $duplicateCount,
            $skippedCount,
            $unmatchedCount,
            $note !== '' ? $note : null,
        ]);
    } catch (Throwable $e) {
    }
}

function wc_backfill_thread_metadata(PDO $pdo, string $applicationId = ''): void
{
    try {
        wc_ensure_tables($pdo);
        $where = [];
        $params = [];
        if (trim($applicationId) !== '') {
            $where[] = 'application_id = ?';
            $params[] = trim($applicationId);
        }
        $where[] = "(
            COALESCE(thread_owner_role, '') = ''
            OR COALESCE(thread_id, '') = ''
            OR (direction = 'outgoing' AND COALESCE(root_outgoing_communication_id, 0) = 0)
            OR direction = 'incoming'
        )";
        $sql = 'SELECT communication_id, application_id, case_id, component_key, role_key, direction, actor_role, message_id, in_reply_to, references_header, thread_id, thread_owner_role, root_outgoing_communication_id, subject, body
                  FROM Vati_Payfiller_Workflow_Communications';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY communication_id ASC LIMIT 500';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return;

        $up = $pdo->prepare('UPDATE Vati_Payfiller_Workflow_Communications
                                SET thread_id = ?, thread_owner_role = ?, thread_scope = ?, root_outgoing_communication_id = ?
                              WHERE communication_id = ?');

        foreach ($rows as $row) {
            $communicationId = (int)($row['communication_id'] ?? 0);
            if ($communicationId <= 0) continue;
            $resolvedApp = trim((string)($row['application_id'] ?? ''));
            if ($resolvedApp === '') continue;
            $componentKey = strtolower(trim((string)($row['component_key'] ?? '')));
            if ($componentKey === '') $componentKey = 'timeline';
            $direction = strtolower(trim((string)($row['direction'] ?? 'outgoing')));
            $threadId = trim((string)($row['thread_id'] ?? ''));
            $threadOwnerRole = wc_norm_thread_owner_role((string)($row['thread_owner_role'] ?? ''));
            $rootOutgoingCommunicationId = (int)($row['root_outgoing_communication_id'] ?? 0);

            if ($direction === 'outgoing') {
                if ($threadOwnerRole === '') {
                    $threadOwnerRole = wc_norm_thread_owner_role((string)($row['actor_role'] ?? $row['role_key'] ?? ''));
                }
                if ($threadId === '') {
                    $threadId = wc_build_thread_id($resolvedApp, $componentKey, $threadOwnerRole);
                }
                if ($rootOutgoingCommunicationId <= 0) {
                    $rootOutgoingCommunicationId = $communicationId;
                }
            } else {
                $hdrMatch = wc_try_thread_by_headers(
                    $pdo,
                    wc_norm_msg_id((string)($row['in_reply_to'] ?? '')),
                    (string)($row['references_header'] ?? '')
                );
                $threadMatch = ['application_id' => '', 'case_id' => 0, 'thread_id' => '', 'component_key' => '', 'thread_owner_role' => '', 'root_outgoing_communication_id' => 0];
                $subjectMatch = ['application_id' => '', 'case_id' => 0, 'thread_id' => '', 'component_key' => '', 'thread_owner_role' => '', 'root_outgoing_communication_id' => 0];
                if ($threadId !== '' && $componentKey !== '') {
                    $threadMatch = wc_try_thread_by_existing_thread($pdo, $resolvedApp, $componentKey, $threadId);
                }
                $ambiguousThread = ($threadId !== '' && !empty($threadMatch['thread_id']) && empty($threadMatch['thread_owner_role']));
                if (
                    !$ambiguousThread
                    && !wc_is_strong_thread_match($hdrMatch)
                    && !wc_is_strong_thread_match($threadMatch)
                    && ($threadOwnerRole === '' || $threadId === '' || $rootOutgoingCommunicationId <= 0)
                ) {
                    $subjectMatch = wc_try_thread_by_subject(
                        $pdo,
                        $resolvedApp,
                        (string)($row['subject'] ?? ''),
                        (string)($row['body'] ?? '')
                    );
                }
                if ($threadOwnerRole === '') {
                    $threadOwnerRole = wc_norm_thread_owner_role(
                        wc_first_non_empty_string(
                            $hdrMatch['thread_owner_role'] ?? '',
                            $threadMatch['thread_owner_role'] ?? '',
                            $subjectMatch['thread_owner_role'] ?? ''
                        )
                    );
                }
                if ($ambiguousThread) {
                    $threadOwnerRole = '';
                    $rootOutgoingCommunicationId = 0;
                }
                if ($threadId === '') {
                    $threadId = wc_first_non_empty_string(
                        $hdrMatch['thread_id'] ?? '',
                        $threadMatch['thread_id'] ?? '',
                        $subjectMatch['thread_id'] ?? ''
                    );
                }
                if ($rootOutgoingCommunicationId <= 0) {
                    $rootOutgoingCommunicationId = wc_first_positive_int(
                        $hdrMatch['root_outgoing_communication_id'] ?? 0,
                        $threadMatch['root_outgoing_communication_id'] ?? 0,
                        $subjectMatch['root_outgoing_communication_id'] ?? 0
                    );
                }
                if ($threadId === '' && $threadOwnerRole !== '') {
                    $threadId = wc_build_thread_id($resolvedApp, $componentKey, $threadOwnerRole);
                }
            }

            if ($threadId === '') {
                $threadId = wc_build_thread_id($resolvedApp, $componentKey, $threadOwnerRole !== '' ? $threadOwnerRole : 'system');
            }

            $up->execute([
                $threadId,
                $threadOwnerRole !== '' ? $threadOwnerRole : null,
                'component_role',
                $rootOutgoingCommunicationId > 0 ? $rootOutgoingCommunicationId : null,
                $communicationId,
            ]);
        }
    } catch (Throwable $e) {
    }
}

function wc_sync_verification_communications(PDO $pdo, string $applicationId): int
{
    $applicationId = trim($applicationId);
    if ($applicationId === '') return 0;
    wc_ensure_tables($pdo);
    try {
        $exists = $pdo->prepare(
            'SELECT 1
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name = ?
              LIMIT 1'
        );
        $exists->execute(['Vati_Payfiller_Verification_Communications']);
        if (!$exists->fetchColumn()) {
            wc_log_ingest_event($pdo, 'verification_sync', $applicationId, 0, 'skipped', 0, 0, 0, 0, 'Vati_Payfiller_Verification_Communications table missing');
            return 0;
        }

        $sel = $pdo->prepare(
            'SELECT vc.id, vc.case_id, vc.application_id, vc.component_key, vc.template_key, vc.recipient_email,
                    vc.node_thread_id, vc.node_conversation_id, vc.communication_status, vc.subject_snapshot,
                    vc.body_snapshot, vc.last_message_at, vc.created_by, vc.created_at,
                    u.first_name, u.last_name, u.username, LOWER(TRIM(COALESCE(u.role, \'\'))) AS sender_role
               FROM Vati_Payfiller_Verification_Communications vc
               LEFT JOIN Vati_Payfiller_Users u ON u.user_id = vc.created_by
              WHERE vc.application_id = ?
              ORDER BY vc.id ASC'
        );
        $sel->execute([$applicationId]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            wc_log_ingest_event($pdo, 'verification_sync', $applicationId, 0, 'noop', 0, 0, 0, 0, 'no verification communications');
            return 0;
        }

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO Vati_Payfiller_Workflow_Communications
             (application_id, case_id, component_key, role_key, action_key, subject, body, sent_by_user_id, sent_by_name, sent_at,
              delivery_status, communication_type, direction, actor_role, actor_name, workflow_stage, source_table, source_message_key,
              thread_id, thread_owner_role, thread_scope, root_outgoing_communication_id, message_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $inserted = 0;
        $duplicates = 0;
        $lastCaseId = 0;
        foreach ($rows as $row) {
            $caseId = isset($row['case_id']) ? (int)$row['case_id'] : 0;
            $lastCaseId = $caseId > 0 ? $caseId : $lastCaseId;
            $componentKey = strtolower(trim((string)($row['component_key'] ?? '')));
            $senderRole = strtolower(trim((string)($row['sender_role'] ?? 'verifier')));
            if ($senderRole === '') $senderRole = 'verifier';
            $senderName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
            if ($senderName === '') {
                $senderName = trim((string)($row['username'] ?? ''));
            }
            $threadOwnerRole = wc_norm_thread_owner_role($senderRole);
            $threadId = wc_build_thread_id($applicationId, $componentKey, $threadOwnerRole);
            $messageId = 'verification.' . $applicationId . '.' . (int)($row['id'] ?? 0) . '@local';
            $sourceKey = 'verification_comm:' . (int)($row['id'] ?? 0);
            $ins->execute([
                $applicationId,
                $caseId > 0 ? $caseId : null,
                $componentKey !== '' ? $componentKey : 'verification',
                $senderRole,
                'verification_request',
                (string)($row['subject_snapshot'] ?? 'Verification Mail'),
                (string)($row['body_snapshot'] ?? ''),
                isset($row['created_by']) ? (int)$row['created_by'] : null,
                $senderName !== '' ? $senderName : null,
                (string)($row['last_message_at'] ?? $row['created_at'] ?? date('Y-m-d H:i:s')),
                wc_normalize_delivery_status((string)($row['communication_status'] ?? 'sent')),
                'verification_request',
                'outgoing',
                $senderRole,
                $senderName !== '' ? $senderName : null,
                $senderRole,
                'Vati_Payfiller_Verification_Communications',
                $sourceKey,
                $threadId,
                $threadOwnerRole,
                'component_role',
                null,
                $messageId
            ]);
            $inc = (int)$ins->rowCount();
            if ($inc > 0) {
                $inserted += $inc;
                wc_thread_upsert($pdo, $applicationId, $caseId, $threadId, trim($messageId, '<> '), trim($messageId, '<> '));
                $communicationId = (int)$pdo->lastInsertId();
                if ($communicationId > 0) {
                    $up = $pdo->prepare('UPDATE Vati_Payfiller_Workflow_Communications
                                            SET root_outgoing_communication_id = ?
                                          WHERE communication_id = ?
                                            AND COALESCE(root_outgoing_communication_id, 0) = 0');
                    $up->execute([$communicationId, $communicationId]);
                }
            } else {
                $duplicates++;
            }
        }

        wc_log_ingest_event($pdo, 'verification_sync', $applicationId, $lastCaseId, 'ok', $inserted, $duplicates, 0, 0, 'verification communications synchronized');
        return $inserted;
    } catch (Throwable $e) {
        wc_log_ingest_event($pdo, 'verification_sync', $applicationId, 0, 'error', 0, 0, 0, 0, $e->getMessage());
        return 0;
    }
}

function wc_ingest_incoming_replies(PDO $pdo, string $applicationId): int {
    $applicationId = trim($applicationId);
    if ($applicationId === '') return 0;
    wc_ensure_tables($pdo);
    $table = wc_resolve_replies_table($pdo);
    if ($table === '') {
        wc_log_ingest_event($pdo, 'legacy_reply_ingest', $applicationId, 0, 'skipped', 0, 0, 0, 0, 'legacy replies table missing');
        return 0;
    }
    $cols = wc_resolve_reply_columns($pdo, $table);
    if (empty($cols['ok'])) {
        wc_log_ingest_event($pdo, 'legacy_reply_ingest', $applicationId, 0, 'skipped', 0, 0, 0, 0, 'legacy replies columns unsupported');
        return 0;
    }

    $select = 'SELECT `'.str_replace('`','``',$cols['sender']).'` AS sender, '
        . '`'.str_replace('`','``',$cols['message']).'` AS message, '
        . '`'.str_replace('`','``',$cols['created_at']).'` AS created_at '
        . ($cols['subject'] !== '' ? ', `'.str_replace('`','``',$cols['subject']).'` AS subject ' : ", '' AS subject ")
        . ($cols['message_id'] !== '' ? ', `'.str_replace('`','``',$cols['message_id']).'` AS message_id ' : ", '' AS message_id ")
        . ($cols['in_reply_to'] !== '' ? ', `'.str_replace('`','``',$cols['in_reply_to']).'` AS in_reply_to ' : ", '' AS in_reply_to ")
        . ($cols['references_header'] !== '' ? ', `'.str_replace('`','``',$cols['references_header']).'` AS references_header ' : ", '' AS references_header ")
        . ($cols['mailbox_uid'] !== '' ? ', `'.str_replace('`','``',$cols['mailbox_uid']).'` AS mailbox_uid ' : ", '' AS mailbox_uid ")
        . ($cols['thread_id'] !== '' ? ', `'.str_replace('`','``',$cols['thread_id']).'` AS thread_id ' : ", '' AS thread_id ")
        . 'FROM `'.str_replace('`','``',$table).'` '
        . "WHERE REPLACE(LOWER(TRIM(application_id)), ' ', '') = REPLACE(LOWER(TRIM(?)), ' ', '') ";
    $q = $select . 'ORDER BY `'.str_replace('`','``',$cols['created_at']).'` DESC LIMIT 250';
    $st = $pdo->prepare($q);
    $st->execute([$applicationId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Fallback path for environments where mailbox ingestion does not persist application_id:
    // recover candidate replies by threading headers against known outgoing workflow Message-IDs.
    if (!$rows && ($cols['in_reply_to'] !== '' || $cols['references_header'] !== '')) {
        $knownStmt = $pdo->prepare("SELECT LOWER(TRIM(COALESCE(message_id,''))) AS message_id
                                      FROM Vati_Payfiller_Workflow_Communications
                                     WHERE application_id = ?
                                       AND direction = 'outgoing'
                                       AND COALESCE(message_id,'') <> ''
                                     ORDER BY communication_id DESC
                                     LIMIT 400");
        $knownStmt->execute([$applicationId]);
        $knownRows = $knownStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $known = [];
        foreach ($knownRows as $kr) {
            $mid = wc_norm_msg_id((string)($kr['message_id'] ?? ''));
            if ($mid !== '') $known[$mid] = true;
        }
        if ($known) {
            $q2 = str_replace(
                "WHERE REPLACE(LOWER(TRIM(application_id)), ' ', '') = REPLACE(LOWER(TRIM(?)), ' ', '') ",
                '',
                $select
            ) . 'ORDER BY `'.str_replace('`','``',$cols['created_at']).'` DESC LIMIT 600';
            $st2 = $pdo->query($q2);
            $allRows = $st2 ? ($st2->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($allRows as $rr) {
                $ir = wc_norm_msg_id((string)($rr['in_reply_to'] ?? ''));
                $rf = wc_extract_msg_ids((string)($rr['references_header'] ?? ''));
                $linked = ($ir !== '' && isset($known[$ir]));
                if (!$linked && $rf) {
                    foreach ($rf as $rid) {
                        if ($rid !== '' && isset($known[$rid])) {
                            $linked = true;
                            break;
                        }
                    }
                }
                if ($linked) $rows[] = $rr;
            }
        }
    }
    if (!$rows) {
        wc_log_ingest_event($pdo, 'legacy_reply_ingest', $applicationId, 0, 'noop', 0, 0, 0, 0, 'no legacy replies matched');
        return 0;
    }

    $ins = $pdo->prepare('INSERT IGNORE INTO Vati_Payfiller_Workflow_Communications
        (application_id, case_id, component_key, role_key, action_key, subject, body, sent_by_name, sent_at, delivery_status, communication_type, direction, actor_role, actor_name, workflow_stage, source_table, source_message_key, message_id, in_reply_to, references_header, thread_id, thread_owner_role, thread_scope, root_outgoing_communication_id, mailbox_uid)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $count = 0;
    $duplicates = 0;
    $unmatched = 0;
    $lastCaseId = 0;
    foreach ($rows as $r) {
        $sender = trim((string)($r['sender'] ?? ''));
        $message = trim((string)($r['message'] ?? ''));
        $createdAt = trim((string)($r['created_at'] ?? ''));
        $subject = trim((string)($r['subject'] ?? ''));
        $messageId = wc_norm_msg_id((string)($r['message_id'] ?? ''));
        $inReplyTo = wc_norm_msg_id((string)($r['in_reply_to'] ?? ''));
        $referencesHeader = trim((string)($r['references_header'] ?? ''));
        $mailboxUid = trim((string)($r['mailbox_uid'] ?? ''));
        $threadId = trim((string)($r['thread_id'] ?? ''));
        if ($message === '' && $sender === '') continue;
        $hdrMatch = wc_try_thread_by_headers($pdo, $inReplyTo, $referencesHeader);
        $resolvedApp = trim((string)($hdrMatch['application_id'] ?? ''));
        if ($resolvedApp === '') $resolvedApp = $applicationId;
        $resolvedCaseId = (int)($hdrMatch['case_id'] ?? 0);
        if ($resolvedCaseId > 0) $lastCaseId = $resolvedCaseId;
        $resolvedComponentKey = strtolower(trim((string)($hdrMatch['component_key'] ?? '')));
        $resolvedOwnerRole = wc_norm_thread_owner_role((string)($hdrMatch['thread_owner_role'] ?? ''));
        $rootOutgoingCommunicationId = (int)($hdrMatch['root_outgoing_communication_id'] ?? 0);
        $threadMatch = ['application_id' => '', 'case_id' => 0, 'thread_id' => '', 'component_key' => '', 'thread_owner_role' => '', 'root_outgoing_communication_id' => 0];
        $subjectMatch = ['application_id' => '', 'case_id' => 0, 'thread_id' => '', 'component_key' => '', 'thread_owner_role' => '', 'root_outgoing_communication_id' => 0];
        if ($threadId !== '' && $resolvedComponentKey !== '') {
            $threadMatch = wc_try_thread_by_existing_thread($pdo, $resolvedApp !== '' ? $resolvedApp : $applicationId, $resolvedComponentKey, $threadId);
        }
        if (
            !wc_is_strong_thread_match($hdrMatch)
            && !wc_is_strong_thread_match($threadMatch)
            && ($resolvedComponentKey === '' || $threadId === '' || $resolvedOwnerRole === '' || $rootOutgoingCommunicationId <= 0)
        ) {
            $subjectMatch = wc_try_thread_by_subject($pdo, $resolvedApp !== '' ? $resolvedApp : $applicationId, $subject, $message);
            if ($resolvedCaseId <= 0) {
                $resolvedCaseId = (int)($subjectMatch['case_id'] ?? 0);
                if ($resolvedCaseId > 0) $lastCaseId = $resolvedCaseId;
            }
            if ($resolvedComponentKey === '') {
                $resolvedComponentKey = strtolower(trim((string)($subjectMatch['component_key'] ?? '')));
            }
            if ($resolvedOwnerRole === '') {
                $resolvedOwnerRole = wc_norm_thread_owner_role((string)($subjectMatch['thread_owner_role'] ?? ''));
            }
            if ($rootOutgoingCommunicationId <= 0) {
                $rootOutgoingCommunicationId = (int)($subjectMatch['root_outgoing_communication_id'] ?? 0);
            }
        }
        if ($threadId === '') $threadId = trim((string)($hdrMatch['thread_id'] ?? ''));
        if ($threadId === '') $threadId = wc_first_non_empty_string($threadMatch['thread_id'] ?? '', $subjectMatch['thread_id'] ?? '');
        if ($resolvedOwnerRole === '') {
            $resolvedOwnerRole = wc_norm_thread_owner_role(
                wc_first_non_empty_string(
                    $threadMatch['thread_owner_role'] ?? '',
                    $subjectMatch['thread_owner_role'] ?? ''
                )
            );
        }
        if ($rootOutgoingCommunicationId <= 0) {
            $rootOutgoingCommunicationId = wc_first_positive_int(
                $threadMatch['root_outgoing_communication_id'] ?? 0,
                $subjectMatch['root_outgoing_communication_id'] ?? 0
            );
        }
        if ($threadId === '' && $resolvedOwnerRole !== '') {
            $threadId = wc_build_thread_id($resolvedApp, $resolvedComponentKey !== '' ? $resolvedComponentKey : 'timeline', $resolvedOwnerRole);
        }
        if ($resolvedCaseId <= 0) $unmatched++;
        $srcKey = $messageId !== '' ? ('msgid:' . $messageId) : ($mailboxUid !== '' ? ('uid:' . $mailboxUid) : sha1($sender . '|' . $message . '|' . $createdAt));
        $ins->execute([
            $resolvedApp,
            $resolvedCaseId > 0 ? $resolvedCaseId : null,
            $resolvedComponentKey !== '' ? $resolvedComponentKey : 'timeline',
            'candidate',
            'reply',
            $subject !== '' ? $subject : 'Candidate Reply',
            $message,
            $sender !== '' ? $sender : 'Candidate',
            $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
            'received',
            'manual_message',
            'incoming',
            'candidate',
            $sender !== '' ? $sender : 'Candidate',
            'candidate',
            $table,
            $srcKey,
            $messageId !== '' ? $messageId : null,
            $inReplyTo !== '' ? $inReplyTo : null,
            $referencesHeader !== '' ? $referencesHeader : null,
            $threadId !== '' ? $threadId : null,
            $resolvedOwnerRole !== '' ? $resolvedOwnerRole : null,
            'component_role',
            $rootOutgoingCommunicationId > 0 ? $rootOutgoingCommunicationId : null,
            $mailboxUid !== '' ? $mailboxUid : null,
        ]);
        $inc = (int)$ins->rowCount();
        $count += $inc;
        if ($inc > 0) {
            wc_thread_upsert(
                $pdo,
                $resolvedApp,
                $resolvedCaseId,
                $threadId,
                $inReplyTo !== '' ? $inReplyTo : $messageId,
                $messageId
            );
        } else {
            $duplicates++;
        }
    }
    wc_log_ingest_event(
        $pdo,
        'legacy_reply_ingest',
        $applicationId,
        $lastCaseId,
        'ok',
        $count,
        $duplicates,
        max(0, count($rows) - $count - $duplicates),
        $unmatched,
        'legacy replies ingested into Vati_Payfiller_Workflow_Communications'
    );
    return $count;
}

function wc_log_timeline(PDO $pdo, string $applicationId, string $role, string $component, string $message): void {
    $uid = (int)($_SESSION['auth_user_id'] ?? 0);
    $stmt = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$applicationId, $uid > 0 ? $uid : null, $role, 'action', $component, $message]);
}
