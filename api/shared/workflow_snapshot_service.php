<?php

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

function ws_compute_component_stage_label(array $stages): string
{
    $cand = strtolower(trim((string)($stages['candidate'] ?? '')));
    $val = strtolower(trim((string)($stages['validator'] ?? '')));
    $ver = strtolower(trim((string)($stages['verifier'] ?? '')));
    $qa = strtolower(trim((string)($stages['qa'] ?? '')));

    if ($qa === 'rejected') return 'QA Rejected';
    if ($qa === 'approved') return 'Completed';
    if ($ver === 'rejected') return 'VE Rejected';
    if ($val === 'rejected') return 'VA Rejected';
    if ($cand === 'rejected') return 'Candidate Rejected';

    if ($ver === 'approved') return 'QA Pending';
    if ($val === 'approved') return 'VE Pending';
    if ($cand === 'approved') return 'VA Pending';
    return 'Candidate Pending';
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
              WHERE application_id = ?'
        );
        $stmt->execute([$applicationId]);
        $componentRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $componentRows = [];
    }

    $workflowByComponent = [];
    if (ws_workflow_table_available($pdo)) {
        try {
            $workflowStmt = $pdo->prepare(
                'SELECT component_key, stage, status, completed_at, updated_at
                   FROM Vati_Payfiller_Case_Component_Workflow
                  WHERE application_id = ?'
            );
            $workflowStmt->execute([$applicationId]);
            $workflowRows = $workflowStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($workflowRows as $row) {
                $ck = ws_norm_component_key((string)($row['component_key'] ?? ''));
                $stage = strtolower(trim((string)($row['stage'] ?? '')));
                if ($ck === '' || $stage === '') continue;
                if (!isset($workflowByComponent[$ck])) {
                    $workflowByComponent[$ck] = [];
                }
                $workflowByComponent[$ck][$stage] = [
                    'status' => strtolower(trim((string)($row['status'] ?? ''))),
                    'completed_at' => $row['completed_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            $workflowByComponent = [];
        }
    }

    foreach ($componentRows as $component) {
        $componentKey = ws_norm_component_key((string)($component['component_key'] ?? ''));
        if ($componentKey === '') continue;

        if (!in_array($componentKey, $visibleSections, true)) {
            $visibleSections[] = $componentKey;
        }

        $w = $workflowByComponent[$componentKey] ?? [];
        $stageSimple = [
            'candidate' => isset($w['candidate']['status']) ? (string)$w['candidate']['status'] : 'pending',
            'validator' => isset($w['validator']['status']) ? (string)$w['validator']['status'] : 'pending',
            'verifier' => isset($w['verifier']['status']) ? (string)$w['verifier']['status'] : 'pending',
            'qa' => isset($w['qa']['status']) ? (string)$w['qa']['status'] : 'pending',
        ];

        $componentWorkflow[$componentKey] = [
            'candidate' => [
                'status' => $stageSimple['candidate'],
                'completed_at' => $w['candidate']['completed_at'] ?? null,
                'updated_at' => $w['candidate']['updated_at'] ?? null,
            ],
            'validator' => [
                'status' => $stageSimple['validator'],
                'completed_at' => $w['validator']['completed_at'] ?? null,
                'updated_at' => $w['validator']['updated_at'] ?? null,
            ],
            'verifier' => [
                'status' => $stageSimple['verifier'],
                'completed_at' => $w['verifier']['completed_at'] ?? null,
                'updated_at' => $w['verifier']['updated_at'] ?? null,
            ],
            'qa' => [
                'status' => $stageSimple['qa'],
                'completed_at' => $w['qa']['completed_at'] ?? null,
                'updated_at' => $w['qa']['updated_at'] ?? null,
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
