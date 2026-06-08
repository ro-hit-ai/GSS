<?php

require_once __DIR__ . '/WorkflowRepository.php';
require_once __DIR__ . '/WorkflowGuardService.php';
require_once __DIR__ . '/WorkflowProjectionService.php';
require_once __DIR__ . '/WorkflowInvariantService.php';
require_once __DIR__ . '/WorkflowLockService.php';
require_once __DIR__ . '/../workflow_status_semantics.php';
require_once __DIR__ . '/../workflow_stage_config.php';
require_once __DIR__ . '/../workflow_semantics.php';

final class WorkflowTransitionService
{
    private WorkflowRepository $repo;
    private WorkflowGuardService $guard;
    private WorkflowProjectionService $projection;
    private WorkflowInvariantService $invariant;
    private WorkflowLockService $lockService;

    public function __construct(PDO $pdo)
    {
        $this->repo = new WorkflowRepository($pdo);
        $this->guard = new WorkflowGuardService();
        $this->projection = new WorkflowProjectionService($this->repo);
        $this->invariant = new WorkflowInvariantService($this->repo);
        $this->lockService = new WorkflowLockService($this->repo);
    }

    private function isSplitReferenceComponent(string $componentKey): bool
    {
        $componentKey = strtolower(trim($componentKey));
        return $componentKey === 'education_reference' || $componentKey === 'employment_reference';
    }

