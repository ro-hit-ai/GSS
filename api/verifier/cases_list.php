<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/queue_visibility.php';
require_once __DIR__ . '/../shared/workflow_status_semantics.php';
require_once __DIR__ . '/../shared/operational_status_governance.php';
require_once __DIR__ . '/../shared/verifier_case_queue.php';

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

function verifier_unique_upper_values(array $values): array
{
    $out = [];
    foreach ($values as $value) {
        $norm = strtoupper(trim((string)$value));
        if ($norm !== '') $out[$norm] = true;
    }
    return array_values(array_keys($out));
}

function verifier_csv_values(string $csv): array
{
    $parts = preg_split('/\s*,\s*/', trim($csv), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_map(static function ($v) {
        return strtolower(trim((string)$v));
    }, $parts)));
}

function verifier_group_context_map(PDO $pdo, array $caseIds): array
{
    $ids = [];
    foreach ($caseIds as $id) {
        $n = (int)$id;
        if ($n > 0) $ids[$n] = true;
    }
    $ids = array_keys($ids);
    if (!$ids) return [];

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT case_id,
                   GROUP_CONCAT(DISTINCT UPPER(TRIM(group_key)) ORDER BY group_key SEPARATOR ',') AS all_group_keys,
                   GROUP_CONCAT(DISTINCT CASE WHEN completed_at IS NULL THEN UPPER(TRIM(group_key)) END ORDER BY group_key SEPARATOR ',') AS open_group_keys
              FROM Vati_Payfiller_Verifier_Group_Queue
             WHERE case_id IN ($ph)
             GROUP BY case_id";
    $st = $pdo->prepare($sql);
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $cid = (int)($row['case_id'] ?? 0);
        if ($cid <= 0) continue;
        $allGroups = verifier_unique_upper_values(explode(',', (string)($row['all_group_keys'] ?? '')));
        $openGroups = verifier_unique_upper_values(explode(',', (string)($row['open_group_keys'] ?? '')));
        $currentGroups = $openGroups ?: $allGroups;
        $out[$cid] = [
            'all_group_keys' => $allGroups,
            'open_group_keys' => $openGroups,
            'current_group_keys' => $currentGroups,
        ];
    }
    return $out;
}

function verifier_lineage_context_map(PDO $pdo, array $caseIds): array
{
    $ids = [];
    foreach ($caseIds as $id) {
        $n = (int)$id;
        if ($n > 0) $ids[$n] = true;
    }
    $ids = array_keys($ids);
    if (!$ids) return [];

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT case_id,
                   SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) = 'reopened' THEN 1 ELSE 0 END) AS reopened_count,
                   SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('invalidated_by_validator_reopen','invalidated_by_verifier_reopen') THEN 1 ELSE 0 END) AS invalidated_count,
                   SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('waiting_candidate','insufficient_documents') THEN 1 ELSE 0 END) AS correction_count
              FROM Vati_Payfiller_Case_Component_Workflow
             WHERE case_id IN ($ph)
               AND LOWER(TRIM(stage)) = 'verifier'
             GROUP BY case_id";
    $st = $pdo->prepare($sql);
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $cid = (int)($row['case_id'] ?? 0);
        if ($cid <= 0) continue;
        $reopened = (int)($row['reopened_count'] ?? 0);
        $invalidated = (int)($row['invalidated_count'] ?? 0);
        $correction = (int)($row['correction_count'] ?? 0);
        $label = '';
        if ($invalidated > 0) {
            $label = 'Invalidated pending re-verification';
        } elseif ($reopened > 0) {
            $label = 'Reopened for re-verification';
        } elseif ($correction > 0) {
            $label = 'Correction in progress';
        }
        $out[$cid] = [
            'reopened_count' => $reopened,
            'invalidated_count' => $invalidated,
            'correction_count' => $correction,
            'label' => $label,
        ];
    }
    return $out;
}

