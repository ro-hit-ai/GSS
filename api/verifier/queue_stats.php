<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/queue_visibility.php';
require_once __DIR__ . '/../shared/workflow_status_semantics.php';
require_once __DIR__ . '/../shared/reference_component_compat.php';

auth_require_login('verifier');

auth_session_start();
$userId = (int)($_SESSION['auth_user_id'] ?? 0);
$clientId = 0;
$scope = strtolower(trim((string)($_GET['scope'] ?? 'all'))); // all|mine
$debug = (isset($_GET['debug']) && (string)$_GET['debug'] === '1');

function verifier_stats_group_components(string $groupKey): array
{
    $all = verifier_group_components($groupKey);
    $out = [];
    foreach ($all as $c) {
        $k = strtolower(trim((string)$c));
        if ($k !== '') $out[$k] = true;
    }
    return reference_compat_effective_keys(array_keys($out));
}

try {
    $pdo = getDB();

    $rowStmt = $pdo->prepare(
        'SELECT id, case_id, application_id, client_id, group_key, status, assigned_user_id, completed_at '
        . 'FROM Vati_Payfiller_Verifier_Group_Queue '
        . 'WHERE (? = 0 OR client_id = ?)'
    );
    $rowStmt->execute([$clientId, $clientId]);
    $queueRows = $rowStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $allowedSet = verifier_allowed_sections_set_from_session($pdo);
    $queueRows = verifier_filter_actionable_queue_rows($pdo, $queueRows, $allowedSet);

    $rowsByGroup = [];
    foreach ($queueRows as $r) {
        $g = strtoupper(trim((string)($r['group_key'] ?? '')));
        if ($g === '') continue;
        if (!isset($rowsByGroup[$g])) {
            $rowsByGroup[$g] = [
                'group_key' => $g,
                'pending' => 0,
                'followup' => 0,
                'in_progress' => 0,
                'awaiting_evaluation' => 0,
                'evaluated' => 0,
                'active_unresolved' => 0,
                'waiting_candidate' => 0,
                'reopened' => 0,
                'active_holds' => 0,
                'completed_total' => 0,
                'completed_today' => 0
            ];
        }

        $assigned = isset($r['assigned_user_id']) ? (int)$r['assigned_user_id'] : 0;
        $status = strtolower(trim((string)($r['status'] ?? '')));
        $completedAt = trim((string)($r['completed_at'] ?? ''));

        $isEvaluated = wf_is_evaluated_status($status) || in_array($status, ['done', 'completed'], true);
        $isActive = wf_is_active_queue_status($status) && $completedAt === '';
        $isOperationallyActive = wf_is_operationally_active_status($status);

        if ($completedAt !== '' && $assigned === $userId) {
            $rowsByGroup[$g]['completed_total']++;
            if (substr($completedAt, 0, 10) === date('Y-m-d')) {
                $rowsByGroup[$g]['completed_today']++;
            }
        }

        if ($isEvaluated && $assigned === $userId) {
            $rowsByGroup[$g]['evaluated']++;
        }

        if (!$isActive) {
            continue;
        }

        // "mine" should include:
        // - unassigned pending rows (actionable for this verifier)
        // - rows assigned to current verifier
        // and exclude rows assigned to other verifiers.
        if ($scope === 'mine' && $assigned !== 0 && $assigned !== $userId) {
            continue;
        }

        if ($assigned === 0) {
            $rowsByGroup[$g]['pending']++;
            $rowsByGroup[$g]['awaiting_evaluation']++;
            continue;
        }
        if ($assigned === $userId && $status === 'followup') {
            $rowsByGroup[$g]['followup']++;
            continue;
        }
        if ($assigned === $userId) {
            $rowsByGroup[$g]['in_progress']++;
            if ($isOperationallyActive) {
                $rowsByGroup[$g]['active_unresolved']++;
            }
            if ($status === 'waiting_candidate') $rowsByGroup[$g]['waiting_candidate']++;
            if ($status === 'reopened') $rowsByGroup[$g]['reopened']++;
            if ($status === 'hold') $rowsByGroup[$g]['active_holds']++;
        }
    }

    $rows = array_values($rowsByGroup);

    $corrOpen = 0;
    $corrStale = 0;
    $corrRepeated = 0;
    try {
        $q = $pdo->prepare("SELECT SUM(CASE WHEN status IN ('active','submitted') THEN 1 ELSE 0 END) AS open_cnt,
            SUM(CASE WHEN status IN ('active','submitted') AND created_at < (NOW() - INTERVAL 48 HOUR) THEN 1 ELSE 0 END) AS stale_cnt
            FROM Vati_Payfiller_Candidate_Correction_Sessions
            WHERE case_id IN (SELECT case_id FROM Vati_Payfiller_Verifier_Group_Queue WHERE assigned_user_id = ?)");
        $q->execute([$userId]);
        $rw = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $corrOpen = (int)($rw['open_cnt'] ?? 0);
        $corrStale = (int)($rw['stale_cnt'] ?? 0);
        $q2 = $pdo->prepare("SELECT COUNT(*) FROM (
            SELECT case_id, component_key, COUNT(*) c
            FROM Vati_Payfiller_Component_Correction_Cycles
            WHERE case_id IN (SELECT case_id FROM Vati_Payfiller_Verifier_Group_Queue WHERE assigned_user_id = ?)
            GROUP BY case_id, component_key HAVING COUNT(*) > 1
        ) t");
        $q2->execute([$userId]);
        $corrRepeated = (int)($q2->fetchColumn() ?: 0);
    } catch (Throwable $e) {}

    // Historical participation metrics (transition-audit truth) for dashboard continuity.
    $participated = [
        'cases_total' => 0,
        'reviewed_today' => 0,
        'rejected_total' => 0,
        'hold_total' => 0,
        'insufficient_documents_total' => 0,
    ];
    $governance = [
        'reopen_actions_total' => 0,
        'supervisory_reopens_total' => 0,
        'invalidations_caused_total' => 0,
        'active_invalidated_downstream' => 0,
        'stuck_reopened_components' => 0,
    ];
    try {
        $allowedGroups = verifier_allowed_groups_from_sections($allowedSet);
        $components = [];
        foreach ($allowedGroups as $gk) {
            foreach (verifier_stats_group_components($gk) as $ck) $components[$ck] = true;
        }
        $components = reference_compat_effective_keys(array_keys($components));
        if ($components) {
            $ph = implode(',', array_fill(0, count($components), '?'));
            $sqlP = "SELECT
                        COUNT(DISTINCT t.case_id) AS cases_total,
                        COUNT(DISTINCT CASE WHEN DATE(t.created_at) = CURRENT_DATE() THEN t.case_id END) AS reviewed_today,
                        SUM(CASE WHEN LOWER(TRIM(COALESCE(t.to_status,''))) = 'rejected' THEN 1 ELSE 0 END) AS rejected_total,
                        SUM(CASE WHEN LOWER(TRIM(COALESCE(t.to_status,''))) = 'hold' THEN 1 ELSE 0 END) AS hold_total,
                        SUM(CASE WHEN LOWER(TRIM(COALESCE(t.to_status,''))) = 'insufficient_documents' THEN 1 ELSE 0 END) AS insufficient_documents_total
                     FROM Vati_Payfiller_Workflow_Transitions t
                     JOIN Vati_Payfiller_Cases c ON c.case_id = t.case_id AND c.application_id = t.application_id
                     WHERE t.actor_user_id = ?
                       AND LOWER(TRIM(COALESCE(t.actor_role,''))) IN ('verifier','db_verifier','component verifier','component_verifier')
                       AND LOWER(TRIM(COALESCE(t.component_key,''))) IN ($ph)
                       AND (? = 0 OR c.client_id = ?)";
            $stP = $pdo->prepare($sqlP);
            $params = [$userId];
            $params = array_merge($params, $components);
            $params[] = $clientId;
            $params[] = $clientId;
            $stP->execute($params);
            $pr = $stP->fetch(PDO::FETCH_ASSOC) ?: [];
            $participated['cases_total'] = (int)($pr['cases_total'] ?? 0);
            $participated['reviewed_today'] = (int)($pr['reviewed_today'] ?? 0);
            $participated['rejected_total'] = (int)($pr['rejected_total'] ?? 0);
            $participated['hold_total'] = (int)($pr['hold_total'] ?? 0);
            $participated['insufficient_documents_total'] = (int)($pr['insufficient_documents_total'] ?? 0);

            $sqlGov = "SELECT
                        SUM(CASE WHEN LOWER(TRIM(COALESCE(t.action,''))) = 'reopen' THEN 1 ELSE 0 END) AS reopen_actions_total,
                        SUM(CASE WHEN LOWER(TRIM(COALESCE(t.action,''))) = 'reopen'
                                  AND LOWER(TRIM(COALESCE(t.stage,''))) = 'validator' THEN 1 ELSE 0 END) AS supervisory_reopens_total,
                        SUM(CASE WHEN LOWER(TRIM(COALESCE(t.action,''))) = 'invalidate_due_to_reopen' THEN 1 ELSE 0 END) AS invalidations_caused_total
                     FROM Vati_Payfiller_Workflow_Transitions t
                     JOIN Vati_Payfiller_Cases c ON c.case_id = t.case_id AND c.application_id = t.application_id
                     WHERE t.actor_user_id = ?
                       AND LOWER(TRIM(COALESCE(t.actor_role,''))) IN ('verifier','db_verifier','component verifier','component_verifier')
                       AND LOWER(TRIM(COALESCE(t.component_key,''))) IN ($ph)
                       AND (? = 0 OR c.client_id = ?)";
            $stGov = $pdo->prepare($sqlGov);
            $paramsGov = [$userId];
            $paramsGov = array_merge($paramsGov, $components);
            $paramsGov[] = $clientId;
            $paramsGov[] = $clientId;
            $stGov->execute($paramsGov);
            $gv = $stGov->fetch(PDO::FETCH_ASSOC) ?: [];
            $governance['reopen_actions_total'] = (int)($gv['reopen_actions_total'] ?? 0);
            $governance['supervisory_reopens_total'] = (int)($gv['supervisory_reopens_total'] ?? 0);
            $governance['invalidations_caused_total'] = (int)($gv['invalidations_caused_total'] ?? 0);

            $sqlActiveGov = "SELECT
                                SUM(CASE WHEN LOWER(TRIM(COALESCE(w.status,''))) = 'invalidated_by_verifier_reopen' AND LOWER(TRIM(COALESCE(w.stage,''))) = 'qa' THEN 1 ELSE 0 END) AS active_invalidated_downstream,
                                SUM(CASE WHEN LOWER(TRIM(COALESCE(w.status,''))) = 'reopened' AND LOWER(TRIM(COALESCE(w.stage,''))) = 'verifier' THEN 1 ELSE 0 END) AS stuck_reopened_components
                             FROM Vati_Payfiller_Case_Component_Workflow w
                             JOIN Vati_Payfiller_Cases c ON c.case_id = w.case_id AND c.application_id = w.application_id
                             WHERE LOWER(TRIM(COALESCE(w.component_key,''))) IN ($ph)
                               AND (? = 0 OR c.client_id = ?)";
            $stActiveGov = $pdo->prepare($sqlActiveGov);
            $paramsActiveGov = $components;
            $paramsActiveGov[] = $clientId;
            $paramsActiveGov[] = $clientId;
            $stActiveGov->execute($paramsActiveGov);
            $ag = $stActiveGov->fetch(PDO::FETCH_ASSOC) ?: [];
            $governance['active_invalidated_downstream'] = (int)($ag['active_invalidated_downstream'] ?? 0);
            $governance['stuck_reopened_components'] = (int)($ag['stuck_reopened_components'] ?? 0);
        }
    } catch (Throwable $e) {
    }

    $resp = ['status' => 1, 'message' => 'ok', 'data' => $rows, 'kpi' => [
        'correction_requested' => (int)$corrOpen,
        'repeated_corrections' => (int)$corrRepeated,
        'stale_corrections' => (int)$corrStale,
        'participated_cases_total' => (int)$participated['cases_total'],
        'participated_reviewed_today' => (int)$participated['reviewed_today'],
        'participated_rejected_total' => (int)$participated['rejected_total'],
        'participated_hold_total' => (int)$participated['hold_total'],
        'participated_need_docs_total' => (int)$participated['insufficient_documents_total'],
        'reopen_actions_total' => (int)$governance['reopen_actions_total'],
        'supervisory_reopens_total' => (int)$governance['supervisory_reopens_total'],
        'invalidations_caused_total' => (int)$governance['invalidations_caused_total'],
        'active_invalidated_downstream' => (int)$governance['active_invalidated_downstream'],
        'stuck_reopened_components' => (int)$governance['stuck_reopened_components']
    ]];
    if ($debug) {
        $activeCount = 0;
        $evaluatedCount = 0;
        $unresolvedCount = 0;
        $activeStatuses = [];
        $evaluatedStatuses = [];
        foreach ($queueRows as $r) {
            $s = strtolower(trim((string)($r['status'] ?? '')));
            $completedAt = trim((string)($r['completed_at'] ?? ''));
            if ($completedAt === '' && wf_is_active_queue_status($s)) {
                $activeCount++;
                $activeStatuses[$s] = true;
            }
            if (wf_is_evaluated_status($s) || in_array($s, ['done', 'completed'], true)) {
                $evaluatedCount++;
                $evaluatedStatuses[$s] = true;
            }
            if (wf_is_operationally_active_status($s)) {
                $unresolvedCount++;
            }
        }
        $resp['debug'] = [
            'active_count' => $activeCount,
            'evaluated_count' => $evaluatedCount,
            'unresolved_count' => $unresolvedCount,
            'historical_visible_count' => $evaluatedCount,
            'active_statuses_detected' => array_values(array_keys($activeStatuses)),
            'evaluated_statuses_detected' => array_values(array_keys($evaluatedStatuses)),
            'projection_reason' => 'verifier_queue_stats_semantic_helpers',
            'visibility_classification' => 'active_vs_evaluated',
            'participation_source' => 'Vati_Payfiller_Workflow_Transitions',
            'participated_metrics' => $participated
        ];
    }
    if (getenv('WF_STATUS_DEBUG_LOGS') === '1') {
        @file_put_contents(__DIR__ . '/../../logs/workflow_transition.log', json_encode([
            'ts' => date('c'),
            'event' => 'verifier_dashboard_visibility_stats',
            'verifier_user_id' => $userId,
            'scope' => $scope,
            'active_rows_count' => count($queueRows),
            'group_rows_count' => count($rows),
            'participated_metrics' => $participated,
            'governance_metrics' => $governance,
            'queue_source' => 'Vati_Payfiller_Verifier_Group_Queue',
            'participation_source' => 'Vati_Payfiller_Workflow_Transitions',
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }
    echo json_encode($resp);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
