<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../includes/integration.php';

integration_bootstrap_json_api();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function crv_header_value(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$key])) return trim((string)$_SERVER[$key]);
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strcasecmp((string)$k, $name) === 0) return trim((string)$v);
            }
        }
    }
    return '';
}

function crv_api_key_valid(): bool
{
    $incoming = crv_header_value('X-API-Key');
    if ($incoming === '') return false;
    $expected = (string)(env_get('PHP_API_KEY', env_get('SHARED_API_KEY', '')) ?? '');
    if ($expected === '') return false;
    return hash_equals($expected, $incoming);
}

function crv_role_norm(): string
{
    foreach (['auth_role', 'role', 'moduleAccess', 'auth_moduleAccess'] as $key) {
        $value = trim((string)($_SESSION[$key] ?? ''));
        if ($value !== '') return strtolower($value);
    }
    return '';
}

function crv_client_id(): int
{
    foreach (['auth_client_id', 'client_id', 'Client_id'] as $key) {
        if (isset($_SESSION[$key]) && (int)$_SESSION[$key] > 0) return (int)$_SESSION[$key];
    }
    return 0;
}

function crv_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['status' => 0, 'message' => $message]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$hasStaffSession = !empty($_SESSION['auth_user_id'])
    || !empty($_SESSION['auth_moduleAccess'])
    || !empty($_SESSION['auth_role'])
    || !empty($_SESSION['role']);
$isCandidatePortalSession = !$hasStaffSession && !empty($_SESSION['logged_in']) && !empty($_SESSION['application_id']);
$hasApiKey = crv_header_value('X-API-Key') !== '';
$apiKeyOk = crv_api_key_valid();

if ($hasApiKey && !$apiKeyOk) {
    crv_fail(401, 'Unauthorized');
}
if (!$isCandidatePortalSession && !$apiKeyOk) {
    integration_resolve_actor(true);
}

try {
    $pdo = getDatabaseConnection();
    $applicationId = integration_normalize_application_id((string)($_GET['application_id'] ?? $_GET['applicationId'] ?? ''));
    $caseId = (int)($_GET['case_id'] ?? $_GET['caseId'] ?? 0);
    if ($applicationId === '' && $caseId <= 0) {
        crv_fail(400, 'application_id or case_id is required');
    }

    if ($applicationId !== '') {
        $stmt = $pdo->prepare(
            "SELECT case_id, application_id, client_id, workflow_version, updated_at
               FROM Vati_Payfiller_Cases
              WHERE application_id = ?
              LIMIT 1"
        );
        $stmt->execute([$applicationId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT case_id, application_id, client_id, workflow_version, updated_at
               FROM Vati_Payfiller_Cases
              WHERE case_id = ?
              LIMIT 1"
        );
        $stmt->execute([$caseId]);
    }

    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$case) {
        crv_fail(404, 'Case not found');
    }

    $role = crv_role_norm();
    $authViaApiKey = $apiKeyOk;
    if (!$authViaApiKey && $isCandidatePortalSession) {
        $sessionAppId = integration_normalize_application_id((string)($_SESSION['application_id'] ?? ''));
        if ($sessionAppId === '' || strcasecmp($sessionAppId, (string)$case['application_id']) !== 0) {
            crv_fail(403, 'Forbidden');
        }
    } elseif (!$authViaApiKey) {
        $allowedRoles = ['verifier', 'validator', 'qa', 'client_admin', 'gss_admin', 'db_verifier'];
        if (!in_array($role, $allowedRoles, true)) {
            crv_fail(403, 'Forbidden');
        }
        if ($role === 'client_admin') {
            $sessionClientId = crv_client_id();
            if ($sessionClientId > 0 && $sessionClientId !== (int)($case['client_id'] ?? 0)) {
                crv_fail(403, 'Forbidden');
            }
        }
    }

    $workflowVersion = (int)($case['workflow_version'] ?? 0);
    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'case_id' => (int)$case['case_id'],
            'caseId' => (int)$case['case_id'],
            'application_id' => (string)$case['application_id'],
            'applicationId' => (string)$case['application_id'],
            'workflow_version' => $workflowVersion,
            'workflowVersion' => $workflowVersion,
            'updated_at' => (string)($case['updated_at'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    error_log('[candidate_report_version] ' . $e->getMessage());
    crv_fail(500, 'Failed to load report version');
}
