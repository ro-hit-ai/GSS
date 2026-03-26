<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('gss_admin');

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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $clientId = get_int('client_id', 0);
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
                c.invite_token,
                c.invite_sent_at,
                c.created_at,
                app.status AS application_status,
                cl.internal_tat,
                cl.weekend_rules,
                TRIM(CONCAT(vu.first_name, ' ', vu.last_name)) AS validator_assigned_name,
                CONCAT_WS(' | ',
                    (CASE WHEN ub.user_id IS NULL THEN NULL ELSE CONCAT('BASIC: ', TRIM(CONCAT(ub.first_name, ' ', ub.last_name))) END),
                    (CASE WHEN ue.user_id IS NULL THEN NULL ELSE CONCAT('EDUCATION: ', TRIM(CONCAT(ue.first_name, ' ', ue.last_name))) END)
                ) AS verifier_assigned_name,
                CASE
                    WHEN UPPER(TRIM(c.case_status)) IN ('REJECTED','STOP_BGV') THEN 'QA Rejected'
                    WHEN UPPER(TRIM(c.case_status)) IN ('APPROVED','VERIFIED','COMPLETED','CLEAR') THEN 'QA Completed'
                    WHEN (vq.completed_at IS NOT NULL AND COALESCE(vr.vr_pending, 0) = 0 AND COALESCE(vr.vr_total, 0) > 0) THEN 'QA Pending'
                    WHEN (COALESCE(vr.vr_total, 0) > 0 AND COALESCE(vr.vr_pending, 0) > 0 AND COALESCE(vr.vr_in_progress, 0) > 0) THEN 'Verifier In Progress'
                    WHEN (COALESCE(vr.vr_total, 0) > 0 AND COALESCE(vr.vr_pending, 0) > 0) THEN 'Verifier Pending'
                    WHEN (vq.assigned_user_id IS NOT NULL AND vq.completed_at IS NULL) THEN 'Validation In Progress'
                    WHEN (vq.case_id IS NOT NULL AND vq.completed_at IS NULL) THEN 'Validation Pending'
                    WHEN LOWER(TRIM(COALESCE(app.status, ''))) = 'submitted' THEN 'Validation Pending'
                    WHEN (bd.application_id IS NOT NULL) THEN 'Candidate Submitted'
                    WHEN (c.invite_sent_at IS NOT NULL) THEN 'Invited'
                    ELSE 'Created'
                END AS current_stage
            FROM Vati_Payfiller_Cases c
            LEFT JOIN Vati_Payfiller_Clients cl ON cl.client_id = c.client_id
            LEFT JOIN Vati_Payfiller_Candidate_Applications app ON app.application_id = c.application_id
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
                  ) q2 ON q2.case_id = q1.case_id AND COALESCE(q2.max_ts, '1970-01-01 00:00:00') = COALESCE(COALESCE(q1.completed_at, q1.claimed_at), '1970-01-01 00:00:00')
            ) vq ON vq.case_id = c.case_id
            LEFT JOIN Vati_Payfiller_Users vu ON vu.user_id = vq.assigned_user_id
            LEFT JOIN (
                SELECT case_id,
                       MAX(CASE WHEN group_key = 'BASIC' THEN assigned_user_id END) AS basic_uid,
                       MAX(CASE WHEN group_key = 'EDUCATION' THEN assigned_user_id END) AS edu_uid
                  FROM Vati_Payfiller_Verifier_Group_Queue
                 GROUP BY case_id
            ) vra ON vra.case_id = c.case_id
            LEFT JOIN Vati_Payfiller_Users ub ON ub.user_id = vra.basic_uid
            LEFT JOIN Vati_Payfiller_Users ue ON ue.user_id = vra.edu_uid
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
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
