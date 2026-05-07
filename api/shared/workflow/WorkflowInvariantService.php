<?php

final class WorkflowInvariantService
{
    private WorkflowRepository $repo;

    public function __construct(WorkflowRepository $repo)
    {
        $this->repo = $repo;
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
            $rows = $this->repo->loadRequiredComponentStageStatuses($caseId, $stage);
            foreach ($rows as $r) {
                $s = strtolower(trim((string)($r['status'] ?? 'pending')));
                if (!in_array($s, ['approved', 'rejected'], true)) {
                    throw new RuntimeException('WF_INVARIANT_APPROVED_WITH_UNRESOLVED');
                }
            }
        }
    }

    public function assertQaApproveGate(string $stage, string $action, int $caseId): void
    {
        if (strtolower(trim($stage)) !== 'qa' || strtolower(trim($action)) !== 'approve') {
            return;
        }

        $rows = $this->repo->loadRequiredComponentStageStatuses($caseId, 'verifier');
        foreach ($rows as $r) {
            $s = strtolower(trim((string)($r['status'] ?? 'pending')));
            if (!in_array($s, ['approved', 'rejected'], true)) {
                throw new RuntimeException('WF_INVARIANT_QA_REQUIRES_VERIFIER_FINAL');
            }
        }
    }
}
