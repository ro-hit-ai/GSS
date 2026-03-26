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

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

function enrich_rows_with_application_status(PDO $pdo, array $rows): array {
    if (!$rows) return [];
    $appIds = [];
    foreach ($rows as $r) {
        $appId = trim((string)($r['application_id'] ?? ''));
        if ($appId !== '') $appIds[$appId] = true;
    }
    if (!$appIds) return $rows;

    $ids = array_keys($appIds);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $map = [];
    try {
        $st = $pdo->prepare('SELECT application_id, LOWER(TRIM(COALESCE(status, \'\'))) AS app_status FROM Vati_Payfiller_Candidate_Applications WHERE application_id IN (' . $ph . ')');
        $st->execute($ids);
        $rr = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rr as $it) {
            $k = trim((string)($it['application_id'] ?? ''));
            if ($k === '') continue;
            $map[$k] = (string)($it['app_status'] ?? '');
        }
    } catch (Throwable $e) {
        return $rows;
    }

    foreach ($rows as &$r) {
        $appId = trim((string)($r['application_id'] ?? ''));
        $r['__app_status'] = $appId !== '' ? (string)($map[$appId] ?? '') : '';
    }
    unset($r);
    return $rows;
}

function is_stop_bgv_case(array $row): bool {
    $status = strtoupper(trim((string)($row['case_status'] ?? '')));
    return $status === 'STOP_BGV';
}

function is_candidate_pending_case(array $row): bool {
    $status = strtoupper(trim((string)($row['case_status'] ?? '')));
    if (!in_array($status, ['PENDING_CANDIDATE', 'CANDIDATE_PENDING', 'DRAFT'], true)) return false;
    $appStatus = strtolower(trim((string)($row['__app_status'] ?? '')));
    // If candidate application is already submitted, treat as validator-actionable.
    return $appStatus !== 'submitted';
}

function filter_open_rows(array $rows): array {
    if (!$rows) return [];
    return array_values(array_filter($rows, function ($r) {
        $it = (array)$r;
        return !is_stop_bgv_case($it) && !is_candidate_pending_case($it);
    }));
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

    $view = strtolower(get_str('view', 'available'));
    $search = get_str('search', '');

    $pdo = getDB();

    // Ensure validator queue is seeded (candidate-submitted cases only) before listing.
    // Without this, the list can appear empty on new/clean environments.
    if ($view === 'available' || $view === 'mine') {
        $seed = $pdo->prepare('CALL SP_Vati_Payfiller_VAL_EnsureQueue(?)');
        $seed->execute([null]);
        while ($seed->nextRowset()) {
        }
    }

    if ($view === 'available') {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VAL_ListAvailable(?, ?)');
        $stmt->execute([null, $search !== '' ? $search : null]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        while ($stmt->nextRowset()) {
        }

        // Include current user's in-progress/open items in "available" view
        // so validator list shows both pending + in-progress open work.
        $mineStmt = $pdo->prepare('CALL SP_Vati_Payfiller_VAL_ListMine(?, ?, ?)');
        $mineStmt->execute([$userId, null, $search !== '' ? $search : null]);
        $mineRows = $mineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        while ($mineStmt->nextRowset()) {
        }
        if ($mineRows) {
            $seen = [];
            foreach ($rows as $r) {
                $k = isset($r['case_id']) ? (string)$r['case_id'] : '';
                if ($k !== '') $seen[$k] = true;
            }
            foreach ($mineRows as $mr) {
                $k = isset($mr['case_id']) ? (string)$mr['case_id'] : '';
                if ($k !== '' && isset($seen[$k])) continue;
                if ($k !== '') $seen[$k] = true;
                $rows[] = $mr;
            }
        }

        // Fallback query when SP returns no data (keeps UI usable across env/proc variants).
        if (!$rows) {
            $params = [];
            $sql = "SELECT q.case_id, q.application_id, q.client_id, q.status, q.assigned_user_id, q.claimed_at, q.completed_at,\n" .
                   "       c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at\n" .
                   "  FROM Vati_Payfiller_Validator_Queue q\n" .
                   "  JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
                   " WHERE q.completed_at IS NULL\n" .
                   "   AND (COALESCE(q.assigned_user_id, 0) = 0 OR q.assigned_user_id = ?)\n" .
                   "   AND LOWER(TRIM(COALESCE(q.status, 'pending'))) <> 'completed'";
            $params[] = $userId;

            if ($search !== '') {
                $sql .= " AND (q.application_id LIKE ? OR c.candidate_first_name LIKE ? OR c.candidate_last_name LIKE ? OR c.candidate_email LIKE ? OR c.candidate_mobile LIKE ?)";
                $like = '%' . $search . '%';
                $params = array_merge($params, [$like, $like, $like, $like, $like]);
            }

            $sql .= " ORDER BY COALESCE(q.claimed_at, c.created_at) ASC LIMIT 500";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $rows = enrich_rows_with_application_status($pdo, $rows);
        $rows = filter_open_rows($rows);
        echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);
        exit;
    }

    if ($view === 'completed') {
        $params = [];
        $sql = "SELECT q.case_id, q.application_id, q.client_id, q.status, q.assigned_user_id, q.claimed_at, q.completed_at,\n" .
               "       c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at\n" .
               "  FROM Vati_Payfiller_Validator_Queue q\n" .
               "  JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
               " WHERE (q.completed_at IS NOT NULL OR q.status = 'completed')";

        if ($search !== '') {
            $sql .= " AND (c.application_id LIKE ? OR c.candidate_first_name LIKE ? OR c.candidate_last_name LIKE ? OR c.candidate_email LIKE ? OR c.candidate_mobile LIKE ?)";
            $like = '%' . $search . '%';
            $params = [$like, $like, $like, $like, $like];
        }

        $sql .= " ORDER BY COALESCE(q.completed_at, q.claimed_at, c.created_at) DESC LIMIT 500";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows = enrich_rows_with_application_status($pdo, $rows);
        echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);
        exit;
    }

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VAL_ListMine(?, ?, ?)');
    $stmt->execute([$userId, null, $search !== '' ? $search : null]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    // Fallback for environments where the SP may be stricter than queue_claim/list UI.
    if (!$rows) {
        $params = [$userId];
        $sql = "SELECT q.case_id, q.application_id, q.client_id, q.status, q.assigned_user_id, q.claimed_at, q.completed_at,\n" .
               "       c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at\n" .
               "  FROM Vati_Payfiller_Validator_Queue q\n" .
               "  JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
               " WHERE q.completed_at IS NULL\n" .
               "   AND q.assigned_user_id = ?";

        if ($search !== '') {
            $sql .= " AND (q.application_id LIKE ? OR c.candidate_first_name LIKE ? OR c.candidate_last_name LIKE ? OR c.candidate_email LIKE ? OR c.candidate_mobile LIKE ?)";
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }

        $sql .= " ORDER BY COALESCE(q.claimed_at, c.created_at) DESC LIMIT 500";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $rows = enrich_rows_with_application_status($pdo, $rows);
    $rows = filter_open_rows($rows);
    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
