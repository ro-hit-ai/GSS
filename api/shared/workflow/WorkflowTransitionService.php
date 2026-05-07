<?php

require_once __DIR__ . '/WorkflowRepository.php';
require_once __DIR__ . '/WorkflowGuardService.php';
require_once __DIR__ . '/WorkflowProjectionService.php';
require_once __DIR__ . '/WorkflowInvariantService.php';

final class WorkflowTransitionService
{
    private WorkflowRepository $repo;
    private WorkflowGuardService $guard;
    private WorkflowProjectionService $projection;
    private WorkflowInvariantService $invariant;

    public function __construct(PDO $pdo)
    {
        $this->repo = new WorkflowRepository($pdo);
        $this->guard = new WorkflowGuardService();
        $this->projection = new WorkflowProjectionService($this->repo);
        $this->invariant = new WorkflowInvariantService($this->repo);
    }

    public function applyTransition(array $cmd): array
    {
        $t0 = microtime(true);
        $caseId = (int)($cmd['case_id'] ?? 0);
        $applicationId = (string)($cmd['application_id'] ?? '');
        $componentKey = strtolower(trim((string)($cmd['component_key'] ?? '')));
        $itemKey = strtolower(trim((string)($cmd['item_key'] ?? '')));
        $action = strtolower(trim((string)($cmd['action'] ?? '')));
        $reason = trim((string)($cmd['reason'] ?? ''));
        $expectedVersion = (int)($cmd['expected_workflow_version'] ?? -1);
        $transitionRequestId = trim((string)($cmd['transition_request_id'] ?? ''));
        $actorUserId = (int)($cmd['actor_user_id'] ?? 0);
        $actorRole = strtolower(trim((string)($cmd['actor_role'] ?? '')));
        $stage = $this->guard->roleToStage($actorRole);
        $group = strtoupper(trim((string)($cmd['group'] ?? '')));

        $this->logEvent('transition_start', [
            'application_id' => $applicationId,
            'case_id' => $caseId,
            'component_key' => $componentKey,
            'actor_role' => $actorRole,
            'action' => $action,
            'stage' => $stage,
            'expected_workflow_version' => $expectedVersion,
            'transition_request_id' => $transitionRequestId,
        ]);

        if ($caseId <= 0 || $applicationId === '' || $componentKey === '' || $action === '' || $actorUserId <= 0 || $actorRole === '') {
            return $this->err(400, 'WF_BAD_REQUEST', 'Missing required workflow transition input');
        }
        if ($expectedVersion < 0) {
            return $this->err(409, 'WF_VERSION_CONFLICT', 'expected_workflow_version is required');
        }
        if ($transitionRequestId !== '') {
            $existing = $this->repo->findTransitionByRequestId($applicationId, $caseId, $transitionRequestId);
            if ($existing) {
                $caseNow = $this->repo->loadCaseStatusAndVersion($caseId, $applicationId) ?: [];
                $this->logEvent('transition_idempotent_replay', [
                    'application_id' => $applicationId,
                    'case_id' => $caseId,
                    'transition_request_id' => $transitionRequestId,
                    'existing_transition_id' => (int)($existing['transition_id'] ?? 0),
                ]);
                return [
                    'ok' => true,
                    'http' => 200,
                    'code' => 'WF_IDEMPOTENT_REPLAY',
                    'message' => 'Duplicate transition request replayed safely',
                    'data' => [
                        'application_id' => $applicationId,
                        'case_id' => $caseId,
                        'component_key' => (string)($existing['component_key'] ?? $componentKey),
                        'stage' => (string)($existing['stage'] ?? $stage),
                        'action' => (string)($existing['action'] ?? $action),
                        'component_status' => (string)($existing['to_status'] ?? 'pending'),
                        'case_status' => (string)($caseNow['case_status'] ?? ''),
                        'workflow_version' => (int)($caseNow['workflow_version'] ?? ($existing['resulting_workflow_version'] ?? 0)),
                        'idempotent_replay' => true,
                    ],
                ];
            }
        }

        try {
            $this->repo->begin();

            $case = $this->repo->loadCaseForUpdate($caseId, $applicationId);
            if (!$case) {
                throw new RuntimeException('WF_CASE_NOT_FOUND');
            }
            $this->guard->assertRoleAllowed($actorRole);
            $this->guard->assertVersion($expectedVersion, (int)$case['workflow_version']);
            $beforeVersion = (int)$case['workflow_version'];

            $component = $this->repo->loadComponentForUpdate($caseId, $applicationId, $componentKey);
            if (!$component) {
                throw new RuntimeException('WF_COMPONENT_NOT_IN_SNAPSHOT');
            }

            $this->guard->assertAssignmentAllowed($component, $actorRole, $actorUserId);

            $currentStatus = $this->repo->loadWorkflowStatusForUpdate($caseId, $componentKey, $stage);
            $this->guard->assertAllowedTransition($currentStatus, $action);

            $stages = $this->repo->loadComponentStageStatuses($caseId, $componentKey);
            if (!isset($stages['candidate'])) {
                $this->repo->upsertWorkflowStatus($caseId, $applicationId, $componentKey, 'candidate', 'completed', 0, 'candidate');
                $stages['candidate'] = 'completed';
            }
            $this->guard->assertStageGate($stage, $stages);
            $this->invariant->assertQaApproveGate($stage, $action, $caseId);

            $newStatus = $this->guard->actionToStatus($action);
            $this->repo->upsertWorkflowStatus($caseId, $applicationId, $componentKey, $stage, $newStatus, $actorUserId, $actorRole);
            $this->repo->syncComponentStatus($caseId, $applicationId, $componentKey, $newStatus);

            $nextCaseStatus = $this->deriveCaseStatus($caseId);
            $newVersion = ((int)$case['workflow_version']) + 1;
            $this->invariant->assertVersionIncrement((int)$case['workflow_version'], $newVersion);

            $ok = $this->repo->updateCaseStatusAndVersion($caseId, $applicationId, $nextCaseStatus, (int)$case['workflow_version'], $newVersion);
            if (!$ok) {
                throw new RuntimeException('WF_VERSION_CONFLICT');
            }

            $this->projection->syncQueues($caseId, $actorUserId, $componentKey, $stage);
            $this->invariant->assertNoUnresolvedOnApproved($nextCaseStatus, $caseId);

            $this->repo->insertTransitionAudit([
                'transition_request_id' => $transitionRequestId,
                'application_id' => $applicationId,
                'case_id' => $caseId,
                'component_key' => $componentKey,
                'item_key' => $itemKey,
                'stage' => $stage,
                'action' => $action,
                'from_status' => $currentStatus,
                'to_status' => $newStatus,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'reason' => $reason,
                'expected_workflow_version' => $expectedVersion,
                'resulting_workflow_version' => $newVersion,
            ]);

            $this->repo->commit();

            $this->logDriftCompare($caseId, $applicationId, $componentKey, $stage, $newStatus, $nextCaseStatus, $group);
            $this->logEvent('transition_commit', [
                'application_id' => $applicationId,
                'case_id' => $caseId,
                'component_key' => $componentKey,
                'stage' => $stage,
                'action' => $action,
                'workflow_version_before' => $beforeVersion,
                'workflow_version_after' => $newVersion,
                'case_status' => $nextCaseStatus,
                'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
            ]);

            return [
                'ok' => true,
                'http' => 200,
                'code' => 'WF_OK',
                'message' => 'Transition applied',
                'data' => [
                    'application_id' => $applicationId,
                    'case_id' => $caseId,
                    'component_key' => $componentKey,
                    'stage' => $stage,
                    'action' => $action,
                    'component_status' => $newStatus,
                    'case_status' => $nextCaseStatus,
                    'workflow_version' => $newVersion,
                ],
            ];
        } catch (Throwable $e) {
            $this->repo->rollback();
            $m = $e->getMessage();
            $this->logEvent('transition_rollback', [
                'application_id' => $applicationId,
                'case_id' => $caseId,
                'component_key' => $componentKey,
                'stage' => $stage,
                'action' => $action,
                'error_code' => $m,
                'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
            ]);
            $map = [
                'WF_VERSION_CONFLICT' => [409, 'Workflow version conflict. Please refresh and retry.'],
                'WF_CASE_NOT_FOUND' => [404, 'Case not found'],
                'WF_COMPONENT_NOT_IN_SNAPSHOT' => [400, 'Component is not part of case snapshot'],
                'WF_NOT_ASSIGNED' => [403, 'Not assigned to this component'],
                'WF_FORBIDDEN_ROLE' => [403, 'Forbidden'],
                'WF_PREVIOUS_STAGE_PENDING' => [400, 'Previous stage pending'],
                'WF_INVALID_ACTION' => [400, 'Invalid action'],
                'WF_INVALID_TRANSITION' => [409, 'Action not allowed from current status'],
                'WF_INVARIANT_APPROVED_WITH_UNRESOLVED' => [409, 'Invariant failed: unresolved required component'],
                'WF_INVARIANT_QA_REQUIRES_VERIFIER_FINAL' => [409, 'Invariant failed: verifier stage not final'],
                'WF_INVARIANT_VERSION_INCREMENT' => [500, 'Invariant failed: workflow version increment'],
            ];
            if (isset($map[$m])) {
                $this->logEvent('transition_failure', ['error_code' => $m, 'message' => $map[$m][1]]);
                return $this->err($map[$m][0], $m, $map[$m][1]);
            }
            $this->logEvent('transition_failure', ['error_code' => 'WF_INTERNAL_ERROR', 'message' => $m]);
            return $this->err(500, 'WF_INTERNAL_ERROR', 'Workflow transition failed');
        }
    }

