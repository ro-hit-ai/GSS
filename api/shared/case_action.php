<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/integration.php';

auth_require_login(null);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function enforce_qa_workflow_gate(PDO $pdo, string $applicationId): void {
    $role = session_role_norm();
    if ($role !== 'qa') return;

    $st = $pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
    $st->execute([$applicationId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $caseId = $row && isset($row['case_id']) ? (int)$row['case_id'] : 0;
    if ($caseId <= 0) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found']);
        exit;
    }

    // Validator must be completed
    $v = $pdo->prepare('SELECT 1 FROM Vati_Payfiller_Validator_Queue WHERE case_id = ? AND completed_at IS NOT NULL LIMIT 1');
    $v->execute([$caseId]);
    if (!$v->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['status' => 0, 'message' => 'Validator not completed yet']);
        exit;
    }

    // Verifier groups must be completed (BASIC + EDUCATION rows)
    $q = $pdo->prepare(
        "SELECT\n" .
        "  SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END) AS open_items,\n" .
        "  COUNT(*) AS total_items\n" .
        "FROM Vati_Payfiller_Verifier_Group_Queue\n" .
        "WHERE case_id = ?"
    );
    $q->execute([$caseId]);
    $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $open = (int)($r['open_items'] ?? 0);
    $total = (int)($r['total_items'] ?? 0);
    if ($total < 2 || $open > 0) {
        http_response_code(409);
        echo json_encode(['status' => 0, 'message' => 'Verifier not completed yet']);
        exit;
    }
}

