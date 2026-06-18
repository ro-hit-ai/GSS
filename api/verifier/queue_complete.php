<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../shared/authorization/application_status_guard.php';
require_once __DIR__ . '/queue_visibility.php';
require_once __DIR__ . '/../shared/verifier_case_queue.php';

auth_require_login('verifier');

auth_session_start();
$userId = (int)($_SESSION['auth_user_id'] ?? 0);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$caseId = isset($input['case_id']) ? (int)$input['case_id'] : 0;
$groupKey = strtoupper(trim((string)($input['group'] ?? '')));

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $pdo = getDB();
    if ($caseId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'case_id is required']);
        exit;
    }

    if (verifier_case_queue_is_case_model($pdo, $caseId, '')) {
        if (!verifier_case_queue_can_open($pdo, $caseId, $userId)) {
            http_response_code(403);
            echo json_encode(['status' => 0, 'message' => 'Access denied']);
            exit;
        }
        $queue = verifier_case_queue_sync($pdo, $caseId, $userId);
        if (!$queue || strtolower(trim((string)($queue['status'] ?? ''))) !== 'completed') {
            http_response_code(409);
            echo json_encode(['status' => 0, 'message' => 'Verifier work is not completed yet']);
            exit;
        }

        echo json_encode(['status' => 1, 'message' => 'completed', 'data' => ['case_id' => $caseId]]);
        exit;
    }

    if (!wf_is_valid_verifier_group($groupKey)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'case_id and valid group are required']);
        exit;
    }

    $allowedSet = verifier_allowed_sections_set_from_session();
    if (!verifier_can_group_by_sections($allowedSet, $groupKey)) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Access denied']);
        exit;
    }

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VR_CompleteCase(?, ?, ?)');
    $stmt->execute([$userId, $caseId, $groupKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    $affected = isset($row['affected_rows']) ? (int)$row['affected_rows'] : 0;
    if ($affected <= 0) {
        http_response_code(409);
        echo json_encode(['status' => 0, 'message' => 'Not claimed by you or already completed']);
        exit;
    }

    // Best-effort: log to case timeline
    try {
        $log = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) SELECT application_id, ?, ?, ?, ?, ?, NOW() FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $role = !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : 'verifier';
        $log->execute([$userId, $role, 'action', 'verifier', 'Verifier completed the group: ' . $groupKey, $caseId]);

        // If all verifier groups are completed for this case, add one case-level completion event.
        $left = $pdo->prepare(
            "SELECT COUNT(*) AS open_count
             FROM Vati_Payfiller_Verifier_Group_Queue
             WHERE case_id = ? AND completed_at IS NULL"
        );
        $left->execute([$caseId]);
        $openCount = (int)($left->fetchColumn() ?: 0);

        if ($openCount === 0) {
            // Move overall case/app to next stage when verifier work is done.
            try {
                $uCase = $pdo->prepare(
                    "UPDATE Vati_Payfiller_Cases
                     SET case_status = 'PENDING_QA'
                     WHERE case_id = ?
                       AND UPPER(TRIM(COALESCE(case_status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED')"
                );
                $uCase->execute([$caseId]);
            } catch (Throwable $e) {
            }
            try {
                $submittedStatus = wf_assert_valid_application_status('submitted', 'verifier.queue_complete');
                $uApp = $pdo->prepare(
                    "UPDATE Vati_Payfiller_Candidate_Applications
                     SET status = ?
                     WHERE application_id = (
                        SELECT application_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1
                     )
                       AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('rejected','verified')"
                );
                $uApp->execute([$submittedStatus, $caseId]);
            } catch (Throwable $e) {
            }

            $logCase = $pdo->prepare(
                "INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at)
                 SELECT c.application_id, ?, ?, 'action', 'verifier', 'Verifier completed the case', NOW()
                 FROM Vati_Payfiller_Cases c
                 WHERE c.case_id = ?
                   AND NOT EXISTS (
                       SELECT 1
                       FROM Vati_Payfiller_Case_Timeline t
                       WHERE t.application_id = c.application_id
                         AND t.section_key = 'verifier'
                         AND t.event_type = 'action'
                         AND t.message = 'Verifier completed the case'
                   )
                 LIMIT 1"
            );
            $logCase->execute([$userId, $role, $caseId]);
        }
    } catch (Throwable $e) {
        // ignore
    }

    echo json_encode(['status' => 1, 'message' => 'completed', 'data' => ['affected_rows' => $affected]]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
