<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../shared/workflow/workflow_status_semantics.php';

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

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $userId = (int)($_SESSION['auth_user_id'] ?? 0);
    $debug = (isset($_GET['debug']) && (string)$_GET['debug'] === '1');
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $pdo = getDB();

    // Queue completion is projection-owned (WorkflowProjectionService). Do not auto-close here.

    // Keep KPI filters consistent with queue list behavior:
    // - include actionable unassigned rows + rows assigned to current user
    // - hide STOP_BGV and candidate-not-submitted rows
    $stmt = $pdo->prepare(
        "SELECT q.case_id, q.application_id, q.status, q.assigned_user_id, q.completed_at, c.case_status\n" .
        "FROM Vati_Payfiller_Validator_Queue q\n" .
        "JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id\n" .
        "WHERE (COALESCE(q.assigned_user_id,0) = 0 OR q.assigned_user_id = ?)"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rows = enrich_rows_with_application_status($pdo, $rows);
    $rows = array_values(array_filter($rows, function ($r) {
        $it = (array)$r;
        return !is_stop_bgv_case($it) && !is_candidate_pending_case($it);
    }));

    $pending = 0;
    $inProgress = 0;
    $completedToday = 0;
    $awaitingEvaluation = 0;
    $evaluated = 0;
    $reviewedComplete = 0;
    $downstreamProcessing = 0;
    $activeUnresolved = 0;
    $waitingCandidate = 0;
    $reopened = 0;
    $activeHolds = 0;
    $today = date('Y-m-d');
    foreach ($rows as $r) {
        $status = strtolower(trim((string)($r['status'] ?? 'pending')));
        $completedAt = trim((string)($r['completed_at'] ?? ''));
        if ($status === 'pending') $awaitingEvaluation++;
        if (wf_is_evaluated_status($status) || in_array($status, ['done', 'completed'], true)) $evaluated++;
        if (wf_is_resolved_status($status) || in_array($status, ['done', 'completed'], true)) $reviewedComplete++;
        if (wf_is_evaluated_status($status) && !wf_is_operationally_active_status($status)) $downstreamProcessing++;
        if (in_array($status, ['blocked', 'hold', 'insufficient_documents', 'waiting_candidate', 'reopened'], true)) $activeUnresolved++;
        if ($status === 'waiting_candidate') $waitingCandidate++;
        if ($status === 'reopened') $reopened++;
        if ($status === 'hold') $activeHolds++;
        if ($completedAt !== '') {
            if (substr($completedAt, 0, 10) === $today && ($status === 'done' || $status === 'completed')) {
                $completedToday++;
            }
            continue;
        }
        if ($status === 'pending' || $status === 'waiting_candidate') $pending++;
        if (in_array($status, ['in_progress', 'blocked', 'hold', 'insufficient_documents', 'pending', 'waiting_candidate'], true)) $inProgress++;
    }

    $correctionRequested = 0;
    $staleCorrections = 0;
    $repeatedCorrections = 0;
    $governance = [
        'reopen_actions_total' => 0,
        'invalidations_caused_total' => 0,
        'active_invalidated_downstream' => 0,
        'stuck_reopened_components' => 0,
    ];
    try {
        $q = $pdo->prepare("SELECT COUNT(*) AS total,
            SUM(CASE WHEN status IN ('active','submitted') THEN 1 ELSE 0 END) AS open_cnt,
            SUM(CASE WHEN status IN ('active','submitted') AND created_at < (NOW() - INTERVAL 48 HOUR) THEN 1 ELSE 0 END) AS stale_cnt
            FROM Vati_Payfiller_Candidate_Correction_Sessions WHERE case_id IN (SELECT case_id FROM Vati_Payfiller_Validator_Queue WHERE COALESCE(assigned_user_id,0)=0 OR assigned_user_id=?)");
        $q->execute([$userId]);
        $rw = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $correctionRequested = (int)($rw['open_cnt'] ?? 0);
        $staleCorrections = (int)($rw['stale_cnt'] ?? 0);
        $q2 = $pdo->prepare("SELECT COUNT(*) FROM (
                SELECT case_id, component_key, COUNT(*) c
                FROM Vati_Payfiller_Component_Correction_Cycles
                WHERE case_id IN (SELECT case_id FROM Vati_Payfiller_Validator_Queue WHERE COALESCE(assigned_user_id,0)=0 OR assigned_user_id=?)
                GROUP BY case_id, component_key
                HAVING COUNT(*) > 1
            ) t");
        $q2->execute([$userId]);
        $repeatedCorrections = (int)($q2->fetchColumn() ?: 0);
    } catch (Throwable $e) {}

    try {
        $gov = $pdo->prepare("SELECT
                SUM(CASE WHEN LOWER(TRIM(COALESCE(t.action,''))) = 'reopen' THEN 1 ELSE 0 END) AS reopen_actions_total,
                SUM(CASE WHEN LOWER(TRIM(COALESCE(t.action,''))) = 'invalidate_due_to_reopen' THEN 1 ELSE 0 END) AS invalidations_caused_total
            FROM Vati_Payfiller_Workflow_Transitions t
            WHERE t.actor_user_id = ?
              AND LOWER(TRIM(COALESCE(t.actor_role,''))) IN ('validator')");
        $gov->execute([$userId]);
        $gr = $gov->fetch(PDO::FETCH_ASSOC) ?: [];
        $governance['reopen_actions_total'] = (int)($gr['reopen_actions_total'] ?? 0);
        $governance['invalidations_caused_total'] = (int)($gr['invalidations_caused_total'] ?? 0);

        $gov2 = $pdo->prepare("SELECT
                SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) = 'invalidated_by_validator_reopen' AND LOWER(TRIM(COALESCE(stage,''))) = 'verifier' THEN 1 ELSE 0 END) AS active_invalidated_downstream,
                SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) = 'reopened' AND LOWER(TRIM(COALESCE(stage,''))) = 'validator' THEN 1 ELSE 0 END) AS stuck_reopened_components
            FROM Vati_Payfiller_Case_Component_Workflow");
        $gov2->execute();
        $gr2 = $gov2->fetch(PDO::FETCH_ASSOC) ?: [];
        $governance['active_invalidated_downstream'] = (int)($gr2['active_invalidated_downstream'] ?? 0);
        $governance['stuck_reopened_components'] = (int)($gr2['stuck_reopened_components'] ?? 0);
    } catch (Throwable $e) {}

    $payload = [
        'pending' => (int)$pending,
        'in_progress' => (int)$inProgress,
        'completed_today' => (int)$completedToday,
        'awaiting_evaluation' => (int)$awaitingEvaluation,
        'evaluated' => (int)$evaluated,
        'active_unresolved' => (int)$activeUnresolved,
        'waiting_candidate' => (int)$waitingCandidate,
        'reopened' => (int)$reopened,
        'active_holds' => (int)$activeHolds,
        'reviewed_complete' => (int)$reviewedComplete,
        'downstream_processing' => (int)$downstreamProcessing,
        'correction_requested' => (int)$correctionRequested,
        'repeated_corrections' => (int)$repeatedCorrections,
        'stale_corrections' => (int)$staleCorrections,
        'reopen_actions_total' => (int)$governance['reopen_actions_total'],
        'invalidations_caused_total' => (int)$governance['invalidations_caused_total'],
        'active_invalidated_downstream' => (int)$governance['active_invalidated_downstream'],
        'stuck_reopened_components' => (int)$governance['stuck_reopened_components']
    ];
    if ($debug) {
        $rejVisible = 0;
        $hiddenClosed = 0;
        $activeStatuses = [];
        $evaluatedStatuses = [];
        $unresolvedCount = 0;
        foreach ($rows as $r) {
            $s = strtolower(trim((string)($r['status'] ?? '')));
            if ($s === 'rejected') $rejVisible++;
            if (wf_is_closed_hidden_status($s)) $hiddenClosed++;
            if (wf_is_active_queue_status($s)) $activeStatuses[$s] = true;
            if (wf_is_evaluated_status($s) || in_array($s, ['done', 'completed'], true)) $evaluatedStatuses[$s] = true;
            if (wf_is_operationally_active_status($s)) $unresolvedCount++;
        }
        $payload['debug'] = [
            'active_count' => (int)$inProgress,
            'evaluated_count' => (int)$evaluated,
            'unresolved_count' => (int)$unresolvedCount,
            'historical_visible_count' => (int)$evaluated,
            'active_statuses_detected' => array_values(array_keys($activeStatuses)),
            'evaluated_statuses_detected' => array_values(array_keys($evaluatedStatuses)),
            'projection_reason' => 'validator_queue_stats_semantic_helpers',
            'visibility_classification' => 'active_vs_evaluated',
            'active_queue_count' => (int)$inProgress,
            'evaluated_visible_count' => (int)$evaluated,
            'rejected_visible_count' => (int)$rejVisible,
            'hidden_closed_count' => (int)$hiddenClosed
        ];
    }
    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $payload]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
