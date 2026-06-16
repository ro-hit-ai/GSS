<?php
require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../includes/integration.php';

integration_bootstrap_json_api();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Service-Token, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function ncc_json(int $httpCode, array $payload): void
{
    integration_json_response($httpCode, $payload);
}

function ncc_unauthorized(): void
{
    ncc_json(401, [
        'status' => 0,
        'code' => 'UNAUTHORIZED',
        'message' => 'Unauthorized',
    ]);
}

function ncc_request_scheme(): string
{
    $forwardedProto = '';
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && is_string($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0] ?? ''));
    }
    if (in_array($forwardedProto, ['http', 'https'], true)) {
        return $forwardedProto;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    return $isHttps ? 'https' : 'http';
}

function ncc_request_host(): string
{
    $forwardedHost = '';
    if (isset($_SERVER['HTTP_X_FORWARDED_HOST']) && is_string($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $forwardedHost = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0] ?? '');
    }

    $host = $forwardedHost !== ''
        ? $forwardedHost
        : trim((string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')));

    if ($host === '' || preg_match('~^https?://~i', $host)) {
        return 'localhost';
    }

    return $host;
}

function ncc_app_base_url_path(): string
{
    $configured = trim((string)(env_get('APP_BASE_URL', '') ?? ''));
    if ($configured === '' && function_exists('app_base_url')) {
        $configured = trim((string)app_base_url());
    }

    if ($configured === '') {
        return '/GSS';
    }

    if (preg_match('~^https?://~i', $configured)) {
        $path = (string)(parse_url($configured, PHP_URL_PATH) ?: '');
    } else {
        $path = $configured;
    }

    $path = '/' . trim($path, '/');
    return $path === '/' ? '' : $path;
}

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        ncc_json(405, [
            'status' => 0,
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Method not allowed',
        ]);
    }

    if (!integration_is_valid_service_token()) {
        ncc_unauthorized();
    }

    integration_resolve_actor(true);

    $appBaseUrl = ncc_app_base_url_path();
    $contractBaseUrl = ncc_request_scheme() . '://' . ncc_request_host() . $appBaseUrl . '/api/shared';

    ncc_json(200, [
        'status' => 1,
        'contractVersion' => '1.0',
        'appBaseUrl' => $appBaseUrl,
        'contractBaseUrl' => $contractBaseUrl,
        'endpoints' => [
            'laneAuthorize' => '/workflow_lane_authorize.php',
            'messageAuthorize' => '/workflow_message_authorize.php',
            'attachmentAuthorize' => '/workflow_attachment_authorize.php',
            'routeInboundEmail' => '/workflow_route_inbound_email.php',
            'communicationLookup' => '/workflow_communication_lookup.php',
        ],
    ]);
} catch (Throwable $e) {
    ncc_json(500, [
        'status' => 0,
        'code' => 'SERVER_ERROR',
        'message' => 'Server error',
    ]);
}
