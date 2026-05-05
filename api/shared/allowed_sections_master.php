<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key');

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function allowed_sections_log(string $msg): void {
    error_log('[allowed_sections_master] ' . $msg);
}

function get_header_value(string $name): string {
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

function shared_api_key_valid(): bool {
    $incoming = get_header_value('X-API-Key');
    if ($incoming === '') return false;
    $expected = (string)(env_get('PHP_API_KEY', env_get('SHARED_API_KEY', '')) ?? '');
    if ($expected === '') return false;
    return hash_equals($expected, $incoming);
}

$incomingApiKey = get_header_value('X-API-Key');
$hasApiKey = $incomingApiKey !== '';
$apiKeyOk = shared_api_key_valid();
if ($hasApiKey && !$apiKeyOk) {
    allowed_sections_log('auth failure method=api-key');
    http_response_code(401);
    echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
    exit;
}

$authViaApiKey = $apiKeyOk;
allowed_sections_log('hit auth_method=' . ($authViaApiKey ? 'api-key' : 'session') . ' auth=' . ($authViaApiKey ? 'success' : 'pending'));

if (!$authViaApiKey && !auth_is_logged_in()) {
    allowed_sections_log('auth failure method=session');
    http_response_code(401);
    echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
    exit;
}

echo json_encode([
    'status' => 1,
    'message' => 'ok',
    'data' => [
        ['key' => 'basic', 'label' => 'Basic'],
        ['key' => 'id', 'label' => 'ID'],
        ['key' => 'contact', 'label' => 'Contact'],
        ['key' => 'education', 'label' => 'Education'],
        ['key' => 'employment', 'label' => 'Employment'],
        ['key' => 'socialmedia', 'label' => 'SocialMedia'],
        ['key' => 'ecourt', 'label' => 'ECourt'],
        ['key' => 'reference', 'label' => 'Reference'],
        ['key' => 'reports', 'label' => 'Authorization'],
        ['key' => 'timeline', 'label' => 'Timeline'],
    ]
]);
