<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../shared/verifier_case_queue.php';

auth_require_login('verifier');
auth_session_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function vr_case_board_bucket(string $status, ?string $completedAt): string
{
    if (trim((string)$completedAt) !== '') return 'completed';
    $s = strtolower(trim($status));
    if ($s === 'followup' || $s === 'hold' || $s === 'reopened' || $s === 'blocked') return 'followup';
    if ($s === 'waiting_candidate' || $s === 'insufficient_documents') return 'insuff_docs';
    return 'pending';
}

function vr_case_board_row_state(string $bucket, int $assignedUserId, int $currentUserId, ?string $completedAt): string
{
    if (trim((string)$completedAt) !== '') return 'completed';
    if ($bucket === 'followup' && $assignedUserId === $currentUserId) return 'followup';
    if ($assignedUserId <= 0) return 'available';
    if ($assignedUserId === $currentUserId) return 'mine_active';
    return 'claimed_by_other';
}

function vr_case_board_state_from_routing(array $routingState, string $bucket): string
{
    if (!empty($routingState['owned_active_components'])) {
        return $bucket === 'followup' ? 'followup' : 'mine_active';
    }
    if (!empty($routingState['claimable_next_components'])) {
        return 'available';
    }
    if (!empty($routingState['completed_components'])) {
        return 'completed';
    }
    if (!empty($routingState['locked_future_components'])) {
        return 'locked_future';
    }
    return 'hidden_unrelated';
}

function vr_case_board_open_url(int $caseId, string $applicationId, int $clientId, string $bucket): string
{
    $params = [
        'case_id' => (string)$caseId,
        'application_id' => $applicationId,
        'client_id' => (string)$clientId,
        'board' => '1',
        'view' => $bucket === 'completed' ? 'participated' : ($bucket === 'followup' ? 'followup' : 'mine'),
        'filter' => $bucket === 'completed' ? 'review_complete' : ($bucket === 'insuff_docs' ? 'waiting_candidate' : 'active_work'),
    ];
    return app_url('/modules/verifier/candidate_view.php') . '?' . http_build_query($params);
}

