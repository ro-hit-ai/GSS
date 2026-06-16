<?php
require_once __DIR__ . '/../../../includes/integration.php';
require_once __DIR__ . '/../auth_client_snapshot.php';
require_once __DIR__ . '/workflow_jwt_service.php';

integration_bootstrap_json_api();

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        integration_json_response(405, [
            'status' => 0,
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Method not allowed',
        ]);
    }

    $actor = integration_resolve_actor(false);
    $phpUserId = auth_user_id();
    if ($phpUserId <= 0 || !empty($actor['service'])) {
        integration_json_response(401, [
            'status' => 0,
            'code' => 'UNAUTHORIZED',
            'message' => 'Unauthorized',
        ]);
    }

    $snapshot = auth_client_snapshot_hydrate(getDB());
    $phpRole = strtolower(trim((string)($snapshot['role'] ?? auth_module_access())));
    $phpClientId = (int)($snapshot['client_id'] ?? 0);

    if ($phpRole === '') {
        integration_json_response(401, [
            'status' => 0,
            'code' => 'UNAUTHORIZED',
            'message' => 'Unauthorized',
        ]);
    }

    $issued = workflow_jwt_issue_for_session($phpUserId, $phpRole, $phpClientId);

    integration_json_response(200, [
        'status' => 1,
        'token' => $issued['token'],
        'tokenType' => 'Bearer',
        'expiresIn' => $issued['expiresIn'],
    ]);
} catch (WorkflowJwtConfigurationException $e) {
    integration_json_response(500, [
        'status' => 0,
        'code' => 'CONFIGURATION_ERROR',
        'message' => 'Workflow JWT is not configured',
    ]);
} catch (Throwable $e) {
    integration_log('workflow_jwt', 'Workflow JWT issue failed', [
        'error' => $e->getMessage(),
    ]);
    integration_json_response(500, [
        'status' => 0,
        'code' => 'SERVER_ERROR',
        'message' => 'Server error',
    ]);
}
