<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/workflow_status_semantics.php';
require_once __DIR__ . '/workflow_communication_service.php';
require_once __DIR__ . '/workflow_mode.php';
require_once __DIR__ . '/case_component_binding.php';

function ccs_role_norm(string $role): string {
    $r = strtolower(trim($role));
    if ($r === 'customer_admin') return 'client_admin';
    if ($r === 'component verifier' || $r === 'component_verifier' || $r === 'db verifier') return 'verifier';
    if ($r === 'component validator' || $r === 'component_validator') return 'validator';
    if ($r === 'team lead' || $r === 'team_lead') return 'qa';
    if ($r === 'gss admin') return 'gss_admin';
    return $r;
}

function ccs_component_norm(string $k): string {
    $k = strtolower(trim($k));
    $k = str_replace(['-', ' '], '_', $k);
    if ($k === 'basic_details' || $k === 'basic_detail') return 'basic';
    if ($k === 'identification') return 'id';
    if ($k === 'contact_information' || $k === 'contact_details' || $k === 'contact_detail') return 'contact';
    if ($k === 'education_details' || $k === 'education_detail') return 'education';
    if ($k === 'employment_details' || $k === 'employment_detail') return 'employment';
    if ($k === 'references') return 'reference';
    if ($k === 'e_court' || $k === 'ecourt_check') return 'ecourt';
    if ($k === 'social' || $k === 'social_media') return 'socialmedia';
    return $k;
}

function ccs_is_reference_split(string $component): bool {
    $component = ccs_component_norm($component);
    return $component === 'education_reference' || $component === 'employment_reference';
}

function ccs_allowed_component_match(array $allowedSet, string $component): bool {
    $component = ccs_component_norm($component);
    if (isset($allowedSet['*']) || isset($allowedSet[$component])) return true;
    if (ccs_is_reference_split($component) && isset($allowedSet['reference'])) return true;
    if ($component === 'reference' && (isset($allowedSet['education_reference']) || isset($allowedSet['employment_reference']))) return true;
    return false;
}

function ccs_component_storage_candidates(string $component): array {
    $component = ccs_component_norm($component);
    if (ccs_is_reference_split($component)) return [$component, 'reference'];
    return $component !== '' ? [$component] : [];
}

function ccs_component_overlap_keys(string $component): array {
    $component = ccs_component_norm($component);
    if ($component === 'reference') return ['reference', 'education_reference', 'employment_reference'];
    if (ccs_is_reference_split($component)) return [$component];
    return $component !== '' ? [$component] : [];
}

