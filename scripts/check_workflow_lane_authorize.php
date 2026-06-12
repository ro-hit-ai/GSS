<?php
/**
 * Smoke tests for /api/shared/workflow_lane_authorize.php.
 *
 * Usage:
 *   php scripts/check_workflow_lane_authorize.php
 *
 * Optional env for deeper checks:
 *   GSS_BASE_URL=http://localhost/GSS
 *   MINTLEAF_SERVICE_TOKEN=...
 *   INTEGRATION_SERVICE_TOKEN=...
 *   WLA_USER_ID=255
 *   WLA_USER_ROLE=verifier
 *   WLA_APP_ID=APP-...
 *   WLA_COMPONENT=employment
 *   WLA_OWNER_ROLE=verifier
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../config/env.php';

$base = getenv('GSS_BASE_URL') ?: 'http://localhost/GSS';
$base = rtrim($base, '/');
$token = getenv('MINTLEAF_SERVICE_TOKEN') ?: getenv('INTEGRATION_SERVICE_TOKEN');
if (!$token) {
    $token = (string)(env_get('MINTLEAF_SERVICE_TOKEN', '') ?: env_get('INTEGRATION_SERVICE_TOKEN', ''));
}
$url = $base . '/api/shared/workflow_lane_authorize.php';

if (trim((string)$token) === '') {
    echo "SKIP: MINTLEAF_SERVICE_TOKEN or INTEGRATION_SERVICE_TOKEN is required to run endpoint smoke tests.\n";
    exit(0);
}

function wla_test_post(string $url, array $payload, string $token): array
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . trim($token),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = is_string($body) ? json_decode($body, true) : null;
    return [
        'http' => $http,
        'error' => $err,
        'json' => is_array($json) ? $json : null,
        'raw' => $body,
    ];
}

$tests = [
    [
        'name' => 'rejects missing required fields',
        'payload' => [],
        'expect_http' => 400,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 0
                && ($json['code'] ?? '') === 'INVALID_REQUEST';
        },
    ],
    [
        'name' => 'rejects invalid access type',
        'payload' => [
            'userId' => 1,
            'applicationId' => 'APP-NONEXISTENT',
            'componentKey' => 'employment',
            'ownerRole' => 'verifier',
            'accessType' => 'delete',
        ],
        'expect_http' => 400,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 0
                && ($json['code'] ?? '') === 'INVALID_REQUEST';
        },
    ],
    [
        'name' => 'invalid user returns USER_NOT_FOUND before app authorization',
        'payload' => [
            'userId' => 999999999,
            'applicationId' => 'APP-NONEXISTENT',
            'componentKey' => 'employment',
            'ownerRole' => 'verifier',
            'accessType' => 'read',
        ],
        'expect_http' => 404,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 0
                && ($json['code'] ?? '') === 'USER_NOT_FOUND';
        },
    ],
];

$userId = (int)(getenv('WLA_USER_ID') ?: 0);
$appId = trim((string)(getenv('WLA_APP_ID') ?: ''));
if ($userId > 0 && $appId !== '') {
    $role = trim((string)(getenv('WLA_USER_ROLE') ?: 'verifier'));
    $component = trim((string)(getenv('WLA_COMPONENT') ?: 'employment'));
    $ownerRole = trim((string)(getenv('WLA_OWNER_ROLE') ?: $role));
    $tests[] = [
        'name' => 'valid app/user returns authorization decision',
        'payload' => [
            'userId' => $userId,
            'role' => $role,
            'applicationId' => $appId,
            'componentKey' => $component,
            'ownerRole' => $ownerRole,
            'accessType' => 'read',
        ],
        'expect_http' => 200,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 1
                && array_key_exists('allowed', $json)
                && isset($json['visibility'])
                && is_array($json['visibility']);
        },
    ];
    $tests[] = [
        'name' => 'thread mismatch returns denied decision',
        'payload' => [
            'userId' => $userId,
            'role' => $role,
            'applicationId' => $appId,
            'componentKey' => $component,
            'ownerRole' => $ownerRole,
            'threadId' => 'wf:mismatch:' . strtolower($component) . ':' . strtolower($ownerRole),
            'accessType' => 'read',
        ],
        'expect_http' => 200,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 1
                && ($json['allowed'] ?? null) === false
                && ($json['reason'] ?? '') === 'thread_id_mismatch';
        },
    ];
}

$failed = false;
foreach ($tests as $test) {
    $result = wla_test_post($url, $test['payload'], $token);
    $json = $result['json'] ?? null;
    $ok = $result['http'] === (int)$test['expect_http']
        && is_array($json)
        && $test['expect']($json);

    echo ($ok ? 'OK' : 'FAIL') . ': ' . $test['name'] . ' HTTP=' . $result['http'] . "\n";
    if (!$ok) {
        $failed = true;
        if ($result['error'] !== '') {
            echo '  curl_error=' . $result['error'] . "\n";
        }
        echo '  response=' . trim((string)$result['raw']) . "\n";
    }
}

if ($userId <= 0 || $appId === '') {
    echo "NOTE: set WLA_USER_ID and WLA_APP_ID to run live authorization decision checks.\n";
}

exit($failed ? 2 : 0);
