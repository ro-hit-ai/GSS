<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../shared/workflow_status_semantics.php';
require_once __DIR__ . '/../shared/workflow_stage_config.php';
require_once __DIR__ . '/../shared/operational_status_governance.php';

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

function row_visibility_flags(array $row): array {
    $status = strtolower(trim((string)($row['status'] ?? 'pending')));
    $completedAt = trim((string)($row['completed_at'] ?? ''));
    $isEvaluated = ($completedAt !== '') || wf_is_visible_historical_status($status);
    $isActive = ($completedAt === '') && wf_is_active_queue_status($status);
    return [
        'is_active_work' => $isActive ? 1 : 0,
        'evaluated_visible' => $isEvaluated ? 1 : 0,
        'is_evaluated' => $isEvaluated ? 1 : 0,
        'rejected_visible' => ($isEvaluated && $status === 'rejected') ? 1 : 0,
        'visibility_class' => $isActive ? 'active_work' : ($isEvaluated ? 'evaluated_history' : 'other')
    ];
}

function annotate_visibility(array $rows): array {
    foreach ($rows as &$r) {
        $f = row_visibility_flags((array)$r);
        $r['is_active_work'] = $f['is_active_work'];
        $r['evaluated_visible'] = $f['evaluated_visible'];
        $r['is_evaluated'] = $f['is_evaluated'];
        $r['rejected_visible'] = $f['rejected_visible'];
        $r['visibility_class'] = $f['visibility_class'];
    }
    unset($r);
    return $rows;
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
    $debug = get_str('debug', '') === '1';

    $pdo = getDB();

    if ($view === 'all' || $view === 'all_cases') {
        $rows = [];

        // Active visibility pool (queue actionable surface).
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VAL_ListAvailable(?, ?)');
        $stmt->execute([null, $search !== '' ? $search : null]);
        $availRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        while ($stmt->nextRowset()) {}

        $mineStmt = $pdo->prepare('CALL SP_Vati_Payfiller_VAL_ListMine(?, ?, ?)');
        $mineStmt->execute([$userId, null, $search !== '' ? $search : null]);
        $mineRows = $mineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        while ($mineStmt->nextRowset()) {}

        $rows = array_merge($availRows, $mineRows);

        // Evaluated/historical visibility pool owned by validator.
        $stage1 = wf_stage_keys()[0] ?? 'validator';
        $params = [$userId, $userId, strtolower(trim($stage1))];
        $sql = "SELECT q.case_id, q.application_id, q.client_id, q.status, q.assigned_user_id, q.claimed_at, q.completed_at,\n" .
               "       c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at\n" .
               "  FROM Vati_Payfiller_Validator_Queue q\n" .
               "  JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
               " WHERE (q.assigned_user_id = ? OR EXISTS (\n" .
               "        SELECT 1 FROM Vati_Payfiller_Workflow_Transitions a\n" .
               "         WHERE a.case_id = q.case_id\n" .
               "           AND a.actor_user_id = ?\n" .
               "           AND LOWER(TRIM(COALESCE(a.stage,''))) = ?\n" .
               "      ))";
        if ($search !== '') {
            $sql .= " AND (q.application_id LIKE ? OR c.candidate_first_name LIKE ? OR c.candidate_last_name LIKE ? OR c.candidate_email LIKE ? OR c.candidate_mobile LIKE ?)";
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }
        $sql .= " ORDER BY CASE WHEN q.completed_at IS NULL THEN 0 ELSE 1 END, COALESCE(q.completed_at, q.claimed_at, c.created_at) DESC LIMIT 500";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $histRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows = array_merge($rows, $histRows);

        // Deduplicate by case_id after union.
        if ($rows) {
            $seen = [];
            $dedup = [];
            foreach ($rows as $r) {
                $k = isset($r['case_id']) ? (string)$r['case_id'] : '';
                if ($k === '') continue;
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $dedup[] = $r;
            }
            $rows = $dedup;
        }

        $rows = enrich_rows_with_application_status($pdo, $rows);
        $rows = array_values(array_filter($rows, function ($r) {
            $it = (array)$r;
            if (is_stop_bgv_case($it) || is_candidate_pending_case($it)) return false;
            if (wf_is_closed_hidden_status((string)($it['status'] ?? ''))) return false;
            return true;
        }));
        $rows = annotate_visibility($rows);
        $rows = os_enrich_rows($pdo, $rows, 'validator');
        $resp = ['status' => 1, 'message' => 'ok', 'data' => $rows];
        if ($debug) {
            $active = 0; $eval = 0; $unresolved = 0;
            $activeStatuses = []; $evaluatedStatuses = [];
            foreach ($rows as $r) {
                $s = strtolower(trim((string)($r['status'] ?? '')));
                if ((int)($r['is_active_work'] ?? 0) === 1) { $active++; $activeStatuses[$s] = true; }
                if ((int)($r['evaluated_visible'] ?? 0) === 1) { $eval++; $evaluatedStatuses[$s] = true; }
                if (wf_is_operationally_active_status($s)) $unresolved++;
            }
            $resp['debug'] = [
                'helper_owner_file' => realpath(__DIR__ . '/../shared/workflow_stage_config.php') ?: (__DIR__ . '/../shared/workflow_stage_config.php'),
                'helper_loaded' => function_exists('wf_stage_keys'),
                'stage_keys_resolved' => function_exists('wf_stage_keys') ? wf_stage_keys() : [],
                'selected_view' => $view,
                'selected_visibility_mode' => 'all_cases',
                'generated_filters' => ['exclude_stop_bgv' => true, 'exclude_candidate_pending_draft' => true, 'exclude_closed_hidden' => true],
                'active_count' => $active,
                'evaluated_count' => $eval,
                'unresolved_count' => $unresolved,
                'active_statuses_detected' => array_values(array_keys($activeStatuses)),
                'evaluated_statuses_detected' => array_values(array_keys($evaluatedStatuses)),
                'returned_row_count' => count($rows),
                'visibility_classification' => 'active_plus_evaluated_union',
                'projection_reason' => 'validator_cases_all_union_visibility'
            ];
        }
        echo json_encode($resp);
        exit;
    }

    if ($view === 'active' || $view === 'available') {
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
        $rows = annotate_visibility($rows);
        $rows = os_enrich_rows($pdo, $rows, 'validator');
        $resp = ['status' => 1, 'message' => 'ok', 'data' => $rows];
        if ($debug) {
            $active = 0; $eval = 0; $unresolved = 0;
            $activeStatuses = []; $evaluatedStatuses = [];
            foreach ($rows as $r) {
                $s = strtolower(trim((string)($r['status'] ?? '')));
                if ((int)($r['is_active_work'] ?? 0) === 1) {
                    $active++;
                    $activeStatuses[$s] = true;
                }
                if ((int)($r['evaluated_visible'] ?? 0) === 1) {
                    $eval++;
                    $evaluatedStatuses[$s] = true;
                }
                if (wf_is_operationally_active_status($s)) $unresolved++;
            }
            $resp['debug'] = [
                'helper_owner_file' => realpath(__DIR__ . '/../shared/workflow_stage_config.php') ?: (__DIR__ . '/../shared/workflow_stage_config.php'),
                'helper_loaded' => function_exists('wf_stage_keys'),
                'stage_keys_resolved' => function_exists('wf_stage_keys') ? wf_stage_keys() : [],
                'selected_view' => $view,
                'selected_visibility_mode' => 'active_work',
                'generated_filters' => ['active_queue_only' => true, 'exclude_stop_bgv' => true, 'exclude_candidate_pending_draft' => true],
                'active_count' => $active,
                'evaluated_count' => $eval,
                'unresolved_count' => $unresolved,
                'historical_visible_count' => $eval,
                'active_statuses_detected' => array_values(array_keys($activeStatuses)),
                'evaluated_statuses_detected' => array_values(array_keys($evaluatedStatuses)),
                'projection_reason' => 'validator_cases_list_semantic_helpers',
                'visibility_classification' => 'active_vs_evaluated',
                'returned_row_count' => count($rows),
                'active_queue_count' => $active,
                'evaluated_visible_count' => $eval,
                'rejected_visible_count' => 0,
                'hidden_closed_count' => 0
            ];
        }
        echo json_encode($resp);
        exit;
    }

    if ($view === 'history' || $view === 'completed') {
        $stage1 = wf_stage_keys()[0] ?? 'validator';
        $params = [];
        $sql = "SELECT q.case_id, q.application_id, q.client_id, q.status, q.assigned_user_id, q.claimed_at, q.completed_at,\n" .
               "       c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at\n" .
               "  FROM Vati_Payfiller_Validator_Queue q\n" .
               "  JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
               " WHERE (q.assigned_user_id = ? OR EXISTS (\n" .
               "        SELECT 1 FROM Vati_Payfiller_Workflow_Transitions a\n" .
               "         WHERE a.case_id = q.case_id\n" .
               "           AND a.actor_user_id = ?\n" .
               "           AND LOWER(TRIM(COALESCE(a.stage,''))) = ?\n" .
               "      ))";
        $params[] = $userId;
        $params[] = $userId;
        $params[] = strtolower(trim($stage1));

        if ($search !== '') {
            $sql .= " AND (c.application_id LIKE ? OR c.candidate_first_name LIKE ? OR c.candidate_last_name LIKE ? OR c.candidate_email LIKE ? OR c.candidate_mobile LIKE ?)";
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }

        $sql .= " ORDER BY COALESCE(q.completed_at, q.claimed_at, c.created_at) DESC LIMIT 500";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows = enrich_rows_with_application_status($pdo, $rows);
        $rows = annotate_visibility($rows);
        $rows = os_enrich_rows($pdo, $rows, 'validator');
        $rows = array_values(array_filter($rows, function ($r) {
            $it = (array)$r;
            if (wf_is_closed_hidden_status((string)($it['status'] ?? ''))) return false;
            return (int)($it['evaluated_visible'] ?? 0) === 1;
        }));
        $resp = ['status' => 1, 'message' => 'ok', 'data' => $rows];
        if ($debug) {
            $rej = 0;
            $active = 0;
            $unresolved = 0;
            $activeStatuses = [];
            $evaluatedStatuses = [];
            foreach ($rows as $r) if ((int)($r['rejected_visible'] ?? 0) === 1) $rej++;
            foreach ($rows as $r) {
                $s = strtolower(trim((string)($r['status'] ?? '')));
                if ((int)($r['is_active_work'] ?? 0) === 1) { $active++; $activeStatuses[$s] = true; }
                if ((int)($r['evaluated_visible'] ?? 0) === 1) $evaluatedStatuses[$s] = true;
                if (wf_is_operationally_active_status($s)) $unresolved++;
            }
            $resp['debug'] = [
                'helper_owner_file' => realpath(__DIR__ . '/../shared/workflow_stage_config.php') ?: (__DIR__ . '/../shared/workflow_stage_config.php'),
                'helper_loaded' => function_exists('wf_stage_keys'),
                'stage_keys_resolved' => function_exists('wf_stage_keys') ? wf_stage_keys() : [],
                'selected_view' => $view,
                'selected_visibility_mode' => 'evaluated',
                'generated_filters' => ['evaluated_visible_only' => true, 'exclude_closed_hidden' => true],
                'active_count' => $active,
                'evaluated_count' => count($rows),
                'unresolved_count' => $unresolved,
                'historical_visible_count' => count($rows),
                'active_statuses_detected' => array_values(array_keys($activeStatuses)),
                'evaluated_statuses_detected' => array_values(array_keys($evaluatedStatuses)),
                'projection_reason' => 'validator_cases_history_semantic_helpers',
                'visibility_classification' => 'active_vs_evaluated',
                'returned_row_count' => count($rows),
                'active_queue_count' => $active,
                'evaluated_visible_count' => count($rows),
                'rejected_visible_count' => $rej,
                'hidden_closed_count' => 0
            ];
        }
        echo json_encode($resp);
        exit;
    }

    // "mine": include active + evaluated history owned by validator.
    $stage1 = wf_stage_keys()[0] ?? 'validator';
    $params = [$userId, $userId, strtolower(trim($stage1))];
    $sql = "SELECT q.case_id, q.application_id, q.client_id, q.status, q.assigned_user_id, q.claimed_at, q.completed_at,\n" .
           "       c.candidate_first_name, c.candidate_last_name, c.candidate_email, c.candidate_mobile, c.case_status, c.created_at\n" .
           "  FROM Vati_Payfiller_Validator_Queue q\n" .
           "  JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
           " WHERE (q.assigned_user_id = ? OR EXISTS (\n" .
           "        SELECT 1 FROM Vati_Payfiller_Workflow_Transitions a\n" .
           "         WHERE a.case_id = q.case_id\n" .
           "           AND a.actor_user_id = ?\n" .
           "           AND LOWER(TRIM(COALESCE(a.stage,''))) = ?\n" .
           "      ))";
    if ($search !== '') {
        $sql .= " AND (q.application_id LIKE ? OR c.candidate_first_name LIKE ? OR c.candidate_last_name LIKE ? OR c.candidate_email LIKE ? OR c.candidate_mobile LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }
    $sql .= " ORDER BY CASE WHEN q.completed_at IS NULL THEN 0 ELSE 1 END, COALESCE(q.completed_at, q.claimed_at, c.created_at) DESC LIMIT 500";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rows = enrich_rows_with_application_status($pdo, $rows);
    $rows = array_values(array_filter($rows, function ($r) {
        $it = (array)$r;
        return !is_stop_bgv_case($it) && !is_candidate_pending_case($it);
    }));
    $rows = annotate_visibility($rows);
    $rows = os_enrich_rows($pdo, $rows, 'validator');
    $resp = ['status' => 1, 'message' => 'ok', 'data' => $rows];
    if ($debug) {
        $active = 0;
        $evaluated = 0;
        $rejected = 0;
        $unresolved = 0;
        $activeStatuses = [];
        $evaluatedStatuses = [];
        foreach ($rows as $r) {
            $s = strtolower(trim((string)($r['status'] ?? '')));
            if ((int)($r['is_active_work'] ?? 0) === 1) { $active++; $activeStatuses[$s] = true; }
            if ((int)($r['evaluated_visible'] ?? 0) === 1) { $evaluated++; $evaluatedStatuses[$s] = true; }
            if ((int)($r['rejected_visible'] ?? 0) === 1) $rejected++;
            if (wf_is_operationally_active_status($s)) $unresolved++;
        }
        $resp['debug'] = [
            'helper_owner_file' => realpath(__DIR__ . '/../shared/workflow_stage_config.php') ?: (__DIR__ . '/../shared/workflow_stage_config.php'),
            'helper_loaded' => function_exists('wf_stage_keys'),
            'stage_keys_resolved' => function_exists('wf_stage_keys') ? wf_stage_keys() : [],
            'selected_view' => $view,
            'selected_visibility_mode' => 'my_visibility',
            'generated_filters' => ['assigned_or_acted_by_validator' => true, 'exclude_stop_bgv' => true, 'exclude_candidate_pending_draft' => true],
            'active_count' => $active,
            'evaluated_count' => $evaluated,
            'unresolved_count' => $unresolved,
            'historical_visible_count' => $evaluated,
            'active_statuses_detected' => array_values(array_keys($activeStatuses)),
            'evaluated_statuses_detected' => array_values(array_keys($evaluatedStatuses)),
            'projection_reason' => 'validator_cases_mine_semantic_helpers',
            'visibility_classification' => 'active_vs_evaluated',
            'returned_row_count' => count($rows),
            'active_queue_count' => $active,
            'evaluated_visible_count' => $evaluated,
            'rejected_visible_count' => $rejected,
            'hidden_closed_count' => 0
        ];
    }
    echo json_encode($resp);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
