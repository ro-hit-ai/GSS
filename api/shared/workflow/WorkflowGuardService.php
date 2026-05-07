<?php

final class WorkflowGuardService
{
    public function roleToStage(string $role): string
    {
        $role = strtolower(trim($role));
        if ($role === 'db_verifier') return 'verifier';
        if ($role === 'team_lead') return 'qa';
        return $role;
    }

    public function actionToStatus(string $action): string
    {
        $action = strtolower(trim($action));
        $map = [
            'approve' => 'approved',
            'reject' => 'rejected',
            'hold' => 'hold',
            'insufficient_documents' => 'insufficient_documents',
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
        if (!in_array($role, ['validator', 'verifier', 'db_verifier', 'qa', 'team_lead'], true)) {
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
            'hold' => ['approve', 'reject', 'insufficient_documents'],
            'insufficient_documents' => ['approve', 'hold', 'reject'],
            'approved' => ['hold', 'insufficient_documents', 'reject'],
            'rejected' => ['hold', 'insufficient_documents', 'approve'],
        ];
        $set = $allowed[$fromStatus] ?? $allowed['pending'];
        if (!in_array($action, $set, true)) {
            throw new RuntimeException('WF_INVALID_TRANSITION');
        }
    }

    public function assertStageGate(string $stage, array $componentStageStatuses): void
    {
        $stage = strtolower(trim($stage));
        $prev = '';
        if ($stage === 'validator') $prev = 'candidate';
        if ($stage === 'verifier') $prev = 'validator';
        if ($stage === 'qa') $prev = 'verifier';
        if ($prev === '') return;

        $prevStatus = strtolower(trim((string)($componentStageStatuses[$prev] ?? 'pending')));
        if (!in_array($prevStatus, ['approved', 'completed', 'clear', 'verified'], true)) {
            throw new RuntimeException('WF_PREVIOUS_STAGE_PENDING');
        }
    }
}
