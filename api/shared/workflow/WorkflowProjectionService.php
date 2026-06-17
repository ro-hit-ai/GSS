<?php

require_once __DIR__ . '/workflow_status_semantics.php';
require_once __DIR__ . '/workflow_stage_config.php';
require_once __DIR__ . '/workflow_semantics.php';
require_once __DIR__ . '/../case_management/case_component_binding.php';
require_once __DIR__ . '/../verifier_case_queue.php';

final class WorkflowProjectionService
{
    private WorkflowRepository $repo;
    public function __construct(WorkflowRepository $repo)
    {
        $this->repo = $repo;
    }

    public function syncQueues(int $caseId, int $userId, string $componentKey, string $stage): void
    {
        $stage = strtolower(trim($stage));
        $stageKeys = wf_stage_keys();
        $stage1 = $stageKeys[0] ?? 'validator';
        $stage2 = $stageKeys[1] ?? 'verifier';
        $stage3 = $stageKeys[2] ?? 'qa';
        $caseStatus = strtoupper(trim((string)($this->repo->loadCaseStatusByCaseId($caseId) ?? '')));

        if ($stage === $stage1) {
            $validatorRows = $this->repo->loadStageParticipantStatuses($caseId, $stage1, true);
            $candidateRows = $this->repo->loadRequiredComponentStageStatuses($caseId, 'candidate');

            $candidateByComponent = [];
            foreach ($candidateRows as $r) {
                $candidateByComponent[(string)$r['component_key']] = strtolower(trim((string)($r['status'] ?? 'pending')));
            }

            $counts = ['approved' => 0, 'rejected' => 0, 'pending' => 0, 'hold' => 0, 'insufficient_documents' => 0, 'in_progress' => 0, 'waiting_candidate' => 0, 'evaluated' => 0, 'active' => 0];
            foreach ($validatorRows as $r) {
                $k = (string)($r['component_key'] ?? '');
                $validatorStatus = strtolower(trim((string)($r['status'] ?? 'pending')));
                $candidateStatus = strtolower(trim((string)($candidateByComponent[$k] ?? 'pending')));

                $isInternalOperational = ($k === 'reports');
                if (!$isInternalOperational && !in_array($candidateStatus, ['approved', 'completed', 'clear', 'verified'], true)) {
                    $counts['waiting_candidate']++;
                    continue;
                }

                if ($validatorStatus === 'approved') { $counts['approved']++; continue; }
                if ($validatorStatus === 'rejected') { $counts['rejected']++; continue; }
                if ($validatorStatus === 'hold') { $counts['hold']++; continue; }
                if ($validatorStatus === 'insufficient_documents') { $counts['insufficient_documents']++; continue; }
                if ($validatorStatus === 'waiting_candidate' || $validatorStatus === 'reopened' || $validatorStatus === 'blocked') { $counts['waiting_candidate']++; continue; }
                if ($validatorStatus === 'in_progress' || $validatorStatus === 'correction_submitted') { $counts['in_progress']++; continue; }
                $counts['pending']++;
            }
            $counts['evaluated'] = $counts['approved'] + $counts['rejected'] + $counts['hold'] + $counts['insufficient_documents'];
            // Canonical finality: rejected/hold/insufficient_documents are evaluated-final.
            // Only waiting_candidate keeps queue operationally open.
            $counts['active'] = $counts['waiting_candidate'];

            $total = count($validatorRows);
            $operationalState = 'in_progress';
            if ($total > 0 && $counts['evaluated'] === $total && $counts['active'] === 0) {
                $operationalState = 'completed';
            } elseif ($counts['waiting_candidate'] > 0) {
                $operationalState = 'waiting_candidate';
            } elseif ($counts['hold'] > 0 || $counts['insufficient_documents'] > 0) {
                $operationalState = 'blocked';
            } else {
                $operationalState = 'in_progress';
            }

            $this->repo->setValidatorQueueOperationalState($caseId, $userId, $operationalState);
            $seededVerifierGroups = [];
            // Lifecycle separation: verifier queue creation is assignment/snapshot-driven
            // and happens at validator->verifier handoff, not via active-participant closure logic.
            if ($operationalState === 'completed' && $caseStatus === 'PENDING_VERIFIER') {
                $seededVerifierGroups = $this->seedVerifierGroupsForCase($caseId);
            }
            $this->logProjection($stage1, $caseId, $componentKey, [
                'operational_state' => $operationalState,
                'counts' => $counts,
                'total_required' => $total,
                'completion_reason' => ($total > 0 && $counts['evaluated'] === $total && $counts['active'] === 0) ? 'all_evaluated_no_unresolved' : 'operationally_visible',
                'unresolved_total' => $counts['active'],
                'case_status' => $caseStatus,
                'forwarding_eligible' => ($caseStatus === 'PENDING_VERIFIER'),
                'seeded_verifier_groups' => $seededVerifierGroups,
            ]);
        }

        if ($stage === $stage2) {
            $mode = verifier_case_queue_is_case_model($this->repo->pdo(), $caseId, '');
            if ($mode) {
                $queue = verifier_case_queue_sync($this->repo->pdo(), $caseId, $userId);
                $this->logProjection($stage2 . '_case_recompute', $caseId, $componentKey, [
                    'ownership_model' => 'case_level',
                    'queue_row' => $queue,
                    'case_status' => $caseStatus,
                ]);
                return;
            }
            $actedGroup = $this->groupForComponent($componentKey);
            $seededGroups = $this->repo->loadVerifierQueueGroupsForCase($caseId);
            $targetGroups = [];
            foreach ($seededGroups as $g) {
                if ($this->groupComponents($g)) $targetGroups[$g] = true;
            }
            if (!$targetGroups && $actedGroup !== '') {
                $targetGroups[$actedGroup] = true;
            }

            $recomputedGroups = [];
            foreach (array_values(array_keys($targetGroups)) as $group) {
                $partsStatic = $this->groupComponents($group);
                $parts = $this->activeGroupComponents($caseId, $group, $partsStatic);
                $statusMapByComponent = $this->repo->loadStageStatusesForComponents($caseId, $parts);
                $counts = ['approved' => 0, 'rejected' => 0, 'pending' => 0, 'hold' => 0, 'insufficient_documents' => 0, 'in_progress' => 0, 'waiting_candidate' => 0, 'skipped_not_required' => 0, 'evaluated' => 0, 'active' => 0];
                $unresolvedActiveComponents = [];
                $resolvedActiveComponents = [];
                foreach ($parts as $part) {
                    $statuses = $statusMapByComponent[strtolower(trim($part))] ?? [];
                    if (!$statuses) {
                        $counts['skipped_not_required']++;
                        continue;
                    }
                    $validatorStatus = strtolower(trim((string)($statuses[$stage1] ?? 'pending')));
                    $verifierStatus = strtolower(trim((string)($statuses[$stage2] ?? 'pending')));

                    $validatorGatePassed = wf_is_evaluated_status($validatorStatus)
                        || in_array($validatorStatus, ['waiting_candidate', 'reopened', 'blocked'], true);
                    if (!$validatorGatePassed) {
                        $counts['waiting_candidate']++;
                        $unresolvedActiveComponents[] = ['component' => $part, 'reason' => 'validator_gate_not_passed', 'validator_status' => $validatorStatus, 'verifier_status' => $verifierStatus];
                        continue;
                    }

                    if ($verifierStatus === 'approved') { $counts['approved']++; $resolvedActiveComponents[] = ['component' => $part, 'status' => 'approved']; continue; }
                    if ($verifierStatus === 'rejected') { $counts['rejected']++; $resolvedActiveComponents[] = ['component' => $part, 'status' => 'rejected']; continue; }
                    if ($verifierStatus === 'hold') { $counts['hold']++; $resolvedActiveComponents[] = ['component' => $part, 'status' => 'hold']; continue; }
                    if ($verifierStatus === 'insufficient_documents') { $counts['insufficient_documents']++; $resolvedActiveComponents[] = ['component' => $part, 'status' => 'insufficient_documents']; continue; }
                    if ($verifierStatus === 'waiting_candidate' || $verifierStatus === 'reopened' || $verifierStatus === 'blocked') { $counts['waiting_candidate']++; $unresolvedActiveComponents[] = ['component' => $part, 'reason' => 'verifier_waiting_candidate', 'verifier_status' => $verifierStatus]; continue; }
                    if ($verifierStatus === 'in_progress' || $verifierStatus === 'correction_submitted') { $counts['in_progress']++; $unresolvedActiveComponents[] = ['component' => $part, 'reason' => 'verifier_in_progress']; continue; }
                    $counts['pending']++;
                    $unresolvedActiveComponents[] = ['component' => $part, 'reason' => 'verifier_pending'];
                }
                $counts['evaluated'] = $counts['approved'] + $counts['rejected'] + $counts['hold'] + $counts['insufficient_documents'];
                // Canonical finality: rejected/hold/insufficient_documents are evaluated-final.
                // Only waiting_candidate keeps queue operationally open.
                $counts['active'] = $counts['waiting_candidate'];

                $actionableTotal = $counts['approved']
                    + $counts['rejected']
                    + $counts['pending']
                    + $counts['hold']
                    + $counts['insufficient_documents']
                    + $counts['in_progress']
                    + $counts['waiting_candidate'];
                $operationalState = 'in_progress';
                if ($actionableTotal === 0) {
                    $operationalState = 'completed';
                } elseif ($counts['evaluated'] === $actionableTotal && $counts['active'] === 0) {
                    $operationalState = 'completed';
                } elseif ($counts['waiting_candidate'] > 0) {
                    $operationalState = 'waiting_candidate';
                } elseif ($counts['hold'] > 0 || $counts['insufficient_documents'] > 0) {
                    $operationalState = 'blocked';
                } else {
                    $operationalState = 'in_progress';
                }

                $before = $this->repo->loadVerifierQueueGroupState($caseId, $group);
                $this->repo->setVerifierGroupOperationalState($caseId, $userId, $group, $operationalState);
                $after = $this->repo->loadVerifierQueueGroupState($caseId, $group);
                $recomputedGroups[] = [
                    'group' => $group,
                    'static_group_components' => $partsStatic,
                    'active_group_components' => $parts,
                    'counts' => $counts,
                    'actionable_total' => $actionableTotal,
                    'operational_state' => $operationalState,
                    'before' => $before,
                    'after' => $after,
                    'unresolved_active_components' => $unresolvedActiveComponents,
                    'resolved_active_components' => $resolvedActiveComponents,
                    'queue_close_decision' => ($operationalState === 'completed'),
                    'completion_reason' => ($counts['evaluated'] === $actionableTotal && $counts['active'] === 0) ? 'all_evaluated_no_unresolved' : 'operationally_visible',
                ];
            }

            $this->logProjection($stage2 . '_groups_recompute', $caseId, $componentKey, [
                'acted_group' => $actedGroup,
                'seeded_groups' => $seededGroups,
                'recomputed_groups' => $recomputedGroups,
                'remaining_active_queue_rows' => $this->repo->countActiveVerifierQueueRows($caseId),
                'case_status' => $caseStatus,
            ]);
        }

        if ($stage === $stage3) {
            $qaRows = $this->repo->loadStageParticipantStatuses($caseId, $stage3);
            $counts = ['approved' => 0, 'rejected' => 0, 'pending' => 0, 'hold' => 0, 'insufficient_documents' => 0, 'in_progress' => 0, 'waiting_candidate' => 0, 'evaluated' => 0, 'active' => 0];
            foreach ($qaRows as $r) {
                $s = strtolower(trim((string)($r['status'] ?? 'pending')));
                if ($s === 'approved') { $counts['approved']++; continue; }
                if ($s === 'rejected') { $counts['rejected']++; continue; }
                if ($s === 'hold') { $counts['hold']++; continue; }
                if ($s === 'insufficient_documents') { $counts['insufficient_documents']++; continue; }
                if ($s === 'waiting_candidate' || $s === 'reopened' || $s === 'blocked') { $counts['waiting_candidate']++; continue; }
                if ($s === 'in_progress' || $s === 'correction_submitted') { $counts['in_progress']++; continue; }
                $counts['pending']++;
            }
            $counts['evaluated'] = $counts['approved'] + $counts['rejected'] + $counts['hold'] + $counts['insufficient_documents'];
            // Canonical finality: rejected/hold/insufficient_documents are evaluated-final.
            // waiting_candidate/reopened keep QA operationally unresolved.
            $counts['active'] = $counts['waiting_candidate'];
            $total = count($qaRows);
            $operationalState = 'in_progress';
            if ($total > 0 && $counts['evaluated'] === $total && $counts['active'] === 0) {
                $operationalState = 'completed';
            } elseif ($counts['waiting_candidate'] > 0) {
                $operationalState = 'waiting_candidate';
            } elseif ($counts['hold'] > 0 || $counts['insufficient_documents'] > 0) {
                $operationalState = 'blocked';
            } else {
                $operationalState = 'in_progress';
            }
            $this->logProjection($stage3, $caseId, $componentKey, [
                'operational_state' => $operationalState,
                'counts' => $counts,
                'total_required' => $total,
                'completion_reason' => ($total > 0 && $counts['evaluated'] === $total && $counts['active'] === 0) ? 'all_evaluated_no_unresolved' : 'operationally_visible',
                'unresolved_total' => $counts['active'],
                'case_status' => $caseStatus,
            ]);
        }
    }