    private function componentStorageCandidates(string $componentKey): array
    {
        $componentKey = strtolower(trim($componentKey));
        if ($this->isSplitReferenceComponent($componentKey)) {
            return [$componentKey, 'reference'];
        }
        return $componentKey !== '' ? [$componentKey] : [];
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
        $targetStage = strtolower(trim((string)($cmd['target_stage'] ?? '')));
        $group = strtoupper(trim((string)($cmd['group'] ?? '')));
        $effectiveStage = $stage;
        $supervisedReopen = false;
        $downstreamAwareReopen = false;
        $decisionReplacement = false;
        $downstreamAwareDecisionChange = false;

        $this->logEvent('transition_start', [
            'application_id' => $applicationId,
            'case_id' => $caseId,
            'component_key' => $componentKey,
            'actor_role' => $actorRole,
            'action' => $action,
            'stage' => $stage,
            'target_stage' => $targetStage,
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
            if (!$this->repo->pdo()->inTransaction() && function_exists('verifier_case_queue_ensure_table')) {
                verifier_case_queue_ensure_table($this->repo->pdo());
            }
            $this->repo->begin();

            $case = $this->repo->loadCaseForUpdate($caseId, $applicationId);
            if (!$case) {
                throw new RuntimeException('WF_CASE_NOT_FOUND');
            }
            $this->guard->assertRoleAllowed($actorRole);
            $this->guard->assertVersion($expectedVersion, (int)$case['workflow_version']);
            $beforeVersion = (int)$case['workflow_version'];

            $requestedComponentKey = $componentKey;
            $workflowComponentKey = $componentKey;
            $component = null;
            foreach ($this->componentStorageCandidates($componentKey) as $candidateComponentKey) {
                $component = $this->repo->loadComponentForUpdate($caseId, $applicationId, $candidateComponentKey);
                if ($component) {
                    $workflowComponentKey = $candidateComponentKey;
                    break;
                }
            }
            if (!$component) {
                throw new RuntimeException('WF_COMPONENT_NOT_IN_SNAPSHOT');
            }

            $this->guard->assertAssignmentAllowed($component, $actorRole, $actorUserId);

            $currentStatus = $this->repo->loadWorkflowStatusForUpdate($caseId, $workflowComponentKey, $effectiveStage);
            $isDecisionAction = in_array($action, ['approve', 'reject', 'hold', 'insufficient_documents'], true);
            $isReplaceableCheckpoint = $this->guard->isEvaluatedStatus($currentStatus) || wf_is_invalidated_status($currentStatus);
            $decisionReplacement = $isDecisionAction && $isReplaceableCheckpoint;

            if ($decisionReplacement && $reason === '') {
                throw new RuntimeException('WF_DECISION_REPLACEMENT_REASON_REQUIRED');
            }

            if ($action === 'reopen' && $reason === '') {
                throw new RuntimeException('WF_REOPEN_REASON_REQUIRED');
            }

            if ($action === 'reopen' && $targetStage !== '' && $targetStage !== $stage) {
                throw new RuntimeException('WF_SUPERVISORY_REOPEN_FORBIDDEN');
            }

            if ($action === 'reopen' && !$supervisedReopen) {
                $downstreamAwareReopen = $this->repo->hasDownstreamActivity($caseId, $workflowComponentKey, $stage);
            }
            if ($decisionReplacement) {
                $downstreamAwareDecisionChange = $this->repo->hasDownstreamActivity($caseId, $workflowComponentKey, $stage);
            }

            if (!$supervisedReopen && !($action === 'reopen' && $downstreamAwareReopen) && !$decisionReplacement) {
                $lock = $this->lockService->canModifyComponent($caseId, $workflowComponentKey, $actorRole, $action);
                if (empty($lock['allowed'])) {
                    throw new RuntimeException((string)($lock['code'] ?? 'WF_COMPONENT_LOCKED_BY_DOWNSTREAM_ACTIVITY'));
                }
            }

            $reopenableCheckpoint = $this->guard->isEvaluatedStatus($currentStatus) || wf_is_invalidated_status($currentStatus);
            if ($action === 'reopen' && !$reopenableCheckpoint) {
                throw new RuntimeException('WF_REOPEN_NOT_FINALIZED');
            }
            $this->guard->assertAllowedTransition($currentStatus, $action);

            $stages = $this->repo->loadComponentStageStatuses($caseId, $workflowComponentKey);
            if (!isset($stages['candidate'])) {
                $this->repo->upsertWorkflowStatus($caseId, $applicationId, $workflowComponentKey, 'candidate', 'completed', 0, 'candidate');
                $stages['candidate'] = 'completed';
            }
            $this->guard->assertStageGate($effectiveStage, $stages);
            $this->invariant->assertQaApproveGate($stage, $action, $caseId, $workflowComponentKey);

            $newStatus = $this->guard->actionToStatus($action);
            $this->repo->upsertWorkflowStatus($caseId, $applicationId, $workflowComponentKey, $effectiveStage, $newStatus, $actorUserId, $actorRole);
            $this->repo->clearWorkflowInvalidationMetadata($caseId, $applicationId, $workflowComponentKey, $effectiveStage);
            $invalidatedStages = [];
            if ($action === 'reopen') {
                $this->repo->markWorkflowReopened($caseId, $applicationId, $workflowComponentKey, $effectiveStage, $actorUserId, $actorRole, $reason);
                if ($supervisedReopen || $downstreamAwareReopen) {
                    $invalidatedStages = $this->repo->invalidateDownstreamStagesForReopen(
                        $caseId,
                        $applicationId,
                        $workflowComponentKey,
                        $effectiveStage,
                        $actorUserId,
                        $actorRole,
                        $reason
                    );
                }
            } elseif ($decisionReplacement) {
                $this->repo->logWorkflowDecisionChange(
                    $caseId,
                    $applicationId,
                    $workflowComponentKey,
                    $effectiveStage,
                    $actorUserId,
                    $actorRole,
                    $currentStatus,
                    $newStatus,
                    $reason
                );
                if ($downstreamAwareDecisionChange) {
                    $invalidatedStages = $this->repo->invalidateDownstreamStagesForDecisionChange(
                        $caseId,
                        $applicationId,
                        $workflowComponentKey,
                        $effectiveStage,
                        $actorUserId,
                        $actorRole,
                        $reason,
                        $currentStatus,
                        $newStatus
                    );
                }
            } elseif (in_array($action, ['approve', 'reject', 'hold', 'insufficient_documents'], true)) {
                $this->repo->logWorkflowDecisionRecorded(
                    $caseId,
                    $applicationId,
                    $workflowComponentKey,
                    $effectiveStage,
                    $actorUserId,
                    $actorRole,
                    $currentStatus,
                    $newStatus,
                    $reason
                );
            } elseif ($currentStatus === 'reopened' && $this->guard->isEvaluatedStatus($newStatus)) {
                $this->repo->markWorkflowRelocked($caseId, $applicationId, $workflowComponentKey, $effectiveStage, $actorUserId, $actorRole);
            }
            $this->repo->syncComponentStatus($caseId, $applicationId, $workflowComponentKey, $newStatus);

            $stageSummaries = [];
            foreach (wf_stage_keys() as $sk) {
                $rowsDbg = $this->repo->loadRequiredComponentStageStatuses($caseId, $sk);
                $stageSummaries[$sk] = $this->summarizeStageRows($rowsDbg);
            }
            $nextCaseStatus = $this->deriveCaseStatus($caseId);
            $newVersion = ((int)$case['workflow_version']) + 1;
            $this->invariant->assertVersionIncrement((int)$case['workflow_version'], $newVersion);

            $ok = $this->repo->updateCaseStatusAndVersion($caseId, $applicationId, $nextCaseStatus, (int)$case['workflow_version'], $newVersion);
            if (!$ok) {
                throw new RuntimeException('WF_VERSION_CONFLICT');
            }

            $this->projection->syncQueues($caseId, $actorUserId, $workflowComponentKey, $effectiveStage);
            foreach ($invalidatedStages as $inv) {
                $invStage = strtolower(trim((string)($inv['stage'] ?? '')));
                if ($invStage !== '') {
                    $this->projection->syncQueues($caseId, $actorUserId, $workflowComponentKey, $invStage);
                }
            }
            $this->invariant->assertNoUnresolvedOnApproved($nextCaseStatus, $caseId);

            $this->repo->insertTransitionAudit([
                'transition_request_id' => $transitionRequestId,
                'application_id' => $applicationId,
                'case_id' => $caseId,
                'component_key' => $workflowComponentKey,
                'item_key' => $itemKey,
                'stage' => $effectiveStage,
                'action' => $action,
                'from_status' => $currentStatus,
                'to_status' => $newStatus,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'reason' => $reason,
                'expected_workflow_version' => $expectedVersion,
                'resulting_workflow_version' => $newVersion,
            ]);
            foreach ($invalidatedStages as $inv) {
                $invStage = strtolower(trim((string)($inv['stage'] ?? '')));
                if ($invStage === '') {
                    continue;
                }
                $this->repo->insertTransitionAudit([
                    'transition_request_id' => ($transitionRequestId !== '' ? ($transitionRequestId . ':invalidate:' . $invStage) : uniqid('inv-', true)),
                    'application_id' => $applicationId,
                    'case_id' => $caseId,
                    'component_key' => $workflowComponentKey,
                    'item_key' => $itemKey,
                    'stage' => $invStage,
                    'action' => ($decisionReplacement ? 'invalidate_due_to_decision_change' : 'invalidate_due_to_reopen'),
                    'from_status' => (string)($inv['from_status'] ?? 'pending'),
                    'to_status' => (string)($inv['to_status'] ?? ''),
                    'actor_user_id' => $actorUserId,
                    'actor_role' => $actorRole,
                    'reason' => trim((string)($inv['reason'] ?? $reason)),
                    'expected_workflow_version' => $expectedVersion,
                    'resulting_workflow_version' => $newVersion,
                ]);
            }

            $this->repo->commit();

            $this->logDriftCompare($caseId, $applicationId, $workflowComponentKey, $stage, $newStatus, $nextCaseStatus, $group);
            $this->logEvent('transition_commit', [
                'application_id' => $applicationId,
                'case_id' => $caseId,
                'component_key' => $requestedComponentKey,
                'storage_component_key' => $workflowComponentKey,
                'stage' => $effectiveStage,
                'action' => $action,
                'component_status_before' => $currentStatus,
                'component_status_after' => $newStatus,
                'supervised_reopen' => $supervisedReopen ? 1 : 0,
                'downstream_aware_reopen' => $downstreamAwareReopen ? 1 : 0,
                'decision_replacement' => $decisionReplacement ? 1 : 0,
                'downstream_aware_decision_change' => $downstreamAwareDecisionChange ? 1 : 0,
                'invalidated_stages' => $invalidatedStages,
                'stage_summaries' => $stageSummaries,
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
                    'component_key' => $requestedComponentKey,
                    'storage_component_key' => $workflowComponentKey,
                    'stage' => $effectiveStage,
                    'action' => $action,
                    'component_status' => $newStatus,
                    'case_status' => $nextCaseStatus,
                    'workflow_version' => $newVersion,
                    'supervised_reopen' => $supervisedReopen ? 1 : 0,
                    'downstream_aware_reopen' => $downstreamAwareReopen ? 1 : 0,
                    'decision_replacement' => $decisionReplacement ? 1 : 0,
                    'downstream_aware_decision_change' => $downstreamAwareDecisionChange ? 1 : 0,
                    'previous_component_status' => $currentStatus,
                    'invalidated_stages' => $invalidatedStages,
                ],
            ];
        } catch (Throwable $e) {
            $this->repo->rollback();
            $m = $e->getMessage();
            $this->logEvent('transition_rollback', [
                'application_id' => $applicationId,
                'case_id' => $caseId,
                'component_key' => $componentKey,
                'stage' => $effectiveStage,
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
                'WF_COMPONENT_LOCKED_BY_DOWNSTREAM_ACTIVITY' => [409, 'Downstream review is already active. Change the decision with a reason to reconcile this component.'],
                'WF_REOPEN_BLOCKED_BY_DOWNSTREAM_ACTIVITY' => [409, 'Downstream review is already active. Use governed reopen to reconcile this component.'],
                'WF_SUPERVISORY_REOPEN_FORBIDDEN' => [403, 'Reopen is stage-local. Cross-stage reopen is not allowed.'],
                'WF_SUPERVISORY_REOPEN_REQUIRES_DOWNSTREAM_ACTIVITY' => [409, 'Cross-stage reopen requires active downstream review.'],
                'WF_REOPEN_REASON_REQUIRED' => [400, 'Reopen reason is required'],
                'WF_REOPEN_NOT_FINALIZED' => [409, 'Reopen allowed only from finalized or invalidated component status'],
                'WF_DECISION_REPLACEMENT_REASON_REQUIRED' => [400, 'Reason is required when replacing a previous decision'],
            ];
            if (isset($map[$m])) {
                $this->logEvent('transition_failure', ['error_code' => $m, 'message' => $map[$m][1]]);
                return $this->err($map[$m][0], $m, $map[$m][1]);
            }
            $this->logEvent('transition_failure', ['error_code' => 'WF_INTERNAL_ERROR', 'message' => $m]);
            return $this->err(500, 'WF_INTERNAL_ERROR', 'Workflow transition failed');
        }
    }

    public function reconcileCorrectionLifecycle(
        int $caseId,
        string $applicationId,
        string $stage,
        array $componentKeys,
        int $actorUserId,
        string $actorRole,
        string $reason = ''
    ): array {
        $stage = strtolower(trim($stage));
        $applicationId = trim($applicationId);
        $actorRole = strtolower(trim($actorRole));
        $normalizedComponents = [];
        foreach ($componentKeys as $componentKey) {
            $ck = strtolower(trim((string)$componentKey));
            if ($ck !== '') {
                $normalizedComponents[$ck] = true;
            }
        }
        $components = array_values(array_keys($normalizedComponents));
        if ($caseId <= 0 || $applicationId === '' || $stage === '' || !$components) {
            return $this->err(400, 'WF_BAD_REQUEST', 'Missing correction reconciliation input');
        }

        $startedTx = false;
        try {
            if (!$this->repo->pdo()->inTransaction() && function_exists('verifier_case_queue_ensure_table')) {
                verifier_case_queue_ensure_table($this->repo->pdo());
            }
            if (!$this->repo->pdo()->inTransaction()) {
                $this->repo->begin();
                $startedTx = true;
            }

            $case = $this->repo->loadCaseForUpdate($caseId, $applicationId);
            if (!$case) {
                throw new RuntimeException('WF_CASE_NOT_FOUND');
            }

            $beforeVersion = (int)($case['workflow_version'] ?? 0);
            $storageComponents = [];
            foreach ($components as $componentKey) {
                $component = null;
                $storageComponentKey = $componentKey;
                foreach ($this->componentStorageCandidates($componentKey) as $candidateComponentKey) {
                    $component = $this->repo->loadComponentForUpdate($caseId, $applicationId, $candidateComponentKey);
                    if ($component) {
                        $storageComponentKey = $candidateComponentKey;
                        break;
                    }
                }
                if (!$component) {
                    throw new RuntimeException('WF_COMPONENT_NOT_IN_SNAPSHOT');
                }
                $storageComponents[$componentKey] = $storageComponentKey;
                $this->repo->loadWorkflowStatusForUpdate($caseId, $storageComponentKey, $stage);
            }

            $nextCaseStatus = $this->deriveCaseStatus($caseId);
            $newVersion = $beforeVersion + 1;
            $this->invariant->assertVersionIncrement($beforeVersion, $newVersion);
            $ok = $this->repo->updateCaseStatusAndVersion($caseId, $applicationId, $nextCaseStatus, $beforeVersion, $newVersion);
            if (!$ok) {
                throw new RuntimeException('WF_VERSION_CONFLICT');
            }

            foreach ($components as $componentKey) {
                $this->projection->syncQueues($caseId, $actorUserId, $storageComponents[$componentKey] ?? $componentKey, $stage);
            }
            $this->invariant->assertNoUnresolvedOnApproved($nextCaseStatus, $caseId);

            if ($startedTx) {
                $this->repo->commit();
            }

            $this->logEvent('correction_lifecycle_reconcile', [
                'case_id' => $caseId,
                'application_id' => $applicationId,
                'stage' => $stage,
                'components' => $components,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'reason' => $reason,
                'workflow_version_before' => $beforeVersion,
                'workflow_version_after' => $newVersion,
                'case_status' => $nextCaseStatus,
            ]);

            return [
                'ok' => true,
                'http' => 200,
                'code' => 'WF_OK',
                'message' => 'Correction lifecycle reconciled',
                'data' => [
                    'case_id' => $caseId,
                    'application_id' => $applicationId,
                    'stage' => $stage,
                    'components' => $components,
                    'workflow_version' => $newVersion,
                    'case_status' => $nextCaseStatus,
                ],
            ];
        } catch (Throwable $e) {
            if ($startedTx) {
                $this->repo->rollback();
            }
            $m = $e->getMessage();
            $map = [
                'WF_VERSION_CONFLICT' => [409, 'Workflow version conflict. Please refresh and retry.'],
                'WF_CASE_NOT_FOUND' => [404, 'Case not found'],
                'WF_COMPONENT_NOT_IN_SNAPSHOT' => [400, 'Component is not part of case snapshot'],
            ];
            if (isset($map[$m])) {
                return $this->err($map[$m][0], $m, $map[$m][1]);
            }
            return $this->err(500, 'WF_INTERNAL_ERROR', 'Correction lifecycle reconcile failed');
        }
    }

    private function deriveCaseStatus(int $caseId): string
    {
        $stages = wf_stage_keys();
        if (!$stages) return 'PENDING_VALIDATOR';

        $finalStage = wf_final_stage();
        $debugRows = [];
        foreach (array_reverse($stages) as $stageKey) {
            $rows = $this->repo->loadStageParticipantStatuses($caseId, $stageKey);
            if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
                $unresolved = [];
                foreach ($rows as $r) {
                    $s = strtolower(trim((string)($r['status'] ?? 'pending')));
                    if (!wf_is_evaluated_status($s)) {
                        $unresolved[] = (string)($r['component_key'] ?? '');
                    }
                }
                $debugRows[$stageKey] = [
                    'participant_count' => count($rows),
                    'unresolved_components' => $unresolved,
                ];
            }
            $stageSummary = $this->summarizeForwardingEligibility($rows);
            if (!$stageSummary['all_final']) continue;

            if ($stageKey === $finalStage) {
                $next = $stageSummary['all_approved'] ? 'APPROVED' : strtoupper((string)$stageSummary['dominant_terminal']);
                if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
                    $this->logEvent('aggregate_lifecycle_decision', [
                        'case_id' => $caseId,
                        'decision' => $next,
                        'forwarding_eligible' => false,
                        'stage_summary' => $stageSummary,
                        'stage_rows' => $debugRows,
                    ]);
                }
                return $next;
            }

            if ($stageSummary['forwarding_eligible']) {
                $next = wf_next_stage($stageKey);
                if ($next === '') continue;
                $decision = 'PENDING_' . strtoupper($next);
                if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
                    $this->logEvent('aggregate_lifecycle_decision', [
                        'case_id' => $caseId,
                        'decision' => $decision,
                        'forwarding_eligible' => true,
                        'stage_summary' => $stageSummary,
                        'stage_rows' => $debugRows,
                    ]);
                }
                return $decision;
            }

            $decision = strtoupper((string)$stageKey . '_' . (string)$this->forwardingTerminalSuffix((string)$stageSummary['dominant_terminal']));
            if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
                $this->logEvent('aggregate_lifecycle_decision', [
                    'case_id' => $caseId,
                    'decision' => $decision,
                    'forwarding_eligible' => false,
                    'stage_summary' => $stageSummary,
                    'stage_rows' => $debugRows,
                ]);
            }
            return $decision;
        }

        if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
            $this->logEvent('aggregate_lifecycle_decision', [
                'case_id' => $caseId,
                'decision' => 'PENDING_' . strtoupper((string)$stages[0]),
                'stage_rows' => $debugRows,
            ]);
        }
        return 'PENDING_' . strtoupper((string)$stages[0]);
    }

    private function forwardingTerminalSuffix(string $terminal): string
    {
        $t = strtolower(trim($terminal));
        if ($t === 'insufficient_documents') return 'NEED_DOCS';
        if ($t === 'waiting_candidate') return 'CANDIDATE_PENDING';
        return strtoupper($t);
    }

    private function summarizeForwardingEligibility(array $rows): array
    {
        $counts = [
            'approved' => 0,
            'rejected' => 0,
            'hold' => 0,
            'insufficient_documents' => 0,
            'waiting_candidate' => 0,
            'pending_like' => 0,
            'all_final' => true,
            'all_approved' => false,
            'forwarding_eligible' => false,
            'dominant_terminal' => 'completed',
        ];
        if (!$rows) {
            $counts['all_final'] = false;
            return $counts;
        }

        foreach ($rows as $r) {
            $s = strtolower(trim((string)($r['status'] ?? 'pending')));
            if (!wf_is_evaluated_status($s)) {
                $counts['all_final'] = false;
                if ($s === 'waiting_candidate' || $s === 'reopened') {
                    $counts['waiting_candidate']++;
                } else {
                    $counts['pending_like']++;
                }
                continue;
            }
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }

        $total = count($rows);
        $counts['all_approved'] = ($counts['approved'] === $total);
        // Forwarding continuity: non-final stages forward once all participants are finalized,
        // regardless of terminal outcome (approved/rejected/hold/need_docs/etc).
        $counts['forwarding_eligible'] = $counts['all_final'];

        if ($counts['rejected'] > 0) {
            $counts['dominant_terminal'] = 'rejected';
        } elseif ($counts['hold'] > 0) {
            $counts['dominant_terminal'] = 'hold';
        } elseif ($counts['insufficient_documents'] > 0) {
            $counts['dominant_terminal'] = 'insufficient_documents';
        } elseif ($counts['waiting_candidate'] > 0) {
            $counts['dominant_terminal'] = 'waiting_candidate';
        } elseif ($counts['all_approved']) {
            $counts['dominant_terminal'] = 'approved';
        } else {
            $counts['dominant_terminal'] = 'completed';
        }

        return $counts;
    }

    private function assertSupervisoryReopenAllowed(string $actorRole, string $actorStage, string $targetStage): void
    {
        $actorRole = strtolower(trim($actorRole));
        $actorStage = strtolower(trim($actorStage));
        $targetStage = strtolower(trim($targetStage));
        if ($targetStage === '' || $actorStage === '' || $targetStage === $actorStage) {
            throw new RuntimeException('WF_SUPERVISORY_REOPEN_FORBIDDEN');
        }

        $expectedSupervisorStage = wf_next_stage($targetStage);
        if ($expectedSupervisorStage === '' || $expectedSupervisorStage !== $actorStage) {
            throw new RuntimeException('WF_SUPERVISORY_REOPEN_FORBIDDEN');
        }

        $allowedRoles = [];
        if ($targetStage === 'validator') {
            $allowedRoles = ['verifier', 'db_verifier'];
        } elseif ($targetStage === 'verifier') {
            $allowedRoles = ['qa', 'team_lead'];
        }
        if (!in_array($actorRole, $allowedRoles, true)) {
            throw new RuntimeException('WF_SUPERVISORY_REOPEN_FORBIDDEN');
        }
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

        $stageKeys = wf_stage_keys();
        $stage1 = $stageKeys[0] ?? 'validator';
        $stage2 = $stageKeys[1] ?? 'verifier';
        if ($stage === $stage1) {
            $q = $this->repo->loadValidatorQueueState($caseId);
            if ($q) {
                $drift['validator_queue_state'] = $q;
            }
        }
        if ($stage === $stage2) {
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
        foreach (wf_verifier_group_map() as $group => $components) {
            $normalized = array_map(static function ($x) { return strtolower(trim((string)$x)); }, $components);
            if (in_array($k, $normalized, true)) return (string)$group;
        }
        return '';
    }

    private function summarizeStageRows(array $rows): array
    {
        $summary = [
            'required_total' => count($rows),
            'approved' => 0,
            'rejected' => 0,
            'hold' => 0,
            'insufficient_documents' => 0,
            'pending' => 0,
            'in_progress' => 0,
        ];
        foreach ($rows as $r) {
            $s = strtolower(trim((string)($r['status'] ?? 'pending')));
            if (isset($summary[$s])) {
                $summary[$s]++;
            } else {
                $summary['pending']++;
            }
        }
        return $summary;
    }
}
