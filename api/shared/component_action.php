<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/integration.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/case_component_binding.php';

auth_require_login(null);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function resolve_user_id(): int {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    return !empty($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
}

function resolve_user_role(): string {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    return !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : '';
}

function session_role_norm(): string {
    $role = strtolower(trim(resolve_user_role()));
    if ($role === 'customer_admin') $role = 'client_admin';
    return $role;
}

function norm_component_key(string $k): string {
    $k = strtolower(trim($k));
    if ($k === 'identification') return 'id';
    if ($k === 'social_media' || $k === 'social-media') return 'socialmedia';
    if ($k === 'driving' || $k === 'driving_license') return 'driving_licence';
    return $k;
}

function workflow_table_available(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM Vati_Payfiller_Case_Component_Workflow LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function component_item_workflow_table_available(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM Vati_Payfiller_Case_Component_Item_Workflow LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_component_item_workflow_table(PDO $pdo): void {
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Vati_Payfiller_Case_Component_Item_Workflow ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'case_id INT NOT NULL, '
            . 'application_id VARCHAR(64) NOT NULL, '
            . 'component_key VARCHAR(64) NOT NULL, '
            . 'item_key VARCHAR(191) NOT NULL, '
            . 'stage VARCHAR(32) NOT NULL, '
            . 'status VARCHAR(64) NOT NULL, '
            . 'updated_by_user_id INT NULL, '
            . 'updated_by_role VARCHAR(64) NULL, '
            . 'completed_at DATETIME NULL, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY (id), '
            . 'UNIQUE KEY uq_case_component_item_stage (case_id, component_key, item_key, stage), '
            . 'KEY idx_item_app (application_id, component_key, item_key), '
            . 'KEY idx_item_stage_status (case_id, component_key, stage, status)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
    }
}

function norm_item_key(string $k): string {
    $k = strtolower(trim($k));
    if ($k === '') return '';
    if (strlen($k) > 191) {
        $k = substr($k, 0, 191);
    }
    return $k;
}

function component_supports_item_workflow(string $componentKey): bool {
    $k = norm_component_key($componentKey);
    return in_array($k, ['id', 'education', 'employment'], true);
}

function role_to_stage(string $role): string {
    $role = strtolower(trim($role));
    if ($role === 'validator') return 'validator';
    if ($role === 'qa' || $role === 'team_lead') return 'qa';
    if ($role === 'verifier' || $role === 'db_verifier') return 'verifier';
    return $role;
}

function prev_stage(string $stage): string {
    $stage = strtolower(trim($stage));
    if ($stage === 'validator') return 'candidate';
    if ($stage === 'verifier') return 'validator';
    if ($stage === 'qa') return 'verifier';
    return '';
}

function bootstrap_prev_stage_if_completed(PDO $pdo, int $caseId, string $applicationId, string $componentKey, string $prevStage): bool {
    $prevStage = strtolower(trim($prevStage));
    if ($caseId <= 0 || $applicationId === '' || $componentKey === '' || $prevStage === '') return false;

    try {
        if ($prevStage === 'validator') {
            $q = $pdo->prepare('SELECT 1 FROM Vati_Payfiller_Validator_Queue WHERE case_id = ? AND completed_at IS NOT NULL LIMIT 1');
            $q->execute([$caseId]);
            $ok = (bool)$q->fetchColumn();
            if (!$ok) return false;

            $ins = $pdo->prepare(
                'INSERT INTO Vati_Payfiller_Case_Component_Workflow (case_id, application_id, component_key, stage, status, updated_by_user_id, updated_by_role, completed_at) '
                . 'VALUES (?, ?, ?, \'validator\', \'approved\', NULL, \'validator\', NOW()) '
                . 'ON DUPLICATE KEY UPDATE status = \'approved\', completed_at = COALESCE(completed_at, NOW()), updated_at = NOW()'
            );
            $ins->execute([$caseId, $applicationId, $componentKey]);
            return true;
        }

        if ($prevStage === 'verifier') {
            $q = $pdo->prepare(
                "SELECT\n"
                . "  SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END) AS open_items,\n"
                . "  COUNT(*) AS total_items\n"
                . "FROM Vati_Payfiller_Verifier_Group_Queue\n"
                . "WHERE case_id = ?"
            );
            $q->execute([$caseId]);
            $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            $open = (int)($r['open_items'] ?? 0);
            $total = (int)($r['total_items'] ?? 0);
            if ($total < 2 || $open > 0) return false;

            $ins = $pdo->prepare(
                'INSERT INTO Vati_Payfiller_Case_Component_Workflow (case_id, application_id, component_key, stage, status, updated_by_user_id, updated_by_role, completed_at) '
                . 'VALUES (?, ?, ?, \'verifier\', \'approved\', NULL, \'verifier\', NOW()) '
                . 'ON DUPLICATE KEY UPDATE status = \'approved\', completed_at = COALESCE(completed_at, NOW()), updated_at = NOW()'
            );
            $ins->execute([$caseId, $applicationId, $componentKey]);
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

function session_allowed_sections(): array {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $raw = isset($_SESSION['auth_allowed_sections']) ? (string)$_SESSION['auth_allowed_sections'] : '';
    $raw = strtolower(trim($raw));
    if ($raw === '' || $raw === '*') return ['*' => true];
    $parts = preg_split('/[\s,|]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $k = norm_component_key((string)$p);
        if ($k === '') continue;
        $out[$k] = true;
    }
    return $out;
}

function can_section(array $allowedSet, string $key): bool {
    if (isset($allowedSet['*'])) return true;
    $k = strtolower(trim($key));
    return $k !== '' && isset($allowedSet[$k]);
}

function verifier_group_for_component(string $componentKey): string {
    $k = strtolower(trim($componentKey));
    if (in_array($k, ['basic', 'id', 'contact'], true)) return 'BASIC';
    if (in_array($k, ['education', 'employment', 'reference'], true)) return 'EDUCATION';
    if (in_array($k, ['ecourt', 'socialmedia'], true)) return 'ADDITIONAL';
    return '';
}

function verifier_group_components(string $groupKey): array {
    $g = strtoupper(trim($groupKey));
    if ($g === 'BASIC') return ['basic', 'id', 'contact'];
    if ($g === 'EDUCATION') return ['education', 'employment', 'reference'];
    if ($g === 'ADDITIONAL') return ['ecourt', 'socialmedia'];
    return [];
}

function sync_verifier_group_queue(PDO $pdo, int $caseId, int $userId, string $componentKey): void {
    $groupKey = verifier_group_for_component($componentKey);
    if ($caseId <= 0 || $userId <= 0 || $groupKey === '') return;

    $parts = verifier_group_components($groupKey);
    if (!$parts) return;
    $ph = implode(',', array_fill(0, count($parts), '?'));

    // Group is "pending" for verifier only when validator approved and verifier not finalized.
    $sqlPending =
        'SELECT COUNT(*) AS pending_count '
        . 'FROM Vati_Payfiller_Case_Components c '
        . "LEFT JOIN Vati_Payfiller_Case_Component_Workflow v ON v.case_id = c.case_id AND LOWER(TRIM(v.component_key)) = LOWER(TRIM(c.component_key)) AND v.stage = 'validator' "
        . "LEFT JOIN Vati_Payfiller_Case_Component_Workflow w ON w.case_id = c.case_id AND LOWER(TRIM(w.component_key)) = LOWER(TRIM(c.component_key)) AND w.stage = 'verifier' "
        . 'WHERE c.case_id = ? AND c.is_required = 1 AND LOWER(TRIM(c.component_key)) IN (' . $ph . ') '
        . "AND COALESCE(LOWER(TRIM(v.status)), '') = 'approved' "
        . "AND COALESCE(LOWER(TRIM(w.status)), '') NOT IN ('approved','rejected')";

    $params = array_merge([$caseId], $parts);
    $st = $pdo->prepare($sqlPending);
    $st->execute($params);
    $pending = (int)($st->fetchColumn() ?: 0);

    if ($pending > 0) {
        // Keep queue ownership/progress in sync when verifier is actively working this group.
        $touch = $pdo->prepare(
            "UPDATE Vati_Payfiller_Verifier_Group_Queue
             SET assigned_user_id = COALESCE(assigned_user_id, ?),
                 claimed_at = COALESCE(claimed_at, NOW()),
                 status = CASE
                     WHEN COALESCE(LOWER(TRIM(status)), '') = 'followup' THEN status
                     WHEN completed_at IS NULL THEN 'in_progress'
                     ELSE status
                 END
             WHERE case_id = ?
               AND UPPER(TRIM(group_key)) = ?"
        );
        $touch->execute([$userId, $caseId, $groupKey]);
        return;
    }

    // No pending verifier-actionable components left in this group -> complete queue row.
    // Do not require prior assigned_user match; stale assignment metadata should not block dashboard sync.
    $upd = $pdo->prepare(
        "UPDATE Vati_Payfiller_Verifier_Group_Queue
         SET status = 'done',
             completed_at = COALESCE(completed_at, NOW()),
             assigned_user_id = COALESCE(assigned_user_id, ?),
             claimed_at = COALESCE(claimed_at, NOW())
         WHERE case_id = ?
           AND UPPER(TRIM(group_key)) = ?
           AND completed_at IS NULL"
    );
    $upd->execute([$userId, $caseId, $groupKey]);
}

function sync_validator_queue(PDO $pdo, int $caseId, int $userId, bool $useWorkflow): void {
    if ($caseId <= 0 || $userId <= 0) return;

    $pending = 0;
    if ($useWorkflow) {
        $sqlPending =
            'SELECT COUNT(*) AS pending_count '
            . 'FROM Vati_Payfiller_Case_Components c '
            . "LEFT JOIN Vati_Payfiller_Case_Component_Workflow cand ON cand.case_id = c.case_id AND LOWER(TRIM(cand.component_key)) = LOWER(TRIM(c.component_key)) AND cand.stage = 'candidate' "
            . "LEFT JOIN Vati_Payfiller_Case_Component_Workflow val ON val.case_id = c.case_id AND LOWER(TRIM(val.component_key)) = LOWER(TRIM(c.component_key)) AND val.stage = 'validator' "
            . 'WHERE c.case_id = ? AND c.is_required = 1 '
            . "AND LOWER(TRIM(c.component_key)) <> 'reports' "
            . "AND COALESCE(LOWER(TRIM(cand.status)), '') = 'approved' "
            . "AND COALESCE(LOWER(TRIM(val.status)), '') NOT IN ('approved','rejected')";
        $st = $pdo->prepare($sqlPending);
        $st->execute([$caseId]);
        $pending = (int)($st->fetchColumn() ?: 0);
    } else {
        $sqlPending =
            'SELECT COUNT(*) AS pending_count '
            . 'FROM Vati_Payfiller_Case_Components '
            . 'WHERE case_id = ? AND is_required = 1 '
            . "AND LOWER(TRIM(component_key)) <> 'reports' "
            . "AND COALESCE(LOWER(TRIM(status)), '') NOT IN ('approved','rejected')";
        $st = $pdo->prepare($sqlPending);
        $st->execute([$caseId]);
        $pending = (int)($st->fetchColumn() ?: 0);
    }

    if ($pending > 0) {
        $touch = $pdo->prepare(
            "UPDATE Vati_Payfiller_Validator_Queue
             SET assigned_user_id = COALESCE(assigned_user_id, ?),
                 claimed_at = COALESCE(claimed_at, NOW()),
                 status = CASE
                     WHEN COALESCE(LOWER(TRIM(status)), '') = 'followup' THEN status
                     WHEN completed_at IS NULL THEN 'in_progress'
                     ELSE status
                 END
             WHERE case_id = ?
               AND completed_at IS NULL"
        );
        $touch->execute([$userId, $caseId]);
        return;
    }

    $upd = $pdo->prepare(
        "UPDATE Vati_Payfiller_Validator_Queue
         SET status = 'done',
             completed_at = COALESCE(completed_at, NOW()),
             assigned_user_id = COALESCE(assigned_user_id, ?),
             claimed_at = COALESCE(claimed_at, NOW())
         WHERE case_id = ?
           AND completed_at IS NULL"
    );
    $upd->execute([$userId, $caseId]);
}

function html_escape(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function fetch_candidate_context(PDO $pdo, string $applicationId): array {
    try {
        $st = $pdo->prepare(
            "SELECT
                c.candidate_first_name,
                c.candidate_last_name,
                c.candidate_email,
                b.first_name AS basic_first_name,
                b.last_name AS basic_last_name,
                b.email AS basic_email
             FROM Vati_Payfiller_Cases c
             LEFT JOIN Vati_Payfiller_Candidate_Basic_details b ON b.application_id = c.application_id
             WHERE c.application_id = ?
             LIMIT 1"
        );
        $st->execute([$applicationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $email = integration_normalize_email((string)($row['basic_email'] ?? $row['candidate_email'] ?? ''));
        $first = trim((string)($row['basic_first_name'] ?? $row['candidate_first_name'] ?? ''));
        $last = trim((string)($row['basic_last_name'] ?? $row['candidate_last_name'] ?? ''));
        $name = trim($first . ' ' . $last);

        return [
            'email' => $email,
            'name' => $name !== '' ? $name : 'Candidate'
        ];
    } catch (Throwable $e) {
        return ['email' => '', 'name' => 'Candidate'];
    }
}

function component_label(string $componentKey): string {
    $k = strtolower(trim($componentKey));
    if ($k === 'id') return 'Identification';
    if ($k === 'education') return 'Education';
    if ($k === 'employment') return 'Employment';
    if ($k === 'basic') return 'Basic';
    if ($k === 'contact') return 'Contact';
    if ($k === 'reference') return 'Reference';
    if ($k === 'socialmedia') return 'Social Media';
    if ($k === 'ecourt') return 'E-court';
    if ($k === 'reports') return 'Reports';
    if ($k === 'driving_licence') return 'Driving Licence';
    return ucfirst($k);
}

function send_component_action_email(PDO $pdo, string $applicationId, string $componentKey, string $action, string $reason, string $role): bool {
    $ctx = fetch_candidate_context($pdo, $applicationId);
    $to = trim((string)($ctx['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $section = component_label($componentKey);
    $actorRole = ucfirst(strtolower(trim($role)));
    $candidateName = (string)($ctx['name'] ?? 'Candidate');
    $safeName = html_escape($candidateName);
    $safeSection = html_escape($section);
    $safeReason = nl2br(html_escape($reason));

    $subject = '';
    $body = '';
    if ($action === 'reject') {
        $subject = 'BGV update: ' . $section . ' verification rejected';
        $body =
            '<div style="font-family:Arial,sans-serif;font-size:13px;color:#0f172a;">'
            . '<p>Dear ' . $safeName . ',</p>'
            . '<p>Your <b>' . $safeSection . '</b> verification has been rejected by ' . html_escape($actorRole) . '.</p>'
            . '<p><b>Reason:</b><br>' . $safeReason . '</p>'
            . '<p>Please update and re-submit the required information/documents.</p>'
            . '<p>Application ID: <b>' . html_escape($applicationId) . '</b></p>'
            . '</div>';
    } elseif ($action === 'insufficient_documents') {
        $subject = 'BGV update: documents required for ' . $section;
        $body =
            '<div style="font-family:Arial,sans-serif;font-size:13px;color:#0f172a;">'
            . '<p>Dear ' . $safeName . ',</p>'
            . '<p>Your <b>' . $safeSection . '</b> verification needs additional documents.</p>'
            . '<p><b>Required details:</b><br>' . $safeReason . '</p>'
            . '<p>Please upload the requested documents to proceed. You may also reply to this email with clarification or details.</p>'
            . '<p>Application ID: <b>' . html_escape($applicationId) . '</b></p>'
            . '</div>';
    } elseif ($action === 'hold') {
        $sendHold = trim((string)(env_get('APP_SEND_HOLD_EMAIL', '0') ?? '0')) === '1';
        if (!$sendHold) return false;
        $subject = 'BGV update: ' . $section . ' verification on hold';
        $body =
            '<div style="font-family:Arial,sans-serif;font-size:13px;color:#0f172a;">'
            . '<p>Dear ' . $safeName . ',</p>'
            . '<p>Your <b>' . $safeSection . '</b> verification is currently on hold.</p>'
            . '<p><b>Reason:</b><br>' . $safeReason . '</p>'
            . '<p>Application ID: <b>' . html_escape($applicationId) . '</b></p>'
            . '</div>';
    } else {
        return false;
    }

    app_mail_set_log_meta([
        'application_id' => $applicationId,
        'event_type' => 'component.action.email',
        'section' => $componentKey,
        'action' => $action
    ]);
    $ok = send_app_mail($to, $subject, $body, 'VATI GSS', [
        'application_id' => $applicationId,
        'event_type' => 'component.action.email',
    ]);
    app_mail_clear_log_meta();
    return $ok;
}

function save_component_action_log(PDO $pdo, int $caseId, string $applicationId, string $componentKey, string $stage, string $action, string $status, string $reason, int $userId, string $role): void {
    try {
        $ins = $pdo->prepare(
            'INSERT INTO Vati_Payfiller_Component_Action_Log '
            . '(case_id, application_id, component_key, stage, action_type, status, reason, actor_user_id, actor_role, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute([
            $caseId,
            $applicationId,
            $componentKey,
            $stage,
            $action,
            $status,
            $reason !== '' ? $reason : null,
            $userId,
            $role
        ]);
    } catch (Throwable $e) {
        // Table may not exist in old installs; keep workflow non-blocking.
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $applicationId = isset($input['application_id']) ? integration_normalize_application_id((string)$input['application_id']) : '';
    $caseId = isset($input['case_id']) ? (int)$input['case_id'] : 0;
    $componentKey = isset($input['component_key']) ? norm_component_key((string)$input['component_key']) : '';
    $itemKey = isset($input['item_key']) ? norm_item_key((string)$input['item_key']) : '';
    $action = isset($input['action']) ? strtolower(trim((string)$input['action'])) : '';
    $groupKey = isset($input['group']) ? strtoupper(trim((string)$input['group'])) : '';
    $overrideReason = isset($input['override_reason']) ? trim((string)$input['override_reason']) : '';
    $reason = isset($input['reason']) ? trim((string)$input['reason']) : '';
    if ($reason === '' && $overrideReason !== '') {
        $reason = $overrideReason;
    }

    if ($applicationId === '' || $caseId <= 0 || $componentKey === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id, case_id and component_key are required']);
        exit;
    }

    $allowed = ['hold', 'reject', 'approve', 'insufficient_documents'];
    if (!in_array($action, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Invalid action']);
        exit;
    }

    if (($action === 'hold' || $action === 'reject' || $action === 'insufficient_documents') && $reason === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Reason is required for this action']);
        exit;
    }

    $userId = resolve_user_id();
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Not logged in']);
        exit;
    }

    $role = session_role_norm();
    if (!in_array($role, ['verifier', 'validator', 'db_verifier', 'qa', 'team_lead'], true)) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Forbidden']);
        exit;
    }

    $pdo = getDB();
    ensure_component_item_workflow_table($pdo);

    $useWorkflow = workflow_table_available($pdo);
    $useItemWorkflow = component_item_workflow_table_available($pdo)
        && $itemKey !== ''
        && component_supports_item_workflow($componentKey);
    $stage = role_to_stage($role);
    $overrideReasonContext = '';

    // Ensure component row exists
    try {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO Vati_Payfiller_Case_Components (case_id, application_id, component_key, is_required, status) '
            . 'VALUES (?, ?, ?, 1, \'pending\')'
        );
        $ins->execute([$caseId, $applicationId, $componentKey]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 0, 'message' => 'Component table not installed']);
        exit;
    }

    // Enforce assignment (QA/Team Lead can bypass)
   if (!in_array($role, ['qa', 'team_lead', 'validator'], true)) {
        $as = $pdo->prepare(
            'SELECT assigned_role, assigned_user_id '
            . 'FROM Vati_Payfiller_Case_Components '
            . 'WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ? LIMIT 1'
        );
        $as->execute([$caseId, $applicationId, $componentKey]);
        $row = $as->fetch(PDO::FETCH_ASSOC) ?: null;

        $assignedRole = $row ? strtolower(trim((string)($row['assigned_role'] ?? ''))) : '';
        $assignedUserId = $row && isset($row['assigned_user_id']) ? (int)$row['assigned_user_id'] : 0;

        // Auto-fetch mode:
        // If no explicit per-component assignment exists, allow based on allowed_sections.
        // If explicit assignment exists, enforce it strictly.
        if ($assignedRole === '' || $assignedUserId <= 0) {
            $configAllowed = case_component_binding_role_allowed($pdo, $caseId, $applicationId, $componentKey, $role);
            if ($configAllowed === false) {
                http_response_code(403);
                echo json_encode(['status' => 0, 'message' => 'Not assigned to this component']);
                exit;
            }

            $allowedSet = session_allowed_sections();
            if (!can_section($allowedSet, $componentKey)) {
                http_response_code(403);
                echo json_encode(['status' => 0, 'message' => 'Not assigned to this component']);
                exit;
            }
        } else {
            if ($assignedRole !== $role || $assignedUserId !== $userId) {
                http_response_code(403);
                echo json_encode([
                    'status' => 0,
                    'message' => 'Not assigned to this component',
                    'data' => [
                        'assigned_role' => $assignedRole,
                        'assigned_user_id' => $assignedUserId
                    ]
                ]);
                exit;
            }
        }
    }

    // Stage-order enforcement (workflow table only)
    if ($useWorkflow) {
        // Seed candidate stage first so validator can pass prev-stage gate.
        try {
            $insCand = $pdo->prepare(
                'INSERT INTO Vati_Payfiller_Case_Component_Workflow (case_id, application_id, component_key, stage, status, updated_by_user_id, updated_by_role, completed_at) '
                . 'VALUES (?, ?, ?, \'candidate\', \'approved\', NULL, \'candidate\', NOW()) '
                . 'ON DUPLICATE KEY UPDATE stage = stage'
            );
            $insCand->execute([$caseId, $applicationId, $componentKey]);
        } catch (Throwable $e) {
        }

        // Lock: do not allow changing final stage decisions
        try {
            if ($useItemWorkflow) {
                $cs = $pdo->prepare(
                    'SELECT status FROM Vati_Payfiller_Case_Component_Item_Workflow '
                    . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND LOWER(TRIM(item_key)) = ? AND stage = ? LIMIT 1'
                );
                $cs->execute([$caseId, $componentKey, $itemKey, $stage]);
            } else {
                $cs = $pdo->prepare(
                    'SELECT status FROM Vati_Payfiller_Case_Component_Workflow '
                    . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ? LIMIT 1'
                );
                $cs->execute([$caseId, $componentKey, $stage]);
            }
            $cur = strtolower(trim((string)($cs->fetchColumn() ?: '')));
            if ($cur === 'approved' || $cur === 'rejected') {
                http_response_code(400);
                echo json_encode(['status' => 0, 'message' => 'Action already finalized: ' . $cur]);
                exit;
            }
        } catch (Throwable $e) {
        }

        $prev = prev_stage($stage);
        if ($prev !== '') {
            try {
                if ($useItemWorkflow) {
                    $ps = $pdo->prepare(
                        'SELECT status FROM Vati_Payfiller_Case_Component_Item_Workflow '
                        . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND LOWER(TRIM(item_key)) = ? AND stage = ? LIMIT 1'
                    );
                    $ps->execute([$caseId, $componentKey, $itemKey, $prev]);
                } else {
                    $ps = $pdo->prepare(
                        'SELECT status FROM Vati_Payfiller_Case_Component_Workflow '
                        . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ? LIMIT 1'
                    );
                    $ps->execute([$caseId, $componentKey, $prev]);
                }
                $prevStatus = strtolower(trim((string)($ps->fetchColumn() ?: '')));
                if ($prevStatus !== 'approved') {
                    if ($useItemWorkflow) {
                        // Fallback to section-level previous-stage status when item-level
                        // status has not been captured yet (backward-compatible rollout).
                        $psComp = $pdo->prepare(
                            'SELECT status FROM Vati_Payfiller_Case_Component_Workflow '
                            . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ? LIMIT 1'
                        );
                        $psComp->execute([$caseId, $componentKey, $prev]);
                        $prevStatus = strtolower(trim((string)($psComp->fetchColumn() ?: $prevStatus)));
                    } else {
                        // Backward compatibility: bootstrap missing previous-stage workflow rows
                        // from existing queue completion markers (validator/verifier).
                        if (bootstrap_prev_stage_if_completed($pdo, $caseId, $applicationId, $componentKey, $prev)) {
                            $ps->execute([$caseId, $componentKey, $prev]);
                            $prevStatus = strtolower(trim((string)($ps->fetchColumn() ?: '')));
                        }
                    }
                }
                if ($prevStatus !== 'approved') {
                    if ($prevStatus === 'rejected') {
                        if ($stage === 'verifier' && $prev === 'validator' && $action === 'approve') {
                            if ($reason === '') {
                                http_response_code(409);
                                echo json_encode([
                                    'status' => 0,
                                    'message' => 'Validator rejected this component. Verifier reason is required to approve.'
                                ]);
                                exit;
                            }
                            $overrideReasonContext = 'verifier_on_validator_rejected';
                        } elseif (($stage === 'qa') && $prev === 'verifier' && ($action === 'approve' || $action === 'reject')) {
                            if ($reason === '') {
                                http_response_code(409);
                                echo json_encode([
                                    'status' => 0,
                                    'message' => 'Verifier rejected this component. QA reason is required to proceed.'
                                ]);
                                exit;
                            }
                            $overrideReasonContext = 'qa_on_verifier_rejected';
                        } else {
                            http_response_code(409);
                            $blockedMsg = ($prev === 'validator')
                                ? 'Validator rejected this component. Verifier cannot approve it.'
                                : ('Previous stage rejected: ' . $prev);
                            echo json_encode(['status' => 0, 'message' => $blockedMsg]);
                            exit;
                        }
                    }
                    http_response_code(400);
                    echo json_encode(['status' => 0, 'message' => 'Previous stage pending: ' . $prev]);
                    exit;
                }
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['status' => 0, 'message' => 'Workflow check failed']);
                exit;
            }
        }
    }

    // Legacy lock: if component already approved/rejected, block
    if (!$useWorkflow) {
        try {
            $cs = $pdo->prepare(
                'SELECT status FROM Vati_Payfiller_Case_Components '
                . 'WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ? LIMIT 1'
            );
            $cs->execute([$caseId, $applicationId, $componentKey]);
            $cur = strtolower(trim((string)($cs->fetchColumn() ?: '')));
            if ($cur === 'approved' || $cur === 'rejected') {
                http_response_code(400);
                echo json_encode(['status' => 0, 'message' => 'Action already finalized: ' . $cur]);
                exit;
            }
        } catch (Throwable $e) {
        }
    }

    $newStatus = 'hold';
    if ($action === 'approve') $newStatus = 'approved';
    if ($action === 'reject') $newStatus = 'rejected';
    if ($action === 'insufficient_documents') $newStatus = 'insufficient_documents';
    $completedAt = $action === 'approve' ? 'NOW()' : 'NULL';
    $componentStatusToPersist = $newStatus;
    $componentCompletedAtExpr = $completedAt;

    if ($useItemWorkflow) {
        try {
            $itemCompletedExpr = $action === 'approve' ? 'NOW()' : 'NULL';
            $iw = $pdo->prepare(
                'INSERT INTO Vati_Payfiller_Case_Component_Item_Workflow '
                . '(case_id, application_id, component_key, item_key, stage, status, updated_by_user_id, updated_by_role, completed_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . $itemCompletedExpr . ') '
                . 'ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by_user_id = VALUES(updated_by_user_id), updated_by_role = VALUES(updated_by_role), completed_at = ' . $itemCompletedExpr . ', updated_at = NOW()'
            );
            $iw->execute([$caseId, $applicationId, $componentKey, $itemKey, $stage, $newStatus, $userId, $role]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 0, 'message' => 'Item workflow update failed']);
            exit;
        }

        try {
            $agg = $pdo->prepare(
                'SELECT '
                . "SUM(CASE WHEN LOWER(TRIM(status)) = 'rejected' THEN 1 ELSE 0 END) AS rejected_count, "
                . "SUM(CASE WHEN LOWER(TRIM(status)) = 'approved' THEN 1 ELSE 0 END) AS approved_count, "
                . "SUM(CASE WHEN LOWER(TRIM(status)) NOT IN ('approved','rejected') THEN 1 ELSE 0 END) AS pending_count "
                . 'FROM Vati_Payfiller_Case_Component_Item_Workflow '
                . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ?'
            );
            $agg->execute([$caseId, $componentKey, $stage]);
            $ar = $agg->fetch(PDO::FETCH_ASSOC) ?: [];
            $pendingCount = (int)($ar['pending_count'] ?? 0);
            $rejectedCount = (int)($ar['rejected_count'] ?? 0);
            $approvedCount = (int)($ar['approved_count'] ?? 0);
            if ($pendingCount > 0) {
                $componentStatusToPersist = 'pending';
                $componentCompletedAtExpr = 'NULL';
            } elseif ($rejectedCount > 0) {
                $componentStatusToPersist = 'rejected';
                $componentCompletedAtExpr = 'NULL';
            } elseif ($approvedCount > 0) {
                $componentStatusToPersist = 'approved';
                $componentCompletedAtExpr = 'NOW()';
            } else {
                $componentStatusToPersist = 'pending';
                $componentCompletedAtExpr = 'NULL';
            }
        } catch (Throwable $e) {
            $componentStatusToPersist = 'pending';
            $componentCompletedAtExpr = 'NULL';
        }
    }

    $upd = $pdo->prepare(
        'UPDATE Vati_Payfiller_Case_Components '
        . 'SET status = ?, completed_at = ' . $componentCompletedAtExpr . ', updated_at = NOW() '
        . 'WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ?'
    );
    $upd->execute([$componentStatusToPersist, $caseId, $applicationId, $componentKey]);

    if ($useWorkflow) {
        try {
            $completedExpr = $componentCompletedAtExpr;
            $w = $pdo->prepare(
                'INSERT INTO Vati_Payfiller_Case_Component_Workflow '
                . '(case_id, application_id, component_key, stage, status, updated_by_user_id, updated_by_role, completed_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ' . $completedExpr . ') '
                . 'ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by_user_id = VALUES(updated_by_user_id), updated_by_role = VALUES(updated_by_role), completed_at = ' . $completedExpr . ', updated_at = NOW()'
            );
            $w->execute([$caseId, $applicationId, $componentKey, $stage, $componentStatusToPersist, $userId, $role]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 0, 'message' => 'Workflow update failed']);
            exit;
        }
    }

    // Best-effort: log to case timeline
    try {
        $labelMap = ['hold' => 'HOLD', 'reject' => 'REJECTED', 'approve' => 'APPROVED', 'insufficient_documents' => 'INSUFFICIENT_DOCUMENTS'];
        $label = $labelMap[$action] ?? strtoupper($action);
        $stageLabel = strtoupper(trim((string)$stage));
        $msg = $stageLabel . ' component status: ' . $label;
        if ($stage === 'verifier' && $action === 'approve' && $reason !== '') {
            $msg .= ' (verifier override after validator rejection)';
        } elseif ($stage === 'qa' && $overrideReasonContext === 'qa_on_verifier_rejected' && $reason !== '') {
            $msg .= ' (QA decision on verifier rejected component)';
        }
        $log = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $log->execute([$applicationId, $userId, resolve_user_role() ?: null, 'update', $componentKey, $msg]);
        if (($stage === 'verifier' && $action === 'approve' && $reason !== '') || ($stage === 'qa' && $overrideReasonContext === 'qa_on_verifier_rejected' && $reason !== '')) {
            $prefix = ($stage === 'qa')
                ? 'QA reason on verifier rejected component: '
                : 'Verifier override reason: ';
            $comment = $prefix . $reason;
            $log->execute([$applicationId, $userId, resolve_user_role() ?: null, 'comment', $componentKey, $comment]);
        } elseif (in_array($action, ['hold', 'reject', 'insufficient_documents'], true) && $reason !== '') {
            $reasonPrefix = ($action === 'insufficient_documents')
                ? 'Insufficient documents details: '
                : ('Action reason (' . strtoupper($action) . '): ');
            $log->execute([$applicationId, $userId, resolve_user_role() ?: null, 'comment', $componentKey, $reasonPrefix . $reason]);
        }
    } catch (Throwable $e) {
    }

    save_component_action_log($pdo, $caseId, $applicationId, $componentKey, $stage, $action, $newStatus, $reason, $userId, $role);

    $mailSent = null;
    $shouldAttemptMail = in_array($action, ['reject', 'insufficient_documents', 'hold'], true) && $reason !== '';
    if ($shouldAttemptMail && $action === 'hold') {
        $shouldAttemptMail = trim((string)(env_get('APP_SEND_HOLD_EMAIL', '0') ?? '0')) === '1';
    }
    if ($shouldAttemptMail) {
        try {
            $mailSent = send_component_action_email($pdo, $applicationId, $componentKey, $action, $reason, $role);
        } catch (Throwable $e) {
            $mailSent = false;
        }
    }

    $caseStatus = null;
    $appStatus = null;

    // Advance case/application status when all required components are approved at current stage.
    if ($action === 'approve' && $componentStatusToPersist === 'approved') {
        try {
            $pendingCount = 0;
            $excludeReports = ($stage === 'validator' || $stage === 'verifier');
            if ($useWorkflow) {
                $check = $pdo->prepare(
                    'SELECT COUNT(*) AS pending_count '
                    . 'FROM Vati_Payfiller_Case_Components c '
                    . 'LEFT JOIN Vati_Payfiller_Case_Component_Workflow w '
                    . 'ON w.case_id = c.case_id AND LOWER(TRIM(w.component_key)) = LOWER(TRIM(c.component_key)) AND w.stage = ? '
                    . 'WHERE c.application_id = ? AND c.is_required = 1 '
                    . ($excludeReports ? "AND LOWER(TRIM(c.component_key)) <> 'reports' " : '')
                    . 'AND (w.status IS NULL OR LOWER(TRIM(w.status)) <> \'approved\')'
                );
                $check->execute([$stage, $applicationId]);
                $pendingCount = (int)($check->fetchColumn() ?: 0);
            } else {
                $check = $pdo->prepare(
                    'SELECT COUNT(*) AS pending_count '
                    . 'FROM Vati_Payfiller_Case_Components '
                    . 'WHERE application_id = ? AND is_required = 1 '
                    . ($excludeReports ? "AND LOWER(TRIM(component_key)) <> 'reports' " : '')
                    . 'AND LOWER(TRIM(status)) <> \'approved\''
                );
                $check->execute([$applicationId]);
                $pendingCount = (int)($check->fetchColumn() ?: 0);
            }

            if ($pendingCount === 0) {
                if ($stage === 'qa') {
                    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_CaseAction(?, ?, ?)');
                    $stmt->execute([$applicationId, 'approve', $userId]);
                    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $stmt->closeCursor();
                    $caseStatus = (string)($r['case_status'] ?? '');
                    $appStatus = isset($r['application_status']) ? $r['application_status'] : null;
                } else {
                    $nextStatus = ($stage === 'verifier') ? 'PENDING_QA' : (($stage === 'validator') ? 'PENDING_VERIFIER' : '');
                    if ($nextStatus !== '') {
                        try {
                            $uCase = $pdo->prepare(
                                "UPDATE Vati_Payfiller_Cases
                                 SET case_status = ?
                                 WHERE application_id = ?
                                   AND UPPER(TRIM(COALESCE(case_status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED')"
                            );
                            $uCase->execute([$nextStatus, $applicationId]);
                        } catch (Throwable $e) {
                        }
                        try {
                            $uApp = $pdo->prepare(
                                "UPDATE Vati_Payfiller_Candidate_Applications
                                 SET status = ?
                                 WHERE application_id = ?
                                   AND UPPER(TRIM(COALESCE(status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED')"
                            );
                            $uApp->execute([$nextStatus, $applicationId]);
                        } catch (Throwable $e) {
                        }
                        $caseStatus = $nextStatus;
                        $appStatus = $nextStatus;
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }

    // When validator/verifier have no open components left (approved/rejected are both final),
    // move case/application to next stage automatically.
    if (($stage === 'validator' || $stage === 'verifier')
        && ($action === 'approve' || $action === 'reject')
        && ($componentStatusToPersist === 'approved' || $componentStatusToPersist === 'rejected')) {
        try {
            $openCount = 0;
            if ($useWorkflow) {
                $checkOpen = $pdo->prepare(
                    'SELECT COUNT(*) AS open_count '
                    . 'FROM Vati_Payfiller_Case_Components c '
                    . 'LEFT JOIN Vati_Payfiller_Case_Component_Workflow w '
                    . 'ON w.case_id = c.case_id AND LOWER(TRIM(w.component_key)) = LOWER(TRIM(c.component_key)) AND w.stage = ? '
                    . 'WHERE c.application_id = ? AND c.is_required = 1 '
                    . "AND LOWER(TRIM(c.component_key)) <> 'reports' "
                    . "AND COALESCE(LOWER(TRIM(w.status)), '') NOT IN ('approved','rejected')"
                );
                $checkOpen->execute([$stage, $applicationId]);
                $openCount = (int)($checkOpen->fetchColumn() ?: 0);
            } else {
                $checkOpen = $pdo->prepare(
                    'SELECT COUNT(*) AS open_count '
                    . 'FROM Vati_Payfiller_Case_Components '
                    . 'WHERE application_id = ? AND is_required = 1 '
                    . "AND LOWER(TRIM(component_key)) <> 'reports' "
                    . "AND COALESCE(LOWER(TRIM(status)), '') NOT IN ('approved','rejected')"
                );
                $checkOpen->execute([$applicationId]);
                $openCount = (int)($checkOpen->fetchColumn() ?: 0);
            }

            if ($openCount === 0) {
                $nextStatus = ($stage === 'verifier') ? 'PENDING_QA' : 'PENDING_VERIFIER';
                try {
                    $uCase = $pdo->prepare(
                        "UPDATE Vati_Payfiller_Cases
                         SET case_status = ?
                         WHERE application_id = ?
                           AND UPPER(TRIM(COALESCE(case_status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED')"
                    );
                    $uCase->execute([$nextStatus, $applicationId]);
                } catch (Throwable $e) {
                }
                try {
                    $uApp = $pdo->prepare(
                        "UPDATE Vati_Payfiller_Candidate_Applications
                         SET status = ?
                         WHERE application_id = ?
                           AND UPPER(TRIM(COALESCE(status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED')"
                    );
                    $uApp->execute([$nextStatus, $applicationId]);
                } catch (Throwable $e) {
                }
                $caseStatus = $nextStatus;
                $appStatus = $nextStatus;
            }
        } catch (Throwable $e) {
        }
    }

    // QA rejection should finalize the case without separate "Complete and Next".
    if ($stage === 'qa' && $action === 'reject') {
        // Best effort call to keep legacy procedure side-effects/audits.
        try {
            $stmtReject = $pdo->prepare('CALL SP_Vati_Payfiller_CaseAction(?, ?, ?)');
            $stmtReject->execute([$applicationId, 'reject', $userId]);
            while ($stmtReject->nextRowset()) {
            }
        } catch (Throwable $e) {
        }

        // Hard rule: QA reject must always move case/application to REJECTED.
        try {
            $uCaseRej = $pdo->prepare(
                "UPDATE Vati_Payfiller_Cases
                 SET case_status = 'REJECTED'
                 WHERE application_id = ?
                   AND UPPER(TRIM(COALESCE(case_status,''))) NOT IN ('APPROVED','COMPLETED','CLEAR')"
            );
            $uCaseRej->execute([$applicationId]);
        } catch (Throwable $e2) {
        }
        try {
            $uAppRej = $pdo->prepare(
                "UPDATE Vati_Payfiller_Candidate_Applications
                 SET status = 'REJECTED'
                 WHERE application_id = ?
                   AND UPPER(TRIM(COALESCE(status,''))) NOT IN ('APPROVED','COMPLETED','CLEAR')"
            );
            $uAppRej->execute([$applicationId]);
        } catch (Throwable $e3) {
        }
        $caseStatus = 'REJECTED';
        $appStatus = 'REJECTED';
    }

    // Keep verifier dashboard queue KPIs in sync with the final saved component/workflow state.
    // This must run after updates above, otherwise "last component done" isn't detected.
    if ($stage === 'verifier'
        && ($action === 'approve' || $action === 'reject')
        && ($componentStatusToPersist === 'approved' || $componentStatusToPersist === 'rejected')) {
        try {
            sync_verifier_group_queue($pdo, $caseId, $userId, $componentKey);
        } catch (Throwable $e) {
        }
    }

    // Validator queue should also auto-complete when all validator components are finalized.
    if ($stage === 'validator'
        && ($action === 'approve' || $action === 'reject')
        && ($componentStatusToPersist === 'approved' || $componentStatusToPersist === 'rejected')) {
        try {
            sync_validator_queue($pdo, $caseId, $userId, $useWorkflow);
        } catch (Throwable $e) {
        }
    }

    $eventType = ($action === 'approve' && ($caseStatus !== null || $appStatus !== null))
        ? 'workflow.stage.changed'
        : 'application.status.changed';
    $links = integration_deep_links($applicationId, $caseId);
    $webhook = integration_send_webhook($eventType, [
        'applicationId' => $applicationId,
        'caseId' => $caseId,
        'currentStage' => $stage,
        'status' => trim((string)($caseStatus ?? $appStatus ?? $newStatus)),
        'triggeredBy' => [
            'userId' => $userId,
            'role' => $role,
        ],
        'triggeredAt' => gmdate('c'),
        'metadata' => array_merge([
            'componentKey' => $componentKey,
            'componentStatus' => $componentStatusToPersist,
            'itemKey' => $itemKey !== '' ? $itemKey : null,
            'itemStatus' => $newStatus,
            'action' => $action,
            'reason' => $reason !== '' ? $reason : null,
            'overrideReason' => $reason !== '' ? $reason : null,
            'applicationStatus' => $appStatus,
        ], $links),
    ]);

    echo json_encode([
        'status' => 1,
        'message' => 'Updated',
        'data' => [
            'application_id' => $applicationId,
            'applicationId' => $applicationId,
            'case_id' => $caseId,
            'component_key' => $componentKey,
            'component_status' => $componentStatusToPersist,
            'item_key' => $itemKey !== '' ? $itemKey : null,
            'item_status' => $newStatus,
            'reason' => $reason !== '' ? $reason : null,
            'override_reason' => $reason !== '' ? $reason : null,
            'email_sent' => $mailSent,
            'case_status' => $caseStatus,
            'application_status' => $appStatus,
            'applicationUrl' => $links['applicationUrl'],
            'candidateUrl' => $links['candidateUrl'],
            'timelineUrl' => $links['timelineUrl'],
            'webhook_event_id' => $webhook['eventId'] ?? null
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
  
