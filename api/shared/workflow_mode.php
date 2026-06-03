<?php

require_once __DIR__ . '/case_component_binding.php';
require_once __DIR__ . '/workflow_semantics.php';
require_once __DIR__ . '/workflow/WorkflowRepository.php';
require_once __DIR__ . '/verifier_case_queue.php';

function wf_mode_normalize(string $mode): string
{
    $m = strtolower(trim($mode));
    return $m === 'verifier_first' ? 'verifier_first' : 'validator_first';
}

function wf_mode_ensure_case_column(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    try {
        $st = $pdo->prepare(
            "SELECT 1
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'Vati_Payfiller_Cases'
                AND column_name = 'workflow_mode'
              LIMIT 1"
        );
        $st->execute();
        if (!$st->fetchColumn()) {
            $pdo->exec(
                "ALTER TABLE Vati_Payfiller_Cases
                 ADD COLUMN workflow_mode VARCHAR(32) NULL DEFAULT NULL AFTER case_status"
            );
        }
    } catch (Throwable $e) {
    }
}

function wf_mode_get_case_mode(PDO $pdo, int $caseId = 0, string $applicationId = ''): string
{
    wf_mode_ensure_case_column($pdo);

    try {
        if ($caseId > 0) {
            $st = $pdo->prepare('SELECT workflow_mode FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
            $st->execute([$caseId]);
            return wf_mode_normalize((string)($st->fetchColumn() ?: 'validator_first'));
        }
        if (trim($applicationId) !== '') {
            $st = $pdo->prepare('SELECT workflow_mode FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
            $st->execute([$applicationId]);
            return wf_mode_normalize((string)($st->fetchColumn() ?: 'validator_first'));
        }
    } catch (Throwable $e) {
    }

    return 'validator_first';
}

function wf_mode_set_case_mode(PDO $pdo, int $caseId, string $mode): void
{
    if ($caseId <= 0) {
        return;
    }
    wf_mode_ensure_case_column($pdo);
    $norm = wf_mode_normalize($mode);
    try {
        $st = $pdo->prepare(
            "UPDATE Vati_Payfiller_Cases
                SET workflow_mode = ?,
                    updated_at = NOW()
              WHERE case_id = ?"
        );
        $st->execute([$norm, $caseId]);
    } catch (Throwable $e) {
    }
}

function wf_mode_is_verifier_first(PDO $pdo, int $caseId = 0, string $applicationId = ''): bool
{
    return wf_mode_get_case_mode($pdo, $caseId, $applicationId) === 'verifier_first';
}

function wf_mode_first_human_stage(PDO $pdo, int $caseId = 0, string $applicationId = ''): string
{
    return wf_mode_is_verifier_first($pdo, $caseId, $applicationId) ? 'verifier' : 'validator';
}

function wf_mode_default_requested_role(PDO $pdo, int $caseId = 0, string $applicationId = ''): string
{
    return wf_mode_first_human_stage($pdo, $caseId, $applicationId);
}

function wf_mode_log_system_event(PDO $pdo, string $applicationId, string $eventType, string $sectionKey, string $message): void
{
    if (trim($applicationId) === '' || trim($message) === '') {
        return;
    }
    try {
        $st = $pdo->prepare(
            'INSERT INTO Vati_Payfiller_Case_Timeline
             (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at)
             VALUES (?, NULL, ?, ?, ?, ?, NOW())'
        );
        $st->execute([$applicationId, 'system', $eventType, $sectionKey, $message]);
    } catch (Throwable $e) {
    }
}

function wf_mode_assign_group_components(PDO $pdo, int $caseId, string $applicationId, string $groupKey, int $assignedUserId): void
{
    $groupKey = strtoupper(trim($groupKey));
    if ($caseId <= 0 || $groupKey === '') {
        return;
    }

    $components = wf_verifier_group_components($groupKey);
    if (!$components) {
        return;
    }

    $params = [$applicationId];
    $placeholders = [];
    foreach ($components as $componentKey) {
        $placeholders[] = '?';
        $params[] = strtolower(trim((string)$componentKey));
    }
    $params[] = $caseId;

    $sql =
        "UPDATE Vati_Payfiller_Case_Components
            SET assigned_role = 'verifier',
                assigned_user_id = " . ($assignedUserId > 0 ? (string)$assignedUserId : 'NULL') . ",
                updated_at = NOW()
          WHERE application_id = ?
            AND LOWER(TRIM(component_key)) IN (" . implode(',', $placeholders) . ")
            AND case_id = ?";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
    } catch (Throwable $e) {
    }
}

function wf_mode_shadow_validator_and_seed_verifier(PDO $pdo, int $caseId, string $applicationId, int $clientId = 0): array
{
    $result = [
        'groups_seeded' => [],
        'groups_unassigned' => [],
        'case_queue_seeded' => 0,
        'case_queue_assigned_user_id' => 0,
    ];

    if ($caseId <= 0 || trim($applicationId) === '') {
        return $result;
    }

    case_component_binding_sync_case_components($pdo, $caseId, $applicationId);
    if (function_exists('case_component_binding_seed_stage_workflow_rows_until_stable')) {
        case_component_binding_seed_stage_workflow_rows_until_stable($pdo, $caseId, $applicationId, ['candidate', 'validator', 'verifier', 'qa']);
    } else {
        case_component_binding_seed_stage_workflow_rows($pdo, $caseId, $applicationId, ['candidate', 'validator', 'verifier', 'qa']);
    }

    $requiredSt = $pdo->prepare(
        "SELECT DISTINCT LOWER(TRIM(component_key)) AS component_key
           FROM Vati_Payfiller_Case_Components
          WHERE case_id = ?
            AND is_required = 1"
    );
    $requiredSt->execute([$caseId]);
    $requiredRows = $requiredSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $requiredComponents = [];
    $groupSet = [];
    foreach ($requiredRows as $row) {
        $componentKey = strtolower(trim((string)($row['component_key'] ?? '')));
        if ($componentKey === '') {
            continue;
        }
        $requiredComponents[$componentKey] = true;
        foreach (wf_verifier_groups_for_component($componentKey) as $groupKey) {
            $groupSet[strtoupper(trim($groupKey))] = true;
        }
    }

    $workflowUpsert = $pdo->prepare(
        "INSERT INTO Vati_Payfiller_Case_Component_Workflow
            (case_id, application_id, component_key, stage, status, updated_by_user_id, updated_by_role, completed_at)
         VALUES (?, ?, ?, 'validator', 'completed', NULL, 'system', NOW())
         ON DUPLICATE KEY UPDATE
            status = 'completed',
            updated_by_user_id = NULL,
            updated_by_role = 'system',
            completed_at = COALESCE(completed_at, NOW()),
            updated_at = NOW()"
    );
    foreach (array_keys($requiredComponents) as $componentKey) {
        $workflowUpsert->execute([$caseId, $applicationId, $componentKey]);
    }

    $queueCheck = $pdo->prepare('SELECT 1 FROM Vati_Payfiller_Validator_Queue WHERE case_id = ? LIMIT 1');
    $queueCheck->execute([$caseId]);
    if ($queueCheck->fetchColumn()) {
        $repo = new WorkflowRepository($pdo);
        $repo->setValidatorQueueOperationalState($caseId, 0, 'completed');
    }

    $pdo->prepare(
        "UPDATE Vati_Payfiller_Cases
            SET case_status = 'PENDING_VERIFIER',
                updated_at = NOW()
          WHERE case_id = ?
            AND UPPER(TRIM(COALESCE(case_status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED','CLEAR')"
    )->execute([$caseId]);

    $repo = isset($repo) ? $repo : new WorkflowRepository($pdo);
    foreach (array_keys($groupSet) as $groupKey) {
        // Keep legacy group rows readable during migration, but new operational flow is case-based.
        $repo->ensureVerifierGroupQueueRow($caseId, $groupKey);
        $result['groups_seeded'][] = $groupKey;
    }

    $caseQueue = verifier_case_queue_ensure_row($pdo, $caseId);
    if ($caseQueue) {
        $result['case_queue_seeded'] = 1;
        wf_mode_log_system_event(
            $pdo,
            $applicationId,
            'workflow.queue_seeded',
            'verifier',
            'Verifier queue seeded for manual claim'
        );
        verifier_case_queue_sync($pdo, $caseId, 0);
    }

    return $result;
}
