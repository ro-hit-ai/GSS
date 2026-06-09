<?php

require_once __DIR__ . '/../workflow_status_semantics.php';
require_once __DIR__ . '/../workflow_stage_config.php';

final class WorkflowGuardService
{
    public function isEvaluatedStatus(string $status): bool
    {
        return wf_is_evaluated_status($status);
    }

    public function isOperationallyActiveStatus(string $status): bool
    {
        return wf_is_operationally_active_status($status);
    }

    public function isResolvedStatus(string $status): bool
    {
        return wf_is_resolved_status($status);
    }

    public function roleToStage(string $role): string
    {
        return wf_role_to_stage_key($role);
    }

    public function actionToStatus(string $action): string
    {
        $action = strtolower(trim($action));
        $map = [
            'approve' => 'approved',
            'reject' => 'rejected',
            'hold' => 'hold',
            'insufficient_documents' => 'insufficient_documents',
            'reopen' => 'reopened',
        ];
        if (!isset($map[$action])) {
            throw new RuntimeException('WF_INVALID_ACTION');
        }
        return $map[$action];
    }

    public function assertVersion(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException('WF_VERSION_CONFLICT');
        }
    }

    public function assertRoleAllowed(string $role): void
    {
        $role = strtolower(trim($role));
        if (!in_array($role, wf_stage_assigned_roles(), true)) {
            throw new RuntimeException('WF_FORBIDDEN_ROLE');
        }
    }

    public function assertAssignmentAllowed(array $component, string $role, int $userId): void
    {
        $role = strtolower(trim($role));
        if (in_array($role, ['validator', 'qa', 'team_lead'], true)) {
            return;
        }

        $assignedRole = strtolower(trim((string)($component['assigned_role'] ?? '')));
        $assignedUserId = (int)($component['assigned_user_id'] ?? 0);
        if ($assignedRole !== '' && $assignedUserId > 0) {
            if ($assignedRole !== $this->roleToStage($role) || $assignedUserId !== $userId) {
                throw new RuntimeException('WF_NOT_ASSIGNED');
            }
        }
    }

    public function assertAllowedTransition(string $fromStatus, string $action): void
    {
        $fromStatus = strtolower(trim($fromStatus));
        if ($fromStatus === '' || $fromStatus === 'submitted' || $fromStatus === 'in_progress') {
            $fromStatus = 'pending';
        }
        $action = strtolower(trim($action));
        $allowed = [
            'pending' => ['hold', 'insufficient_documents', 'reject', 'approve'],
            'correction_submitted' => ['hold', 'insufficient_documents', 'reject', 'approve'],
            'reopened' => ['hold', 'insufficient_documents', 'reject', 'approve'],
            'waiting_candidate' => ['hold', 'insufficient_documents', 'reject', 'approve'],
            'blocked' => ['hold', 'insufficient_documents', 'reject', 'approve'],
            'hold' => ['approve', 'reject', 'insufficient_documents'],
            'insufficient_documents' => ['approve', 'hold', 'reject', 'insufficient_documents'],
            'approved' => ['hold', 'insufficient_documents', 'reject'],
            'rejected' => ['hold', 'insufficient_documents', 'approve'],
            'completed' => ['hold', 'insufficient_documents', 'reject', 'approve'],
            'clear' => ['hold', 'insufficient_documents', 'reject', 'approve'],
            'verified' => ['hold', 'insufficient_documents', 'reject', 'approve'],
            'invalidated_by_validator_reopen' => ['hold', 'insufficient_documents', 'reject', 'approve'],
            'invalidated_by_verifier_reopen' => ['hold', 'insufficient_documents', 'reject', 'approve'],
        ];
        $set = $allowed[$fromStatus] ?? $allowed['pending'];
        if (!in_array($action, $set, true)) {
            throw new RuntimeException('WF_INVALID_TRANSITION');
        }
    }

    public function assertStageGate(string $stage, array $componentStageStatuses): void
    {
        $stage = strtolower(trim($stage));
        $prev = wf_previous_stage($stage);
        if ($prev === '') return;

        $prevStatus = strtolower(trim((string)($componentStageStatuses[$prev] ?? 'pending')));
        if (!$this->isEvaluatedStatus($prevStatus)) {
            throw new RuntimeException('WF_PREVIOUS_STAGE_PENDING');
        }
    }
}
