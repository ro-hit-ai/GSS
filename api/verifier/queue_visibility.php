<?php

require_once __DIR__ . '/../shared/workflow/workflow_semantics.php';
require_once __DIR__ . '/../shared/verifier_routing.php';

function verifier_allowed_sections_set_from_session(?PDO $pdo = null): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $raw = isset($_SESSION['auth_allowed_sections']) ? (string)$_SESSION['auth_allowed_sections'] : '';
    if ($pdo) {
        try {
            $uid = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
            if ($uid > 0) {
                $st = $pdo->prepare('SELECT allowed_sections FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1');
                $st->execute([$uid]);
                $dbRaw = (string)($st->fetchColumn() ?: '');
                $raw = $dbRaw;
                $_SESSION['auth_allowed_sections'] = $dbRaw;
            }
        } catch (Throwable $e) {
            // fallback to session
        }
    }
    $raw = strtolower(trim($raw));
    if ($raw === '*') return ['*' => true];
    if ($raw === '') return [];
    $parts = preg_split('/[\s,|]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $k = strtolower(trim((string)$p));
        if ($k === '') continue;
        $out[$k] = true;
    }
    return $out;
}

function verifier_group_components(string $groupKey): array {
    return wf_verifier_group_components($groupKey);
}

function verifier_norm_component_key(string $k): string {
    $k = strtolower(trim($k));
    if ($k === 'identification') return 'id';
    if ($k === 'driving' || $k === 'driving_license') return 'driving_licence';
    return $k;
}

function verifier_can_group_by_sections(array $allowedSet, string $groupKey): bool {
    if (isset($allowedSet['*'])) return true;
    $need = verifier_group_components($groupKey);
    foreach ($need as $k) {
        if (isset($allowedSet[$k])) return true;
    }
    return false;
}

function verifier_allowed_groups_from_sections(array $allowedSet): array {
    $out = [];
    foreach (wf_verifier_group_keys() as $groupKey) {
        if (verifier_can_group_by_sections($allowedSet, $groupKey)) {
            $out[] = $groupKey;
        }
    }
    return $out;
}

function verifier_filter_actionable_queue_rows(PDO $pdo, array $rows, array $allowedSet): array {
    if (!$rows) return [];
    $userId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
    if ($userId <= 0) return [];
    $out = [];
    foreach ($rows as $r) {
        $rowCaseStatus = strtoupper(trim((string)($r['case_status'] ?? '')));
        if ($rowCaseStatus === 'STOP_BGV') {
            continue;
        }
        $caseId = (int)($r['case_id'] ?? 0);
        if ($caseId <= 0) {
            $out[] = $r;
            continue;
        }
        $routingState = verifier_routing_case_state($pdo, $caseId, $userId);
        $components = $routingState['components'] ?? [];
        $owned = $routingState['owned_active_components'] ?? [];
        $claimable = $routingState['claimable_next_components'] ?? [];
        $completed = $routingState['completed_components'] ?? [];
        $locked = $routingState['locked_future_components'] ?? [];
        if (!$owned && !$claimable && !$completed && !$locked) {
            continue;
        }
        $bestPriority = 99;
        foreach (array_merge($owned, $claimable, $completed, $locked) as $componentKey) {
            $priority = isset($components[$componentKey]['priority']) ? (int)$components[$componentKey]['priority'] : 99;
            if ($priority > 0) $bestPriority = min($bestPriority, $priority);
        }
        $r['routing_state'] = $routingState;
        $r['routing_component_states'] = $components;
        $r['owned_active_components'] = $owned;
        $r['claimable_next_components'] = $claimable;
        $r['completed_components'] = $completed;
        $r['locked_future_components'] = $locked;
        $r['routing_priority_rank'] = $bestPriority;
        $r['can_claim'] = $claimable ? 1 : 0;
        $r['can_open'] = !empty($routingState['can_open']) ? 1 : 0;
        $out[] = $r;
    }
    usort($out, static function (array $a, array $b): int {
        $ap = (int)($a['routing_priority_rank'] ?? 99);
        $bp = (int)($b['routing_priority_rank'] ?? 99);
        if ($ap !== $bp) return $ap <=> $bp;
        return strcmp((string)($a['claimed_at'] ?: $a['created_at'] ?: ''), (string)($b['claimed_at'] ?: $b['created_at'] ?: ''));
    });
    return $out;
}
