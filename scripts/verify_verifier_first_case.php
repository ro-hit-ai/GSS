<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/shared/authorization/workflow_mode.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from CLI.\n");
    exit(1);
}

$applicationId = trim((string)($argv[1] ?? ''));
if ($applicationId === '') {
    fwrite(STDERR, "Usage: php verify_verifier_first_case.php APP-XXXX\n");
    exit(1);
}

$pdo = getDB();
$caseSt = $pdo->prepare(
    "SELECT case_id, application_id, case_status, workflow_mode, client_id
       FROM Vati_Payfiller_Cases
      WHERE application_id = ?
      LIMIT 1"
);
$caseSt->execute([$applicationId]);
$case = $caseSt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$case) {
    fwrite(STDERR, "Case not found for application_id={$applicationId}\n");
    exit(2);
}

$caseId = (int)$case['case_id'];
$workflowMode = wf_mode_get_case_mode($pdo, $caseId, $applicationId);

$wfSt = $pdo->prepare(
    "SELECT LOWER(TRIM(component_key)) AS component_key,
            LOWER(TRIM(stage)) AS stage_name,
            LOWER(TRIM(status)) AS status_name,
            updated_by_role,
            completed_at
       FROM Vati_Payfiller_Case_Component_Workflow
      WHERE case_id = ?
      ORDER BY component_key, stage_name"
);
$wfSt->execute([$caseId]);
$workflowRows = $wfSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$validatorQueueSt = $pdo->prepare(
    "SELECT id, status, assigned_user_id, claimed_at, completed_at
       FROM Vati_Payfiller_Validator_Queue
      WHERE case_id = ?
      ORDER BY id DESC"
);
$validatorQueueSt->execute([$caseId]);
$validatorQueues = $validatorQueueSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$verifierQueueSt = $pdo->prepare(
    "SELECT id, group_key, status, assigned_user_id, dedicated_user_id, claimed_at, completed_at
       FROM Vati_Payfiller_Verifier_Group_Queue
      WHERE case_id = ?
      ORDER BY group_key, id DESC"
);
$verifierQueueSt->execute([$caseId]);
$verifierQueues = $verifierQueueSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$compSt = $pdo->prepare(
    "SELECT LOWER(TRIM(component_key)) AS component_key, assigned_role, assigned_user_id, status
       FROM Vati_Payfiller_Case_Components
      WHERE case_id = ?
      ORDER BY component_key"
);
$compSt->execute([$caseId]);
$components = $compSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$summary = [
    'case' => [
        'case_id' => $caseId,
        'application_id' => $applicationId,
        'case_status' => (string)($case['case_status'] ?? ''),
        'workflow_mode' => $workflowMode,
        'client_id' => (int)($case['client_id'] ?? 0),
    ],
    'validator_workflow_completed_count' => 0,
    'validator_workflow_pending_count' => 0,
    'verifier_queue_groups' => [],
    'unassigned_verifier_groups' => [],
    'verifier_owned_components' => [],
];

foreach ($workflowRows as $row) {
    if (($row['stage_name'] ?? '') === 'validator') {
        if (($row['status_name'] ?? '') === 'completed') {
            $summary['validator_workflow_completed_count']++;
        } else {
            $summary['validator_workflow_pending_count']++;
        }
    }
}

foreach ($verifierQueues as $row) {
    $groupKey = strtoupper(trim((string)($row['group_key'] ?? '')));
    if ($groupKey === '') {
        continue;
    }
    $summary['verifier_queue_groups'][$groupKey] = [
        'status' => (string)($row['status'] ?? ''),
        'assigned_user_id' => isset($row['assigned_user_id']) ? (int)$row['assigned_user_id'] : 0,
        'completed_at' => $row['completed_at'] ?? null,
    ];
    if ((int)($row['assigned_user_id'] ?? 0) <= 0) {
        $summary['unassigned_verifier_groups'][] = $groupKey;
    }
}

foreach ($components as $row) {
    if (strtolower(trim((string)($row['assigned_role'] ?? ''))) === 'verifier') {
        $summary['verifier_owned_components'][] = [
            'component_key' => (string)($row['component_key'] ?? ''),
            'assigned_user_id' => isset($row['assigned_user_id']) ? (int)$row['assigned_user_id'] : 0,
            'status' => (string)($row['status'] ?? ''),
        ];
    }
}

echo json_encode([
    'summary' => $summary,
    'validator_queue' => $validatorQueues,
    'verifier_queue' => $verifierQueues,
    'components' => $components,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
