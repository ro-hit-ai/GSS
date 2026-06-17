<?php

require_once __DIR__ . '/WorkflowRepository.php';
require_once __DIR__ . '/workflow_stage_config.php';

final class WorkflowLockService
{
    private WorkflowRepository $repo;

    public function __construct(WorkflowRepository $repo)
    {
        $this->repo = $repo;
    }

    public function canModifyComponent(int $caseId, string $componentKey, string $role, string $action = ''): array
    {
        $stage = wf_role_to_stage_key($role);
        $downstream = wf_next_stage($stage);
        $action = strtolower(trim($action));
        $currentStatus = $this->repo->loadWorkflowStatus($caseId, $componentKey, $stage);
        if ($downstream === '') {
            return ['allowed' => true, 'locked_by_stage' => '', 'reason' => 'no_downstream_stage'];
        }

        // Supervised reopen makes the stage editable again even though historical downstream
        // activity still exists. Downstream invalidation is handled inside transition service.
        if ($action !== 'reopen' && $currentStatus === 'reopened') {
            return ['allowed' => true, 'locked_by_stage' => '', 'reason' => 'reopened_editable'];
        }

        $has = $this->repo->hasDownstreamActivity($caseId, $componentKey, $downstream);
        if (!$has) {
            return ['allowed' => true, 'locked_by_stage' => '', 'reason' => 'downstream_not_started'];
        }

        return [
            'allowed' => false,
            'locked_by_stage' => $downstream,
            'reason' => 'downstream_activity_started',
            'code' => ($action === 'reopen')
                ? 'WF_REOPEN_BLOCKED_BY_DOWNSTREAM_ACTIVITY'
                : 'WF_COMPONENT_LOCKED_BY_DOWNSTREAM_ACTIVITY',
        ];
    }
}
