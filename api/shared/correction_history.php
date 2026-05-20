<?php
header('Content-Type: application/json');
require_once __DIR__ . '/candidate_correction_service.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login();
auth_session_start();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }
    $pdo = getDB();
    ccs_ensure_table($pdo);
    $applicationId = trim((string)($_GET['application_id'] ?? ''));
    $caseId = (int)($_GET['case_id'] ?? 0);
    if ($applicationId === '' && $caseId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id or case_id required']);
        exit;
    }
    if ($caseId <= 0) {
        $st = $pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $st->execute([$applicationId]);
        $caseId = (int)($st->fetchColumn() ?: 0);
    }
    if ($caseId <= 0) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found']);
        exit;
    }
    if ($applicationId === '') {
        $st2 = $pdo->prepare('SELECT application_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $st2->execute([$caseId]);
        $applicationId = (string)($st2->fetchColumn() ?: '');
    }

    $s = $pdo->prepare("SELECT ccs.correction_session_id, ccs.requested_role, ccs.requested_by_name, ccs.correction_reason, ccs.status, ccs.created_at, ccs.completed_at, ccc.component_key, ccc.cycle_number, ccc.previous_status, ccc.requested_at, ccc.candidate_submitted_at, ccc.reviewer_completed_at, ccc.final_status, ccc.reopened_count
                        FROM candidate_correction_sessions ccs
                        JOIN component_correction_cycles ccc ON ccc.correction_session_id = ccs.correction_session_id
                        WHERE ccs.case_id = ?
                        ORDER BY ccc.requested_at DESC, ccc.correction_cycle_id DESC
                        LIMIT 300");
    $s->execute([$caseId]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
