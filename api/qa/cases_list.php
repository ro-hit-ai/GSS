<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_any_access(['qa', 'team_lead']);
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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $clientId = get_int('client_id', 0);
    $search = get_str('search', '');
    $status = get_str('status', '');
    $from = get_str('from', '');
    $to = get_str('to', '');
    $view = strtolower(get_str('view', 'ready')); // ready|all|pending|completed

    $validatorUserId = get_int('validator_user_id', 0);
    $verifierUserId = get_int('verifier_user_id', 0);
    $verifierGroup = strtoupper(get_str('verifier_group', ''));

    $role = strtolower((string)($_SESSION['auth_moduleAccess'] ?? 'qa'));
    if ($role !== 'team_lead') {
        // QA role only sees cases that are ready for QA.
        $view = 'ready';
        $validatorUserId = 0;
        $verifierUserId = 0;
        $verifierGroup = '';
    }

    if (!in_array($view, ['ready', 'all', 'pending', 'completed'], true)) {
        $view = 'ready';
    }

    if ($verifierGroup !== '' && !in_array($verifierGroup, ['BASIC', 'EDUCATION'], true)) {
        $verifierGroup = '';
    }

    $pdo = getDB();

    $sql = "SELECT 
                c.case_id,
                c.client_id,
                cl.customer_name,
                c.application_id,
                c.candidate_first_name,
                c.candidate_last_name,
                c.candidate_email,
                c.candidate_mobile,
                c.case_status,
                c.created_at,
                (SELECT q.assigned_user_id
                   FROM Vati_Payfiller_Validator_Queue q
                  WHERE q.case_id = c.case_id
                  ORDER BY q.id DESC
                  LIMIT 1) AS validator_user_id,
                (SELECT TRIM(CONCAT(u.first_name, ' ', u.last_name))
                   FROM Vati_Payfiller_Validator_Queue q
                   JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id
                  WHERE q.case_id = c.case_id
                  ORDER BY q.id DESC
                  LIMIT 1) AS validator_assigned_name,
                (SELECT q.assigned_user_id
                   FROM Vati_Payfiller_Verifier_Group_Queue q
                  WHERE q.case_id = c.case_id AND q.group_key = 'BASIC'
                  ORDER BY q.id DESC
                  LIMIT 1) AS verifier_basic_user_id,
                (SELECT TRIM(CONCAT(u.first_name, ' ', u.last_name))
                   FROM Vati_Payfiller_Verifier_Group_Queue q
                   JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id
                  WHERE q.case_id = c.case_id AND q.group_key = 'BASIC'
                  ORDER BY q.id DESC
                  LIMIT 1) AS verifier_basic_assigned_name,
                (SELECT q.assigned_user_id
                   FROM Vati_Payfiller_Verifier_Group_Queue q
                  WHERE q.case_id = c.case_id AND q.group_key = 'EDUCATION'
                  ORDER BY q.id DESC
                  LIMIT 1) AS verifier_education_user_id,
                (SELECT TRIM(CONCAT(u.first_name, ' ', u.last_name))
                   FROM Vati_Payfiller_Verifier_Group_Queue q
                   JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id
                  WHERE q.case_id = c.case_id AND q.group_key = 'EDUCATION'
                  ORDER BY q.id DESC
                  LIMIT 1) AS verifier_education_assigned_name,
                (CASE
                    WHEN EXISTS (SELECT 1 FROM Vati_Payfiller_Validator_Queue vq WHERE vq.case_id = c.case_id AND vq.completed_at IS NOT NULL) THEN 1
                    ELSE 0
                END) AS is_validator_done,
                (CASE
                    WHEN EXISTS (
                        SELECT 1
                          FROM Vati_Payfiller_Verifier_Group_Queue q
                         WHERE q.case_id = c.case_id
                         GROUP BY q.case_id
                        HAVING SUM(CASE WHEN q.completed_at IS NULL THEN 1 ELSE 0 END) = 0
                           AND COUNT(*) >= 2
                    ) THEN 1
                    ELSE 0
                END) AS is_verifier_done,
                CASE
                    WHEN UPPER(TRIM(c.case_status)) IN ('REJECTED','STOP_BGV') THEN 'QA Rejected'
                    WHEN UPPER(TRIM(c.case_status)) IN ('APPROVED','VERIFIED','COMPLETED','CLEAR') THEN 'QA Completed'
                    ELSE 'QA Pending'
                END AS current_stage
            FROM Vati_Payfiller_Cases c
            LEFT JOIN Vati_Payfiller_Clients cl ON cl.client_id = c.client_id
            WHERE 1=1";

    $params = [];

    if ($view === 'ready') {
        $sql .= " AND EXISTS (SELECT 1 FROM Vati_Payfiller_Validator_Queue vq WHERE vq.case_id = c.case_id AND vq.completed_at IS NOT NULL)";
        $sql .= " AND EXISTS (\n" .
                "     SELECT 1\n" .
                "       FROM Vati_Payfiller_Verifier_Group_Queue q\n" .
                "      WHERE q.case_id = c.case_id\n" .
                "      GROUP BY q.case_id\n" .
                "     HAVING SUM(CASE WHEN q.completed_at IS NULL THEN 1 ELSE 0 END) = 0\n" .
                "        AND COUNT(*) >= 2\n" .
                " )";
    } elseif ($view === 'completed') {
        $sql .= " AND UPPER(TRIM(c.case_status)) IN ('APPROVED','VERIFIED','COMPLETED','CLEAR')";
    } elseif ($view === 'pending') {
        $sql .= " AND (UPPER(TRIM(c.case_status)) NOT IN ('APPROVED','VERIFIED','COMPLETED','CLEAR') OR c.case_status IS NULL OR TRIM(c.case_status) = '')";
    }

    if ($validatorUserId > 0) {
        $sql .= " AND EXISTS (SELECT 1 FROM Vati_Payfiller_Validator_Queue vq2 WHERE vq2.case_id = c.case_id AND vq2.assigned_user_id = ?)";
        $params[] = $validatorUserId;
    }

    if ($verifierUserId > 0) {
        $sql .= " AND EXISTS (SELECT 1 FROM Vati_Payfiller_Verifier_Group_Queue q2 WHERE q2.case_id = c.case_id AND q2.assigned_user_id = ?";
        $params[] = $verifierUserId;
        if ($verifierGroup !== '') {
            $sql .= " AND q2.group_key = ?";
            $params[] = $verifierGroup;
        }
        $sql .= ")";
    } elseif ($verifierGroup !== '') {
        $sql .= " AND EXISTS (SELECT 1 FROM Vati_Payfiller_Verifier_Group_Queue q2 WHERE q2.case_id = c.case_id AND q2.group_key = ?)";
        $params[] = $verifierGroup;
    }

    if ($clientId > 0) {
        $sql .= " AND c.client_id = ?";
        $params[] = $clientId;
    }

    if ($status !== '') {
        $sql .= " AND UPPER(TRIM(c.case_status)) = UPPER(TRIM(?))";
        $params[] = $status;
    }

    if ($from !== '') {
        $sql .= " AND DATE(c.created_at) >= DATE(?)";
        $params[] = $from;
    }

    if ($to !== '') {
        $sql .= " AND DATE(c.created_at) <= DATE(?)";
        $params[] = $to;
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

    // Role-based stage label + combined assignee display for TL
    if (!empty($rows)) {
        foreach ($rows as &$r) {
            $valDone = !empty($r['is_validator_done']) ? ((int)$r['is_validator_done'] === 1) : false;
            $vrDone = !empty($r['is_verifier_done']) ? ((int)$r['is_verifier_done'] === 1) : false;

            $vb = trim((string)($r['verifier_basic_assigned_name'] ?? ''));
            $ve = trim((string)($r['verifier_education_assigned_name'] ?? ''));

            $verifierAssigned = '';
            if ($vb !== '' && $ve !== '') {
                $verifierAssigned = 'BASIC: ' . $vb . ' | EDUCATION: ' . $ve;
            } elseif ($vb !== '') {
                $verifierAssigned = 'BASIC: ' . $vb;
            } elseif ($ve !== '') {
                $verifierAssigned = 'EDUCATION: ' . $ve;
            }
            $r['verifier_assigned_name'] = $verifierAssigned;

            if ($role === 'team_lead') {
                $caseStatusUpper = strtoupper(trim((string)($r['case_status'] ?? '')));
                $isCompleted = in_array($caseStatusUpper, ['APPROVED', 'VERIFIED', 'COMPLETED', 'CLEAR'], true);
                $isRejected = in_array($caseStatusUpper, ['REJECTED', 'STOP_BGV'], true);
                if ($isRejected) {
                    $r['current_stage'] = 'QA Rejected';
                } elseif ($isCompleted) {
                    $r['current_stage'] = 'Completed';
                } else {
                    $r['current_stage'] = ($valDone && $vrDone) ? 'Pending Ready' : 'Pending Not Ready';
                }
            }
        }
        unset($r);
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => $rows
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
