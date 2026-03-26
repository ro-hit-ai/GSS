<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/queue_visibility.php';

auth_require_login('verifier');
auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
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

    $userId = (int)($_SESSION['auth_user_id'] ?? 0);
    $clientId = 0;
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $groupKey = strtoupper(get_str('group', ''));
    $search = get_str('search', '');
    $view = strtolower(get_str('view', 'available')); // available|mine|followup|completed

    if ($groupKey === '' || !in_array($groupKey, ['BASIC', 'EDUCATION'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Valid group is required']);
        exit;
    }

    $pdo = getDB();
    $allowedSet = verifier_allowed_sections_set_from_session($pdo);
    if (!verifier_can_group_by_sections($allowedSet, $groupKey)) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Access denied']);
        exit;
    }

    if ($view !== 'mine' && $view !== 'available' && $view !== 'followup' && $view !== 'completed') {
        $view = 'mine';
    }

    
    // Ensure verifier group queue exists so list does not appear empty in fresh/stale states.
    try {
        $ensure = $pdo->prepare('CALL SP_Vati_Payfiller_VR_EnsureGroupQueue(?)');
        $ensure->execute([$clientId > 0 ? $clientId : null]);
        while ($ensure->nextRowset()) {
        }
    } catch (Throwable $e) {
        // ignore and continue with listing query
    }

    if ($view === 'available') {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListAvailable(?, ?, ?, ?)');
        $stmt->execute([$userId, $clientId > 0 ? $clientId : null, $groupKey, $search !== '' ? $search : null]);
    } else if ($view === 'mine') {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListMine(?, ?, ?, ?)');
        $stmt->execute([$userId, $clientId > 0 ? $clientId : null, $groupKey, $search !== '' ? $search : null]);
    } else {
        $whereStatus = $view === 'followup'
            ? "q.completed_at IS NULL AND q.assigned_user_id = ? AND LOWER(TRIM(q.status)) = 'followup'"
            : "q.completed_at IS NOT NULL AND q.assigned_user_id = ?";

        $sql =
            'SELECT q.id, q.case_id, q.application_id, q.client_id, q.group_key, q.status, q.assigned_user_id, q.claimed_at, q.completed_at, ' .
            'c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at ' .
            'FROM Vati_Payfiller_Verifier_Group_Queue q ' .
            'JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id ' .
            'WHERE ( ? = 0 OR q.client_id = ? ) ' .
            'AND q.group_key = ? ' .
            'AND ' . $whereStatus . ' ' .
            "AND ( ? = '' OR c.application_id LIKE CONCAT('%', ?, '%') OR c.candidate_first_name LIKE CONCAT('%', ?, '%') OR c.candidate_last_name LIKE CONCAT('%', ?, '%') OR c.candidate_email LIKE CONCAT('%', ?, '%') OR c.candidate_mobile LIKE CONCAT('%', ?, '%') ) " .
            'ORDER BY ' . ($view === 'followup' ? 'q.claimed_at DESC' : 'q.completed_at DESC') . ', c.created_at ASC ' .
            'LIMIT 200';

        $stmt = $pdo->prepare($sql);
        $searchParam = $search !== '' ? $search : '';
        $stmt->execute([
            $clientId,
            $clientId,
            $groupKey,
            $userId,
            $searchParam,
            $searchParam,
            $searchParam,
            $searchParam,
            $searchParam,
            $searchParam
        ]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }
    $rows = verifier_filter_actionable_queue_rows($pdo, $rows, $allowedSet);

    // "Available" should also surface current verifier's open tasks so users can
    // continue work without switching views.
    if ($view === 'available') {
        $sqlMineOpen =
            'SELECT q.id, q.case_id, q.application_id, q.client_id, q.group_key, q.status, q.assigned_user_id, q.claimed_at, q.completed_at, ' .
            'c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at ' .
            'FROM Vati_Payfiller_Verifier_Group_Queue q ' .
            'JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id ' .
            'WHERE ( ? = 0 OR q.client_id = ? ) ' .
            'AND q.group_key = ? ' .
            'AND q.completed_at IS NULL AND q.assigned_user_id = ? ' .
            "AND ( ? = '' OR c.application_id LIKE CONCAT('%', ?, '%') OR c.candidate_first_name LIKE CONCAT('%', ?, '%') OR c.candidate_last_name LIKE CONCAT('%', ?, '%') OR c.candidate_email LIKE CONCAT('%', ?, '%') OR c.candidate_mobile LIKE CONCAT('%', ?, '%') ) " .
            'ORDER BY COALESCE(q.claimed_at, c.created_at) ASC ' .
            'LIMIT 200';
        $mineOpen = $pdo->prepare($sqlMineOpen);
        $searchParam = $search !== '' ? $search : '';
        $mineOpen->execute([
            $clientId,
            $clientId,
            $groupKey,
            $userId,
            $searchParam,
            $searchParam,
            $searchParam,
            $searchParam,
            $searchParam,
            $searchParam
        ]);
        $mineRows = $mineOpen->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mineRows = verifier_filter_actionable_queue_rows($pdo, $mineRows, $allowedSet);

        if ($mineRows) {
            $seen = [];
            foreach ($rows as $r) {
                $k = !empty($r['id'])
                    ? ('id:' . (string)$r['id'])
                    : ('cg:' . (string)($r['case_id'] ?? '') . '|' . strtoupper(trim((string)($r['group_key'] ?? ''))));
                $seen[$k] = true;
            }
            foreach ($mineRows as $r) {
                $k = !empty($r['id'])
                    ? ('id:' . (string)$r['id'])
                    : ('cg:' . (string)($r['case_id'] ?? '') . '|' . strtoupper(trim((string)($r['group_key'] ?? ''))));
                if (!isset($seen[$k])) {
                    $rows[] = $r;
                    $seen[$k] = true;
                }
            }
        }
    }

    // Fallback SQL when SPs return no rows (keeps UI stable across env/proc variants).
    if (!$rows && ($view === 'available' || $view === 'mine')) {
        $whereStatus = ($view === 'available')
            ? "q.completed_at IS NULL AND COALESCE(q.assigned_user_id,0) = 0"
            : "q.completed_at IS NULL AND q.assigned_user_id = ?";

        $sql =
            'SELECT q.id, q.case_id, q.application_id, q.client_id, q.group_key, q.status, q.assigned_user_id, q.claimed_at, q.completed_at, ' .
            'c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at ' .
            'FROM Vati_Payfiller_Verifier_Group_Queue q ' .
            'JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id ' .
            'WHERE ( ? = 0 OR q.client_id = ? ) ' .
            'AND q.group_key = ? ' .
            'AND ' . $whereStatus . ' ' .
            "AND ( ? = '' OR c.application_id LIKE CONCAT('%', ?, '%') OR c.candidate_first_name LIKE CONCAT('%', ?, '%') OR c.candidate_last_name LIKE CONCAT('%', ?, '%') OR c.candidate_email LIKE CONCAT('%', ?, '%') OR c.candidate_mobile LIKE CONCAT('%', ?, '%') ) " .
            'ORDER BY COALESCE(q.claimed_at, c.created_at) ASC ' .
            'LIMIT 200';

        $st = $pdo->prepare($sql);
        $searchParam = $search !== '' ? $search : '';
        $params = [$clientId, $clientId, $groupKey];
        if ($view === 'mine') {
            $params[] = $userId;
        }
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows = verifier_filter_actionable_queue_rows($pdo, $rows, $allowedSet);
    }

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
