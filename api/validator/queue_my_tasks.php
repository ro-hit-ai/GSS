<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('validator');
auth_session_start();

function is_stop_bgv_case(array $row): bool {
    $status = strtoupper(trim((string)($row['case_status'] ?? '')));
    return $status === 'STOP_BGV';
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

function is_candidate_pending_case(array $row): bool {
    $status = strtoupper(trim((string)($row['case_status'] ?? '')));
    if (!in_array($status, ['PENDING_CANDIDATE', 'CANDIDATE_PENDING', 'DRAFT'], true)) return false;
    $appStatus = strtolower(trim((string)($row['__app_status'] ?? '')));
    return $appStatus !== 'submitted';
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
    $stmt = $pdo->prepare(
        "SELECT q.case_id, q.application_id, q.client_id, q.status, q.assigned_user_id, q.claimed_at, q.completed_at,\n" .
        "       c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at\n" .
        "  FROM Vati_Payfiller_Validator_Queue q\n" .
        "  JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
        " WHERE q.assigned_user_id = ? AND q.completed_at IS NULL\n" .
        " ORDER BY q.claimed_at DESC, c.created_at ASC\n" .
        " LIMIT 10"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rows = enrich_rows_with_application_status($pdo, $rows);
    $rows = array_values(array_filter($rows, function ($r) {
        $it = (array)$r;
        return !is_stop_bgv_case($it) && !is_candidate_pending_case($it);
    }));

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
