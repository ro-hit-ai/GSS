<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/queue_visibility.php';
require_once __DIR__ . '/../shared/verifier_case_queue.php';

auth_require_login('verifier');
auth_session_start();

$userId = (int)($_SESSION['auth_user_id'] ?? 0);
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$clientId = isset($input['client_id']) ? (int)$input['client_id'] : 0;
$debug = !empty($input['debug']);

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
    verifier_case_queue_ensure_table($pdo);
    verifier_case_queue_clear_db_verifier_owners($pdo);
    try {
        $vfCases = $pdo->query(
            "SELECT DISTINCT case_id FROM (
                SELECT case_id
                  FROM Vati_Payfiller_Cases
                 WHERE LOWER(TRIM(COALESCE(workflow_mode,''))) = 'verifier_first'
                   AND UPPER(TRIM(COALESCE(case_status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED','CLEAR')
                UNION
                SELECT case_id
                  FROM Vati_Payfiller_Verifier_Group_Queue
            ) x"
        );
        foreach (($vfCases ? ($vfCases->fetchAll(PDO::FETCH_ASSOC) ?: []) : []) as $vfRow) {
            $cid = (int)($vfRow['case_id'] ?? 0);
            if ($cid <= 0) continue;
            if (verifier_case_queue_is_case_model($pdo, $cid, '')) {
                verifier_case_queue_ensure_row($pdo, $cid);
                verifier_case_queue_sync($pdo, $cid);
            } else {
                verifier_case_queue_sync_from_group_rows($pdo, $cid);
            }
        }
    } catch (Throwable $e) {
    }

    $st = $pdo->prepare(
        "SELECT q.id, q.case_id, q.application_id, q.client_id,
                COALESCE(LOWER(TRIM(q.status)), 'pending') AS status,
                q.assigned_user_id, q.claimed_at, q.completed_at, q.workflow_mode,
                c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at
           FROM Vati_Payfiller_Verifier_Case_Queue q
           JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id
          WHERE (? = 0 OR q.client_id = ?)
            AND q.completed_at IS NULL
            AND UPPER(TRIM(COALESCE(c.case_status,''))) NOT IN ('STOP_BGV','REJECTED','APPROVED','COMPLETED','CLEAR')
          ORDER BY COALESCE(q.claimed_at, c.created_at) ASC, q.id ASC
          LIMIT 300"
    );
    $st->execute([$clientId, $clientId]);
    $rows = verifier_filter_actionable_queue_rows($pdo, $st->fetchAll(PDO::FETCH_ASSOC) ?: [], []);

    $selected = null;
    foreach ($rows as $row) {
        if (!empty($row['can_open'])) {
            $selected = $row;
            break;
        }
    }
    if (!$selected) {
        foreach ($rows as $row) {
            if (!empty($row['can_claim'])) {
                $selected = $row;
                break;
            }
        }
    }

    if (!$selected) {
        $resp = ['status' => 1, 'message' => 'No claimable verifier components are available', 'data' => ['url' => null]];
        if ($debug) $resp['debug'] = ['path' => 'empty', 'candidate_rows' => count($rows)];
        echo json_encode($resp);
        exit;
    }

    $caseId = (int)($selected['case_id'] ?? 0);
    if ($caseId <= 0) {
        http_response_code(500);
        echo json_encode(['status' => 0, 'message' => 'Invalid case identifier returned from queue.']);
        exit;
    }

    if (empty($selected['can_open'])) {
        $claim = verifier_case_queue_claim($pdo, $caseId, $userId);
        if (empty($claim['ok'])) {
            http_response_code(409);
            echo json_encode(['status' => 0, 'message' => (string)($claim['message'] ?? 'Unable to claim case')]);
            exit;
        }
        $selected['claimed_components'] = $claim['components'] ?? [];
    }

    $appId = trim((string)($selected['application_id'] ?? ''));
    $cid = (int)($selected['client_id'] ?? 0);
    $view = app_url('/modules/verifier/candidate_view.php') . '?' . http_build_query([
        'case_id' => (string)$caseId,
        'application_id' => $appId,
        'client_id' => (string)$cid,
        'view' => 'mine',
        'filter' => 'active_work',
    ]);

    try {
        $log = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $role = !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : 'verifier';
        $claimedComponents = implode(', ', array_map('strval', $selected['claimed_components'] ?? $selected['owned_active_components'] ?? []));
        $log->execute([$appId, $userId, $role, 'update', 'verifier', 'Verifier opened component workload: ' . $claimedComponents]);
    } catch (Throwable $e) {
    }

    $resp = ['status' => 1, 'message' => 'ok', 'data' => ['case' => $selected, 'url' => $view]];
    if ($debug) $resp['debug'] = ['path' => empty($selected['claimed_components']) ? 'open_existing' : 'claimed', 'case_id' => $caseId];
    echo json_encode($resp);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