function verifier_load_participated_rows(PDO $pdo, int $userId, int $clientId, string $search): array
{
    $searchParam = $search !== '' ? $search : '';

    $sql = 'SELECT c.case_id, c.application_id, c.client_id, '
        . 'c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, '
        . 'c.case_status, c.created_at, MAX(t.created_at) AS participated_at, '
        . "GROUP_CONCAT(DISTINCT LOWER(TRIM(COALESCE(t.component_key, ''))) ORDER BY LOWER(TRIM(COALESCE(t.component_key, ''))) SEPARATOR ',') AS participated_components "
        . 'FROM Vati_Payfiller_Workflow_Transitions t '
        . 'JOIN Vati_Payfiller_Cases c ON c.case_id = t.case_id AND c.application_id = t.application_id '
        . 'WHERE t.actor_user_id = ? '
        . "AND LOWER(TRIM(COALESCE(t.actor_role,''))) IN ('verifier','db_verifier','component verifier','component_verifier') "
        . 'AND (? = 0 OR c.client_id = ?) '
        . "AND ( ? = '' OR c.application_id LIKE CONCAT('%', ?, '%') OR c.candidate_first_name LIKE CONCAT('%', ?, '%') OR c.candidate_last_name LIKE CONCAT('%', ?, '%') OR c.candidate_email LIKE CONCAT('%', ?, '%') OR c.candidate_mobile LIKE CONCAT('%', ?, '%') ) "
        . 'GROUP BY c.case_id, c.application_id, c.client_id, c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at '
        . 'ORDER BY participated_at DESC, c.created_at DESC '
        . 'LIMIT 200';

    $st = $pdo->prepare($sql);
    $params = [
        $userId,
        $clientId,
        $clientId,
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam,
        $searchParam
    ];
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $caseIds = array_map(static function ($r) {
        return (int)($r['case_id'] ?? 0);
    }, $rows);
    $groupContextByCase = verifier_group_context_map($pdo, $caseIds);
    $lineageContextByCase = verifier_lineage_context_map($pdo, $caseIds);

    foreach ($rows as &$r) {
        $cid = (int)($r['case_id'] ?? 0);
        $components = verifier_csv_values((string)($r['participated_components'] ?? ''));
        $participatedGroups = [];
        foreach ($components as $component) {
            $participatedGroups = array_merge($participatedGroups, wf_verifier_groups_for_component($component));
        }
        $participatedGroups = verifier_unique_upper_values($participatedGroups);
        $queueCtx = $groupContextByCase[$cid] ?? ['all_group_keys' => [], 'open_group_keys' => [], 'current_group_keys' => []];
        $currentGroups = $queueCtx['current_group_keys'] ?? [];
        $openGroups = $queueCtx['open_group_keys'] ?? [];
        $allGroups = $queueCtx['all_group_keys'] ?? [];

        $r['group_key'] = $currentGroups[0] ?? ($participatedGroups[0] ?? '');
        $r['participated_group_keys'] = $participatedGroups;
        $r['current_group_keys'] = $currentGroups;
        $r['open_group_keys'] = $openGroups;
        $r['all_group_keys'] = $allGroups;
        $r['lineage_context_label'] = (string)(($lineageContextByCase[$cid]['label'] ?? ''));
        $r['visibility_context'] = 'participated';

        if ($participatedGroups && $currentGroups && implode('|', $participatedGroups) !== implode('|', $currentGroups)) {
            $r['group_context_label'] = 'Participated under ' . implode(' / ', $participatedGroups) . '; Current workflow group ' . implode(' / ', $currentGroups);
        } elseif ($participatedGroups) {
            $r['group_context_label'] = 'Participated under ' . implode(' / ', $participatedGroups);
        } elseif ($currentGroups) {
            $r['group_context_label'] = 'Current workflow group ' . implode(' / ', $currentGroups);
        } else {
            $r['group_context_label'] = '';
        }

        $r['id'] = null;
        $r['status'] = (string)($r['case_status'] ?? '');
        $r['assigned_user_id'] = $userId;
        $r['claimed_at'] = (string)($r['participated_at'] ?? '');
        $r['completed_at'] = (string)($r['participated_at'] ?? '');
    }
    unset($r);
    return $rows;
}

