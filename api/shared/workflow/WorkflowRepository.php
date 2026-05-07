<?php

final class WorkflowRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
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

    public function upsertWorkflowStatus(int $caseId, string $applicationId, string $componentKey, string $stage, string $status, int $userId, string $role): void
    {
        $completedAt = in_array($status, ['approved', 'rejected'], true) ? 'NOW()' : 'NULL';
        $sql = 'INSERT INTO Vati_Payfiller_Case_Component_Workflow '
            . '(case_id, application_id, component_key, stage, status, updated_by_user_id, updated_by_role, completed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ' . $completedAt . ') '
            . 'ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by_user_id = VALUES(updated_by_user_id), '
            . 'updated_by_role = VALUES(updated_by_role), completed_at = ' . $completedAt . ', updated_at = NOW()';
        $st = $this->pdo->prepare($sql);
        $st->execute([$caseId, $applicationId, strtolower(trim($componentKey)), strtolower(trim($stage)), $status, $userId, strtolower(trim($role))]);
    }

    public function syncComponentStatus(int $caseId, string $applicationId, string $componentKey, string $status): void
    {
        $st = $this->pdo->prepare(
            'UPDATE Vati_Payfiller_Case_Components SET status = ?, updated_at = NOW() '
            . 'WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ?'
        );
        $st->execute([$status, $caseId, $applicationId, strtolower(trim($componentKey))]);
    }

    public function loadRequiredComponentStageStatuses(int $caseId, string $stage): array
    {
        $st = $this->pdo->prepare(
            'SELECT LOWER(TRIM(c.component_key)) AS component_key, COALESCE(LOWER(TRIM(w.status)),\'pending\') AS status '
            . 'FROM Vati_Payfiller_Case_Components c '
            . 'LEFT JOIN Vati_Payfiller_Case_Component_Workflow w '
            . 'ON w.case_id = c.case_id AND LOWER(TRIM(w.component_key)) = LOWER(TRIM(c.component_key)) AND w.stage = ? '
            . 'WHERE c.case_id = ? AND c.is_required = 1 AND LOWER(TRIM(c.component_key)) <> \'reports\''
        );
        $st->execute([strtolower(trim($stage)), $caseId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        $op = strtolower(trim($operationalState));
        if ($op === 'completed') {
            $st = $this->pdo->prepare(
                "UPDATE Vati_Payfiller_Validator_Queue
                 SET status='done',
                     completed_at=COALESCE(completed_at,NOW()),
                     assigned_user_id=COALESCE(assigned_user_id,?),
                     claimed_at=COALESCE(claimed_at,NOW())
                 WHERE case_id=? AND completed_at IS NULL"
            );
            $st->execute([$userId, $caseId]);
            return;
        }

        $queueStatus = ($op === 'waiting_candidate') ? 'waiting_candidate' : (($op === 'blocked') ? 'blocked' : 'in_progress');
        $st = $this->pdo->prepare(
            "UPDATE Vati_Payfiller_Validator_Queue
             SET status=?,
                 completed_at=NULL,
                 assigned_user_id=COALESCE(assigned_user_id,?),
                 claimed_at=COALESCE(claimed_at,NOW())
             WHERE case_id=?"
        );
        $st->execute([$queueStatus, $userId, $caseId]);
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
}
