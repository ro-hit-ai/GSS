<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

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
        $k = strtolower(trim((string)$p));
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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $applicationId = isset($input['application_id']) ? trim((string)$input['application_id']) : '';
    $caseId = isset($input['case_id']) ? (int)$input['case_id'] : 0;
    $componentKey = isset($input['component_key']) ? norm_component_key((string)$input['component_key']) : '';
    $action = isset($input['action']) ? strtolower(trim((string)$input['action'])) : '';
    $groupKey = isset($input['group']) ? strtoupper(trim((string)$input['group'])) : '';

    if ($applicationId === '' || $caseId <= 0 || $componentKey === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id, case_id and component_key are required']);
        exit;
    }

    $allowed = ['hold', 'reject', 'approve'];
    if (!in_array($action, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Invalid action']);
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

    $useWorkflow = workflow_table_available($pdo);
    $stage = role_to_stage($role);

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
    if (!in_array($role, ['qa', 'team_lead'], true)) {
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
            $cs = $pdo->prepare(
                'SELECT status FROM Vati_Payfiller_Case_Component_Workflow '
                . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ? LIMIT 1'
            );
            $cs->execute([$caseId, $componentKey, $stage]);
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
                $ps = $pdo->prepare(
                    'SELECT status FROM Vati_Payfiller_Case_Component_Workflow '
                    . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ? LIMIT 1'
                );
                $ps->execute([$caseId, $componentKey, $prev]);
                $prevStatus = strtolower(trim((string)($ps->fetchColumn() ?: '')));
                if ($prevStatus !== 'approved') {
                    // Backward compatibility: bootstrap missing previous-stage workflow rows
                    // from existing queue completion markers (validator/verifier).
                    if (bootstrap_prev_stage_if_completed($pdo, $caseId, $applicationId, $componentKey, $prev)) {
                        $ps->execute([$caseId, $componentKey, $prev]);
                        $prevStatus = strtolower(trim((string)($ps->fetchColumn() ?: '')));
                    }
                }
                if ($prevStatus !== 'approved') {
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

    $newStatus = $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'hold');
    $completedAt = $action === 'approve' ? 'NOW()' : 'NULL';

    $upd = $pdo->prepare(
        'UPDATE Vati_Payfiller_Case_Components '
        . 'SET status = ?, completed_at = ' . $completedAt . ', updated_at = NOW() '
        . 'WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ?'
    );
    $upd->execute([$newStatus, $caseId, $applicationId, $componentKey]);

    if ($useWorkflow) {
        try {
            $completedExpr = $action === 'approve' ? 'NOW()' : 'NULL';
            $w = $pdo->prepare(
                'INSERT INTO Vati_Payfiller_Case_Component_Workflow '
                . '(case_id, application_id, component_key, stage, status, updated_by_user_id, updated_by_role, completed_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ' . $completedExpr . ') '
                . 'ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by_user_id = VALUES(updated_by_user_id), updated_by_role = VALUES(updated_by_role), completed_at = ' . $completedExpr . ', updated_at = NOW()'
            );
            $w->execute([$caseId, $applicationId, $componentKey, $stage, $newStatus, $userId, $role]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 0, 'message' => 'Workflow update failed']);
            exit;
        }
    }

    // Best-effort: log to case timeline
    try {
        $labelMap = ['hold' => 'HOLD', 'reject' => 'REJECTED', 'approve' => 'APPROVED'];
        $label = $labelMap[$action] ?? strtoupper($action);
        $msg = 'Component action: ' . $label;
        $log = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $log->execute([$applicationId, $userId, resolve_user_role() ?: null, 'update', $componentKey, $msg]);
    } catch (Throwable $e) {
    }

    $caseStatus = null;
    $appStatus = null;

    // Auto-approve case only when all required components are approved
    if ($action === 'approve') {
        try {
            $pendingCount = 0;
            if ($useWorkflow) {
                $check = $pdo->prepare(
                    'SELECT COUNT(*) AS pending_count '
                    . 'FROM Vati_Payfiller_Case_Components c '
                    . 'LEFT JOIN Vati_Payfiller_Case_Component_Workflow w '
                    . 'ON w.case_id = c.case_id AND LOWER(TRIM(w.component_key)) = LOWER(TRIM(c.component_key)) AND w.stage = \'qa\' '
                    . 'WHERE c.application_id = ? AND c.is_required = 1 AND (w.status IS NULL OR LOWER(TRIM(w.status)) <> \'approved\')'
                );
                $check->execute([$applicationId]);
                $pendingCount = (int)($check->fetchColumn() ?: 0);
            } else {
                $check = $pdo->prepare(
                    'SELECT COUNT(*) AS pending_count '
                    . 'FROM Vati_Payfiller_Case_Components '
                    . 'WHERE application_id = ? AND is_required = 1 AND LOWER(TRIM(status)) <> \'approved\''
                );
                $check->execute([$applicationId]);
                $pendingCount = (int)($check->fetchColumn() ?: 0);
            }

            if ($pendingCount === 0) {
                $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_CaseAction(?, ?, ?)');
                $stmt->execute([$applicationId, 'approve', $userId]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $stmt->closeCursor();
                $caseStatus = (string)($r['case_status'] ?? '');
                $appStatus = isset($r['application_status']) ? $r['application_status'] : null;
            }
        } catch (Throwable $e) {
        }
    }

    echo json_encode([
        'status' => 1,
        'message' => 'Updated',
        'data' => [
            'application_id' => $applicationId,
            'case_id' => $caseId,
            'component_key' => $componentKey,
            'component_status' => $newStatus,
            'case_status' => $caseStatus,
            'application_status' => $appStatus
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