function vr_case_board_summary_text(array $componentSummary): string
{
    $labels = [];
    foreach ($componentSummary as $item) {
        $label = trim((string)($item['label'] ?? ''));
        if ($label !== '') $labels[$label] = true;
    }
    return implode(', ', array_keys($labels));
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $pdo = getDB();
    $userId = (int)($_SESSION['auth_user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    verifier_case_queue_ensure_table($pdo);
    verifier_case_queue_clear_db_verifier_owners($pdo);

    // Ensure case queue rows exist for both new verifier-first cases and legacy cases
    // that still only have compatibility verifier-group rows.
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
        $vfRows = $vfCases ? ($vfCases->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($vfRows as $vfRow) {
            $cid = (int)($vfRow['case_id'] ?? 0);
            if ($cid > 0) {
                if (verifier_case_queue_is_case_model($pdo, $cid, '')) {
                    verifier_case_queue_ensure_row($pdo, $cid);
                    verifier_case_queue_sync($pdo, $cid);
                } else {
                    verifier_case_queue_sync_from_group_rows($pdo, $cid);
                }
            }
        }
    } catch (Throwable $e) {
    }

    $sql = "SELECT q.id, q.case_id, q.application_id, q.client_id,
                   COALESCE(LOWER(TRIM(q.status)), 'pending') AS status,
                   q.assigned_user_id, q.claimed_at, q.completed_at, q.workflow_mode,
                   c.candidate_first_name, c.candidate_last_name, c.case_status, c.created_at,
                   TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS assigned_user_name
              FROM Vati_Payfiller_Verifier_Case_Queue q
              JOIN Vati_Payfiller_Cases c ON c.case_id = q.case_id
         LEFT JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id
             WHERE UPPER(TRIM(COALESCE(c.case_status,''))) <> 'STOP_BGV'
          ORDER BY COALESCE(q.claimed_at, c.created_at) ASC, q.id ASC";
    $st = $pdo->query($sql);
    $queueRows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $rows = [];
    $bucketCounts = ['pending' => 0, 'followup' => 0, 'insuff_docs' => 0, 'completed' => 0];

    foreach ($queueRows as $row) {
        $caseId = (int)($row['case_id'] ?? 0);
        if ($caseId <= 0) continue;

        $bucket = vr_case_board_bucket((string)($row['status'] ?? ''), $row['completed_at'] ?? null);
        $assignedUserId = (int)($row['assigned_user_id'] ?? 0);

        if ($bucket === 'completed' && !verifier_case_queue_can_open($pdo, $caseId, $userId)) {
            continue;
        }

        $routingState = verifier_routing_case_state($pdo, $caseId, $userId);
        $state = vr_case_board_state_from_routing($routingState, $bucket);
        $componentSummary = verifier_case_queue_component_summary($pdo, $caseId);
        $matchingComponents = array_values(array_unique(array_merge(
            $routingState['owned_active_components'] ?? [],
            $routingState['claimable_next_components'] ?? [],
            $routingState['completed_components'] ?? [],
            $routingState['locked_future_components'] ?? []
        )));
        if ($state === 'hidden_unrelated' && !$matchingComponents) {
            continue;
        }
        $routingPriorityRank = verifier_routing_best_priority_for_case($pdo, $caseId, $userId);
        $stateKeys = [];
        foreach (($routingState['components'] ?? []) as $componentState) {
            $stateKey = strtolower(trim((string)($componentState['state'] ?? '')));
            if ($stateKey !== '' && $stateKey !== 'context' && $stateKey !== 'hidden_unrelated') {
                $stateKeys[$stateKey] = true;
            }
        }

        $bucketCounts[$bucket] = (int)($bucketCounts[$bucket] ?? 0) + 1;
        $rows[] = [
            'queue_row_id' => (int)($row['id'] ?? 0),
            'case_id' => $caseId,
            'application_id' => (string)($row['application_id'] ?? ''),
            'client_id' => (int)($row['client_id'] ?? 0),
            'candidate_first_name' => (string)($row['candidate_first_name'] ?? ''),
            'candidate_last_name' => (string)($row['candidate_last_name'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'board_bucket' => $bucket,
            'row_state' => $state,
            'assigned_user_id' => $assignedUserId,
            'assigned_user_name' => $assignedUserId === $userId ? 'You' : trim((string)($row['assigned_user_name'] ?? '')),
            'claimed_at' => $row['claimed_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'workflow_mode' => (string)($row['workflow_mode'] ?? 'validator_first'),
            'case_status' => (string)($row['case_status'] ?? ''),
            'state_keys' => array_values(array_keys($stateKeys)),
            'component_summary' => $componentSummary,
            'component_summary_text' => vr_case_board_summary_text($componentSummary),
            'matching_components' => $matchingComponents,
            'routing_component_states' => $routingState['components'] ?? [],
            'owned_active_components' => $routingState['owned_active_components'] ?? [],
            'claimable_next_components' => $routingState['claimable_next_components'] ?? [],
            'locked_future_components' => $routingState['locked_future_components'] ?? [],
            'completed_components' => $routingState['completed_components'] ?? [],
            'bucket_pending_by_priority' => $routingState['bucket_pending_by_priority'] ?? [],
            'routing_priority_rank' => $routingPriorityRank,
            'can_claim' => !empty($routingState['claimable_next_components']) ? 1 : 0,
            'can_open' => !empty($routingState['can_open']) ? 1 : 0,
            'open_url' => !empty($routingState['can_open'])
                ? vr_case_board_open_url($caseId, (string)($row['application_id'] ?? ''), (int)($row['client_id'] ?? 0), $bucket)
                : '',
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $bucketOrder = ['pending' => 1, 'followup' => 2, 'insuff_docs' => 3, 'completed' => 4];
        $ao = $bucketOrder[$a['board_bucket']] ?? 99;
        $bo = $bucketOrder[$b['board_bucket']] ?? 99;
        if ($ao !== $bo) return $ao <=> $bo;
        $ap = (int)($a['routing_priority_rank'] ?? 99);
        $bp = (int)($b['routing_priority_rank'] ?? 99);
        if ($ap !== $bp) return $ap <=> $bp;
        if ($a['board_bucket'] === 'completed') {
            return strcmp((string)($b['completed_at'] ?? ''), (string)($a['completed_at'] ?? ''));
        }
        return strcmp((string)($a['claimed_at'] ?: $a['created_at'] ?: ''), (string)($b['claimed_at'] ?: $b['created_at'] ?: ''));
    });

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'bucket_counts' => $bucketCounts,
            'rows' => $rows,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