    private function deriveCaseStatus(int $caseId): string
    {
        $qa = $this->repo->loadRequiredComponentStageStatuses($caseId, 'qa');
        if ($this->allFinal($qa)) {
            return $this->allApproved($qa) ? 'APPROVED' : 'REJECTED';
        }

        $ver = $this->repo->loadRequiredComponentStageStatuses($caseId, 'verifier');
        if ($this->allFinal($ver)) {
            return 'PENDING_QA';
        }

        $val = $this->repo->loadRequiredComponentStageStatuses($caseId, 'validator');
        if ($this->allFinal($val)) {
            return 'PENDING_VERIFIER';
        }

        return 'PENDING_VALIDATOR';
    }

    private function allFinal(array $rows): bool
    {
        if (!$rows) return false;
        foreach ($rows as $r) {
            $s = strtolower(trim((string)($r['status'] ?? 'pending')));
            if (!in_array($s, ['approved', 'rejected'], true)) {
                return false;
            }
        }
        return true;
    }

    private function allApproved(array $rows): bool
    {
        if (!$rows) return false;
        foreach ($rows as $r) {
            $s = strtolower(trim((string)($r['status'] ?? 'pending')));
            if ($s !== 'approved') {
                return false;
            }
        }
        return true;
    }

    private function err(int $http, string $code, string $message): array
    {
        return [
            'ok' => false,
            'http' => $http,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function logEvent(string $event, array $data = []): void
    {
        $entry = [
            'ts' => date('c'),
            'event' => $event,
            'data' => $data,
        ];
        @file_put_contents(__DIR__ . '/../../../logs/workflow_transition.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }

    private function logDriftCompare(int $caseId, string $applicationId, string $componentKey, string $stage, string $componentStatus, string $caseStatus, string $group): void
    {
        $drift = [];
        $case = $this->repo->loadCaseStatusAndVersion($caseId, $applicationId) ?: [];
        $actualCaseStatus = strtoupper(trim((string)($case['case_status'] ?? '')));
        if ($actualCaseStatus !== strtoupper(trim($caseStatus))) {
            $drift['case_status_mismatch'] = ['expected' => $caseStatus, 'actual' => $actualCaseStatus];
        }

        $stages = $this->repo->loadComponentStageStatuses($caseId, $componentKey);
        $actualComponentStatus = strtolower(trim((string)($stages[strtolower(trim($stage))] ?? 'pending')));
        if ($actualComponentStatus !== strtolower(trim($componentStatus))) {
            $drift['component_status_mismatch'] = ['expected' => $componentStatus, 'actual' => $actualComponentStatus];
        }

        if ($stage === 'validator') {
            $q = $this->repo->loadValidatorQueueState($caseId);
            if ($q) {
                $drift['validator_queue_state'] = $q;
            }
        }
        if ($stage === 'verifier') {
            $g = $group !== '' ? $group : $this->groupForComponent($componentKey);
            $q = $this->repo->loadVerifierQueueGroupState($caseId, $g);
            if ($q) {
                $drift['verifier_group_queue_state'] = ['group' => $g, 'state' => $q];
            }
        }

        $this->logEvent('legacy_shadow_compare', [
            'application_id' => $applicationId,
            'case_id' => $caseId,
            'component_key' => $componentKey,
            'stage' => $stage,
            'drift' => $drift,
        ]);
    }

    private function groupForComponent(string $componentKey): string
    {
        $k = strtolower(trim($componentKey));
        if (in_array($k, ['basic', 'id', 'contact'], true)) return 'BASIC';
        if (in_array($k, ['education', 'employment', 'reference'], true)) return 'EDUCATION';
        if (in_array($k, ['ecourt', 'socialmedia'], true)) return 'ADDITIONAL';
        return '';
    }
}
