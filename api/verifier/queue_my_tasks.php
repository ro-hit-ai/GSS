<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/queue_visibility.php';
require_once __DIR__ . '/../shared/workflow_status_semantics.php';
require_once __DIR__ . '/../shared/operational_status_governance.php';

auth_require_login('verifier');

auth_session_start();
$userId = (int)($_SESSION['auth_user_id'] ?? 0);
$clientId = 0;

function vr_task_row_key(array $r): string {
    return (string)($r['id'] ?? '') . '|' . (string)($r['case_id'] ?? '') . '|' . strtoupper(trim((string)($r['group_key'] ?? '')));
}

function vr_reason_counts(array $removedRows): array {
    $counts = [];
    foreach ($removedRows as $row) {
        $reasons = $row['removed_reason'] ?? [];
        if (!is_array($reasons)) {
            $reasons = [(string)$reasons];
        }
        foreach ($reasons as $reason) {
            $rk = strtolower(trim((string)$reason));
            if ($rk === '') continue;
            $counts[$rk] = (int)($counts[$rk] ?? 0) + 1;
        }
    }
    ksort($counts);
    return $counts;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListMine(?, ?, ?, ?)');
    $stmt->execute([$userId, $clientId > 0 ? $clientId : null, null, null]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    // Include unassigned actionable cases so verifier sees candidates immediately
    // before explicit Start Verify claim.
    $avail = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListAvailable(?, ?, ?, ?)');
    $avail->execute([$userId, $clientId > 0 ? $clientId : null, null, null]);
    $availRows = $avail->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($avail->nextRowset()) {
    }
    if ($availRows) {
        $seen = [];
        foreach ($rows as $r) {
            $key = (string)($r['id'] ?? '') . '|' . (string)($r['case_id'] ?? '') . '|' . strtoupper(trim((string)($r['group_key'] ?? '')));
            $seen[$key] = true;
        }
        foreach ($availRows as $r) {
            $key = (string)($r['id'] ?? '') . '|' . (string)($r['case_id'] ?? '') . '|' . strtoupper(trim((string)($r['group_key'] ?? '')));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $rows[] = $r;
            }
        }
    }

    $allowedSet = verifier_allowed_sections_set_from_session($pdo);
    $rawRows = $rows;
    $rawCount = count($rawRows);
    $rows = verifier_filter_actionable_queue_rows($pdo, $rawRows, $allowedSet);
    $filteredCount = count($rows);

    // Active dashboard surface authority: open + active actionable queue only.
    $rowsBeforeActive = $rows;
    $rows = array_values(array_filter($rows, static function ($r): bool {
        $s = strtolower(trim((string)($r['status'] ?? '')));
        $completedAt = trim((string)($r['completed_at'] ?? ''));
        return $completedAt === '' && wf_is_active_queue_status($s);
    }));
    $activeCount = count($rows);

    $removedRows = [];
    $isDebug = ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1');
    $reasonCounts = [];
    if ($isDebug) {
        $postFilterKeys = [];
        foreach ($rowsBeforeActive as $r) {
            $postFilterKeys[vr_task_row_key($r)] = $r;
        }
        $activeKeys = [];
        foreach ($rows as $r) {
            $activeKeys[vr_task_row_key($r)] = true;
        }

        foreach ($rawRows as $r) {
            $key = vr_task_row_key($r);
            $groupKey = strtoupper(trim((string)($r['group_key'] ?? '')));
            $status = strtolower(trim((string)($r['status'] ?? '')));
            $completedAt = trim((string)($r['completed_at'] ?? ''));
            $assigned = (int)($r['assigned_user_id'] ?? 0);
            $groupAllowed = verifier_can_group_by_sections($allowedSet, $groupKey);
            $candidateComponents = [];
            foreach (verifier_group_components($groupKey) as $k) {
                if (isset($allowedSet['*']) || isset($allowedSet[$k])) $candidateComponents[] = $k;
            }
            $activeDecision = ($completedAt === '' && wf_is_active_queue_status($status));

            $removed = !isset($activeKeys[$key]);
            if (!$removed) continue;

            $reason = [];
            if (!isset($postFilterKeys[$key])) {
                if (!$groupAllowed) $reason[] = 'group_not_allowed_by_allowed_sections';
                if (!$candidateComponents) $reason[] = 'no_allowed_components_in_group';
                if ($reason === []) $reason[] = 'filtered_by_verifier_actionable_filter';
            } else {
                if ($completedAt !== '') $reason[] = 'queue_closed_completed_at_set';
                if (!wf_is_active_queue_status($status)) $reason[] = 'non_active_queue_status';
                if (!$activeDecision && $reason === []) $reason[] = 'not_active_actionable';
            }

            $removedRows[] = [
                'case_id' => (int)($r['case_id'] ?? 0),
                'application_id' => (string)($r['application_id'] ?? ''),
                'group_key' => $groupKey,
                'status' => $status,
                'completed_at' => $completedAt,
                'assigned_user_id' => $assigned,
                'allowed_sections_group_allowed' => $groupAllowed ? 1 : 0,
                'allowed_components_in_group' => $candidateComponents,
                'active_actionable_decision' => $activeDecision ? 1 : 0,
                'removed_reason' => $reason,
            ];
        }
        $reasonCounts = vr_reason_counts($removedRows);
    }

    $rows = os_enrich_rows($pdo, $rows, 'verifier');

    if (count($rows) > 10) {
        $rows = array_slice($rows, 0, 10);
    }

    if ($isDebug) {
        @file_put_contents(__DIR__ . '/../../logs/workflow_transition.log', json_encode([
            'ts' => date('c'),
            'event' => 'verifier_dashboard_my_tasks_visibility',
            'verifier_user_id' => $userId,
            'source' => 'queue_my_tasks',
            'queue_truth' => 'active_actionable_only',
            'rows_before_filter' => $rawCount,
            'rows_after_actionable_filter' => $filteredCount,
            'rows_after_active_contract' => $activeCount,
            'rows_after_limit' => count($rows),
            'removed_rows' => $removedRows,
            'removed_reason_counts' => $reasonCounts,
            'empty_state_reason' => (count($rows) === 0 ? 'no_open_active_queue_rows_or_filtered_by_allowed_sections' : ''),
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }

    $response = ['status' => 1, 'message' => 'ok', 'data' => $rows];
    if ($isDebug) {
        $response['debug'] = [
            'surface' => 'active_queue_only',
            'rows_before_filter' => $rawCount,
            'rows_after_actionable_filter' => $filteredCount,
            'rows_after_active_contract' => $activeCount,
            'rows_after_limit' => count($rows),
            'removed_reason_counts' => $reasonCounts,
            'empty_state_reason' => (count($rows) === 0 ? 'no_open_active_queue_rows_or_filtered_by_allowed_sections' : ''),
        ];
    }

    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