function ccs_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS Vati_Payfiller_Candidate_Correction_Sessions (
        correction_session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id VARCHAR(128) NULL,
        case_id BIGINT NOT NULL,
        application_id VARCHAR(64) NOT NULL,
        requested_by_user_id BIGINT NULL,
        requested_by_name VARCHAR(255) NULL,
        requested_role VARCHAR(64) NOT NULL,
        correction_reason TEXT NULL,
        allowed_components_json JSON NOT NULL,
        token VARCHAR(128) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        expires_at DATETIME NULL,
        resend_count INT NOT NULL DEFAULT 0,
        workflow_snapshot_version INT NULL,
        thread_id VARCHAR(128) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        completed_by_user_id BIGINT NULL,
        completed_by_role VARCHAR(64) NULL,
        PRIMARY KEY (correction_session_id),
        UNIQUE KEY uq_ccs_token (token),
        UNIQUE KEY uq_ccs_request (request_id),
        KEY idx_ccs_case (case_id),
        KEY idx_ccs_app (application_id),
        KEY idx_ccs_status_exp (status, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS Vati_Payfiller_Component_Correction_Cycles (
        correction_cycle_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        correction_session_id BIGINT UNSIGNED NOT NULL,
        case_id BIGINT NOT NULL,
        application_id VARCHAR(64) NOT NULL,
        component_key VARCHAR(64) NOT NULL,
        requested_by_user_id BIGINT NULL,
        requested_role VARCHAR(64) NOT NULL,
        cycle_number INT NOT NULL DEFAULT 1,
        previous_status VARCHAR(64) NULL,
        correction_reason TEXT NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        candidate_submitted_at DATETIME NULL,
        reviewer_completed_at DATETIME NULL,
        final_status VARCHAR(64) NULL,
        reopened_count INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (correction_cycle_id),
        KEY idx_ccc_case_comp (case_id, component_key),
        KEY idx_ccc_session (correction_session_id),
        KEY idx_ccc_app (application_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS Vati_Payfiller_Component_Document_Versions (
        document_version_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        application_id VARCHAR(64) NOT NULL,
        case_id BIGINT NOT NULL,
        component_key VARCHAR(64) NOT NULL,
        file_name VARCHAR(500) NULL,
        file_url VARCHAR(1000) NULL,
        correction_session_id BIGINT NULL,
        correction_cycle_id BIGINT NULL,
        upload_version INT NOT NULL DEFAULT 1,
        supersedes_document_id BIGINT NULL,
        source_stage VARCHAR(32) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (document_version_id),
        KEY idx_cdv_app_comp (application_id, component_key),
        KEY idx_cdv_session (correction_session_id),
        KEY idx_cdv_cycle (correction_cycle_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ccs_new_token(): string {
    try { return bin2hex(random_bytes(24)); } catch (Throwable $e) {}
    return bin2hex(openssl_random_pseudo_bytes(24));
}

function ccs_timeline(PDO $pdo, string $applicationId, int $userId, string $role, string $section, string $msg): void {
    $st = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $st->execute([$applicationId, $userId > 0 ? $userId : null, $role !== '' ? $role : null, 'action', $section, $msg]);
}

function ccs_user_has_validator_visibility(PDO $pdo, int $caseId, int $userId): bool {
    if ($userId <= 0 || $caseId <= 0) return false;
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

function ccs_user_has_verifier_visibility(PDO $pdo, int $caseId, int $userId): bool {
    if ($userId <= 0 || $caseId <= 0) return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM Vati_Payfiller_Case_Components WHERE case_id = ? AND assigned_role = 'verifier' AND assigned_user_id = ? LIMIT 1");
        $st->execute([$caseId, $userId]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT 1 FROM Vati_Payfiller_Verifier_Group_Queue WHERE case_id = ? AND assigned_user_id = ? LIMIT 1");
        $st->execute([$caseId, $userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    return false;
}

function ccs_is_role_allowed(string $role): bool {
    return in_array($role, ['client_admin', 'validator', 'verifier', 'qa', 'gss_admin'], true);
}

function ccs_get_case(PDO $pdo, int $caseId, string $applicationId): ?array {
    if ($caseId > 0) {
        $st = $pdo->prepare('SELECT case_id, application_id, client_id, case_status, candidate_email, candidate_first_name, candidate_last_name, workflow_version FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $st->execute([$caseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) return $row;
    }
    if ($applicationId !== '') {
        $st = $pdo->prepare('SELECT case_id, application_id, client_id, case_status, candidate_email, candidate_first_name, candidate_last_name, workflow_version FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $st->execute([$applicationId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return null;
}

function ccs_component_stage_for_role(string $role): string {
    if ($role === 'validator') return 'validator';
    if ($role === 'verifier') return 'verifier';
    if ($role === 'qa') return 'qa';
    return '';
}

function ccs_role_allowed_sections(PDO $pdo, int $userId): array {
    $out = ['*' => true];
    if ($userId <= 0) return $out;
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $sessionRaw = strtolower(trim((string)($_SESSION['auth_allowed_sections'] ?? '')));
    if ($sessionRaw !== '') {
        if ($sessionRaw === '*') return ['*' => true];
        $sessionSet = [];
        foreach (preg_split('/[\s,|]+/', $sessionRaw) as $p) {
            $k = ccs_component_norm((string)$p);
            if ($k !== '') $sessionSet[$k] = true;
        }
        if ($sessionSet) return $sessionSet;
    }
    try {
        $st = $pdo->prepare('SELECT allowed_sections FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $raw = strtolower(trim((string)($st->fetchColumn() ?: '')));
        if ($raw === '' || $raw === '*') return ['*' => true];
        $set = [];
        foreach (preg_split('/[\s,|]+/', $raw) as $p) {
            $k = ccs_component_norm((string)$p);
            if ($k !== '') $set[$k] = true;
        }
        return $set ?: ['*' => true];
    } catch (Throwable $e) {
        return ['*' => true];
    }
}

function ccs_stage_status(PDO $pdo, int $caseId, string $component, string $stage): string {
    $st = $pdo->prepare("SELECT LOWER(TRIM(COALESCE(status,''))) FROM Vati_Payfiller_Case_Component_Workflow WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND LOWER(TRIM(stage)) = ? LIMIT 1");
    foreach (ccs_component_storage_candidates($component) as $componentKey) {
        $st->execute([$caseId, $componentKey, strtolower(trim($stage))]);
        $status = (string)($st->fetchColumn() ?: '');
        if ($status !== '') return $status;
    }
    return '';
}

function ccs_is_actionable_status(string $status): bool {
    $s = strtolower(trim($status));
    return in_array($s, ['hold', 'insufficient_documents', 'waiting_candidate', 'reopened', 'blocked', 'rejected', 'approved', 'pending', 'in_progress'], true);
}

function ccs_can_role_request_component(PDO $pdo, int $caseId, string $component, string $role, int $userId, int $clientId, int $caseClientId, array $allowedSet): bool {
    $component = ccs_component_norm($component);
    if ($component === '' || $component === 'reports' || $component === 'timeline') return false;
    if (!ccs_allowed_component_match($allowedSet, $component)) return false;
    if ($role === 'gss_admin') return true;
    if ($role === 'client_admin') {
        $enabled = strtolower(trim((string)(env_get('CLIENT_ADMIN_CAN_REQUEST_CORRECTION', '0') ?? '0'))) === '1';
        return $enabled && $clientId > 0 && $clientId === $caseClientId;
    }
    if ($role === 'validator') {
        $row = null;
        $c = $pdo->prepare("SELECT assigned_role, assigned_user_id FROM Vati_Payfiller_Case_Components WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? LIMIT 1");
        foreach (ccs_component_storage_candidates($component) as $componentKey) {
            $c->execute([$caseId, $componentKey]);
            $row = $c->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) break;
        }
        if (!$row) return false;
        $ar = strtolower(trim((string)($row['assigned_role'] ?? '')));
        $au = (int)($row['assigned_user_id'] ?? 0);
        if ($au > 0 && $au !== $userId) return false;
        if ($ar !== '' && $ar !== 'validator') return false;
        return ccs_is_actionable_status(ccs_stage_status($pdo, $caseId, $component, 'validator'));
    }
    if ($role === 'verifier') {
        if (!ccs_user_has_verifier_visibility($pdo, $caseId, $userId)) return false;
        $case = ccs_get_case($pdo, $caseId, '');
        $applicationId = (string)($case['application_id'] ?? '');
        $row = null;
        $c = $pdo->prepare("SELECT assigned_role, assigned_user_id FROM Vati_Payfiller_Case_Components WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? LIMIT 1");
        foreach (ccs_component_storage_candidates($component) as $componentKey) {
            $c->execute([$caseId, $componentKey]);
            $row = $c->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) break;
        }
        if (!$row) return false;
        $assignedRole = strtolower(trim((string)($row['assigned_role'] ?? '')));
        $assignedUserId = (int)($row['assigned_user_id'] ?? 0);
        if ($assignedRole !== '' && $assignedUserId > 0) {
            if ($assignedRole !== $role || $assignedUserId !== $userId) return false;
        } else {
            $configAllowed = case_component_binding_role_allowed($pdo, $caseId, $applicationId, $component, $role);
            if ($configAllowed === false) return false;
            if (!ccs_allowed_component_match($allowedSet, $component)) return false;
        }
        return ccs_is_actionable_status(ccs_stage_status($pdo, $caseId, $component, 'verifier'));
    }
    if ($role === 'qa') {
        $st = ccs_stage_status($pdo, $caseId, $component, 'qa');
        if (!ccs_is_actionable_status($st)) return false;
        $ver = ccs_stage_status($pdo, $caseId, $component, 'verifier');
        return wf_is_evaluated_status($ver) || wf_is_operationally_active_status($ver);
    }
    return false;
}

function ccs_get_eligible_components(PDO $pdo, int $caseId, string $role, int $userId, int $clientId, int $caseClientId): array {
    $allowedSet = ccs_role_allowed_sections($pdo, $userId);
    $st = $pdo->prepare("SELECT LOWER(TRIM(component_key)) AS component_key FROM Vati_Payfiller_Case_Components WHERE case_id = ? AND is_required = 1 AND LOWER(TRIM(component_key)) <> 'reports'");
    $st->execute([$caseId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $k = ccs_component_norm((string)($r['component_key'] ?? ''));
        if ($k === '') continue;
        if (ccs_can_role_request_component($pdo, $caseId, $k, $role, $userId, $clientId, $caseClientId, $allowedSet)) {
            $out[] = $k;
            if ($k === 'reference') {
                foreach (['education_reference', 'employment_reference'] as $splitKey) {
                    if (ccs_can_role_request_component($pdo, $caseId, $splitKey, $role, $userId, $clientId, $caseClientId, $allowedSet)) {
                        $out[] = $splitKey;
                    }
                }
            }
        }
    }
    return array_values(array_unique($out));
}

function ccs_update_components_waiting_candidate(PDO $pdo, int $caseId, string $applicationId, array $components, int $userId, string $role): int {
    $stage = ccs_component_stage_for_role($role);
    if ($stage === '' || !$components) return 0;
    $count = 0;
    $st = $pdo->prepare(
        "UPDATE Vati_Payfiller_Case_Component_Workflow
            SET status = 'waiting_candidate',
                updated_by_user_id = ?,
                updated_by_role = ?,
                completed_at = NULL,
                updated_at = NOW()
          WHERE case_id = ?
            AND application_id = ?
            AND LOWER(TRIM(component_key)) = ?
            AND LOWER(TRIM(stage)) = ?
            AND LOWER(TRIM(COALESCE(status,''))) <> 'waiting_candidate'"
    );
    foreach ($components as $c) {
        $changed = 0;
        foreach (ccs_component_storage_candidates((string)$c) as $componentKey) {
            $st->execute([$userId > 0 ? $userId : null, $role, $caseId, $applicationId, $componentKey, $stage]);
            $changed += (int)$st->rowCount();
            if ($changed > 0) break;
        }
        $count += $changed;
    }
    return $count;
}

function ccs_resume_components_after_candidate_submit(PDO $pdo, int $caseId, string $applicationId, array $components): int {
    if ($caseId <= 0 || $applicationId === '' || !$components) return 0;
    $st = $pdo->prepare(
        "UPDATE Vati_Payfiller_Case_Component_Workflow
            SET status = 'pending',
                updated_by_role = 'candidate',
                completed_at = NULL,
                updated_at = NOW()
          WHERE case_id = ?
            AND application_id = ?
            AND LOWER(TRIM(component_key)) = ?
            AND LOWER(TRIM(stage)) IN ('validator','verifier','qa')
            AND LOWER(TRIM(COALESCE(status,''))) IN ('waiting_candidate','reopened','hold','insufficient_documents','blocked')"
    );
    $count = 0;
    foreach ($components as $c) {
        $changed = 0;
        foreach (ccs_component_storage_candidates((string)$c) as $componentKey) {
            $st->execute([$caseId, $applicationId, $componentKey]);
            $changed += (int)$st->rowCount();
            if ($changed > 0) break;
        }
        $count += $changed;
    }
    return $count;
}

function ccs_insert_correction_cycles(PDO $pdo, int $sessionId, int $caseId, string $applicationId, array $components, int $userId, string $role, string $reason): void {
    $sel = $pdo->prepare("SELECT COALESCE(MAX(cycle_number),0) FROM Vati_Payfiller_Component_Correction_Cycles WHERE case_id = ? AND LOWER(TRIM(component_key)) = ?");
    $ins = $pdo->prepare("INSERT INTO Vati_Payfiller_Component_Correction_Cycles (correction_session_id, case_id, application_id, component_key, requested_by_user_id, requested_role, cycle_number, previous_status, correction_reason, requested_at, reopened_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())");
    foreach ($components as $c) {
        $k = ccs_component_norm((string)$c);
        $prev = ccs_stage_status($pdo, $caseId, $k, ccs_component_stage_for_role($role));
        $sel->execute([$caseId, $k]);
        $mx = (int)($sel->fetchColumn() ?: 0);
        $cycle = $mx + 1;
        $reopened = ($prev === 'reopened' || $prev === 'waiting_candidate') ? 1 : 0;
        $ins->execute([$sessionId, $caseId, $applicationId, $k, $userId > 0 ? $userId : null, $role, $cycle, $prev !== '' ? $prev : null, $reason !== '' ? $reason : null, $reopened]);
    }
}

function ccs_mark_cycles_candidate_submitted(PDO $pdo, int $sessionId, array $components): void {
    $up = $pdo->prepare("UPDATE Vati_Payfiller_Component_Correction_Cycles SET candidate_submitted_at = NOW(), updated_at = NOW() WHERE correction_session_id = ? AND LOWER(TRIM(component_key)) = ? AND candidate_submitted_at IS NULL");
    foreach ($components as $c) {
        $up->execute([$sessionId, ccs_component_norm((string)$c)]);
    }
}

function ccs_progress_component_after_candidate_save(PDO $pdo, string $applicationId, string $component, bool $isDraft = false): int {
    if ($isDraft) return 0;
    if ($applicationId === '' || $component === '') return 0;
    if (empty($_SESSION['candidate_correction_mode']) || empty($_SESSION['candidate_correction_session_id'])) return 0;
    $sessionId = (int)$_SESSION['candidate_correction_session_id'];
    if ($sessionId <= 0) return 0;

    $st = $pdo->prepare("SELECT correction_session_id, case_id, application_id, allowed_components_json, status
                         FROM Vati_Payfiller_Candidate_Correction_Sessions
                         WHERE correction_session_id = ?
                         LIMIT 1");
    $st->execute([$sessionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) return 0;
    if ((string)($row['application_id'] ?? '') !== $applicationId) return 0;
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if (!in_array($status, ['active', 'submitted'], true)) return 0;

    $allowed = json_decode((string)($row['allowed_components_json'] ?? '[]'), true);
    if (!is_array($allowed) || !$allowed) return 0;
    $allowedSet = [];
    foreach ($allowed as $a) {
        $k = ccs_component_norm((string)$a);
        if ($k !== '') $allowedSet[$k] = true;
    }
    $componentKey = ccs_component_norm($component);
    if ($componentKey === '' || !isset($allowedSet[$componentKey])) return 0;

    $changed = ccs_resume_components_after_candidate_submit($pdo, (int)$row['case_id'], $applicationId, [$componentKey]);
    if ($changed > 0) {
        ccs_mark_cycles_candidate_submitted($pdo, $sessionId, [$componentKey]);
    }
    return $changed;
}

function ccs_snapshot_document_versions(PDO $pdo, int $caseId, string $applicationId, array $components, int $sessionId): void {
    $selLast = $pdo->prepare("SELECT document_version_id, upload_version FROM Vati_Payfiller_Component_Document_Versions WHERE application_id = ? AND component_key = ? ORDER BY upload_version DESC, document_version_id DESC LIMIT 1");
    $ins = $pdo->prepare("INSERT INTO Vati_Payfiller_Component_Document_Versions (application_id, case_id, component_key, file_name, file_url, correction_session_id, correction_cycle_id, upload_version, supersedes_document_id, source_stage, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    foreach ($components as $c) {
        $k = ccs_component_norm((string)$c);
        $selLast->execute([$applicationId, $k]);
        $last = $selLast->fetch(PDO::FETCH_ASSOC) ?: null;
        $prevId = $last ? (int)$last['document_version_id'] : null;
        $ver = $last ? ((int)$last['upload_version'] + 1) : 1;
        $ins->execute([$applicationId, $caseId, $k, null, null, $sessionId, null, $ver, $prevId, 'correction_requested']);
    }
}

function ccs_log_workflow_communication(PDO $pdo, int $caseId, string $applicationId, array $components, string $reason, int $userId, string $userName, string $role, string $threadId): void {
    wc_ensure_tables($pdo);
    $componentKeys = [];
    foreach ($components as $component) {
        $componentKey = ccs_component_norm((string)$component);
        if ($componentKey !== '') {
            $componentKeys[$componentKey] = true;
        }
    }
    $componentKeys = array_keys($componentKeys);
    if (!$componentKeys) {
        return;
    }

    $subject = 'Candidate Correction Requested';
    $componentList = implode(', ', $componentKeys);
    $st = $pdo->prepare("INSERT INTO Vati_Payfiller_Workflow_Communications
        (application_id, case_id, component_key, role_key, action_key, subject, body, notes, sent_by_user_id, sent_by_name, sent_at, delivery_status, communication_type, direction, actor_role, actor_name, workflow_stage, thread_id, source_table, source_message_key)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'sent', 'correction_request', 'outgoing', ?, ?, ?, ?, 'Vati_Payfiller_Candidate_Correction_Sessions', ?)");
    foreach ($componentKeys as $componentKey) {
        $body = 'Correction requested for component: ' . $componentKey;
        if (count($componentKeys) > 1) {
            $body .= ' | selected components: ' . $componentList;
        }
        if ($reason !== '') {
            $body .= ' | reason: ' . $reason;
        }
        $srcKey = 'corr:' . $caseId . ':' . $componentKey . ':' . sha1($applicationId . '|' . $componentKey . '|' . $componentList . '|' . $reason . '|' . date('YmdHi'));
        $st->execute([$applicationId, $caseId, $componentKey, $role, 'correction_request', $subject, $body, $reason !== '' ? $reason : null, $userId > 0 ? $userId : null, $userName !== '' ? $userName : null, $role, $userName !== '' ? $userName : null, $role, $threadId, $srcKey]);
    }
}

function ccs_active_session_conflicts(PDO $pdo, int $caseId, array $components): array {
    if (!$components) return [];
    $st = $pdo->prepare("SELECT correction_session_id, allowed_components_json FROM Vati_Payfiller_Candidate_Correction_Sessions WHERE case_id = ? AND status IN ('active','submitted') AND (expires_at IS NULL OR expires_at >= NOW())");
    $st->execute([$caseId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $asked = [];
    foreach ($components as $c) {
        foreach (ccs_component_overlap_keys((string)$c) as $overlapKey) {
            $asked[$overlapKey] = true;
        }
    }
    $conf = [];
    foreach ($rows as $r) {
        $arr = json_decode((string)($r['allowed_components_json'] ?? '[]'), true);
        if (!is_array($arr)) continue;
        foreach ($arr as $ac) {
            $k = ccs_component_norm((string)$ac);
            foreach (ccs_component_overlap_keys($k) as $overlapKey) {
                if (isset($asked[$overlapKey])) {
                    $conf[$k] = true;
                    break;
                }
            }
        }
    }
    return array_values(array_keys($conf));
}

function ccs_active_conflict_sessions(PDO $pdo, int $caseId, array $components): array {
    if (!$components) return [];
    $asked = [];
    foreach ($components as $c) {
        foreach (ccs_component_overlap_keys((string)$c) as $overlapKey) {
            $asked[$overlapKey] = true;
        }
    }
    if (!$asked) return [];

    $st = $pdo->prepare("SELECT correction_session_id, case_id, application_id, correction_reason, allowed_components_json, token, requested_role, created_at FROM Vati_Payfiller_Candidate_Correction_Sessions WHERE case_id = ? AND status IN ('active','submitted') AND (expires_at IS NULL OR expires_at >= NOW()) ORDER BY correction_session_id DESC");
    $st->execute([$caseId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $arr = json_decode((string)($row['allowed_components_json'] ?? '[]'), true);
        if (!is_array($arr)) continue;
        $overlap = [];
        foreach ($arr as $componentKey) {
            $k = ccs_component_norm((string)$componentKey);
            foreach (ccs_component_overlap_keys($k) as $overlapKey) {
                if (isset($asked[$overlapKey])) {
                    $overlap[$k] = true;
                    break;
                }
            }
        }
        if ($overlap) {
            $row['components'] = array_values(array_keys($overlap));
            $out[] = $row;
        }
    }
    return $out;
}

function ccs_has_correction_mail_log(PDO $pdo, string $applicationId, int $caseId, string $createdAt = ''): bool {
    try {
        $params = [$applicationId, $caseId];
        $createdFilter = '';
        if (trim($createdAt) !== '') {
            $createdFilter = ' AND created_at >= ?';
            $params[] = $createdAt;
        }
        $st = $pdo->prepare(
            "SELECT 1
               FROM Vati_Payfiller_GSS_Mail_Logs
              WHERE status = 'sent'
                AND (application_id = ? OR case_id = ?)
                AND (meta_json LIKE '%candidate.correction.request%' OR subject LIKE '%Correction Required%')
                $createdFilter
              LIMIT 1"
        );
        $st->execute($params);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ccs_resend_existing_session_if_mail_missing(PDO $pdo, array $sessionRow, array $case): array {
    $sessionId = (int)($sessionRow['correction_session_id'] ?? 0);
    $caseId = (int)($sessionRow['case_id'] ?? ($case['case_id'] ?? 0));
    $applicationId = (string)($sessionRow['application_id'] ?? ($case['application_id'] ?? ''));
    if ($sessionId <= 0 || $caseId <= 0 || $applicationId === '') {
        return ['attempted' => false, 'sent' => false, 'reason' => 'invalid_session'];
    }
    if (ccs_has_correction_mail_log($pdo, $applicationId, $caseId, (string)($sessionRow['created_at'] ?? ''))) {
        return ['attempted' => false, 'sent' => true, 'reason' => 'already_sent'];
    }
    $components = $sessionRow['components'] ?? [];
    if (!$components) {
        $components = json_decode((string)($sessionRow['allowed_components_json'] ?? '[]'), true);
    }
    if (!is_array($components)) $components = [];
    $sent = ccs_send_mail(
        $pdo,
        $case,
        $components,
        (string)($sessionRow['correction_reason'] ?? ''),
        (string)($sessionRow['token'] ?? ''),
        (string)($sessionRow['requested_role'] ?? '')
    );
    if ($sent) {
        try {
            $up = $pdo->prepare("UPDATE Vati_Payfiller_Candidate_Correction_Sessions SET resend_count = resend_count + 1, updated_at = NOW() WHERE correction_session_id = ?");
            $up->execute([$sessionId]);
        } catch (Throwable $e) {
        }
    }
    return ['attempted' => true, 'sent' => $sent, 'reason' => $sent ? 'resent' : 'send_failed'];
}

function ccs_candidate_correction_url(string $token): string {
    $path = '/modules/candidate/candidate_correction.php?token=' . urlencode($token);
    $url = app_url($path);
    if (preg_match('~^https?:///~i', $url) || !preg_match('~^https?://[^/]+/~i', $url)) {
        $base = trim((string)(env_get('CANDIDATE_PORTAL_BASE_URL', '') ?? ''));
        if ($base === '') {
            $base = 'http://localhost/GSS';
        }
        $url = rtrim($base, '/') . $path;
    }
    return $url;
}

function ccs_send_mail(PDO $pdo, array $case, array $components, string $reason, string $token, string $role): bool {
    $appId = (string)($case['application_id'] ?? '');
    $name = trim((string)($case['candidate_first_name'] ?? '') . ' ' . (string)($case['candidate_last_name'] ?? ''));
    $to = trim((string)($case['candidate_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $url = ccs_candidate_correction_url($token);
    $safeUrl = htmlspecialchars($url);
    $safeName = htmlspecialchars($name !== '' ? $name : 'Candidate');
    $list = htmlspecialchars(implode(', ', $components));
    $safeReason = htmlspecialchars($reason !== '' ? $reason : 'Please update requested sections.');
    $subject = 'Correction Required - ' . $appId;
    $body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#0f172a;line-height:1.5;">'
        . '<p>Hello ' . $safeName . ',</p>'
        . '<p>Please update the following component(s): <b>' . $list . '</b></p>'
        . '<p>Reason: ' . $safeReason . '</p>'
        . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:10px 14px;background:#2563eb;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;">Open Correction Workspace</a></p>'
        . '<p style="font-size:12px;color:#64748b;">If button does not work, copy this link:<br><span style="word-break:break-all;">' . $safeUrl . '</span></p>'
        . '<p>Requested by: ' . strtoupper($role) . '</p>'
        . '</div>';
    app_mail_set_log_meta([
        'application_id' => $appId,
        'case_id' => (int)($case['case_id'] ?? 0),
        'event_type' => 'candidate.correction.request',
        'role' => $role
    ]);
    $ok = send_app_mail($to, $subject, $body, 'VATI GSS', ['application_id' => $appId, 'event_type' => 'candidate.correction.request']);
    app_mail_clear_log_meta();
    return $ok;
}
