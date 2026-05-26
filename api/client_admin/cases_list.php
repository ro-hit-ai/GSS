<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('client_admin');

auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function get_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

function resolve_client_id(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cid = isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
    if ($cid > 0) return $cid;

    http_response_code(401);
    echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $clientId = resolve_client_id();
    $search = get_str('search', '');

    $pdo = getDB();

    $sql = "SELECT
                c.case_id,
                c.client_id,
                c.application_id,
                c.candidate_first_name,
                c.candidate_last_name,
                c.candidate_email,
                c.candidate_mobile,
                c.case_status,
                COALESCE(NULLIF(TRIM(c.workflow_mode), ''), 'validator_first') AS workflow_mode,
                c.invite_sent_at,
                c.created_at,
                CASE
                    WHEN UPPER(TRIM(c.case_status)) IN ('APPROVED','VERIFIED','COMPLETED','CLEAR') THEN 'QA Completed'
                    WHEN (vq.completed_at IS NOT NULL AND COALESCE(vr.vr_pending, 0) = 0 AND COALESCE(vr.vr_total, 0) > 0) THEN 'QA Pending'
                    WHEN (COALESCE(vr.vr_total, 0) > 0 AND COALESCE(vr.vr_pending, 0) > 0 AND COALESCE(vr.vr_in_progress, 0) > 0) THEN 'Verifier In Progress'
                    WHEN (COALESCE(vr.vr_total, 0) > 0 AND COALESCE(vr.vr_pending, 0) > 0) THEN 'Verifier Pending'
                    WHEN (COALESCE(NULLIF(TRIM(c.workflow_mode), ''), 'validator_first') = 'verifier_first' AND bd.application_id IS NOT NULL) THEN 'Verifier Pending'
                    WHEN (vq.assigned_user_id IS NOT NULL AND vq.completed_at IS NULL) THEN 'Validation In Progress'
                    WHEN (vq.case_id IS NOT NULL AND vq.completed_at IS NULL) THEN 'Validation Pending'
                    WHEN (bd.application_id IS NOT NULL) THEN 'Candidate Submitted'
                    WHEN (c.invite_sent_at IS NOT NULL) THEN 'Invited'
                    ELSE 'Created'
                END AS current_stage
            FROM Vati_Payfiller_Cases c
            LEFT JOIN (
                SELECT application_id
                  FROM Vati_Payfiller_Candidate_Basic_details
                 GROUP BY application_id
            ) bd ON bd.application_id = c.application_id
            LEFT JOIN (
                SELECT q1.*
                  FROM Vati_Payfiller_Validator_Queue q1
                  JOIN (
                        SELECT case_id, MAX(COALESCE(completed_at, claimed_at)) AS max_ts
                          FROM Vati_Payfiller_Validator_Queue
                         GROUP BY case_id
                  ) q2 ON q2.case_id = q1.case_id AND q2.max_ts = COALESCE(q1.completed_at, q1.claimed_at)
            ) vq ON vq.case_id = c.case_id
            LEFT JOIN (
                SELECT case_id,
                       COUNT(*) AS vr_total,
                       SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END) AS vr_pending,
                       SUM(CASE WHEN completed_at IS NULL AND assigned_user_id IS NOT NULL THEN 1 ELSE 0 END) AS vr_in_progress
                  FROM Vati_Payfiller_Verifier_Group_Queue
                 GROUP BY case_id
            ) vr ON vr.case_id = c.case_id
            WHERE 1=1";

    $params = [];

    if ($clientId > 0) {
        $sql .= " AND c.client_id = ?";
        $params[] = $clientId;
    }

    if ($search !== '') {
        $sql .= " AND (
            c.candidate_first_name LIKE ? OR
            c.candidate_last_name LIKE ? OR
            c.candidate_email LIKE ? OR
            c.candidate_mobile LIKE ? OR
            c.application_id LIKE ? OR
            c.case_status LIKE ?
        )";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY c.created_at DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'status' => 1,
        'message' => 'OK',
        'data' => $rows
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log('DB error in api/client_admin/cases_list.php: ' . $e->getMessage());
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
