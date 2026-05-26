<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';

auth_require_login(null);
auth_session_start();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function nrp_json(int $httpCode, array $payload): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        nrp_json(405, [
            'success' => false,
            'source' => 'node',
            'error' => [
                'code' => 'METHOD_NOT_ALLOWED',
                'message' => 'Method not allowed',
            ],
            'meta' => [
                'fallbackRecommended' => true,
            ],
        ]);
    }

    $applicationId = strtoupper(trim((string)($_GET['application_id'] ?? '')));
    if ($applicationId === '') {
        nrp_json(400, [
            'success' => false,
            'source' => 'node',
            'error' => [
                'code' => 'APPLICATION_ID_REQUIRED',
                'message' => 'application_id is required',
            ],
            'meta' => [
                'fallbackRecommended' => true,
            ],
        ]);
    }

    $componentKey = trim((string)($_GET['component_key'] ?? ''));
    $query = [];
    if ($componentKey !== '') {
        $query['componentKey'] = $componentKey;
    }

    $path = '/api/v1/php/applications/' . rawurlencode($applicationId) . '/replies';
    if (!empty($query)) {
        $path .= '?' . http_build_query($query);
    }
    $result = app_node_api_json_request('GET', $path, null, 20);

    if (($result['success'] ?? false) !== true) {
        nrp_json(502, [
            'success' => false,
            'source' => 'node',
            'applicationId' => $applicationId,
            'error' => [
                'code' => 'NODE_REQUEST_FAILED',
                'message' => (string)($result['error'] ?? 'Node request failed'),
            ],
            'meta' => [
                'fallbackRecommended' => true,
                'httpCode' => (int)($result['http_code'] ?? 0),
            ],
        ]);
    }

    $payload = is_array($result['response'] ?? null) ? $result['response'] : null;
    if (!$payload) {
        nrp_json(502, [
            'success' => false,
            'source' => 'node',
            'applicationId' => $applicationId,
            'error' => [
                'code' => 'NODE_INVALID_RESPONSE',
                'message' => 'Node returned an invalid response',
            ],
            'meta' => [
                'fallbackRecommended' => true,
                'httpCode' => (int)($result['http_code'] ?? 0),
            ],
        ]);
    }

    nrp_json(200, $payload);
} catch (Throwable $e) {
    nrp_json(500, [
        'success' => false,
        'source' => 'node',
        'error' => [
            'code' => 'PHP_PROXY_FAILURE',
            'message' => $e->getMessage(),
        ],
        'meta' => [
            'fallbackRecommended' => true,
        ],
    ]);
}
