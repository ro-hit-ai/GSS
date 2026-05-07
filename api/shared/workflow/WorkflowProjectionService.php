<?php

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
        $caseStatus = strtoupper(trim((string)($this->repo->loadCaseStatusByCaseId($caseId) ?? '')));
        $isTerminalRejected = in_array($caseStatus, ['REJECTED', 'STOP_BGV'], true);

        if ($stage === 'validator') {
            $validatorRows = $this->repo->loadRequiredComponentStageStatuses($caseId, 'validator');
            $candidateRows = $this->repo->loadRequiredComponentStageStatuses($caseId, 'candidate');

            $candidateByComponent = [];
            foreach ($candidateRows as $r) {
                $candidateByComponent[(string)$r['component_key']] = strtolower(trim((string)($r['status'] ?? 'pending')));
            }

            $counts = ['approved' => 0, 'rejected' => 0, 'pending' => 0, 'hold' => 0, 'insufficient_documents' => 0, 'in_progress' => 0, 'waiting_candidate' => 0];
            foreach ($validatorRows as $r) {
                $k = (string)($r['component_key'] ?? '');
                $validatorStatus = strtolower(trim((string)($r['status'] ?? 'pending')));
                $candidateStatus = strtolower(trim((string)($candidateByComponent[$k] ?? 'pending')));

                if (!in_array($candidateStatus, ['approved', 'completed', 'clear', 'verified'], true)) {
                    $counts['waiting_candidate']++;
                    continue;
                }

                if ($validatorStatus === 'approved') { $counts['approved']++; continue; }
                if ($validatorStatus === 'rejected') { $counts['rejected']++; continue; }
                if ($validatorStatus === 'hold') { $counts['hold']++; continue; }
                if ($validatorStatus === 'insufficient_documents') { $counts['insufficient_documents']++; continue; }
                if ($validatorStatus === 'in_progress') { $counts['in_progress']++; continue; }
                $counts['pending']++;
            }

            $total = count($validatorRows);
            $operationalState = 'in_progress';
            if ($total > 0 && $counts['approved'] === $total) {
                $operationalState = 'completed';
            } elseif ($isTerminalRejected || ($total > 0 && $counts['rejected'] === $total)) {
                $operationalState = 'completed';
            } elseif ($counts['waiting_candidate'] > 0) {
                $operationalState = 'waiting_candidate';
            } elseif ($counts['hold'] > 0 || $counts['insufficient_documents'] > 0) {
                $operationalState = 'blocked';
            } else {
                $operationalState = 'in_progress';
            }

            $this->repo->setValidatorQueueOperationalState($caseId, $userId, $operationalState);
            $this->logProjection('validator', $caseId, $componentKey, [
                'operational_state' => $operationalState,
                'counts' => $counts,
                'total_required' => $total,
                'terminal_rejection' => $isTerminalRejected,
            ]);
        }

        if ($stage === 'verifier') {
            $group = $this->groupForComponent($componentKey);
            if ($group !== '') {
                $parts = $this->groupComponents($group);
                $counts = ['approved' => 0, 'rejected' => 0, 'pending' => 0, 'hold' => 0, 'insufficient_documents' => 0, 'in_progress' => 0, 'waiting_candidate' => 0, 'skipped_not_required' => 0];
                foreach ($parts as $part) {
                    $statuses = $this->repo->loadComponentStageStatuses($caseId, $part);
                    if (!$statuses) {
                        $counts['skipped_not_required']++;
                        continue;
                    }
                    $validatorStatus = strtolower(trim((string)($statuses['validator'] ?? 'pending')));
                    $verifierStatus = strtolower(trim((string)($statuses['verifier'] ?? 'pending')));

                    if (!in_array($validatorStatus, ['approved', 'rejected'], true)) {
                        $counts['waiting_candidate']++;
                        continue;
                    }

                    if ($validatorStatus === 'rejected') {
                        // Verifier cannot proceed meaningfully until upstream resolves via reopen policy.
                        $counts['blocked'] = ($counts['blocked'] ?? 0) + 1;
                        continue;
                    }

                    if ($verifierStatus === 'approved') { $counts['approved']++; continue; }
                    if ($verifierStatus === 'rejected') { $counts['rejected']++; continue; }
                    if ($verifierStatus === 'hold') { $counts['hold']++; continue; }
                    if ($verifierStatus === 'insufficient_documents') { $counts['insufficient_documents']++; continue; }
                    if ($verifierStatus === 'in_progress') { $counts['in_progress']++; continue; }
                    $counts['pending']++;
                }

                $actionableTotal = $counts['approved'] + $counts['rejected'] + $counts['pending'] + $counts['hold'] + $counts['insufficient_documents'] + $counts['in_progress'];
                $operationalState = 'in_progress';
                if ($actionableTotal > 0 && $counts['approved'] === $actionableTotal) {
                    $operationalState = 'completed';
                } elseif ($isTerminalRejected || ($actionableTotal > 0 && $counts['rejected'] === $actionableTotal)) {
                    $operationalState = 'completed';
                } elseif ($counts['waiting_candidate'] > 0) {
                    $operationalState = 'waiting_candidate';
                } elseif (($counts['blocked'] ?? 0) > 0 || $counts['hold'] > 0 || $counts['insufficient_documents'] > 0) {
                    $operationalState = 'blocked';
                } else {
                    $operationalState = 'in_progress';
                }

                $this->repo->setVerifierGroupOperationalState($caseId, $userId, $group, $operationalState);
                $this->logProjection('verifier_group', $caseId, $componentKey, [
                    'group' => $group,
                    'operational_state' => $operationalState,
                    'counts' => $counts,
                    'actionable_total' => $actionableTotal,
                    'terminal_rejection' => $isTerminalRejected,
                ]);
            }
        }

        if ($stage === 'qa') {
            $qaRows = $this->repo->loadRequiredComponentStageStatuses($caseId, 'qa');
            $counts = ['approved' => 0, 'rejected' => 0, 'pending' => 0, 'hold' => 0, 'insufficient_documents' => 0, 'in_progress' => 0];
            foreach ($qaRows as $r) {
                $s = strtolower(trim((string)($r['status'] ?? 'pending')));
                if ($s === 'approved') { $counts['approved']++; continue; }
                if ($s === 'rejected') { $counts['rejected']++; continue; }
                if ($s === 'hold') { $counts['hold']++; continue; }
                if ($s === 'insufficient_documents') { $counts['insufficient_documents']++; continue; }
                if ($s === 'in_progress') { $counts['in_progress']++; continue; }
                $counts['pending']++;
            }
            $total = count($qaRows);
            $operationalState = 'in_progress';
            if ($total > 0 && $counts['approved'] === $total) {
                $operationalState = 'completed';
            } elseif ($isTerminalRejected || ($total > 0 && $counts['rejected'] === $total)) {
                $operationalState = 'completed';
            } elseif ($counts['hold'] > 0 || $counts['insufficient_documents'] > 0) {
                $operationalState = 'blocked';
            } else {
                $operationalState = 'in_progress';
            }
            $this->logProjection('qa', $caseId, $componentKey, [
                'operational_state' => $operationalState,
                'counts' => $counts,
                'total_required' => $total,
                'terminal_rejection' => $isTerminalRejected,
            ]);
        }
    }

    private function groupForComponent(string $componentKey): string
    {
        $k = strtolower(trim($componentKey));
        if (in_array($k, ['basic', 'id', 'contact'], true)) return 'BASIC';
        if (in_array($k, ['education', 'employment', 'reference'], true)) return 'EDUCATION';
        if (in_array($k, ['ecourt', 'socialmedia'], true)) return 'ADDITIONAL';
        return '';
    }

    private function groupComponents(string $groupKey): array
    {
        $g = strtoupper(trim($groupKey));
        if ($g === 'BASIC') return ['basic', 'id', 'contact'];
        if ($g === 'EDUCATION') return ['education', 'employment', 'reference'];
        if ($g === 'ADDITIONAL') return ['ecourt', 'socialmedia'];
        return [];
    }

    private function logProjection(string $projection, int $caseId, string $componentKey, array $data): void
    {
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
