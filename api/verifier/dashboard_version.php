<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../shared/verifier_case_queue.php';

auth_require_login('verifier');
auth_session_start();

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

    $pdo = getDB();
    $userId = (int)($_SESSION['auth_user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    verifier_case_queue_ensure_table($pdo);

    $queueSql = "
        SELECT
            COUNT(*) AS row_count,
            COALESCE(MAX(c.workflow_version), 0) AS max_workflow_version,
            COALESCE(MAX(GREATEST(
                COALESCE(q.updated_at, '1970-01-01 00:00:00'),
                COALESCE(q.claimed_at, '1970-01-01 00:00:00'),
                COALESCE(q.completed_at, '1970-01-01 00:00:00'),
                COALESCE(c.updated_at, '1970-01-01 00:00:00'),
                COALESCE(c.created_at, '1970-01-01 00:00:00')
            )), '1970-01-01 00:00:00') AS max_updated_at,
            SUM(CASE WHEN COALESCE(q.assigned_user_id, 0) = 0 AND q.completed_at IS NULL THEN 1 ELSE 0 END) AS claimable_count,
            SUM(CASE WHEN COALESCE(q.assigned_user_id, 0) = ? AND q.completed_at IS NULL THEN 1 ELSE 0 END) AS my_active_count,
            SUM(CASE WHEN COALESCE(q.assigned_user_id, 0) = ? AND q.completed_at IS NOT NULL THEN 1 ELSE 0 END) AS my_completed_count
          FROM Vati_Payfiller_Verifier_Case_Queue q
          JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id
         WHERE UPPER(TRIM(COALESCE(c.case_status,''))) <> 'STOP_BGV'
    ";
    $queueStmt = $pdo->prepare($queueSql);
    $queueStmt->execute([$userId, $userId]);
    $queue = $queueStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $caseSql = "
        SELECT
            COUNT(*) AS verifier_case_count,
            COALESCE(MAX(workflow_version), 0) AS verifier_case_max_workflow_version,
            COALESCE(MAX(GREATEST(
                COALESCE(updated_at, '1970-01-01 00:00:00'),
                COALESCE(created_at, '1970-01-01 00:00:00')
            )), '1970-01-01 00:00:00') AS verifier_case_max_updated_at
          FROM Vati_Payfiller_Cases
         WHERE LOWER(TRIM(COALESCE(workflow_mode,''))) = 'verifier_first'
           AND UPPER(TRIM(COALESCE(case_status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED','CLEAR')
    ";
    $caseStmt = $pdo->query($caseSql);
    $cases = $caseStmt ? ($caseStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    $updatedAt = max(
        (string)($queue['max_updated_at'] ?? '1970-01-01 00:00:00'),
        (string)($cases['verifier_case_max_updated_at'] ?? '1970-01-01 00:00:00')
    );
    $workflowVersion = max(
        (int)($queue['max_workflow_version'] ?? 0),
        (int)($cases['verifier_case_max_workflow_version'] ?? 0)
    );

    $signatureParts = [
        'uid=' . $userId,
        'rows=' . (int)($queue['row_count'] ?? 0),
        'wf=' . $workflowVersion,
        'updated=' . $updatedAt,
        'claimable=' . (int)($queue['claimable_count'] ?? 0),
        'active=' . (int)($queue['my_active_count'] ?? 0),
        'completed=' . (int)($queue['my_completed_count'] ?? 0),
        'vfCases=' . (int)($cases['verifier_case_count'] ?? 0),
    ];
    $version = sha1(implode('|', $signatureParts));

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'user_id' => $userId,
            'dashboard_version' => $version,
            'version' => $version,
            'workflow_version' => $workflowVersion,
            'updated_at' => $updatedAt,
            'row_count' => (int)($queue['row_count'] ?? 0),
            'timestamp' => date('c'),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