    private function isEvaluated(string $status): bool
    {
        $s = strtolower(trim($status));
        return in_array($s, ['approved', 'rejected', 'hold', 'insufficient_documents', 'completed', 'clear', 'verified'], true);
    }

    private function groupForComponent(string $componentKey): string
    {
        $k = strtolower(trim($componentKey));
        foreach (wf_verifier_group_map() as $group => $components) {
            $normalized = array_map(static function ($x) { return strtolower(trim((string)$x)); }, $components);
            if (in_array($k, $normalized, true)) return (string)$group;
        }
        return '';
    }

    private function groupComponents(string $groupKey): array
    {
        return wf_verifier_group_components($groupKey);
    }

    private function activeGroupComponents(int $caseId, string $groupKey, array $staticParts): array
    {
        $norm = static function (array $parts): array {
            $out = [];
            foreach ($parts as $p) {
                $k = strtolower(trim((string)$p));
                if ($k !== '') $out[$k] = true;
            }
            return array_values(array_keys($out));
        };

        $staticParts = $norm($staticParts);
        if (!$staticParts) return [];

        $seeded = $this->seededGroupComponents($caseId, $groupKey, $staticParts);
        if ($seeded) {
            return $seeded;
        }

        // Compatibility fallback only when mapping/seeding metadata is unavailable.
        return $staticParts;
    }

