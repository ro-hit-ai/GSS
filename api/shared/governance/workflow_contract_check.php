<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/integration.php';
require_once __DIR__ . '/../workflow_snapshot_service.php';

integration_bootstrap_json_api();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
    exit;
}

$applicationId = integration_normalize_application_id(trim((string)($_GET['application_id'] ?? '')));
if ($applicationId === '') {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'application_id is required']);
    exit;
}

try {
    $pdo = getDB();
    $contract = ws_build_snapshot_contract($pdo, $applicationId);
    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'application_id' => $applicationId,
            'visible_sections' => $contract['visible_sections'],
            'assigned_components_count' => count($contract['assigned_components']),
            'component_workflow_count' => count($contract['component_workflow']),
            'mapping_status' => $contract['mapping_status'],
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Failed to build workflow contract']);
}

