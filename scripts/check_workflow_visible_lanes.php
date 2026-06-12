<?php
/**
 * Smoke tests for /api/shared/workflow_visible_lanes.php.
 *
 * Usage:
 *   php scripts/check_workflow_visible_lanes.php
 *
 * Optional env for live lane checks:
 *   GSS_BASE_URL=http://localhost/GSS
 *   MINTLEAF_SERVICE_TOKEN=...
 *   INTEGRATION_SERVICE_TOKEN=...
 *   WVL_USER_ID=255
 *   WVL_USER_ROLE=verifier
 *   WVL_APP_ID=APP-...
 *   WVL_CASE_ID=123
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
$url = $base . '/api/shared/workflow_visible_lanes.php';

if (trim((string)$token) === '') {
    echo "SKIP: MINTLEAF_SERVICE_TOKEN or INTEGRATION_SERVICE_TOKEN is required to run endpoint smoke tests.\n";
    exit(0);
}

function wvl_test_post(string $url, array $payload, string $token): array
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
        'name' => 'invalid user returns unauthorized',
        'payload' => [
            'userId' => 999999999,
            'role' => 'verifier',
            'applicationId' => 'APP-NONEXISTENT-WVL',
        ],
        'expect_http' => 401,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 0
                && ($json['code'] ?? '') === 'UNAUTHORIZED';
        },
    ],
];

$userId = (int)(getenv('WVL_USER_ID') ?: 0);
$role = trim((string)(getenv('WVL_USER_ROLE') ?: 'verifier'));
$appId = trim((string)(getenv('WVL_APP_ID') ?: ''));
$caseId = (int)(getenv('WVL_CASE_ID') ?: 0);

if ($userId > 0 && ($appId !== '' || $caseId > 0)) {
    $basePayload = [
        'userId' => $userId,
        'role' => $role,
    ];
    if ($appId !== '') {
        $basePayload['applicationId'] = $appId;
    } else {
        $basePayload['caseId'] = $caseId;
    }

    $tests[] = [
        'name' => 'visible lanes returns lane list',
        'payload' => $basePayload,
        'expect_http' => 200,
        'expect' => static function (array $json): bool {
            if (($json['status'] ?? null) !== 1 || !isset($json['applicationId']) || !isset($json['caseId']) || !is_array($json['lanes'] ?? null)) {
                return false;
            }
            foreach ($json['lanes'] as $lane) {
                if (!is_array($lane)
                    || !isset($lane['componentKey'], $lane['ownerRole'], $lane['threadId'], $lane['visibility'], $lane['actionable'])) {
                    return false;
                }
            }
            return true;
        },
    ];

    $tests[] = [
        'name' => 'missing application returns APPLICATION_NOT_FOUND',
        'payload' => [
            'userId' => $userId,
            'role' => $role,
            'applicationId' => 'APP-NONEXISTENT-WVL',
        ],
        'expect_http' => 404,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 0
                && ($json['code'] ?? '') === 'APPLICATION_NOT_FOUND';
        },
    ];
}

$failed = false;
foreach ($tests as $test) {
    $result = wvl_test_post($url, $test['payload'], $token);
    $json = $result['json'] ?? null;
    $ok = $result['http'] === (int)$test['expect_http']
        && is_array($json)
        && $test['expect']($json);

    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $test['name'] . ' HTTP=' . $result['http'] . PHP_EOL;
    if (!$ok) {
        $failed = true;
        if (($result['error'] ?? '') !== '') {
            echo '  curl_error=' . $result['error'] . PHP_EOL;
        }
        echo '  response=' . (is_string($result['raw'] ?? null) ? $result['raw'] : '') . PHP_EOL;
    }
}

exit($failed ? 1 : 0);
