<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('validator');
auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $userId = (int)($_SESSION['auth_user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $pdo = getDB();

    // Ensure queue rows exist so pending counts reflect newly submitted cases.
    try {
        $seed = $pdo->prepare('CALL SP_Vati_Payfiller_VAL_EnsureQueue(?)');
        $seed->execute([null]);
        while ($seed->nextRowset()) {
        }
    } catch (Throwable $e) {
        // continue with counts
    }

    $stmt = $pdo->prepare(
        "SELECT\n" .
        "  SUM(CASE WHEN q.completed_at IS NULL AND q.assigned_user_id IS NULL AND UPPER(TRIM(COALESCE(c.case_status,''))) NOT IN ('STOP_BGV','PENDING_CANDIDATE','CANDIDATE_PENDING','DRAFT') THEN 1 ELSE 0 END) AS pending,\n" .
        "  SUM(CASE WHEN q.completed_at IS NULL AND q.assigned_user_id IS NOT NULL AND UPPER(TRIM(COALESCE(c.case_status,''))) NOT IN ('STOP_BGV','PENDING_CANDIDATE','CANDIDATE_PENDING','DRAFT') THEN 1 ELSE 0 END) AS in_progress,\n" .
        "  SUM(CASE WHEN q.completed_at IS NOT NULL AND DATE(q.completed_at) = CURDATE() THEN 1 ELSE 0 END) AS completed_today\n" .
        "FROM Vati_Payfiller_Validator_Queue q\n" .
        "LEFT JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => [
        'pending' => (int)($row['pending'] ?? 0),
        'in_progress' => (int)($row['in_progress'] ?? 0),
        'completed_today' => (int)($row['completed_today'] ?? 0)
    ]]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