function verifier_participation_label(string $status): string
{
    $s = strtolower(trim($status));
    if ($s === 'approved') return 'VE APPROVED';
    if ($s === 'rejected') return 'VE REJECTED';
    if ($s === 'hold') return 'VE HOLD';
    if ($s === 'insufficient_documents') return 'VE NEED DOCS';
    if ($s === 'waiting_candidate') return 'CANDIDATE PENDING';
    if ($s === 'mail_sent') return 'MAIL SENT';
    if ($s === 'reopened') return 'VE REOPENED';
    if ($s === 'blocked') return 'VE BLOCKED';
    if ($s === 'in_progress') return 'VE PENDING';
    return 'VE PENDING';
}

function verifier_last_outcome_by_case(PDO $pdo, int $userId, array $caseIds): array
{
    $ids = [];
    foreach ($caseIds as $id) {
        $n = (int)$id;
        if ($n > 0) $ids[$n] = true;
    }
    $ids = array_values(array_keys($ids));
    if (!$ids) return [];

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT t.case_id, LOWER(TRIM(COALESCE(t.to_status,''))) AS to_status
            FROM Vati_Payfiller_Workflow_Transitions t
            INNER JOIN (
                SELECT case_id, MAX(transition_id) AS max_id
                FROM Vati_Payfiller_Workflow_Transitions
                WHERE actor_user_id = ?
                  AND LOWER(TRIM(COALESCE(actor_role,''))) IN ('verifier','db_verifier','component verifier','component_verifier')
                  AND case_id IN ($ph)
                GROUP BY case_id
            ) x ON x.max_id = t.transition_id";
    $st = $pdo->prepare($sql);
    $params = array_merge([$userId], $ids);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $cid = (int)($r['case_id'] ?? 0);
        if ($cid <= 0) continue;
        $out[$cid] = strtolower(trim((string)($r['to_status'] ?? '')));
    }
    return $out;
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
    $view = strtolower(get_str('view', 'available'));
    $filterMode = strtolower(get_str('filter', 'all'));
    $sourceRoute = get_str('src', '');
    $debug = get_str('debug', '') === '1';

    if ($groupKey !== '' && !wf_is_valid_verifier_group($groupKey)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Valid group is required']);
        exit;
    }

    $pdo = getDB();
    verifier_case_queue_clear_db_verifier_owners($pdo);
    $allowedSet = verifier_allowed_sections_set_from_session($pdo);
    if ($groupKey !== '' && !verifier_can_group_by_sections($allowedSet, $groupKey)) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Access denied']);
        exit;
    }

    $includeCompletedForAll = false;
    if ($view !== 'mine' && $view !== 'available' && $view !== 'followup' && $view !== 'completed' && $view !== 'active' && $view !== 'claimable' && $view !== 'all' && $view !== 'history' && $view !== 'participated') {
        $view = 'mine';
    }
    if ($view === 'claimable') $view = 'available';
    if ($view === 'active') $view = 'mine';
    if ($view === 'all') {
        $view = 'mine';
        $includeCompletedForAll = true;
    }
    if ($view === 'history') $view = 'participated';
    if ($view === 'completed') $view = 'participated';

    if ($groupKey === '' && $view !== 'participated') {
        verifier_case_queue_ensure_table($pdo);
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
        $whereStatus = $includeCompletedForAll ? "1=1" : "q.completed_at IS NULL";
        if ($view === 'followup') {
            $whereStatus = "q.completed_at IS NULL AND LOWER(TRIM(q.status)) IN ('followup','hold','reopened','blocked')";
        }
        $sql =
            'SELECT q.id, q.case_id, q.application_id, q.client_id, NULL AS group_key, q.status, q.assigned_user_id, q.claimed_at, q.completed_at, ' .
            'c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at ' .
            'FROM Vati_Payfiller_Verifier_Case_Queue q ' .
            'JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id ' .
            'WHERE ' . $whereStatus . ' ' .
            "AND UPPER(TRIM(COALESCE(c.case_status,''))) NOT IN ('STOP_BGV','REJECTED') " .
            "AND ( ? = '' OR c.application_id LIKE CONCAT('%', ?, '%') OR c.candidate_first_name LIKE CONCAT('%', ?, '%') OR c.candidate_last_name LIKE CONCAT('%', ?, '%') OR c.candidate_email LIKE CONCAT('%', ?, '%') OR c.candidate_mobile LIKE CONCAT('%', ?, '%') ) " .
            'ORDER BY COALESCE(q.claimed_at, c.created_at) ASC, q.id ASC LIMIT 300';
        $searchParam = $search !== '' ? $search : '';
        $st = $pdo->prepare($sql);
        $st->execute([$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
        $rows = verifier_filter_actionable_queue_rows($pdo, $st->fetchAll(PDO::FETCH_ASSOC) ?: [], $allowedSet);

        if ($includeCompletedForAll) {
            $rows = array_values(array_filter($rows, static function ($r): bool {
                return !empty($r['can_claim']) || !empty($r['can_open']) || trim((string)($r['completed_at'] ?? '')) !== '';
            }));
        } elseif ($view === 'available') {
            $rows = array_values(array_filter($rows, static function ($r): bool {
                return !empty($r['can_claim']) || !empty($r['can_open']);
            }));
        } elseif ($view === 'mine') {
            $rows = array_values(array_filter($rows, static function ($r): bool {
                return !empty($r['can_open']) || !empty($r['can_claim']);
            }));
        }

        foreach ($rows as &$r) {
            $s = strtolower(trim((string)($r['status'] ?? '')));
            $completedAt = trim((string)($r['completed_at'] ?? ''));
            $r['visibility_context'] = 'operational';
            $r['is_active_work'] = ($completedAt === '' && wf_is_active_queue_status($s)) ? 1 : 0;
            $r['evaluated_visible'] = (($completedAt !== '') || wf_is_visible_historical_status($s)) ? 1 : 0;
            $r['is_evaluated'] = $r['evaluated_visible'];
            $r['rejected_visible'] = ((int)$r['evaluated_visible'] === 1 && $s === 'rejected') ? 1 : 0;
            $r['visibility_class'] = $r['is_active_work'] ? 'active_work' : ($r['is_evaluated'] ? 'evaluated_history' : 'other');
        }
        unset($r);
        $rows = os_enrich_rows($pdo, $rows, 'verifier');
        echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);
        exit;
    }

    $rawMineRows = [];
    $rawAvailRows = [];
    $stmt = null;
    if ($view === 'available') {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListAvailable(?, ?, ?, ?)');
        $stmt->execute([$userId, $clientId > 0 ? $clientId : null, $groupKey, $search !== '' ? $search : null]);
        $rawAvailRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows = $rawAvailRows;
    } else if ($view === 'mine') {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListMine(?, ?, ?, ?)');
        $stmt->execute([$userId, $clientId > 0 ? $clientId : null, $groupKey, $search !== '' ? $search : null]);
        $rawMineRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows = $rawMineRows;
    } else if ($view === 'participated') {
        $rows = verifier_load_participated_rows($pdo, $userId, $clientId, $search);
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

    if (!isset($rows) || !is_array($rows)) {
        $rows = ($stmt instanceof PDOStatement) ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
    if ($stmt instanceof PDOStatement) {
        while ($stmt->nextRowset()) {
        }
    }
    if ($view !== 'participated') {
        $rows = verifier_filter_actionable_queue_rows($pdo, $rows, $allowedSet);
    }

    // Keep verifier candidate list "mine" semantics aligned with dashboard (scope=mine):
    // include unassigned active rows in the selected group as immediately visible workload.
    if ($view === 'mine') {
        $availForMine = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListAvailable(?, ?, ?, ?)');
        $availForMine->execute([$userId, $clientId > 0 ? $clientId : null, $groupKey, $search !== '' ? $search : null]);
        $rawAvailRows = $availForMine->fetchAll(PDO::FETCH_ASSOC) ?: [];
        while ($availForMine->nextRowset()) {
        }
        $availRowsMine = verifier_filter_actionable_queue_rows($pdo, $rawAvailRows, $allowedSet);
        if ($availRowsMine) {
            $seen = [];
            foreach ($rows as $r) {
                $k = !empty($r['id'])
                    ? ('id:' . (string)$r['id'])
                    : ('cg:' . (string)($r['case_id'] ?? '') . '|' . strtoupper(trim((string)($r['group_key'] ?? ''))));
                $seen[$k] = true;
            }
            foreach ($availRowsMine as $r) {
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
    if (($view === 'available' || $view === 'mine') && !$includeCompletedForAll) {
        $rows = array_values(array_filter($rows, static function ($r): bool {
            $s = strtolower(trim((string)($r['status'] ?? '')));
            $completedAt = trim((string)($r['completed_at'] ?? ''));
            return $completedAt === '' && wf_is_active_queue_status($s);
        }));
    }

    if ($includeCompletedForAll) {
        $completedRows = verifier_load_participated_rows($pdo, $userId, $clientId, $search);
        if ($completedRows) {
            $seen = [];
            foreach ($rows as $r) {
                $app = trim((string)($r['application_id'] ?? ''));
                if ($app !== '') $seen[$app] = true;
            }
            foreach ($completedRows as $r) {
                $app = trim((string)($r['application_id'] ?? ''));
                if ($app !== '' && !isset($seen[$app])) {
                    $rows[] = $r;
                    $seen[$app] = true;
                }
            }
        }
    }

    // "Available" should also surface current verifier's open tasks so users can
    // continue work without switching views.
    if ($view === 'available' && !$includeCompletedForAll) {
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
    if (!$rows && ($view === 'available' || $view === 'mine') && !$includeCompletedForAll) {
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
        if ($view === 'available' || $view === 'mine') {
            $rows = array_values(array_filter($rows, static function ($r): bool {
                $s = strtolower(trim((string)($r['status'] ?? '')));
                $completedAt = trim((string)($r['completed_at'] ?? ''));
                return $completedAt === '' && wf_is_active_queue_status($s);
            }));
        }
    }

    foreach ($rows as &$r) {
        $s = strtolower(trim((string)($r['status'] ?? '')));
        $completedAt = trim((string)($r['completed_at'] ?? ''));
        if (!isset($r['visibility_context']) || trim((string)$r['visibility_context']) === '') {
            $r['visibility_context'] = ($view === 'participated') ? 'participated' : 'operational';
        }
        $r['is_active_work'] = ($completedAt === '' && wf_is_active_queue_status($s)) ? 1 : 0;
        $r['evaluated_visible'] = (($completedAt !== '') || wf_is_visible_historical_status($s)) ? 1 : 0;
        $r['is_evaluated'] = $r['evaluated_visible'];
        $r['rejected_visible'] = ((int)$r['evaluated_visible'] === 1 && $s === 'rejected') ? 1 : 0;
        $r['visibility_class'] = $r['is_active_work'] ? 'active_work' : ($r['is_evaluated'] ? 'evaluated_history' : 'other');
    }
    unset($r);
    $rows = os_enrich_rows($pdo, $rows, 'verifier');

    if ($view === 'participated' && $rows) {
        $caseIds = array_map(static function ($r) {
            return (int)($r['case_id'] ?? 0);
        }, $rows);
        $lastByCase = verifier_last_outcome_by_case($pdo, $userId, $caseIds);
        foreach ($rows as &$r) {
            $cid = (int)($r['case_id'] ?? 0);
            $last = strtolower(trim((string)($lastByCase[$cid] ?? '')));
            $lineageContext = trim((string)($r['lineage_context_label'] ?? ''));
            if ($last !== '') {
                $r['participation_status_code'] = $last;
                $r['participation_status_label'] = verifier_participation_label($last);
                // In participated/history context, preserve verifier semantic identity as primary.
                $r['operational_status'] = $last;
                $r['operational_status_label'] = $r['participation_status_label'];
            } else {
                $r['participation_status_code'] = '';
                $r['participation_status_label'] = '';
            }

            $caseStatus = strtoupper(trim((string)($r['case_status'] ?? '')));
            $secondary = [];
            if (strpos($caseStatus, 'PENDING_QA') !== false || strpos($caseStatus, 'QA') !== false) {
                $secondary[] = 'Now in QA';
            }
            $groupContextLabel = trim((string)($r['group_context_label'] ?? ''));
            if ($groupContextLabel !== '') {
                $secondary[] = $groupContextLabel;
            }
            if ($lineageContext !== '') {
                $secondary[] = $lineageContext;
            }
            $r['operational_status_secondary'] = implode(' | ', $secondary);
        }
        unset($r);
    }

    if (getenv('WF_STATUS_DEBUG_LOGS') === '1') {
        @file_put_contents(__DIR__ . '/../../logs/workflow_transition.log', json_encode([
            'ts' => date('c'),
            'event' => 'verifier_visibility_mode_resolved',
            'verifier_user_id' => $userId,
            'visibility_mode' => $view,
            'source_route' => $sourceRoute,
            'filter_mode' => $filterMode,
            'group_key' => $groupKey,
            'queue_filters_applied' => ($view === 'available' || $view === 'mine' || $view === 'followup') ? ['completed_at IS NULL', 'wf_is_active_queue_status'] : [],
            'participation_source' => ($view === 'participated') ? 'actor_transition_lineage' : 'queue_rows',
            'visibility_context' => ($view === 'participated') ? 'participated' : 'operational',
            'rows' => count($rows),
            'sample_render_context' => ($view === 'participated' && isset($rows[0])) ? [
                'lifecycle_owner' => (string)($rows[0]['case_status'] ?? ''),
                'last_verifier_outcome' => (string)($rows[0]['participation_status_code'] ?? ''),
                'resolved_ui_label' => (string)($rows[0]['operational_status_label'] ?? ''),
                'group_context_label' => (string)($rows[0]['group_context_label'] ?? ''),
            ] : null,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }
    $resp = ['status' => 1, 'message' => 'ok', 'data' => $rows];
    if ($debug) {
        $active = 0; $eval = 0; $rej = 0;
        $dashboardVisibleApps = [];
        $candidateListApps = [];
        $filteredOutApps = [];
        $seenRowsApps = [];
        foreach ($rows as $r) {
            if ((int)($r['is_active_work'] ?? 0) === 1) $active++;
            if ((int)($r['evaluated_visible'] ?? 0) === 1) $eval++;
            if ((int)($r['rejected_visible'] ?? 0) === 1) $rej++;
            $app = trim((string)($r['application_id'] ?? ''));
            if ($app !== '') {
                $candidateListApps[$app] = true;
                $seenRowsApps[$app] = true;
            }
        }
        $poolRows = array_merge($rawMineRows, $rawAvailRows);
        foreach ($poolRows as $r) {
            $app = trim((string)($r['application_id'] ?? ''));
            if ($app !== '') $dashboardVisibleApps[$app] = true;
        }
        foreach ($dashboardVisibleApps as $app => $_v) {
            if (!isset($candidateListApps[$app])) {
                $filteredOutApps[$app] = true;
            }
        }
        $statusActive = [];
        $statusEvaluated = [];
        $unresolved = 0;
        foreach ($rows as $r) {
            $s = strtolower(trim((string)($r['status'] ?? '')));
            if ((int)($r['is_active_work'] ?? 0) === 1) $statusActive[$s] = true;
            if ((int)($r['evaluated_visible'] ?? 0) === 1) $statusEvaluated[$s] = true;
            if (wf_is_operationally_active_status($s)) $unresolved++;
        }
        $resp['debug'] = [
            'active_count' => $active,
            'evaluated_count' => $eval,
            'unresolved_count' => $unresolved,
            'historical_visible_count' => $eval,
            'active_statuses_detected' => array_values(array_keys($statusActive)),
            'evaluated_statuses_detected' => array_values(array_keys($statusEvaluated)),
            'projection_reason' => 'verifier_cases_list_semantic_helpers',
            'visibility_classification' => 'active_vs_evaluated',
            'rejected_visible_count' => $rej,
            'hidden_closed_count' => 0,
            'dashboard_visible_applications' => array_values(array_keys($dashboardVisibleApps)),
            'candidate_list_applications' => array_values(array_keys($candidateListApps)),
            'filtered_out_applications' => array_values(array_keys($filteredOutApps)),
            'exclusion_reason' => 'filtered_non_active_or_semantic_mismatch',
            'aggregation_status' => 'group_case_row_union_aligned_with_dashboard'
        ];
    }
    echo json_encode($resp);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
