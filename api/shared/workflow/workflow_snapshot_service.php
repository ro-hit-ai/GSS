<?php

require_once __DIR__ . '/workflow_stage_config.php';

function ws_workflow_table_available(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM Vati_Payfiller_Case_Component_Workflow LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function ws_component_item_workflow_table_available(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM Vati_Payfiller_Case_Component_Item_Workflow LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function ws_norm_component_key(string $k): string
{
    $k = strtolower(trim($k));
    if ($k === 'identification') return 'id';
    if ($k === 'social_media' || $k === 'social-media') return 'socialmedia';
    if ($k === 'driving' || $k === 'driving_license') return 'driving_licence';
    return $k;
}

function ws_is_split_reference_key(string $componentKey): bool
{
    $k = ws_norm_component_key($componentKey);
    return $k === 'education_reference' || $k === 'employment_reference';
}

function ws_stage_meta_from_workflow_row(array $row): array
{
    return [
        'status' => strtolower(trim((string)($row['status'] ?? ''))),
        'completed_at' => $row['completed_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'reopen_reason' => $row['reopen_reason'] ?? null,
        'reopened_by_user_id' => isset($row['reopened_by_user_id']) && (int)$row['reopened_by_user_id'] > 0 ? (int)$row['reopened_by_user_id'] : null,
        'reopened_by_role' => $row['reopened_by_role'] ?? null,
        'reopened_at' => $row['reopened_at'] ?? null,
        'relocked_by_user_id' => isset($row['relocked_by_user_id']) && (int)$row['relocked_by_user_id'] > 0 ? (int)$row['relocked_by_user_id'] : null,
        'relocked_by_role' => $row['relocked_by_role'] ?? null,
        'relocked_at' => $row['relocked_at'] ?? null,
        'invalidation_reason' => $row['invalidation_reason'] ?? null,
        'invalidated_by_user_id' => isset($row['invalidated_by_user_id']) && (int)$row['invalidated_by_user_id'] > 0 ? (int)$row['invalidated_by_user_id'] : null,
        'invalidated_by_role' => $row['invalidated_by_role'] ?? null,
        'invalidated_source_stage' => $row['invalidated_source_stage'] ?? null,
        'invalidated_at' => $row['invalidated_at'] ?? null,
    ];
}

function ws_fetch_workflow_by_component(PDO $pdo, string $applicationId = '', int $caseId = 0): array
{
    $workflowByComponent = [];
    if (!ws_workflow_table_available($pdo)) return $workflowByComponent;
    if ($applicationId === '' && $caseId <= 0) return $workflowByComponent;

    try {
        $where = $caseId > 0 ? 'case_id = ?' : 'application_id = ?';
        $param = $caseId > 0 ? $caseId : $applicationId;
        try {
            $workflowStmt = $pdo->prepare(
                'SELECT component_key, stage, status, completed_at, updated_at,
                        reopen_reason, reopened_by_user_id, reopened_by_role, reopened_at,
                        relocked_by_user_id, relocked_by_role, relocked_at,
                        invalidation_reason, invalidated_by_user_id, invalidated_by_role, invalidated_source_stage, invalidated_at
                   FROM Vati_Payfiller_Case_Component_Workflow
                  WHERE ' . $where
            );
            $workflowStmt->execute([$param]);
            $workflowRows = $workflowStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $workflowStmt = $pdo->prepare(
                'SELECT component_key, stage, status, completed_at, updated_at
                   FROM Vati_Payfiller_Case_Component_Workflow
                  WHERE ' . $where
            );
            $workflowStmt->execute([$param]);
            $workflowRows = $workflowStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        foreach ($workflowRows as $row) {
            $ck = ws_norm_component_key((string)($row['component_key'] ?? ''));
            $stage = strtolower(trim((string)($row['stage'] ?? '')));
            if ($ck === '' || $stage === '') continue;
            if (!isset($workflowByComponent[$ck])) {
                $workflowByComponent[$ck] = [];
            }
            $workflowByComponent[$ck][$stage] = ws_stage_meta_from_workflow_row($row);
        }
    } catch (Throwable $e) {
        return [];
    }

    return $workflowByComponent;
}

function ws_component_has_workflow_rows(array $workflowByComponent, string $componentKey): bool
{
    $key = ws_norm_component_key($componentKey);
    return $key !== '' && isset($workflowByComponent[$key]) && is_array($workflowByComponent[$key]) && !empty($workflowByComponent[$key]);
}

function ws_resolve_workflow_component_key(array $workflowByComponent, string $componentKey): string
{
    $key = ws_norm_component_key($componentKey);
    if ($key === '') return '';
    if (ws_is_split_reference_key($key)) {
        if (ws_component_has_workflow_rows($workflowByComponent, $key)) return $key;
        if (ws_component_has_workflow_rows($workflowByComponent, 'reference')) return 'reference';
    }
    return $key;
}

function ws_resolved_component_workflow_entry(array $workflowByComponent, string $componentKey): array
{
    $key = ws_norm_component_key($componentKey);
    $sourceKey = ws_resolve_workflow_component_key($workflowByComponent, $key);
    $stages = $sourceKey !== '' && isset($workflowByComponent[$sourceKey]) && is_array($workflowByComponent[$sourceKey])
        ? $workflowByComponent[$sourceKey]
        : [];
    return [
        'component_key' => $key,
        'source_component_key' => $sourceKey !== '' ? $sourceKey : $key,
        'stages' => $stages,
        'stage_simple' => ws_component_stage_surface($stages, $key),
    ];
}

function ws_resolved_stage_status(array $workflowByComponent, string $componentKey, string $stage, string $fallback = 'pending'): string
{
    $resolved = ws_resolved_component_workflow_entry($workflowByComponent, $componentKey);
    $stageKey = strtolower(trim($stage));
    $stages = $resolved['stages'];
    if ($stageKey !== '' && isset($stages[$stageKey]) && is_array($stages[$stageKey])) {
        $status = strtolower(trim((string)($stages[$stageKey]['status'] ?? '')));
        if ($status !== '') return $status;
    }
    $fallback = strtolower(trim($fallback));
    return $fallback !== '' ? $fallback : 'pending';
}

function ws_compute_component_stage_label(array $stages): string
{
    $cand = strtolower(trim((string)($stages['candidate'] ?? '')));

    $isEval = function (string $s): bool {
        return in_array($s, ['approved', 'rejected', 'hold', 'insufficient_documents', 'completed', 'clear', 'verified'], true);
    };

    $stageKeys = wf_stage_keys();
    $finalStage = wf_final_stage();
    foreach (array_reverse($stageKeys) as $stageKey) {
        $s = strtolower(trim((string)($stages[$stageKey] ?? '')));
        if ($s === '') continue;
        $label = wf_stage_ui_label($stageKey);
        if ($s === 'approved' && $stageKey === $finalStage) return 'Completed';
        if ($s === 'rejected') return $label . ' Reviewed (Rejected)';
        if ($s === 'hold') return $label . ' Reviewed (Hold)';
        if ($s === 'correction_submitted') return 'Correction Submitted';
        if ($s === 'insufficient_documents') return $label . ' Reviewed (Waiting Candidate)';
        if ($s === 'invalidated_by_validator_reopen' || $s === 'invalidated_by_verifier_reopen') return $label . ' Reviewed (Invalidated)';
        if ($isEval($s)) return $label . ' Reviewed';
    }
    if ($cand === 'rejected') return 'Candidate Rejected';
    foreach ($stageKeys as $stageKey) {
        $prev = wf_previous_stage($stageKey);
        if ($prev === '') {
            if ($cand === 'approved') return strtoupper(wf_stage_ui_label($stageKey)) . ' Pending';
            continue;
        }
        $prevStatus = strtolower(trim((string)($stages[$prev] ?? '')));
        $curStatus = strtolower(trim((string)($stages[$stageKey] ?? 'pending')));
        if ($isEval($prevStatus) && !$isEval($curStatus)) {
            return wf_stage_ui_label($stageKey) . ' Pending';
        }
    }
    if ($cand === 'approved') return 'VA Pending';
    return 'Candidate Pending';
}

function ws_component_stage_surface(array $workflowStages, string $componentKey = ''): array
{
    $ck = ws_norm_component_key($componentKey);
    $surface = [
        'candidate' => isset($workflowStages['candidate']['status']) ? (string)$workflowStages['candidate']['status'] : 'pending',
        'validator' => isset($workflowStages['validator']['status']) ? (string)$workflowStages['validator']['status'] : 'pending',
        'verifier' => isset($workflowStages['verifier']['status']) ? (string)$workflowStages['verifier']['status'] : 'pending',
        'qa' => isset($workflowStages['qa']['status']) ? (string)$workflowStages['qa']['status'] : 'pending',
    ];

    // Reports remains validator-internal operationally even if legacy workflow rows
    // still exist for later stages. Do not let residue create a second owner.
    if ($ck === 'reports') {
        $surface['verifier'] = '';
        $surface['qa'] = '';
    }

    return $surface;
}

function ws_build_snapshot_contract(PDO $pdo, string $applicationId): array
{
    $visibleSections = [];
    $assignedComponents = [];
    $componentWorkflow = [];
    $mappingStatus = 'ok';

    $componentRows = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT component_key, is_required, assigned_role, assigned_user_id, status, completed_at
               FROM Vati_Payfiller_Case_Components
              WHERE application_id = ?
                AND is_required = 1'
        );
        $stmt->execute([$applicationId]);
        $componentRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $componentRows = [];
    }

    $workflowByComponent = ws_fetch_workflow_by_component($pdo, $applicationId);

    foreach ($componentRows as $component) {
        $componentKey = ws_norm_component_key((string)($component['component_key'] ?? ''));
        if ($componentKey === '') continue;

        if (!in_array($componentKey, $visibleSections, true)) {
            $visibleSections[] = $componentKey;
        }

        $resolvedWorkflow = ws_resolved_component_workflow_entry($workflowByComponent, $componentKey);
        $w = $resolvedWorkflow['stages'];
        $stageSimple = $resolvedWorkflow['stage_simple'];

        $componentWorkflow[$componentKey] = [
            '_source_component_key' => $resolvedWorkflow['source_component_key'],
            'candidate' => [
                'status' => $stageSimple['candidate'],
                'completed_at' => $w['candidate']['completed_at'] ?? null,
                'updated_at' => $w['candidate']['updated_at'] ?? null,
                'reopen_reason' => $w['candidate']['reopen_reason'] ?? null,
                'reopened_by_user_id' => $w['candidate']['reopened_by_user_id'] ?? null,
                'reopened_by_role' => $w['candidate']['reopened_by_role'] ?? null,
                'reopened_at' => $w['candidate']['reopened_at'] ?? null,
                'relocked_by_user_id' => $w['candidate']['relocked_by_user_id'] ?? null,
                'relocked_by_role' => $w['candidate']['relocked_by_role'] ?? null,
                'relocked_at' => $w['candidate']['relocked_at'] ?? null,
                'invalidation_reason' => $w['candidate']['invalidation_reason'] ?? null,
                'invalidated_by_user_id' => $w['candidate']['invalidated_by_user_id'] ?? null,
                'invalidated_by_role' => $w['candidate']['invalidated_by_role'] ?? null,
                'invalidated_source_stage' => $w['candidate']['invalidated_source_stage'] ?? null,
                'invalidated_at' => $w['candidate']['invalidated_at'] ?? null,
            ],
            'validator' => [
                'status' => $stageSimple['validator'],
                'completed_at' => $w['validator']['completed_at'] ?? null,
                'updated_at' => $w['validator']['updated_at'] ?? null,
                'reopen_reason' => $w['validator']['reopen_reason'] ?? null,
                'reopened_by_user_id' => $w['validator']['reopened_by_user_id'] ?? null,
                'reopened_by_role' => $w['validator']['reopened_by_role'] ?? null,
                'reopened_at' => $w['validator']['reopened_at'] ?? null,
                'relocked_by_user_id' => $w['validator']['relocked_by_user_id'] ?? null,
                'relocked_by_role' => $w['validator']['relocked_by_role'] ?? null,
                'relocked_at' => $w['validator']['relocked_at'] ?? null,
                'invalidation_reason' => $w['validator']['invalidation_reason'] ?? null,
                'invalidated_by_user_id' => $w['validator']['invalidated_by_user_id'] ?? null,
                'invalidated_by_role' => $w['validator']['invalidated_by_role'] ?? null,
                'invalidated_source_stage' => $w['validator']['invalidated_source_stage'] ?? null,
                'invalidated_at' => $w['validator']['invalidated_at'] ?? null,
            ],
            'verifier' => [
                'status' => $stageSimple['verifier'],
                'completed_at' => $w['verifier']['completed_at'] ?? null,
                'updated_at' => $w['verifier']['updated_at'] ?? null,
                'reopen_reason' => $w['verifier']['reopen_reason'] ?? null,
                'reopened_by_user_id' => $w['verifier']['reopened_by_user_id'] ?? null,
                'reopened_by_role' => $w['verifier']['reopened_by_role'] ?? null,
                'reopened_at' => $w['verifier']['reopened_at'] ?? null,
                'relocked_by_user_id' => $w['verifier']['relocked_by_user_id'] ?? null,
                'relocked_by_role' => $w['verifier']['relocked_by_role'] ?? null,
                'relocked_at' => $w['verifier']['relocked_at'] ?? null,
                'invalidation_reason' => $w['verifier']['invalidation_reason'] ?? null,
                'invalidated_by_user_id' => $w['verifier']['invalidated_by_user_id'] ?? null,
                'invalidated_by_role' => $w['verifier']['invalidated_by_role'] ?? null,
                'invalidated_source_stage' => $w['verifier']['invalidated_source_stage'] ?? null,
                'invalidated_at' => $w['verifier']['invalidated_at'] ?? null,
            ],
            'qa' => [
                'status' => $stageSimple['qa'],
                'completed_at' => $w['qa']['completed_at'] ?? null,
                'updated_at' => $w['qa']['updated_at'] ?? null,
                'reopen_reason' => $w['qa']['reopen_reason'] ?? null,
                'reopened_by_user_id' => $w['qa']['reopened_by_user_id'] ?? null,
                'reopened_by_role' => $w['qa']['reopened_by_role'] ?? null,
                'reopened_at' => $w['qa']['reopened_at'] ?? null,
                'relocked_by_user_id' => $w['qa']['relocked_by_user_id'] ?? null,
                'relocked_by_role' => $w['qa']['relocked_by_role'] ?? null,
                'relocked_at' => $w['qa']['relocked_at'] ?? null,
                'invalidation_reason' => $w['qa']['invalidation_reason'] ?? null,
                'invalidated_by_user_id' => $w['qa']['invalidated_by_user_id'] ?? null,
                'invalidated_by_role' => $w['qa']['invalidated_by_role'] ?? null,
                'invalidated_source_stage' => $w['qa']['invalidated_source_stage'] ?? null,
                'invalidated_at' => $w['qa']['invalidated_at'] ?? null,
            ],
        ];

        $assignedComponents[] = [
            'component_key' => $componentKey,
            'is_required' => isset($component['is_required']) ? (int)$component['is_required'] : 1,
            'assigned_role' => ($component['assigned_role'] ?? null) !== '' ? (string)$component['assigned_role'] : null,
            'assigned_user_id' => isset($component['assigned_user_id']) && (int)$component['assigned_user_id'] > 0
                ? (int)$component['assigned_user_id']
                : null,
            'status' => (string)($component['status'] ?? 'pending'),
            'completed_at' => $component['completed_at'] ?? null,
            'workflow' => $stageSimple,
            'current_stage' => ws_compute_component_stage_label($stageSimple),
        ];
    }

    if (empty($visibleSections) || count($visibleSections) <= 2) {
        $mappingStatus = 'incomplete_mapping';
    }

    return [
        'visible_sections' => array_values(array_unique($visibleSections)),
        'assigned_components' => $assignedComponents,
        'component_workflow' => $componentWorkflow,
        'mapping_status' => $mappingStatus,
    ];
}
