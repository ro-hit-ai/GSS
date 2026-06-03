<?php
header('Content-Type: application/json');
require_once __DIR__ . '/candidate_correction_service.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();
auth_session_start();

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
    $where = '';
    $params = [];
    if ($caseId > 0) { $where = ' WHERE case_id = ? '; $params[] = $caseId; }
    elseif ($applicationId !== '') { $where = ' WHERE application_id = ? '; $params[] = $applicationId; }

    $q1 = $pdo->prepare("SELECT COUNT(*) total,
            SUM(CASE WHEN status IN ('active','submitted') THEN 1 ELSE 0 END) open_sessions,
            SUM(CASE WHEN status IN ('active','submitted') AND created_at < (NOW() - INTERVAL 48 HOUR) THEN 1 ELSE 0 END) stale_sessions
        FROM Vati_Payfiller_Candidate_Correction_Sessions" . $where);
    $q1->execute($params);
    $a = $q1->fetch(PDO::FETCH_ASSOC) ?: [];

    $q2 = $pdo->prepare("SELECT component_key, COUNT(*) cnt FROM Vati_Payfiller_Component_Correction_Cycles" . $where . " GROUP BY component_key ORDER BY cnt DESC LIMIT 10");
    $q2->execute($params);
    $top = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $q3 = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(HOUR, requested_at, candidate_submitted_at)) avg_hours
        FROM Vati_Payfiller_Component_Correction_Cycles" . ($where ? str_replace('WHERE', 'WHERE candidate_submitted_at IS NOT NULL AND', $where) : ' WHERE candidate_submitted_at IS NOT NULL'));
    $q3->execute($params);
    $avgHours = (float)($q3->fetchColumn() ?: 0);

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => [
        'correction_request_count' => (int)($a['total'] ?? 0),
        'open_correction_sessions' => (int)($a['open_sessions'] ?? 0),
        'stale_correction_sessions' => (int)($a['stale_sessions'] ?? 0),
        'average_correction_turnaround_hours' => round($avgHours, 2),
        'most_reopened_components' => $top
    ]]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}

