<?php

final class WorkflowInvariantService
{
    private WorkflowRepository $repo;
    private WorkflowGuardService $guard;

    public function __construct(WorkflowRepository $repo)
    {
        $this->repo = $repo;
        $this->guard = new WorkflowGuardService();
    }

    public function assertVersionIncrement(int $from, int $to): void
    {
        if ($to !== ($from + 1)) {
            throw new RuntimeException('WF_INVARIANT_VERSION_INCREMENT');
        }
    }

    public function assertNoUnresolvedOnApproved(string $caseStatus, int $caseId): void
    {
        $status = strtoupper(trim($caseStatus));
        if (!in_array($status, ['APPROVED', 'COMPLETED', 'CLEAR'], true)) {
            return;
        }

        foreach (['validator', 'verifier', 'qa'] as $stage) {
            $rows = $this->repo->loadStageParticipantStatuses($caseId, $stage);
            foreach ($rows as $r) {
                $s = strtolower(trim((string)($r['status'] ?? 'pending')));
                if (!$this->guard->isEvaluatedStatus($s)) {
                    throw new RuntimeException('WF_INVARIANT_APPROVED_WITH_UNRESOLVED');
                }
            }
        }
    }

    public function assertQaApproveGate(string $stage, string $action, int $caseId, string $componentKey = ''): void
    {
        if (strtolower(trim($stage)) !== 'qa' || strtolower(trim($action)) !== 'approve') {
            return;
        }

        $componentKey = strtolower(trim($componentKey));

        // Controlled collaborative overlap:
        // QA may approve a component once that component's verifier stage is finalized,
        // even if other verifier queue groups on the same case remain open.
        $verifierComponentStatus = $componentKey !== ''
            ? strtolower(trim($this->repo->loadWorkflowStatus($caseId, $componentKey, 'verifier')))
            : '';
        $groups = $this->repo->loadVerifierQueueGroupsForCase($caseId);
        $hasVerifierQueue = !empty($groups);
        $openVerifierQueueRows = $this->repo->countActiveVerifierQueueRows($caseId);
        $componentVerifierFinal = $verifierComponentStatus !== '' && $this->guard->isEvaluatedStatus($verifierComponentStatus);

        if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
            @file_put_contents(
                __DIR__ . '/../../../logs/workflow_transition.log',
                json_encode([
                    'ts' => date('c'),
                    'event' => 'qa_invariant_verifier_final_check',
                    'case_id' => $caseId,
                    'component_key' => $componentKey,
                    'has_verifier_queue' => $hasVerifierQueue ? 1 : 0,
                    'verifier_groups' => $groups,
                    'open_verifier_queue_rows' => $openVerifierQueueRows,
                    'verifier_component_status' => $verifierComponentStatus,
                    'component_verifier_final' => $componentVerifierFinal ? 1 : 0,
                    'gate_source' => 'component_verifier_finality',
                ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND
            );
        }

        if (!$hasVerifierQueue || !$componentVerifierFinal) {
            throw new RuntimeException('WF_INVARIANT_QA_REQUIRES_VERIFIER_FINAL');
        }
    }
}
