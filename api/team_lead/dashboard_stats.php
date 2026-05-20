<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../shared/workflow_semantics.php';

auth_require_login('team_lead');
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
    $validatorUserId = get_int('validator_user_id', 0);
    $verifierUserId = get_int('verifier_user_id', 0);
    $vrGroup = strtoupper(get_str('vr_group', ''));
    if ($vrGroup !== '' && !wf_is_valid_verifier_group($vrGroup)) {
        $vrGroup = '';
    }

    $pdo = getDB();

    // Unassigned Validator queue items
    $valSql =
        "SELECT c.case_id, c.client_id, cl.customer_name, c.application_id, c.created_at\n" .
        "  FROM Vati_Payfiller_Validator_Queue q\n" .
        "  JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
        "  LEFT JOIN Vati_Payfiller_Clients cl ON cl.client_id = c.client_id\n" .
        " WHERE q.completed_at IS NULL\n" .
        "   AND q.assigned_user_id IS NULL\n" .
        "   AND (? = 0 OR c.client_id = ?)\n" .
        " ORDER BY c.created_at DESC\n" .
        " LIMIT 200";
    $valStmt = $pdo->prepare($valSql);
    $valStmt->execute([$clientId, $clientId]);
    $valUnassigned = $valStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Unassigned VR group queue items
    $vrSql =
        "SELECT c.case_id, c.client_id, cl.customer_name, c.application_id, c.created_at, q.group_key\n" .
        "  FROM Vati_Payfiller_Verifier_Group_Queue q\n" .
        "  JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
        "  LEFT JOIN Vati_Payfiller_Clients cl ON cl.client_id = c.client_id\n" .
        " WHERE q.completed_at IS NULL\n" .
        "   AND q.assigned_user_id IS NULL\n" .
        "   AND (? = 0 OR c.client_id = ?)\n";

    $vrParams = [$clientId, $clientId];
    if ($vrGroup !== '') {
        $vrSql .= "   AND UPPER(TRIM(q.group_key)) COLLATE utf8mb4_general_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci\n";
        $vrParams[] = $vrGroup;
    }

    $vrSql .=
        " ORDER BY c.created_at DESC\n" .
        " LIMIT 200";

    $vrStmt = $pdo->prepare($vrSql);
    $vrStmt->execute($vrParams);
    $vrUnassigned = $vrStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Unassigned DBV cases
    $dbvSql =
        "SELECT c.case_id, c.client_id, cl.customer_name, c.application_id, c.case_status\n" .
        "  FROM Vati_Payfiller_Cases c\n" .
        "  LEFT JOIN Vati_Payfiller_Clients cl ON cl.client_id = c.client_id\n" .
        " WHERE c.dbv_completed_at IS NULL\n" .
        "   AND c.dbv_assigned_user_id IS NULL\n" .
        "   AND (? = 0 OR c.client_id = ?)\n" .
        " ORDER BY c.created_at DESC\n" .
        " LIMIT 200";
    $dbvStmt = $pdo->prepare($dbvSql);
    $dbvStmt->execute([$clientId, $clientId]);
    $dbvUnassigned = $dbvStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Recent active assignments (validator + vr + dbv)
    $vrAsgSql =
        "(\n" .
        "  SELECT 'VR' AS queue_type, q.group_key, q.status AS queue_status, q.claimed_at AS assigned_at,\n" .
        "         c.case_id, c.application_id, c.case_status,\n" .
        "         u.user_id, u.username, u.first_name, u.last_name, LOWER(TRIM(u.role)) AS role\n" .
        "    FROM Vati_Payfiller_Verifier_Group_Queue q\n" .
        "    JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
        "    JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id\n" .
        "   WHERE q.completed_at IS NULL\n" .
        "     AND q.assigned_user_id IS NOT NULL\n" .
        "     AND (? = 0 OR c.client_id = ?)\n" .
        "     AND (? = 0 OR q.assigned_user_id = ?)\n";

    $vrAsgParams = [$clientId, $clientId, $verifierUserId, $verifierUserId];
    if ($vrGroup !== '') {
        $vrAsgSql .= "     AND UPPER(TRIM(q.group_key)) COLLATE utf8mb4_general_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci\n";
        $vrAsgParams[] = $vrGroup;
    }
    $vrAsgSql .= ")\n";

    $asgSql =
        "(\n" .
        "  SELECT 'VAL' AS queue_type, NULL AS group_key, q.status AS queue_status, q.claimed_at AS assigned_at,\n" .
        "         c.case_id, c.application_id, c.case_status,\n" .
        "         u.user_id, u.username, u.first_name, u.last_name, LOWER(TRIM(u.role)) AS role\n" .
        "    FROM Vati_Payfiller_Validator_Queue q\n" .
        "    JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
        "    JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id\n" .
        "   WHERE q.completed_at IS NULL\n" .
        "     AND q.assigned_user_id IS NOT NULL\n" .
        "     AND (? = 0 OR c.client_id = ?)\n" .
        "     AND (? = 0 OR q.assigned_user_id = ?)\n" .
        ")\n" .
        "UNION ALL\n" .
        $vrAsgSql .
        "UNION ALL\n" .
        "(\n" .
        "  SELECT 'DBV' AS queue_type, NULL AS group_key, NULL AS queue_status, c.dbv_claimed_at AS assigned_at,\n" .
        "         c.case_id, c.application_id, c.case_status,\n" .
        "         u.user_id, u.username, u.first_name, u.last_name, LOWER(TRIM(u.role)) AS role\n" .
        "    FROM Vati_Payfiller_Cases c\n" .
        "    JOIN Vati_Payfiller_Users u ON u.user_id = c.dbv_assigned_user_id\n" .
        "   WHERE c.dbv_completed_at IS NULL\n" .
        "     AND c.dbv_assigned_user_id IS NOT NULL\n" .
        "     AND (? = 0 OR c.client_id = ?)\n" .
        "     AND (? = 0 OR c.dbv_assigned_user_id = ?)\n" .
        ")\n" .
        "ORDER BY assigned_at DESC\n" .
        "LIMIT 120";

    $asgStmt = $pdo->prepare($asgSql);
    $asgStmt->execute(array_merge(
        [$clientId, $clientId, $validatorUserId, $validatorUserId],
        $vrAsgParams,
        [$clientId, $clientId, $verifierUserId, $verifierUserId]
    ));
    $assignments = $asgStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'kpis' => [
                'validator_unassigned' => count($valUnassigned),
                'vr_unassigned' => count($vrUnassigned),
                'dbv_unassigned' => count($dbvUnassigned),
                'active_assignments' => count($assignments)
            ],
            'unassigned' => [
                'validator' => $valUnassigned,
                'vr' => $vrUnassigned,
                'dbv' => $dbvUnassigned
            ],
            'assignments' => $assignments
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log('team_lead/dashboard_stats PDOException: ' . $e->getMessage());
    echo json_encode(['status' => 0, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