function resolve_user_id(): int {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    if (!empty($_SESSION['auth_user_id'])) {
        return (int)$_SESSION['auth_user_id'];
    }

    return 0;
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

function session_client_id(): int {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    return isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
}

function enforce_client_admin_application_scope(PDO $pdo, string $applicationId): void {
    $role = session_role_norm();
    if ($role !== 'client_admin') return;

    $cid = session_client_id();
    if ($cid <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $st = $pdo->prepare('SELECT client_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
    $st->execute([$applicationId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $appClientId = $row && isset($row['client_id']) ? (int)$row['client_id'] : 0;
    if ($appClientId !== $cid) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Forbidden']);
        exit;
    }
}

function fetch_case_status(PDO $pdo, string $applicationId): string {
    try {
        $st = $pdo->prepare('SELECT case_status FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $st->execute([$applicationId]);
        return trim((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function is_completed_case_status(string $status): bool {
    $s = strtoupper(trim($status));
    if ($s === '') return false;
    return in_array($s, ['APPROVED', 'VERIFIED', 'COMPLETED', 'CLEAR'], true);
}

function resolve_case_id_by_application(PDO $pdo, string $applicationId): int {
    try {
        $st = $pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $st->execute([$applicationId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
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
    $action = isset($input['action']) ? strtolower(trim((string)$input['action'])) : '';
    $caseId = isset($input['case_id']) ? (int)$input['case_id'] : 0;
    $groupKey = isset($input['group']) ? strtoupper(trim((string)$input['group'])) : '';

    if ($applicationId === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id is required']);
        exit;
    }

    $allowed = ['hold', 'reject', 'stop_bgv', 'approve'];
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

    $caseStatus = '';
    $appStatus = null;

    $pdo = getDB();

    enforce_client_admin_application_scope($pdo, $applicationId);

    enforce_qa_workflow_gate($pdo, $applicationId);

    // Idempotent success for approve when case is already completed.
    if ($action === 'approve') {
        $existing = fetch_case_status($pdo, $applicationId);
        if (is_completed_case_status($existing)) {
            $caseStatus = $existing;
            $appStatus = $existing;
        }
    }

    if ($caseStatus === '') {
        try {
            $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_CaseAction(?, ?, ?)');
            $stmt->execute([$applicationId, $action, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $stmt->closeCursor();

            $caseStatus = (string)($row['case_status'] ?? '');
            $appStatus = isset($row['application_status']) ? $row['application_status'] : null;
        } catch (PDOException $e) {
            // If approve failed but case is already completed, treat as success.
            if ($action === 'approve') {
                $existing = fetch_case_status($pdo, $applicationId);
                if (is_completed_case_status($existing)) {
                    $caseStatus = $existing;
                    $appStatus = $existing;
                } else {
                    http_response_code(409);
                    $driverMsg = is_array($e->errorInfo ?? null) ? (string)($e->errorInfo[2] ?? '') : '';
                    $msg = $driverMsg !== '' ? $driverMsg : ('Approve failed: ' . $e->getMessage());
                    echo json_encode(['status' => 0, 'message' => $msg]);
                    exit;
                }
            } else {
                http_response_code(500);
                $driverMsg = is_array($e->errorInfo ?? null) ? (string)($e->errorInfo[2] ?? '') : '';
                $msg = $driverMsg !== '' ? $driverMsg : ('Database error: ' . $e->getMessage());
                echo json_encode(['status' => 0, 'message' => $msg]);
                exit;
            }
        }
    }

    if ($action === 'stop_bgv') {
        try {
            $resolvedCaseId = $caseId > 0 ? $caseId : resolve_case_id_by_application($pdo, $applicationId);
            if ($resolvedCaseId > 0) {
                // Stop BGV is final for current operational queues.
                $vq = $pdo->prepare(
                    "UPDATE Vati_Payfiller_Validator_Queue
                     SET status = 'completed',
                         completed_at = COALESCE(completed_at, NOW())
                     WHERE case_id = ?
                       AND completed_at IS NULL"
                );
                $vq->execute([$resolvedCaseId]);

                $vrq = $pdo->prepare(
                    "UPDATE Vati_Payfiller_Verifier_Group_Queue
                     SET status = 'completed',
                         completed_at = COALESCE(completed_at, NOW())
                     WHERE case_id = ?
                       AND completed_at IS NULL"
                );
                $vrq->execute([$resolvedCaseId]);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // If action is performed by verifier on a claimed queue item, sync queue status as well.
    // - hold => followup
    // Note: completion is handled by /api/verifier/queue_complete.php
    try {
        $roleNorm = session_role_norm();
        if ($roleNorm === 'verifier' && $caseId > 0 && in_array($groupKey, ['BASIC', 'EDUCATION'], true)) {
            $queueStatus = null;
            if ($action === 'hold') {
                $queueStatus = 'followup';
            } elseif ($action === 'approve' || $action === 'reject') {
                $queueStatus = 'in_progress';
            }

            if ($queueStatus !== null) {
                $q = $pdo->prepare(
                    'UPDATE Vati_Payfiller_Verifier_Group_Queue '
                    . 'SET status = ? '
                    . 'WHERE case_id = ? AND UPPER(TRIM(group_key)) = ? AND assigned_user_id = ? AND completed_at IS NULL'
                );
                $q->execute([$queueStatus, $caseId, $groupKey, $userId]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Best-effort: log to case timeline (if table exists)
    try {
        $role = resolve_user_role();
        $labelMap = [
            'hold' => 'HOLD',
            'reject' => 'REJECTED',
            'stop_bgv' => 'STOPPED',
            'approve' => 'APPROVED'
        ];
        $label = $labelMap[$action] ?? strtoupper($action);
        $msg = 'Case action: ' . $label;
        $log = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $log->execute([
            $applicationId,
            $userId,
            $role !== '' ? $role : null,
            'action',
            'case_status',
            $msg
        ]);
    } catch (Throwable $e) {
        // ignore
    }

    $eventTypeMap = [
        'hold' => 'application.status.changed',
        'reject' => 'application.status.changed',
        'stop_bgv' => 'application.closed',
        'approve' => 'application.closed',
    ];
    $links = integration_deep_links($applicationId, $caseId > 0 ? $caseId : null);
    $webhook = integration_send_webhook($eventTypeMap[$action] ?? 'application.status.changed', [
        'applicationId' => $applicationId,
        'caseId' => $caseId > 0 ? $caseId : null,
        'currentStage' => $action,
        'status' => trim((string)($caseStatus !== '' ? $caseStatus : ($appStatus ?? $action))),
        'triggeredBy' => [
            'userId' => $userId,
            'role' => session_role_norm(),
        ],
        'triggeredAt' => gmdate('c'),
        'metadata' => array_merge([
            'action' => $action,
            'group' => $groupKey !== '' ? $groupKey : null,
            'applicationStatus' => $appStatus,
        ], $links),
    ]);

    echo json_encode([
        'status' => 1,
        'message' => 'Updated',
        'data' => [
            'application_id' => $applicationId,
            'applicationId' => $applicationId,
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
    $driverMsg = is_array($e->errorInfo ?? null) ? (string)($e->errorInfo[2] ?? '') : '';
    $msg = $driverMsg !== '' ? $driverMsg : ('Database error: ' . $e->getMessage());
    echo json_encode(['status' => 0, 'message' => $msg]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