    private function seedVerifierGroupsForCase(int $caseId): array
    {
        $out = [];
        foreach (wf_verifier_group_map() as $groupKey => $components) {
            $staticParts = [];
            foreach ((array)$components as $c) {
                $k = strtolower(trim((string)$c));
                if ($k !== '') $staticParts[] = $k;
            }
            $active = $this->seededGroupComponents($caseId, (string)$groupKey, $staticParts);
            if (!$active) {
                continue;
            }
            $this->repo->ensureVerifierGroupQueueRow($caseId, (string)$groupKey);
            $out[] = [
                'group_key' => strtoupper(trim((string)$groupKey)),
                'static_group_components' => array_values(array_unique($staticParts)),
                'seeded_group_components' => $active,
            ];
        }
        return $out;
    }

    private function seededGroupComponents(int $caseId, string $groupKey, array $staticParts): array
    {
        $norm = static function (array $parts): array {
            $out = [];
            foreach ($parts as $p) {
                $k = strtolower(trim((string)$p));
                if ($k !== '') $out[$k] = true;
            }
            return array_values(array_keys($out));
        };

        $staticParts = $norm($staticParts);
        if (!$staticParts) return [];

        try {
            $cfg = case_component_binding_build_for_case($this->repo->pdo(), $caseId, '');
            $rolesByComponent = (array)($cfg['component_roles'] ?? []);
            $hasRoleBinding = !empty($cfg['has_role_binding']);
            $requiredSet = [];
            foreach ((array)($cfg['required_components'] ?? []) as $c) {
                $k = strtolower(trim((string)$c));
                if ($k !== '') $requiredSet[$k] = true;
            }

            $active = [];
            $droppedByOwnership = [];
            foreach ($staticParts as $part) {
                if (!empty($requiredSet) && !isset($requiredSet[$part])) {
                    $droppedByOwnership[] = ['component' => $part, 'reason' => 'not_required_in_snapshot'];
                    continue;
                }
                if ($hasRoleBinding) {
                    $roles = (array)($rolesByComponent[$part] ?? []);
                    $hasVerifierRole = isset($roles['verifier']) || isset($roles['db_verifier']);
                    if (!$hasVerifierRole) {
                        $droppedByOwnership[] = ['component' => $part, 'reason' => 'missing_verifier_role_binding'];
                        continue;
                    }
                }
                $active[$part] = true;
            }

            if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
                @file_put_contents(
                    __DIR__ . '/../../../logs/workflow_transition.log',
                    json_encode([
                        'ts' => date('c'),
                        'event' => 'verifier_participation_resolved',
                        'case_id' => $caseId,
                        'group_key' => strtoupper(trim((string)$groupKey)),
                        'resolver_owner' => 'WorkflowProjectionService::seededGroupComponents',
                        'mapping_source' => $hasRoleBinding ? 'component_roles+required_components' : 'required_components',
                        'component_roles' => $rolesByComponent,
                        'required_components' => array_values(array_keys($requiredSet)),
                        'static_group_components' => $staticParts,
                        'active_group_components' => array_values(array_keys($active)),
                        'dropped_by_ownership' => $droppedByOwnership,
                        'collaborative_group_semantics' => true,
                    ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                    FILE_APPEND
                );
            }
            return array_values(array_keys($active));
        } catch (Throwable $e) {
            return [];
        }
    }

    private function logProjection(string $projection, int $caseId, string $componentKey, array $data): void
    {
        $perf = ((string)getenv('WF_PERF_DEBUG_LOGS') === '1');
        $status = ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1');
        if (!$perf && !$status) {
            return;
        }
        $entry = [
            'ts' => date('c'),
            'event' => 'queue_projection_update',
            'projection' => $projection,
            'case_id' => $caseId,
            'component_key' => strtolower(trim($componentKey)),
            'data' => $data,
        ];
        @file_put_contents(__DIR__ . '/../../../logs/workflow_transition.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }
}
