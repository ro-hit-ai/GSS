<?php

require_once __DIR__ . '/../workflow/workflow_status_semantics.php';

function os_debug_log(string $event, array $data = []): void
{
    if ((string)getenv('WF_STATUS_DEBUG_LOGS') !== '1') return;
    $entry = ['ts' => date('c'), 'event' => $event, 'data' => $data];
    @file_put_contents(__DIR__ . '/../../logs/workflow_operational_status.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

function os_norm(string $v): string
{
    return strtolower(trim($v));
}

function os_role_prefix(string $role): string
{
    $r = os_norm($role);
    if ($r === 'verifier' || $r === 'db_verifier') return 'VE';
    if ($r === 'qa' || $r === 'team_lead') return 'QA';
    return 'VA';
}

function os_is_rejected(string $status): bool
{
    $s = os_norm($status);
    return in_array($s, ['rejected', 'stop_bgv'], true);
}

function os_is_hold(string $status): bool
{
    return os_norm($status) === 'hold';
}

function os_is_need_docs(string $status): bool
{
    return os_norm($status) === 'insufficient_documents';
}

function os_is_candidate_pending(string $status): bool
{
    $s = os_norm($status);
    return in_array($s, ['waiting_candidate', 'pending_candidate', 'candidate_pending', 'reopened'], true);
}

function os_is_completed(string $status): bool
{
    $s = os_norm($status);
    return in_array($s, ['approved', 'completed', 'done', 'verified', 'clear'], true);
}

function os_is_final_case_completed(string $status): bool
{
    $s = os_norm($status);
    return in_array($s, ['approved', 'completed', 'verified', 'clear'], true);
}

function os_stage_pending_label(string $role): string
{
    return os_role_prefix($role) . ' PENDING';
}

function os_stage_resolved_label(string $role): string
{
    $r = os_norm($role);
    if ($r === 'validator') return 'VE PENDING';
    if ($r === 'verifier' || $r === 'db_verifier') return 'QA PENDING';
    return 'QA COMPLETED';
}

function os_resolve_stage_status(array $ctx): array
{
    $role = (string)($ctx['role'] ?? 'validator');
    $r = os_norm($role);
    $caseStatus = os_norm((string)($ctx['case_status'] ?? ''));
    $unresolvedQueueRows = max(0, (int)($ctx['unresolved_queue_rows'] ?? 0));
    $resolvedQueueRows = max(0, (int)($ctx['resolved_queue_rows'] ?? 0));
    $aggPendingLike = max(0, (int)($ctx['agg_pending_like'] ?? 0));
    $aggTotal = max(0, (int)($ctx['agg_total'] ?? 0));

    if (os_is_rejected($caseStatus)) {
        return ['code' => 'qa_rejected', 'label' => 'QA REJECTED', 'rule' => 'stage_case_rejected_final'];
    }

    $hasTrackedStageWork = ($aggTotal > 0) || ($resolvedQueueRows > 0) || ($unresolvedQueueRows > 0);
    $hasUnresolvedStageWork = ($aggPendingLike > 0) || ($unresolvedQueueRows > 0);

    if ($r === 'qa' || $r === 'team_lead') {
        if (os_is_final_case_completed($caseStatus)) {
            return ['code' => 'qa_completed', 'label' => 'QA COMPLETED', 'rule' => 'stage_case_completed_final'];
        }
        if ($hasTrackedStageWork && !$hasUnresolvedStageWork) {
            return ['code' => 'qa_completed', 'label' => 'QA COMPLETED', 'rule' => 'stage_qa_all_components_handled'];
        }
        return ['code' => 'qa_pending', 'label' => 'QA PENDING', 'rule' => 'stage_qa_pending_default'];
    }

    if ($r === 'validator' && $caseStatus === 'pending_verifier') {
        return ['code' => 've_pending', 'label' => 'VE PENDING', 'rule' => 'stage_case_forwarded_to_verifier'];
    }
    if (($r === 'verifier' || $r === 'db_verifier') && $caseStatus === 'pending_qa') {
        return ['code' => 'qa_pending', 'label' => 'QA PENDING', 'rule' => 'stage_case_forwarded_to_qa'];
    }

    if ($hasTrackedStageWork && !$hasUnresolvedStageWork) {
        $label = os_stage_resolved_label($role);
        return [
            'code' => strtolower(str_replace(' ', '_', $label)),
            'label' => $label,
            'rule' => 'stage_all_components_handled_forward_next_stage'
        ];
    }

    $label = os_stage_pending_label($role);
    return [
        'code' => strtolower(str_replace(' ', '_', $label)),
        'label' => $label,
        'rule' => 'stage_current_pending_default'
    ];
}

function os_resolve_operational_status(array $ctx): array
{
    $role = (string)($ctx['role'] ?? 'validator');
    $prefix = os_role_prefix($role);

    $workflowStatus = os_norm((string)($ctx['workflow_status'] ?? ''));
    $queueStatus = os_norm((string)($ctx['queue_status'] ?? ''));
    $caseStatus = os_norm((string)($ctx['case_status'] ?? ''));
    $queueCompletedAt = trim((string)($ctx['queue_completed_at'] ?? ''));
    $queueCompleted = !empty($ctx['queue_completed']) || ($queueCompletedAt !== '');
    $unresolvedQueueRows = max(0, (int)($ctx['unresolved_queue_rows'] ?? 0));
    $resolvedQueueRows = max(0, (int)($ctx['resolved_queue_rows'] ?? 0));
    $aggRejected = max(0, (int)($ctx['agg_rejected'] ?? 0));
    $aggHold = max(0, (int)($ctx['agg_hold'] ?? 0));
    $aggNeedDocs = max(0, (int)($ctx['agg_insufficient_documents'] ?? 0));
    $aggWaitingCandidate = max(0, (int)($ctx['agg_waiting_candidate'] ?? 0));
    $aggPendingLike = max(0, (int)($ctx['agg_pending_like'] ?? 0));
    $aggApproved = max(0, (int)($ctx['agg_approved'] ?? 0));
    $aggTotal = max(0, (int)($ctx['agg_total'] ?? 0));
    $mailSent = !empty($ctx['mail_sent']);

    $statusPool = [$workflowStatus, $queueStatus, $caseStatus];

    // 1) REJECTED
    if ($aggRejected > 0) return ['code' => 'rejected', 'label' => $prefix . ' REJECTED', 'rule' => 'priority_rejected_aggregate'];
    foreach ($statusPool as $s) {
        if (os_is_rejected($s)) return ['code' => 'rejected', 'label' => $prefix . ' REJECTED', 'rule' => 'priority_rejected'];
    }
    // 2) HOLD
    if ($aggHold > 0) return ['code' => 'hold', 'label' => $prefix . ' HOLD', 'rule' => 'priority_hold_aggregate'];
    foreach ($statusPool as $s) {
        if (os_is_hold($s)) return ['code' => 'hold', 'label' => $prefix . ' HOLD', 'rule' => 'priority_hold'];
    }
    // 3) NEED DOCS
    if ($aggNeedDocs > 0) return ['code' => 'need_docs', 'label' => 'NEED DOCS', 'rule' => 'priority_need_docs_aggregate'];
    foreach ($statusPool as $s) {
        if (os_is_need_docs($s)) return ['code' => 'need_docs', 'label' => 'NEED DOCS', 'rule' => 'priority_need_docs'];
    }
    // 4) CANDIDATE PENDING
    if ($aggWaitingCandidate > 0) return ['code' => 'candidate_pending', 'label' => 'CANDIDATE PENDING', 'rule' => 'priority_candidate_pending_aggregate'];
    foreach ($statusPool as $s) {
        if (os_is_candidate_pending($s)) return ['code' => 'candidate_pending', 'label' => 'CANDIDATE PENDING', 'rule' => 'priority_candidate_pending'];
    }
    // 5) MAIL SENT
    if ($mailSent) {
        return ['code' => 'mail_sent', 'label' => 'MAIL SENT', 'rule' => 'priority_mail_sent'];
    }
    // 6) COMPLETED: only when truly resolved and no higher-priority states exist.
    $aggregateCompleted = ($aggTotal > 0 && $aggApproved > 0 && $aggPendingLike === 0
        && $aggRejected === 0 && $aggHold === 0 && $aggNeedDocs === 0 && $aggWaitingCandidate === 0);
    $queueResolved = ($queueCompleted || ($resolvedQueueRows > 0 && $unresolvedQueueRows === 0));
    if ($aggregateCompleted && $queueResolved) {
        return ['code' => 'completed', 'label' => $prefix . ' COMPLETED', 'rule' => 'priority_completed_aggregate_queue_resolved'];
    }
    foreach ($statusPool as $s) {
        if (os_is_completed($s) && $unresolvedQueueRows === 0) {
            return ['code' => 'completed', 'label' => $prefix . ' COMPLETED', 'rule' => 'priority_completed_status_pool_resolved_queue'];
        }
    }

    // 7) PENDING
    return ['code' => 'pending', 'label' => $prefix . ' PENDING', 'rule' => 'priority_pending_default'];
}

function os_stage_for_role(string $role): string
{
    $r = os_norm($role);
    if ($r === 'verifier' || $r === 'db_verifier') return 'verifier';
    if ($r === 'qa' || $r === 'team_lead') return 'qa';
    return 'validator';
}

function os_workflow_aggregate_map(PDO $pdo, array $caseIds, string $role): array
{
    $ids = [];
    foreach ($caseIds as $id) {
        $n = (int)$id;
        if ($n > 0) $ids[$n] = true;
    }
    $ids = array_keys($ids);
    if (!$ids) return [];

    $stage = os_stage_for_role($role);
    $map = [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $sql = "SELECT case_id,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) = 'hold' THEN 1 ELSE 0 END) AS hold_count,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) = 'insufficient_documents' THEN 1 ELSE 0 END) AS need_docs_count,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('waiting_candidate','reopened') THEN 1 ELSE 0 END) AS waiting_candidate_count,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('pending','in_progress','correction_submitted','blocked') THEN 1 ELSE 0 END) AS pending_like_count,
                       COUNT(*) AS total_count
                  FROM Vati_Payfiller_Case_Component_Workflow
                 WHERE case_id IN ($ph) AND LOWER(TRIM(stage)) = ?
                 GROUP BY case_id";
        $params = array_merge($ids, [$stage]);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $cid = (int)($r['case_id'] ?? 0);
            if ($cid <= 0) continue;
            $map[$cid] = [
                'approved_count' => (int)($r['approved_count'] ?? 0),
                'rejected_count' => (int)($r['rejected_count'] ?? 0),
                'hold_count' => (int)($r['hold_count'] ?? 0),
                'need_docs_count' => (int)($r['need_docs_count'] ?? 0),
                'waiting_candidate_count' => (int)($r['waiting_candidate_count'] ?? 0),
                'pending_like_count' => (int)($r['pending_like_count'] ?? 0),
                'total_count' => (int)($r['total_count'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
    }
    return $map;
}

function os_queue_resolution_map(PDO $pdo, array $rows, string $role): array
{
    $r = os_norm($role);
    $byCase = [];

    if ($r === 'verifier' || $r === 'db_verifier') {
        $pairs = [];
        foreach ($rows as $row) {
            $cid = (int)($row['case_id'] ?? 0);
            $g = strtoupper(trim((string)($row['group_key'] ?? '')));
            if ($cid > 0 && $g !== '') $pairs[$cid . '|' . $g] = ['case_id' => $cid, 'group_key' => $g];
        }
        if (!$pairs) return [];
        foreach ($pairs as $k => $p) {
            $cid = (int)$p['case_id'];
            $g = (string)$p['group_key'];
            try {
                $st = $pdo->prepare(
                    "SELECT
                        SUM(CASE WHEN completed_at IS NULL AND LOWER(TRIM(COALESCE(status,''))) IN ('pending','in_progress','correction_submitted','waiting_candidate','hold','insufficient_documents','reopened','blocked') THEN 1 ELSE 0 END) AS unresolved_rows,
                        SUM(CASE WHEN completed_at IS NOT NULL OR LOWER(TRIM(COALESCE(status,''))) IN ('done','completed','approved','rejected','hold','insufficient_documents','verified','clear') THEN 1 ELSE 0 END) AS resolved_rows
                     FROM Vati_Payfiller_Verifier_Group_Queue
                     WHERE case_id = ? AND UPPER(TRIM(group_key)) = ?"
                );
                $st->execute([$cid, $g]);
                $rr = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $byCase[$k] = [
                    'unresolved_rows' => (int)($rr['unresolved_rows'] ?? 0),
                    'resolved_rows' => (int)($rr['resolved_rows'] ?? 0),
                ];
            } catch (Throwable $e) {
                $byCase[$k] = ['unresolved_rows' => 0, 'resolved_rows' => 0];
            }
        }
        return $byCase;
    }

    $caseIds = [];
    foreach ($rows as $row) {
        $cid = (int)($row['case_id'] ?? 0);
        if ($cid > 0) $caseIds[$cid] = true;
    }
    $caseIds = array_keys($caseIds);
    if (!$caseIds) return [];
    $ph = implode(',', array_fill(0, count($caseIds), '?'));
    $table = ($r === 'qa' || $r === 'team_lead') ? 'Vati_Payfiller_Verifier_Group_Queue' : 'Vati_Payfiller_Validator_Queue';
    try {
        $st = $pdo->prepare(
            "SELECT case_id,
                    SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END) AS unresolved_rows,
                    SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved_rows
               FROM {$table}
              WHERE case_id IN ($ph)
              GROUP BY case_id"
        );
        $st->execute($caseIds);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $rr) {
            $cid = (int)($rr['case_id'] ?? 0);
            if ($cid <= 0) continue;
            $byCase[(string)$cid] = [
                'unresolved_rows' => (int)($rr['unresolved_rows'] ?? 0),
                'resolved_rows' => (int)($rr['resolved_rows'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
    }
    return $byCase;
}

function os_mail_sent_map(PDO $pdo, array $applicationIds): array
{
    $ids = [];
    foreach ($applicationIds as $id) {
        $k = trim((string)$id);
        if ($k !== '') $ids[$k] = true;
    }
    $ids = array_keys($ids);
    if (!$ids) return [];

    $out = [];
    $ph = implode(',', array_fill(0, count($ids), '?'));

    try {
        $sql = 'SELECT application_id, 1 AS sent
                  FROM Vati_Payfiller_Verification_Communications
                 WHERE application_id IN (' . $ph . ')
                   AND LOWER(TRIM(COALESCE(communication_status, \'\'))) IN (\'sent\',\'delivered\',\'success\')
                 GROUP BY application_id';
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $k = trim((string)($r['application_id'] ?? ''));
            if ($k !== '') $out[$k] = true;
        }
    } catch (Throwable $e) {
    }

    try {
        $sql2 = 'SELECT application_id, 1 AS sent
                   FROM Vati_Payfiller_Workflow_Communications
                  WHERE application_id IN (' . $ph . ')
                    AND LOWER(TRIM(COALESCE(delivery_status, \'\'))) IN (\'sent\',\'delivered\',\'success\')
                  GROUP BY application_id';
        $st2 = $pdo->prepare($sql2);
        $st2->execute($ids);
        foreach (($st2->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $k = trim((string)($r['application_id'] ?? ''));
            if ($k !== '') $out[$k] = true;
        }
    } catch (Throwable $e) {
    }

    return $out;
}

function os_enrich_rows(PDO $pdo, array $rows, string $role): array
{
    if (!$rows) return [];

    $appIds = [];
    foreach ($rows as $r) {
        $id = trim((string)($r['application_id'] ?? ''));
        if ($id !== '') $appIds[$id] = true;
    }
    $mailMap = os_mail_sent_map($pdo, array_keys($appIds));
    $caseIds = [];
    foreach ($rows as $r) {
        $cid = (int)($r['case_id'] ?? 0);
        if ($cid > 0) $caseIds[$cid] = true;
    }
    $wfAggByCase = os_workflow_aggregate_map($pdo, array_keys($caseIds), $role);
    $queueByScope = os_queue_resolution_map($pdo, $rows, $role);

    foreach ($rows as &$r) {
        $appId = trim((string)($r['application_id'] ?? ''));
        $caseId = (int)($r['case_id'] ?? 0);
        $wfAgg = $wfAggByCase[$caseId] ?? [
            'approved_count' => 0,
            'rejected_count' => 0,
            'hold_count' => 0,
            'need_docs_count' => 0,
            'waiting_candidate_count' => 0,
            'pending_like_count' => 0,
            'total_count' => 0,
        ];
        $queueKey = ($role === 'verifier' || $role === 'db_verifier')
            ? ((string)$caseId . '|' . strtoupper(trim((string)($r['group_key'] ?? ''))))
            : (string)$caseId;
        $queueAgg = $queueByScope[$queueKey] ?? ['unresolved_rows' => 0, 'resolved_rows' => 0];
        $resolved = os_resolve_operational_status([
            'role' => $role,
            'workflow_status' => (string)($r['workflow_status'] ?? $r['status'] ?? ''),
            'queue_status' => (string)($r['status'] ?? ''),
            'case_status' => (string)($r['case_status'] ?? ''),
            'queue_completed_at' => (string)($r['completed_at'] ?? ''),
            'queue_completed' => trim((string)($r['completed_at'] ?? '')) !== '' ? 1 : 0,
            'unresolved_queue_rows' => (int)($queueAgg['unresolved_rows'] ?? 0),
            'resolved_queue_rows' => (int)($queueAgg['resolved_rows'] ?? 0),
            'agg_rejected' => (int)($wfAgg['rejected_count'] ?? 0),
            'agg_hold' => (int)($wfAgg['hold_count'] ?? 0),
            'agg_insufficient_documents' => (int)($wfAgg['need_docs_count'] ?? 0),
            'agg_waiting_candidate' => (int)($wfAgg['waiting_candidate_count'] ?? 0),
            'agg_pending_like' => (int)($wfAgg['pending_like_count'] ?? 0),
            'agg_approved' => (int)($wfAgg['approved_count'] ?? 0),
            'agg_total' => (int)($wfAgg['total_count'] ?? 0),
            'mail_sent' => ($appId !== '' && isset($mailMap[$appId])),
        ]);
        $stageResolved = os_resolve_stage_status([
            'role' => $role,
            'case_status' => (string)($r['case_status'] ?? ''),
            'unresolved_queue_rows' => (int)($queueAgg['unresolved_rows'] ?? 0),
            'resolved_queue_rows' => (int)($queueAgg['resolved_rows'] ?? 0),
            'agg_pending_like' => (int)($wfAgg['pending_like_count'] ?? 0),
            'agg_total' => (int)($wfAgg['total_count'] ?? 0),
        ]);
        $r['operational_status'] = $resolved['code'];
        $r['operational_status_label'] = $resolved['label'];
        $r['operational_rule'] = $resolved['rule'];
        $r['stage_status'] = $stageResolved['code'];
        $r['stage_status_label'] = $stageResolved['label'];
        $r['stage_status_rule'] = $stageResolved['rule'];
        os_debug_log('operational_status_resolved', [
            'application_id' => $appId,
            'case_id' => $caseId,
            'role' => $role,
            'workflow_status' => (string)($r['workflow_status'] ?? $r['status'] ?? ''),
            'queue_status' => (string)($r['status'] ?? ''),
            'case_status' => (string)($r['case_status'] ?? ''),
            'rejected_count' => (int)($wfAgg['rejected_count'] ?? 0),
            'hold_count' => (int)($wfAgg['hold_count'] ?? 0),
            'insufficient_docs_count' => (int)($wfAgg['need_docs_count'] ?? 0),
            'waiting_candidate_count' => (int)($wfAgg['waiting_candidate_count'] ?? 0),
            'pending_like_count' => (int)($wfAgg['pending_like_count'] ?? 0),
            'approved_count' => (int)($wfAgg['approved_count'] ?? 0),
            'aggregate_total' => (int)($wfAgg['total_count'] ?? 0),
            'unresolved_queue_rows' => (int)($queueAgg['unresolved_rows'] ?? 0),
            'resolved_queue_rows' => (int)($queueAgg['resolved_rows'] ?? 0),
            'mail_sent' => ($appId !== '' && isset($mailMap[$appId])) ? 1 : 0,
            'operational_status' => $resolved['code'],
            'operational_label' => $resolved['label'],
            'resolver_rule' => $resolved['rule'],
            'stage_status' => $stageResolved['code'],
            'stage_label' => $stageResolved['label'],
            'stage_rule' => $stageResolved['rule'],
        ]);
    }
    unset($r);

    return $rows;
}
