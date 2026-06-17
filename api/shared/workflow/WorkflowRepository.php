<?php

require_once __DIR__ . '/../case_management/case_component_binding.php';
require_once __DIR__ . '/workflow_semantics.php';

final class WorkflowRepository
{
    private PDO $pdo;
    private static bool $reopenColumnsEnsured = false;
    private static bool $workflowStatusCapacityEnsured = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureReopenAuditColumns();
        $this->ensureWorkflowStatusCapacity();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function ensureReopenAuditColumns(): void
    {
        if ($this->pdo->inTransaction()) return;
        if (self::$reopenColumnsEnsured) return;
        self::$reopenColumnsEnsured = true;
        try {
            $st = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
            );
            $ddl = [
                'reopen_reason' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN reopen_reason TEXT NULL AFTER completed_at",
                'reopened_by_user_id' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN reopened_by_user_id BIGINT NULL AFTER reopen_reason",
                'reopened_by_role' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN reopened_by_role VARCHAR(64) NULL AFTER reopened_by_user_id",
                'reopened_at' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN reopened_at DATETIME NULL AFTER reopened_by_role",
                'relocked_by_user_id' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN relocked_by_user_id BIGINT NULL AFTER reopened_at",
                'relocked_by_role' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN relocked_by_role VARCHAR(64) NULL AFTER relocked_by_user_id",
                'relocked_at' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN relocked_at DATETIME NULL AFTER relocked_by_role",
                'invalidation_reason' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN invalidation_reason TEXT NULL AFTER relocked_at",
                'invalidated_by_user_id' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN invalidated_by_user_id BIGINT NULL AFTER invalidation_reason",
                'invalidated_by_role' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN invalidated_by_role VARCHAR(64) NULL AFTER invalidated_by_user_id",
                'invalidated_source_stage' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN invalidated_source_stage VARCHAR(64) NULL AFTER invalidated_by_role",
                'invalidated_at' => "ALTER TABLE Vati_Payfiller_Case_Component_Workflow ADD COLUMN invalidated_at DATETIME NULL AFTER invalidated_source_stage",
            ];
            foreach ($ddl as $col => $sql) {
                try {
                    $st->execute(['Vati_Payfiller_Case_Component_Workflow', $col]);
                    if (!$st->fetchColumn()) {
                        $this->pdo->exec($sql);
                    }
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
        }
    }

    private function ensureWorkflowStatusCapacity(): void
    {
        if ($this->pdo->inTransaction()) return;
        if (self::$workflowStatusCapacityEnsured) return;
        self::$workflowStatusCapacityEnsured = true;
        try {
            $st = $this->pdo->prepare(
                'SELECT CHARACTER_MAXIMUM_LENGTH
                   FROM information_schema.columns
                  WHERE table_schema = DATABASE()
                    AND table_name = ?
                    AND column_name = ?
                  LIMIT 1'
            );
            $st->execute(['Vati_Payfiller_Case_Component_Workflow', 'status']);
            $len = (int)($st->fetchColumn() ?: 0);
            if ($len > 0 && $len < 64) {
                $this->pdo->exec(
                    "ALTER TABLE Vati_Payfiller_Case_Component_Workflow
                     MODIFY COLUMN status VARCHAR(64) NOT NULL"
                );
            }
        } catch (Throwable $e) {
        }
    }

    public function begin(): void { if (!$this->pdo->inTransaction()) $this->pdo->beginTransaction(); }
    public function commit(): void { if ($this->pdo->inTransaction()) $this->pdo->commit(); }
    public function rollback(): void { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }

    public function loadCaseForUpdate(int $caseId, string $applicationId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT case_id, application_id, case_status, workflow_version, selected_stage, selected_level '
            . 'FROM Vati_Payfiller_Cases WHERE case_id = ? AND application_id = ? LIMIT 1 FOR UPDATE'
        );
        $st->execute([$caseId, $applicationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function loadComponentForUpdate(int $caseId, string $applicationId, string $componentKey): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT case_component_id, case_id, application_id, component_key, is_required, assigned_role, assigned_user_id, status '
            . 'FROM Vati_Payfiller_Case_Components WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ? LIMIT 1 FOR UPDATE'
        );
        $st->execute([$caseId, $applicationId, strtolower(trim($componentKey))]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function loadWorkflowStatusForUpdate(int $caseId, string $componentKey, string $stage): string
    {
        $st = $this->pdo->prepare(
            'SELECT status FROM Vati_Payfiller_Case_Component_Workflow '
            . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ? LIMIT 1 FOR UPDATE'
        );
        $st->execute([$caseId, strtolower(trim($componentKey)), strtolower(trim($stage))]);
        $s = (string)($st->fetchColumn() ?: 'pending');
        return strtolower(trim($s)) ?: 'pending';
    }

    public function loadWorkflowStatus(int $caseId, string $componentKey, string $stage): string
    {
        $st = $this->pdo->prepare(
            'SELECT status FROM Vati_Payfiller_Case_Component_Workflow '
            . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ? LIMIT 1'
        );
        $st->execute([$caseId, strtolower(trim($componentKey)), strtolower(trim($stage))]);
        $s = (string)($st->fetchColumn() ?: 'pending');
        return strtolower(trim($s)) ?: 'pending';
    }

    public function upsertWorkflowStatus(int $caseId, string $applicationId, string $componentKey, string $stage, string $status, int $userId, string $role): void
    {
        $completedAt = in_array($status, ['approved', 'rejected', 'hold', 'insufficient_documents', 'completed', 'clear', 'verified'], true) ? 'NOW()' : 'NULL';
        $sql = 'INSERT INTO Vati_Payfiller_Case_Component_Workflow '
            . '(case_id, application_id, component_key, stage, status, updated_by_user_id, updated_by_role, completed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ' . $completedAt . ') '
            . 'ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by_user_id = VALUES(updated_by_user_id), '
            . 'updated_by_role = VALUES(updated_by_role), completed_at = ' . $completedAt . ', updated_at = NOW()';
        $st = $this->pdo->prepare($sql);
        $st->execute([$caseId, $applicationId, strtolower(trim($componentKey)), strtolower(trim($stage)), $status, $userId, strtolower(trim($role))]);
    }

    public function clearWorkflowInvalidationMetadata(int $caseId, string $applicationId, string $componentKey, string $stage): void
    {
        $st = $this->pdo->prepare(
            'UPDATE Vati_Payfiller_Case_Component_Workflow
                SET invalidation_reason = NULL,
                    invalidated_by_user_id = NULL,
                    invalidated_by_role = NULL,
                    invalidated_source_stage = NULL,
                    invalidated_at = NULL,
                    updated_at = NOW()
              WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ?'
        );
        $st->execute([
            $caseId,
            $applicationId,
            strtolower(trim($componentKey)),
            strtolower(trim($stage)),
        ]);
    }

    public function markWorkflowReopened(int $caseId, string $applicationId, string $componentKey, string $stage, int $actorUserId, string $actorRole, string $reason): void
    {
        $st = $this->pdo->prepare(
            'UPDATE Vati_Payfiller_Case_Component_Workflow
                SET reopen_reason = ?,
                    reopened_by_user_id = ?,
                    reopened_by_role = ?,
                    reopened_at = NOW(),
                    relocked_by_user_id = NULL,
                    relocked_by_role = NULL,
                    relocked_at = NULL,
                    invalidation_reason = NULL,
                    invalidated_by_user_id = NULL,
                    invalidated_by_role = NULL,
                    invalidated_source_stage = NULL,
                    invalidated_at = NULL,
                    updated_at = NOW()
              WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ?'
        );
        $st->execute([
            trim($reason) !== '' ? trim($reason) : null,
            $actorUserId > 0 ? $actorUserId : null,
            strtolower(trim($actorRole)) !== '' ? strtolower(trim($actorRole)) : null,
            $caseId,
            $applicationId,
            strtolower(trim($componentKey)),
            strtolower(trim($stage)),
        ]);

        try {
            $tl = $this->pdo->prepare(
                'INSERT INTO Vati_Payfiller_Case_Timeline
                 (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $tl->execute([
                $applicationId,
                $actorUserId > 0 ? $actorUserId : null,
                strtolower(trim($actorRole)) !== '' ? strtolower(trim($actorRole)) : null,
                'workflow.reopen',
                strtolower(trim($componentKey)),
                'Component reopened' . (trim($reason) !== '' ? (' | reason: ' . trim($reason)) : ''),
            ]);
        } catch (Throwable $e) {
            // keep workflow mutation non-blocking if timeline write fails
        }
    }

    public function markWorkflowRelocked(int $caseId, string $applicationId, string $componentKey, string $stage, int $actorUserId, string $actorRole): void
    {
        $st = $this->pdo->prepare(
            'UPDATE Vati_Payfiller_Case_Component_Workflow
                SET relocked_by_user_id = ?,
                    relocked_by_role = ?,
                    relocked_at = NOW(),
                    updated_at = NOW()
              WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ?'
        );
        $st->execute([
            $actorUserId > 0 ? $actorUserId : null,
            strtolower(trim($actorRole)) !== '' ? strtolower(trim($actorRole)) : null,
            $caseId,
            $applicationId,
            strtolower(trim($componentKey)),
            strtolower(trim($stage)),
        ]);

        try {
            $tl = $this->pdo->prepare(
                'INSERT INTO Vati_Payfiller_Case_Timeline
                 (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $tl->execute([
                $applicationId,
                $actorUserId > 0 ? $actorUserId : null,
                strtolower(trim($actorRole)) !== '' ? strtolower(trim($actorRole)) : null,
                'workflow.relock',
                strtolower(trim($componentKey)),
                'Component relocked',
            ]);
        } catch (Throwable $e) {
            // keep workflow mutation non-blocking if timeline write fails
        }
    }

    public function logWorkflowDecisionChange(
        int $caseId,
        string $applicationId,
        string $componentKey,
        string $stage,
        int $actorUserId,
        string $actorRole,
        string $fromStatus,
        string $toStatus,
        string $reason
    ): void {
        try {
            $tl = $this->pdo->prepare(
                'INSERT INTO Vati_Payfiller_Case_Timeline
                 (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $msg = strtoupper(trim($stage)) . ' decision changed: '
                . strtoupper(trim($fromStatus)) . ' -> ' . strtoupper(trim($toStatus))
                . (trim($reason) !== '' ? (' | reason: ' . trim($reason)) : '');
            $tl->execute([
                $applicationId,
                $actorUserId > 0 ? $actorUserId : null,
                strtolower(trim($actorRole)) !== '' ? strtolower(trim($actorRole)) : null,
                'workflow.decision_change',
                strtolower(trim($componentKey)),
                $msg,
            ]);
        } catch (Throwable $e) {
        }
    }

    public function logWorkflowDecisionRecorded(
        int $caseId,
        string $applicationId,
        string $componentKey,
        string $stage,
        int $actorUserId,
        string $actorRole,
        string $fromStatus,
        string $toStatus,
        string $reason
    ): void {
        try {
            $tl = $this->pdo->prepare(
                'INSERT INTO Vati_Payfiller_Case_Timeline
                 (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $msg = strtoupper(trim($stage)) . ' decision recorded: '
                . strtoupper(trim($fromStatus)) . ' -> ' . strtoupper(trim($toStatus))
                . (trim($reason) !== '' ? (' | reason: ' . trim($reason)) : '');
            $tl->execute([
                $applicationId,
                $actorUserId > 0 ? $actorUserId : null,
                strtolower(trim($actorRole)) !== '' ? strtolower(trim($actorRole)) : null,
                'workflow.decision',
                strtolower(trim($componentKey)),
                $msg,
            ]);
        } catch (Throwable $e) {
            // keep workflow mutation non-blocking if timeline write fails
        }
    }

    public function invalidateDownstreamStagesForDecisionChange(
        int $caseId,
        string $applicationId,
        string $componentKey,
        string $sourceStage,
        int $actorUserId,
        string $actorRole,
        string $reason,
        string $fromStatus,
        string $toStatus
    ): array {
        $sourceStage = strtolower(trim($sourceStage));
        $componentKey = strtolower(trim($componentKey));
        $laterStages = [];
        $cursor = wf_next_stage($sourceStage);
        while ($cursor !== '') {
            $laterStages[] = $cursor;
            $cursor = wf_next_stage($cursor);
        }
        if (!$laterStages) {
            return [];
        }

        $invalidatedStatus = ($sourceStage === 'validator')
            ? 'invalidated_by_validator_reopen'
            : 'invalidated_by_verifier_reopen';
        $result = [];

        foreach ($laterStages as $stage) {
            $prevStatus = $this->loadWorkflowStatusForUpdate($caseId, $componentKey, $stage);
            if ($prevStatus === $invalidatedStatus) {
                continue;
            }

            $hasMeaningfulState = $prevStatus !== 'pending'
                || $this->hasDownstreamActivity($caseId, $componentKey, $stage);
            if (!$hasMeaningfulState) {
                continue;
            }

            $this->upsertWorkflowStatus($caseId, $applicationId, $componentKey, $stage, $invalidatedStatus, $actorUserId, $actorRole);
            $st = $this->pdo->prepare(
                'UPDATE Vati_Payfiller_Case_Component_Workflow
                    SET invalidation_reason = ?,
                        invalidated_by_user_id = ?,
                        invalidated_by_role = ?,
                        invalidated_source_stage = ?,
                        invalidated_at = NOW(),
                        completed_at = NULL,
                        updated_at = NOW()
                  WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ?'
            );
            $st->execute([
                trim($reason) !== '' ? trim($reason) : null,
                $actorUserId > 0 ? $actorUserId : null,
                strtolower(trim($actorRole)) !== '' ? strtolower(trim($actorRole)) : null,
                $sourceStage,
                $caseId,
                $applicationId,
                $componentKey,
                $stage,
            ]);

            try {
                $tl = $this->pdo->prepare(
                    'INSERT INTO Vati_Payfiller_Case_Timeline
                     (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                );
                $msg = strtoupper($stage) . ' decision invalidated after ' . strtoupper($sourceStage) . ' decision change: '
                    . strtoupper(trim($fromStatus)) . ' -> ' . strtoupper(trim($toStatus))
                    . (trim($reason) !== '' ? (' | reason: ' . trim($reason)) : '');
                $tl->execute([
                    $applicationId,
                    $actorUserId > 0 ? $actorUserId : null,
                    strtolower(trim($actorRole)) !== '' ? strtolower(trim($actorRole)) : null,
                    'workflow.invalidation',
                    $componentKey,
                    $msg,
                ]);
            } catch (Throwable $e) {
            }

            $result[] = [
                'stage' => $stage,
                'from_status' => $prevStatus,
                'to_status' => $invalidatedStatus,
                'reason' => trim($reason),
            ];
        }

        return $result;
    }

    public function invalidateDownstreamStagesForReopen(
        int $caseId,
        string $applicationId,
        string $componentKey,
        string $sourceStage,
        int $actorUserId,
        string $actorRole,
        string $reason
    ): array {
        $sourceStage = strtolower(trim($sourceStage));
        $componentKey = strtolower(trim($componentKey));
        $laterStages = [];
        $cursor = wf_next_stage($sourceStage);
        while ($cursor !== '') {
            $laterStages[] = $cursor;
            $cursor = wf_next_stage($cursor);
        }
        if (!$laterStages) {
            return [];
        }

        $invalidatedStatus = ($sourceStage === 'validator')
            ? 'invalidated_by_validator_reopen'
            : 'invalidated_by_verifier_reopen';
        $result = [];

        foreach ($laterStages as $stage) {
            $prevStatus = $this->loadWorkflowStatusForUpdate($caseId, $componentKey, $stage);
            if ($prevStatus === $invalidatedStatus) {
                continue;
            }

            $hasMeaningfulState = $prevStatus !== 'pending'
                || $this->hasDownstreamActivity($caseId, $componentKey, $stage);
            if (!$hasMeaningfulState) {
                continue;
            }

            $this->upsertWorkflowStatus($caseId, $applicationId, $componentKey, $stage, $invalidatedStatus, $actorUserId, $actorRole);
            $st = $this->pdo->prepare(
                'UPDATE Vati_Payfiller_Case_Component_Workflow
                    SET invalidation_reason = ?,
                        invalidated_by_user_id = ?,
                        invalidated_by_role = ?,
                        invalidated_source_stage = ?,
                        invalidated_at = NOW(),
                        completed_at = NULL,
                        updated_at = NOW()
                  WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ? AND stage = ?'
            );
            $st->execute([
                trim($reason) !== '' ? trim($reason) : null,
                $actorUserId > 0 ? $actorUserId : null,
                strtolower(trim($actorRole)) !== '' ? strtolower(trim($actorRole)) : null,
                $sourceStage,
                $caseId,
                $applicationId,
                $componentKey,
                $stage,
            ]);

            try {
                $tl = $this->pdo->prepare(
                    'INSERT INTO Vati_Payfiller_Case_Timeline
                     (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                );
                $tl->execute([
                    $applicationId,
                    $actorUserId > 0 ? $actorUserId : null,
                    strtolower(trim($actorRole)) !== '' ? strtolower(trim($actorRole)) : null,
                    'workflow.invalidation',
                    $componentKey,
                    strtoupper($stage) . ' work invalidated by ' . strtoupper($sourceStage) . ' reopen' . (trim($reason) !== '' ? (' | reason: ' . trim($reason)) : ''),
                ]);
            } catch (Throwable $e) {
            }

            $result[] = [
                'stage' => $stage,
                'from_status' => $prevStatus,
                'to_status' => $invalidatedStatus,
                'reason' => trim($reason),
            ];
        }

        return $result;
    }

    public function syncComponentStatus(int $caseId, string $applicationId, string $componentKey, string $status): void
    {
        $st = $this->pdo->prepare(
            'UPDATE Vati_Payfiller_Case_Components SET status = ?, updated_at = NOW() '
            . 'WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ?'
        );
        $st->execute([$status, $caseId, $applicationId, strtolower(trim($componentKey))]);
    }

    public function loadRequiredComponentStageStatuses(int $caseId, string $stage, bool $includeReports = false): array
    {
        $sql =
            'SELECT LOWER(TRIM(c.component_key)) AS component_key, COALESCE(LOWER(TRIM(w.status)),\'pending\') AS status '
            . 'FROM Vati_Payfiller_Case_Components c '
            . 'LEFT JOIN Vati_Payfiller_Case_Component_Workflow w '
            . 'ON w.case_id = c.case_id AND LOWER(TRIM(w.component_key)) = LOWER(TRIM(c.component_key)) AND w.stage = ? '
            . 'WHERE c.case_id = ? AND c.is_required = 1 ';
        if (!$includeReports) {
            $sql .= 'AND LOWER(TRIM(c.component_key)) <> \'reports\'';
        }
        $st = $this->pdo->prepare($sql);
        $st->execute([strtolower(trim($stage)), $caseId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Stage-participation aware status rows.
     * Uses canonical case component-role bindings when available so aggregate lifecycle
     * progression reflects actual stage participants, not theoretical required components.
     */
    public function loadStageParticipantStatuses(int $caseId, string $stage, bool $includeReports = false): array
    {
        $stage = strtolower(trim($stage));
        $rows = $this->loadRequiredComponentStageStatuses($caseId, $stage, $includeReports);
        if (!$rows) return [];

        try {
            $cfg = case_component_binding_build_for_case($this->pdo, $caseId, '');
            $requiredSet = [];
            foreach ((array)($cfg['required_components'] ?? []) as $c) {
                $k = strtolower(trim((string)$c));
                if ($k !== '') $requiredSet[$k] = true;
            }
            $rolesByComponent = (array)($cfg['component_roles'] ?? []);
            $hasRoleBinding = !empty($cfg['has_role_binding']);

            $out = [];
            foreach ($rows as $r) {
                $ck = strtolower(trim((string)($r['component_key'] ?? '')));
                if ($ck === '') continue;
                if (!$includeReports && $ck === 'reports') continue;
                if ($requiredSet && !isset($requiredSet[$ck])) continue;

                // If canonical role binding exists, include only components participating
                // in this stage.
                if ($hasRoleBinding) {
                    $roles = (array)($rolesByComponent[$ck] ?? []);
                    $participates = false;
                    if ($stage === 'validator') {
                        $participates = isset($roles['validator']);
                    } elseif ($stage === 'verifier') {
                        $participates = isset($roles['verifier']) || isset($roles['db_verifier']);
                    } elseif ($stage === 'qa') {
                        $participates = isset($roles['qa']) || isset($roles['team_lead']);
                    }
                    if (!$participates) continue;
                }

                $out[] = $r;
            }

            if ($stage === 'verifier') {
                $out = $this->scopeVerifierParticipantRowsByOperationalAuthority($caseId, $out, $requiredSet, $rolesByComponent, $hasRoleBinding);
            }

            if ($out) return $out;
        } catch (Throwable $e) {
            // fallback to legacy required-components behavior
        }

        return $rows;
    }

    /**
     * Canonical verifier participant authority:
     * queue-seeded/assigned operational scope (group membership + required + verifier role + assigned verifier allowed_sections).
     * This keeps lifecycle and queue closure participant sets consistent.
     */
    private function scopeVerifierParticipantRowsByOperationalAuthority(
        int $caseId,
        array $rows,
        array $requiredSet,
        array $rolesByComponent,
        bool $hasRoleBinding
    ): array {
        $rowsByComponent = [];
        foreach ($rows as $r) {
            $ck = strtolower(trim((string)($r['component_key'] ?? '')));
            if ($ck === '') continue;
            $rowsByComponent[$ck] = $r;
        }
        if (!$rowsByComponent) return [];

        $seededGroups = $this->loadVerifierQueueGroupsForCase($caseId);
        if (!$seededGroups) {
            // No verifier queue seeded yet: keep legacy participant visibility.
            return array_values($rowsByComponent);
        }

        $allowedByGroup = [];
        $scopedSet = [];
        foreach ($seededGroups as $groupKey) {
            $g = strtoupper(trim((string)$groupKey));
            if ($g === '') continue;
            $staticParts = wf_verifier_group_components($g);
            $groupAllowed = [];

            $assignedUserId = $this->loadVerifierGroupAssignedUserId($caseId, $g);
            $allowedSections = [];
            try {
                $allowedSections = $this->loadUserAllowedSectionsMap($assignedUserId);
            } catch (Throwable $e) {
                $allowedSections = [];
            }

            foreach ((array)$staticParts as $part) {
                $ck = strtolower(trim((string)$part));
                if ($ck === '') continue;
                if ($requiredSet && !isset($requiredSet[$ck])) continue;

                if ($hasRoleBinding) {
                    $roles = (array)($rolesByComponent[$ck] ?? []);
                    $hasVerifierRole = isset($roles['verifier']) || isset($roles['db_verifier']);
                    if (!$hasVerifierRole) continue;
                }

                if ($allowedSections && !isset($allowedSections['*']) && !isset($allowedSections[$ck])) {
                    continue;
                }
                $groupAllowed[$ck] = true;
                $scopedSet[$ck] = true;
            }

            $allowedByGroup[$g] = array_values(array_keys($groupAllowed));
        }

        $out = [];
        foreach (array_keys($scopedSet) as $ck) {
            if (isset($rowsByComponent[$ck])) {
                $out[] = $rowsByComponent[$ck];
            }
        }

        if ((string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
            @file_put_contents(
                __DIR__ . '/../../../logs/workflow_transition.log',
                json_encode([
                    'ts' => date('c'),
                    'event' => 'verifier_lifecycle_participants_scoped',
                    'case_id' => $caseId,
                    'seeded_groups' => array_values($seededGroups),
                    'allowed_by_group' => $allowedByGroup,
                    'lifecycle_participant_components' => array_values(array_map(static function ($r) {
                        return strtolower(trim((string)($r['component_key'] ?? '')));
                    }, $out)),
                    'raw_verifier_components' => array_values(array_keys($rowsByComponent)),
                    'required_components' => array_values(array_keys($requiredSet)),
                ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND
            );
        }

        // If scoped set is empty unexpectedly, keep legacy to avoid false terminal progression.
        return $out ?: array_values($rowsByComponent);
    }

    public function loadComponentStageStatuses(int $caseId, string $componentKey): array
    {
        $st = $this->pdo->prepare(
            'SELECT stage, LOWER(TRIM(status)) AS status FROM Vati_Payfiller_Case_Component_Workflow '
            . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) = ?'
        );
        $st->execute([$caseId, strtolower(trim($componentKey))]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[strtolower(trim((string)$r['stage']))] = strtolower(trim((string)$r['status']));
        }
        return $out;
    }

    public function loadStageStatusesForComponents(int $caseId, array $componentKeys): array
    {
        $keys = [];
        foreach ($componentKeys as $k) {
            $nk = strtolower(trim((string)$k));
            if ($nk !== '') $keys[$nk] = true;
        }
        $keys = array_keys($keys);
        if (!$keys) return [];

        $ph = implode(',', array_fill(0, count($keys), '?'));
        $sql = 'SELECT LOWER(TRIM(component_key)) AS component_key, stage, LOWER(TRIM(status)) AS status '
            . 'FROM Vati_Payfiller_Case_Component_Workflow '
            . 'WHERE case_id = ? AND LOWER(TRIM(component_key)) IN (' . $ph . ')';
        $params = array_merge([$caseId], $keys);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $ck = strtolower(trim((string)($r['component_key'] ?? '')));
            $sg = strtolower(trim((string)($r['stage'] ?? '')));
            if ($ck === '' || $sg === '') continue;
            if (!isset($out[$ck])) $out[$ck] = [];
            $out[$ck][$sg] = strtolower(trim((string)($r['status'] ?? '')));
        }
        return $out;
    }

    public function updateCaseStatusAndVersion(int $caseId, string $applicationId, string $caseStatus, int $expectedVersion, int $newVersion): bool
    {
        $st = $this->pdo->prepare(
            'UPDATE Vati_Payfiller_Cases SET case_status = ?, workflow_version = ?, updated_at = NOW() '
            . 'WHERE case_id = ? AND application_id = ? AND workflow_version = ?'
        );
        $st->execute([$caseStatus, $newVersion, $caseId, $applicationId, $expectedVersion]);
        return $st->rowCount() === 1;
    }

    public function insertTransitionAudit(array $a): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO Vati_Payfiller_Workflow_Transitions '
            . '(transition_request_id, application_id, case_id, component_key, item_key, stage, action, from_status, to_status, actor_user_id, actor_role, reason, workflow_version_before, workflow_version_after, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $st->execute([
            (string)($a['transition_request_id'] ?? ''),
            $a['application_id'],
            (int)$a['case_id'],
            $a['component_key'],
            (string)($a['item_key'] ?? ''),
            $a['stage'],
            $a['action'],
            $a['from_status'],
            $a['to_status'],
            (int)$a['actor_user_id'],
            $a['actor_role'],
            $a['reason'],
            (int)$a['expected_workflow_version'],
            (int)$a['resulting_workflow_version'],
        ]);
    }

    public function markValidatorQueue(int $caseId, int $userId, bool $done): void
    {
        if ($done) {
            $st = $this->pdo->prepare("UPDATE Vati_Payfiller_Validator_Queue SET status='done', completed_at=COALESCE(completed_at,NOW()), assigned_user_id=COALESCE(assigned_user_id,?), claimed_at=COALESCE(claimed_at,NOW()) WHERE case_id=? AND completed_at IS NULL");
            $st->execute([$userId, $caseId]);
        } else {
            $st = $this->pdo->prepare("UPDATE Vati_Payfiller_Validator_Queue SET status = CASE WHEN COALESCE(LOWER(TRIM(status)),'')='followup' THEN status ELSE 'in_progress' END, assigned_user_id=COALESCE(assigned_user_id,?), claimed_at=COALESCE(claimed_at,NOW()) WHERE case_id=? AND completed_at IS NULL");
            $st->execute([$userId, $caseId]);
        }
    }

    public function markVerifierGroupDone(int $caseId, int $userId, string $groupKey, bool $done): void
    {
        if ($groupKey === '') return;
        if ($done) {
            $st = $this->pdo->prepare("UPDATE Vati_Payfiller_Verifier_Group_Queue SET status='done', completed_at=COALESCE(completed_at,NOW()), assigned_user_id=COALESCE(assigned_user_id,?), claimed_at=COALESCE(claimed_at,NOW()) WHERE case_id=? AND UPPER(TRIM(group_key))=? AND completed_at IS NULL");
            $st->execute([$userId, $caseId, strtoupper($groupKey)]);
        } else {
            $st = $this->pdo->prepare("UPDATE Vati_Payfiller_Verifier_Group_Queue SET status = CASE WHEN COALESCE(LOWER(TRIM(status)),'')='followup' THEN status ELSE 'in_progress' END, assigned_user_id=COALESCE(assigned_user_id,?), claimed_at=COALESCE(claimed_at,NOW()) WHERE case_id=? AND UPPER(TRIM(group_key))=?");
            $st->execute([$userId, $caseId, strtoupper($groupKey)]);
        }
    }

    public function setValidatorQueueOperationalState(int $caseId, int $userId, string $operationalState): void
    {
        $before = $this->loadValidatorQueueState($caseId);
        $op = strtolower(trim($operationalState));
        if ($op === 'completed') {
            $st = $this->pdo->prepare(
                "UPDATE Vati_Payfiller_Validator_Queue
                 SET status='done',
                     completed_at=COALESCE(completed_at,NOW()),
                     assigned_user_id=?,
                     claimed_at=COALESCE(claimed_at,NOW())
                 WHERE case_id=? AND completed_at IS NULL"
            );
            $st->execute([$userId, $caseId]);
            $after = $this->loadValidatorQueueState($caseId);
            $this->traceValidatorQueueWrite($caseId, $before, $after, 'WorkflowRepository::setValidatorQueueOperationalState', 'completed');
            return;
        }

        $queueStatus = ($op === 'waiting_candidate') ? 'waiting_candidate' : (($op === 'blocked') ? 'blocked' : 'in_progress');
        $st = $this->pdo->prepare(
            "UPDATE Vati_Payfiller_Validator_Queue
             SET status=?,
                 completed_at=NULL,
                 assigned_user_id=?,
                 claimed_at=COALESCE(claimed_at,NOW())
             WHERE case_id=?"
        );
        $st->execute([$queueStatus, $userId, $caseId]);
        $after = $this->loadValidatorQueueState($caseId);
        $this->traceValidatorQueueWrite($caseId, $before, $after, 'WorkflowRepository::setValidatorQueueOperationalState', $op);
    }

    private function traceValidatorQueueWrite(int $caseId, ?array $before, ?array $after, string $writerSource, string $transitionReason): void
    {
        if ((string)getenv('WF_PERF_DEBUG_LOGS') !== '1') {
            return;
        }
        $entry = [
            'ts' => date('c'),
            'case_id' => $caseId,
            'writer_source' => $writerSource,
            'transition_reason' => $transitionReason,
            'old_status' => (string)($before['status'] ?? ''),
            'new_status' => (string)($after['status'] ?? ''),
            'completed_at_before' => $before['completed_at'] ?? null,
            'completed_at_after' => $after['completed_at'] ?? null,
            'service' => 'workflow_projection_owner',
        ];
        @file_put_contents(__DIR__ . '/../../../logs/validator_queue_debug.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }

    public function setVerifierGroupOperationalState(int $caseId, int $userId, string $groupKey, string $operationalState): void
    {
        if ($groupKey === '') return;
        $op = strtolower(trim($operationalState));
        if ($op === 'completed') {
            $st = $this->pdo->prepare(
                "UPDATE Vati_Payfiller_Verifier_Group_Queue
                 SET status='done',
                     completed_at=COALESCE(completed_at,NOW()),
                     assigned_user_id=COALESCE(assigned_user_id,?),
                     claimed_at=COALESCE(claimed_at,NOW())
                 WHERE case_id=? AND UPPER(TRIM(group_key))=? AND completed_at IS NULL"
            );
            $st->execute([$userId, $caseId, strtoupper($groupKey)]);
            return;
        }

        $queueStatus = ($op === 'waiting_candidate') ? 'waiting_candidate' : (($op === 'blocked') ? 'blocked' : 'in_progress');
        $st = $this->pdo->prepare(
            "UPDATE Vati_Payfiller_Verifier_Group_Queue
             SET status=?,
                 completed_at=NULL,
                 assigned_user_id=COALESCE(assigned_user_id,?),
                 claimed_at=COALESCE(claimed_at,NOW())
             WHERE case_id=? AND UPPER(TRIM(group_key))=?"
        );
        $st->execute([$queueStatus, $userId, $caseId, strtoupper($groupKey)]);
    }

    public function findTransitionByRequestId(string $applicationId, int $caseId, string $requestId): ?array
    {
        if ($requestId === '') return null;
        $st = $this->pdo->prepare(
            'SELECT transition_id, application_id, case_id, component_key, stage, action, to_status, workflow_version_after AS resulting_workflow_version, created_at '
            . 'FROM Vati_Payfiller_Workflow_Transitions '
            . 'WHERE application_id = ? AND case_id = ? AND transition_request_id = ? '
            . 'ORDER BY transition_id DESC LIMIT 1'
        );
        $st->execute([$applicationId, $caseId, $requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function loadCaseStatusAndVersion(int $caseId, string $applicationId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT case_status, workflow_version FROM Vati_Payfiller_Cases WHERE case_id = ? AND application_id = ? LIMIT 1'
        );
        $st->execute([$caseId, $applicationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function loadCaseStatusByCaseId(int $caseId): ?string
    {
        $st = $this->pdo->prepare('SELECT case_status FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $st->execute([$caseId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    public function loadValidatorQueueState(int $caseId): ?array
    {
        $st = $this->pdo->prepare('SELECT status, completed_at FROM Vati_Payfiller_Validator_Queue WHERE case_id = ? LIMIT 1');
        $st->execute([$caseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function loadVerifierQueueGroupState(int $caseId, string $groupKey): ?array
    {
        if ($groupKey === '') return null;
        $st = $this->pdo->prepare(
            'SELECT status, completed_at FROM Vati_Payfiller_Verifier_Group_Queue WHERE case_id = ? AND UPPER(TRIM(group_key)) = ? LIMIT 1'
        );
        $st->execute([$caseId, strtoupper($groupKey)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function loadVerifierQueueGroupsForCase(int $caseId): array
    {
        $st = $this->pdo->prepare(
            'SELECT DISTINCT UPPER(TRIM(group_key)) AS group_key
             FROM Vati_Payfiller_Verifier_Group_Queue
             WHERE case_id = ?'
        );
        $st->execute([$caseId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $g = strtoupper(trim((string)($r['group_key'] ?? '')));
            if ($g !== '') $out[$g] = true;
        }
        return array_values(array_keys($out));
    }

    public function ensureVerifierGroupQueueRow(int $caseId, string $groupKey): void
    {
        $g = strtoupper(trim($groupKey));
        if ($caseId <= 0 || $g === '') return;

        $caseSt = $this->pdo->prepare(
            'SELECT case_id, client_id, application_id
               FROM Vati_Payfiller_Cases
              WHERE case_id = ?
              LIMIT 1'
        );
        $caseSt->execute([$caseId]);
        $case = $caseSt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$case) return;

        $clientId = isset($case['client_id']) ? (int)$case['client_id'] : 0;
        $applicationId = (string)($case['application_id'] ?? '');
        if ($applicationId === '') return;

        $ins = $this->pdo->prepare(
            'INSERT IGNORE INTO Vati_Payfiller_Verifier_Group_Queue
             (case_id, client_id, application_id, group_key, status, assigned_user_id, dedicated_user_id, claimed_at, completed_at)
             VALUES (?, ?, ?, ?, \'pending\', NULL, NULL, NULL, NULL)'
        );
        $ins->execute([$caseId, $clientId > 0 ? $clientId : null, $applicationId, $g]);

        $dedicatedUserId = 0;
        // Apply dedicated assignment rules deterministically (same authority as queue SP).
        try {
            $rule = $this->pdo->prepare(
                "SELECT dedicated_user_id
                   FROM Vati_Payfiller_VR_Assignment_Rules
                  WHERE is_active = 1
                    AND LOWER(TRIM(mode)) = 'dedicated'
                    AND (client_id <=> ?)
                    AND UPPER(TRIM(group_key)) = ?
                    AND dedicated_user_id IS NOT NULL
                  LIMIT 1"
            );
            $rule->execute([$clientId > 0 ? $clientId : null, $g]);
            $dedicatedUserId = (int)($rule->fetchColumn() ?: 0);
            if ($dedicatedUserId > 0) {
                $upd = $this->pdo->prepare(
                    "UPDATE Vati_Payfiller_Verifier_Group_Queue
                     SET dedicated_user_id = ?
                     WHERE case_id = ? AND UPPER(TRIM(group_key)) = ? AND completed_at IS NULL"
                );
                $upd->execute([$dedicatedUserId, $caseId, $g]);
            }
        } catch (Throwable $e) {
            // Keep seeding resilient across envs that may not have assignment-rule table.
        }

    }

    private function pickAutoAssignedVerifierUserId(?int $clientId, string $groupKey): int
    {
        $g = strtoupper(trim($groupKey));
        if ($g === '') return 0;

        $st = $this->pdo->query(
            "SELECT user_id, allowed_sections
              FROM Vati_Payfiller_Users
              WHERE is_active = 1
                AND LOWER(TRIM(role)) = 'verifier'
              ORDER BY user_id ASC"
        );
        $users = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        if (!$users) {
            return 0;
        }

        $eligible = [];
        foreach ($users as $user) {
            $userId = isset($user['user_id']) ? (int)$user['user_id'] : 0;
            if ($userId <= 0) continue;
            $allowed = $this->normalizeAllowedSectionsMap((string)($user['allowed_sections'] ?? ''));
            if (!$this->allowedSectionsCanWorkVerifierGroup($allowed, $g)) {
                continue;
            }
            $eligible[] = $userId;
        }

        if (!$eligible) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($eligible), '?'));
        $groupPlaceholders = implode(',', array_fill(0, count($eligible), '?'));
        $sql =
            "SELECT assigned_user_id AS user_id,
                    COUNT(*) AS total_open_count,
                    SUM(CASE WHEN UPPER(TRIM(group_key)) = ? THEN 1 ELSE 0 END) AS group_open_count
               FROM Vati_Payfiller_Verifier_Group_Queue
              WHERE completed_at IS NULL
                AND assigned_user_id IN ($placeholders)
              GROUP BY assigned_user_id";
        $loadStmt = $this->pdo->prepare($sql);
        $loadStmt->execute(array_merge([$g], $eligible));
        $loads = $loadStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $loadByUser = [];
        foreach ($loads as $row) {
            $uid = isset($row['user_id']) ? (int)$row['user_id'] : 0;
            if ($uid <= 0) continue;
            $loadByUser[$uid] = [
                'group_open_count' => isset($row['group_open_count']) ? (int)$row['group_open_count'] : 0,
                'total_open_count' => isset($row['total_open_count']) ? (int)$row['total_open_count'] : 0,
            ];
        }

        usort($eligible, function (int $a, int $b) use ($loadByUser): int {
            $ga = $loadByUser[$a]['group_open_count'] ?? 0;
            $gb = $loadByUser[$b]['group_open_count'] ?? 0;
            if ($ga !== $gb) {
                return $ga <=> $gb;
            }
            $ta = $loadByUser[$a]['total_open_count'] ?? 0;
            $tb = $loadByUser[$b]['total_open_count'] ?? 0;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            return $a <=> $b;
        });

        return $eligible ? (int)$eligible[0] : 0;
    }

    private function allowedSectionsCanWorkVerifierGroup(array $allowedSet, string $groupKey): bool
    {
        if (isset($allowedSet['*'])) {
            return true;
        }
        // Historical verifier semantics are collaborative:
        // a verifier may participate in a group when they can work any relevant component in it.
        foreach (wf_verifier_group_components($groupKey) as $componentKey) {
            if (isset($allowedSet[strtolower(trim((string)$componentKey))])) {
                return true;
            }
        }
        return false;
    }

    private function normalizeAllowedSectionsMap(string $raw): array
    {
        $raw = strtolower(trim($raw));
        if ($raw === '*') return ['*' => true];
        if ($raw === '') return [];
        $parts = preg_split('/[\s,|]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $k = strtolower(trim((string)$p));
            if ($k === '') continue;
            if ($k === 'identification') $k = 'id';
            if ($k === 'social_media' || $k === 'social-media') $k = 'socialmedia';
            if ($k === 'driving' || $k === 'driving_license') $k = 'driving_licence';
            $out[$k] = true;
        }
        return $out;
    }

    public function countActiveVerifierQueueRows(int $caseId): int
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM Vati_Payfiller_Verifier_Group_Queue
             WHERE case_id = ?
               AND completed_at IS NULL
               AND COALESCE(LOWER(TRIM(status)), 'pending') IN ('pending','in_progress','correction_submitted','waiting_candidate','hold','insufficient_documents','reopened','blocked','followup')"
        );
        $st->execute([$caseId]);
        return (int)($st->fetchColumn() ?: 0);
    }

    public function loadVerifierGroupAssignedUserId(int $caseId, string $groupKey): int
    {
        $g = strtoupper(trim($groupKey));
        if ($caseId <= 0 || $g === '') return 0;
        $st = $this->pdo->prepare(
            "SELECT assigned_user_id
               FROM Vati_Payfiller_Verifier_Group_Queue
              WHERE case_id = ? AND UPPER(TRIM(group_key)) = ?
              ORDER BY id DESC
              LIMIT 1"
        );
        $st->execute([$caseId, $g]);
        return (int)($st->fetchColumn() ?: 0);
    }

    public function loadUserAllowedSectionsMap(int $userId): array
    {
        if ($userId <= 0) return [];
        $st = $this->pdo->prepare('SELECT allowed_sections FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        return $this->normalizeAllowedSectionsMap((string)($st->fetchColumn() ?: ''));
    }

    public function hasDownstreamActivity(int $caseId, string $componentKey, string $downstreamStage): bool
    {
        $caseId = (int)$caseId;
        $ck = strtolower(trim($componentKey));
        $stage = strtolower(trim($downstreamStage));
        if ($caseId <= 0 || $ck === '' || $stage === '') return false;

        // 1) Canonical workflow transition activity.
        try {
            $st = $this->pdo->prepare(
                "SELECT 1
                   FROM Vati_Payfiller_Workflow_Transitions
                  WHERE case_id = ?
                    AND LOWER(TRIM(component_key)) = ?
                    AND LOWER(TRIM(stage)) = ?
                  LIMIT 1"
            );
            $st->execute([$caseId, $ck, $stage]);
            if ($st->fetchColumn()) return true;
        } catch (Throwable $e) {
        }

        // 2) Component workflow row mutated at downstream stage.
        try {
            $st = $this->pdo->prepare(
                "SELECT 1
                   FROM Vati_Payfiller_Case_Component_Workflow
                  WHERE case_id = ?
                    AND LOWER(TRIM(component_key)) = ?
                    AND LOWER(TRIM(stage)) = ?
                    AND (
                        COALESCE(updated_by_user_id,0) > 0
                        OR LOWER(TRIM(COALESCE(status,''))) NOT IN ('', 'pending', 'in_progress', 'submitted', 'correction_submitted')
                        OR completed_at IS NOT NULL
                    )
                  LIMIT 1"
            );
            $st->execute([$caseId, $ck, $stage]);
            if ($st->fetchColumn()) return true;
        } catch (Throwable $e) {
        }

        // 3) Item-level workflow mutation at downstream stage (if table exists).
        try {
            $st = $this->pdo->prepare(
                "SELECT 1
                   FROM Vati_Payfiller_Case_Component_Item_Workflow
                  WHERE case_id = ?
                    AND LOWER(TRIM(component_key)) = ?
                    AND LOWER(TRIM(stage)) = ?
                    AND (
                        COALESCE(updated_by_user_id,0) > 0
                        OR LOWER(TRIM(COALESCE(status,''))) NOT IN ('', 'pending', 'in_progress', 'submitted', 'correction_submitted')
                    )
                  LIMIT 1"
            );
            $st->execute([$caseId, $ck, $stage]);
            if ($st->fetchColumn()) return true;
        } catch (Throwable $e) {
        }

        // 4) Timeline activity by downstream role on the component section.
        try {
            $st = $this->pdo->prepare(
                "SELECT 1
                   FROM Vati_Payfiller_Case_Timeline
                  WHERE LOWER(TRIM(section_key)) = ?
                    AND LOWER(TRIM(actor_role)) IN (" . ($stage === 'qa' ? "'qa','team_lead'" : ($stage === 'verifier' ? "'verifier','db_verifier'" : "'validator'")) . ")
                    AND application_id = (
                        SELECT application_id
                          FROM Vati_Payfiller_Cases
                         WHERE case_id = ?
                         LIMIT 1
                    )
                  LIMIT 1"
            );
            $st->execute([$ck, $caseId]);
            if ($st->fetchColumn()) return true;
        } catch (Throwable $e) {
        }

        return false;
    }
}
