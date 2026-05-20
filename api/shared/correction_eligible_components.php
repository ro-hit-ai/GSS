<?php
header('Content-Type: application/json');

require_once __DIR__ . '/candidate_correction_service.php';

auth_require_login();
auth_session_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }
    $pdo = getDB();
    ccs_ensure_table($pdo);
    $role = ccs_role_norm((string)($_SESSION['auth_moduleAccess'] ?? $_SESSION['auth_role'] ?? ''));
    $userId = (int)($_SESSION['auth_user_id'] ?? 0);
    $clientId = (int)($_SESSION['auth_client_id'] ?? 0);
    $caseId = (int)($_GET['case_id'] ?? 0);
    $applicationId = trim((string)($_GET['application_id'] ?? ''));
    $case = ccs_get_case($pdo, $caseId, $applicationId);
    if (!$case) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found']);
        exit;
    }
    $caseId = (int)$case['case_id'];
    $caseClientId = (int)($case['client_id'] ?? 0);
    $eligible = ccs_get_eligible_components($pdo, $caseId, $role, $userId, $clientId, $caseClientId);
    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => ['components' => $eligible]]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}

