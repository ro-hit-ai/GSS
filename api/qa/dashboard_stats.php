<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../shared/operational_status_governance.php';

auth_require_any_access(['qa', 'team_lead']);
auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function qa_dash_debug_log(string $event, array $data = []): void
{
    if ((string)getenv('WF_STATUS_DEBUG_LOGS') !== '1') return;
    $entry = ['ts' => date('c'), 'event' => $event, 'data' => $data];
    @file_put_contents(__DIR__ . '/../../logs/qa_operational_status.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $pdo = getDB();

    $usersByRole = [];
    $stmt = $pdo->query(
        "SELECT LOWER(TRIM(role)) AS role, COUNT(*) AS cnt\n" .
        "FROM Vati_Payfiller_Users\n" .
        "WHERE is_active = 1\n" .
        "GROUP BY LOWER(TRIM(role))"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $k = (string)($r['role'] ?? '');
        if ($k === '') continue;
        $usersByRole[$k] = (int)($r['cnt'] ?? 0);
    }

    $usersTotal = 0;
    foreach ($usersByRole as $cnt) {
        $usersTotal += (int)$cnt;
    }

    // Verifier group queue workload (active claims)
    $vrWorkload = [];
    try {
        $stmt = $pdo->query(
            "SELECT q.assigned_user_id AS user_id, u.username, u.first_name, u.last_name, LOWER(TRIM(u.role)) AS role,\n" .
            "       COUNT(*) AS open_items\n" .
            "FROM Vati_Payfiller_Verifier_Group_Queue q\n" .
            "JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id\n" .
            "WHERE q.assigned_user_id IS NOT NULL AND q.completed_at IS NULL\n" .
            "GROUP BY q.assigned_user_id, u.username, u.first_name, u.last_name, u.role\n" .
            "ORDER BY open_items DESC"
        );
        $vrWorkload = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $vrWorkload = [];
    }

    // DBV workload (active claims)
    $dbvWorkload = [];
    try {
        $stmt = $pdo->query(
            "SELECT c.dbv_assigned_user_id AS user_id, u.username, u.first_name, u.last_name, LOWER(TRIM(u.role)) AS role,\n" .
            "       COUNT(*) AS open_items\n" .
            "FROM Vati_Payfiller_Cases c\n" .
            "JOIN Vati_Payfiller_Users u ON u.user_id = c.dbv_assigned_user_id\n" .
            "WHERE c.dbv_assigned_user_id IS NOT NULL AND c.dbv_completed_at IS NULL\n" .
            "GROUP BY c.dbv_assigned_user_id, u.username, u.first_name, u.last_name, u.role\n" .
            "ORDER BY open_items DESC"
        );
        $dbvWorkload = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $dbvWorkload = [];
    }

    // Live assignments list (VR queue + DBV)
    $assignments = [];
    try {
        $stmt = $pdo->query(
            "(\n" .
            "  SELECT 'VR' AS queue_type, q.group_key, q.status AS queue_status, q.claimed_at AS assigned_at,\n" .
            "         c.case_id, c.application_id, c.case_status,\n" .
            "         u.user_id, u.username, u.first_name, u.last_name, LOWER(TRIM(u.role)) AS role\n" .
            "    FROM Vati_Payfiller_Verifier_Group_Queue q\n" .
            "    JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
            "    JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id\n" .
            "   WHERE q.assigned_user_id IS NOT NULL AND q.completed_at IS NULL\n" .
            ")\n" .
            "UNION ALL\n" .
            "(\n" .
            "  SELECT 'DBV' AS queue_type, NULL AS group_key, NULL AS queue_status, c.dbv_claimed_at AS assigned_at,\n" .
            "         c.case_id, c.application_id, c.case_status,\n" .
            "         u.user_id, u.username, u.first_name, u.last_name, LOWER(TRIM(u.role)) AS role\n" .
            "    FROM Vati_Payfiller_Cases c\n" .
            "    JOIN Vati_Payfiller_Users u ON u.user_id = c.dbv_assigned_user_id\n" .
            "   WHERE c.dbv_assigned_user_id IS NOT NULL AND c.dbv_completed_at IS NULL\n" .
            ")\n" .
            "ORDER BY assigned_at DESC\n" .
            "LIMIT 120"
        );
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $assignments = [];
    }

    // Canonical operational-status enrichment for case-level assignment rows.
    $assignments = os_enrich_rows($pdo, $assignments, 'qa');

    // Workload rows are user aggregates (not case rows). Attach canonical status
    // using centralized resolver so UI does not hardcode terms.
    foreach ($vrWorkload as &$r) {
        $open = (int)($r['open_items'] ?? 0);
        $resolved = os_resolve_operational_status([
            'role' => 'qa',
            'queue_status' => ($open > 0 ? 'pending' : 'completed'),
            'unresolved_queue_rows' => $open,
            'resolved_queue_rows' => ($open > 0 ? 0 : 1),
        ]);
        $r['operational_status'] = (string)$resolved['code'];
        $r['operational_status_label'] = (string)$resolved['label'];
        $r['operational_rule'] = (string)$resolved['rule'];
    }
    unset($r);

    foreach ($dbvWorkload as &$r) {
        $open = (int)($r['open_items'] ?? 0);
        $resolved = os_resolve_operational_status([
            'role' => 'qa',
            'queue_status' => ($open > 0 ? 'pending' : 'completed'),
            'unresolved_queue_rows' => $open,
            'resolved_queue_rows' => ($open > 0 ? 0 : 1),
        ]);
        $r['operational_status'] = (string)$resolved['code'];
        $r['operational_status_label'] = (string)$resolved['label'];
        $r['operational_rule'] = (string)$resolved['rule'];
    }
    unset($r);

    qa_dash_debug_log('dashboard_stats_resolved', [
        'vr_workload_rows' => count($vrWorkload),
        'dbv_workload_rows' => count($dbvWorkload),
        'assignments_rows' => count($assignments),
        'sample_assignment_status' => isset($assignments[0]) ? [
            'case_id' => (int)($assignments[0]['case_id'] ?? 0),
            'queue_status' => (string)($assignments[0]['queue_status'] ?? ''),
            'case_status' => (string)($assignments[0]['case_status'] ?? ''),
            'operational_status' => (string)($assignments[0]['operational_status'] ?? ''),
            'operational_status_label' => (string)($assignments[0]['operational_status_label'] ?? ''),
            'operational_rule' => (string)($assignments[0]['operational_rule'] ?? ''),
        ] : null,
    ]);

    $governance = [
        'supervisory_reopens_today' => 0,
        'invalidated_verifier_total' => 0,
        'invalidated_qa_total' => 0,
        'reopened_workflows_total' => 0,
    ];
    try {
        $gov = $pdo->query(
            "SELECT
                SUM(CASE WHEN LOWER(TRIM(COALESCE(stage,''))) = 'verifier' AND LOWER(TRIM(COALESCE(status,''))) = 'invalidated_by_validator_reopen' THEN 1 ELSE 0 END) AS invalidated_verifier_total,
                SUM(CASE WHEN LOWER(TRIM(COALESCE(stage,''))) = 'qa' AND LOWER(TRIM(COALESCE(status,''))) = 'invalidated_by_verifier_reopen' THEN 1 ELSE 0 END) AS invalidated_qa_total,
                SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) = 'reopened' THEN 1 ELSE 0 END) AS reopened_workflows_total
             FROM Vati_Payfiller_Case_Component_Workflow"
        );
        $gv = $gov->fetch(PDO::FETCH_ASSOC) ?: [];
        $governance['invalidated_verifier_total'] = (int)($gv['invalidated_verifier_total'] ?? 0);
        $governance['invalidated_qa_total'] = (int)($gv['invalidated_qa_total'] ?? 0);
        $governance['reopened_workflows_total'] = (int)($gv['reopened_workflows_total'] ?? 0);

        $gov2 = $pdo->query(
            "SELECT COUNT(*)
               FROM Vati_Payfiller_Workflow_Transitions
              WHERE LOWER(TRIM(COALESCE(action,''))) = 'reopen'
                AND LOWER(TRIM(COALESCE(actor_role,''))) IN ('qa','team_lead')
                AND DATE(created_at) = CURRENT_DATE()"
        );
        $governance['supervisory_reopens_today'] = (int)($gov2->fetchColumn() ?: 0);
    } catch (Throwable $e) {
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'kpis' => [
                'users_total' => $usersTotal,
                'users_by_role' => $usersByRole,
                'verifier_queue_open_total' => array_sum(array_map(fn($r) => (int)($r['open_items'] ?? 0), $vrWorkload)),
                'dbv_open_total' => array_sum(array_map(fn($r) => (int)($r['open_items'] ?? 0), $dbvWorkload)),
                'supervisory_reopens_today' => (int)$governance['supervisory_reopens_today'],
                'invalidated_verifier_total' => (int)$governance['invalidated_verifier_total'],
                'invalidated_qa_total' => (int)$governance['invalidated_qa_total'],
                'reopened_workflows_total' => (int)$governance['reopened_workflows_total'],
            ],
            'workload' => [
                'vr' => $vrWorkload,
                'dbv' => $dbvWorkload,
            ],
            'assignments' => $assignments
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
