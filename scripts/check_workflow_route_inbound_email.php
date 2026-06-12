<?php
/**
 * Smoke tests for /api/shared/workflow_route_inbound_email.php.
 *
 * Usage:
 *   php scripts/check_workflow_route_inbound_email.php
 *
 * Optional env:
 *   GSS_BASE_URL=http://localhost/GSS
 *   MINTLEAF_SERVICE_TOKEN=...
 *   INTEGRATION_SERVICE_TOKEN=...
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
$url = $base . '/api/shared/workflow_route_inbound_email.php';

if (trim((string)$token) === '') {
    echo "SKIP: MINTLEAF_SERVICE_TOKEN or INTEGRATION_SERVICE_TOKEN is required to run endpoint smoke tests.\n";
    exit(0);
}

function wrie_test_post(string $url, array $payload, string $token = ''): array
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    if (trim($token) !== '') {
        $headers[] = 'Authorization: Bearer ' . trim($token);
    }

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
        'name' => 'rejects missing routing input',
        'payload' => [],
        'expect_http' => 400,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 0
                && ($json['code'] ?? '') === 'ROUTING_INPUT_REQUIRED';
        },
    ],
    [
        'name' => 'accepts message-only unresolved request',
        'payload' => [
            'messageId' => '<route-smoke-' . bin2hex(random_bytes(4)) . '@example.test>',
        ],
        'expect_http' => 200,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 1
                && ($json['resolved'] ?? null) === false
                && ($json['reason'] ?? '') === 'NO_MATCH';
        },
    ],
    [
        'name' => 'accepts application subject fallback unresolved request',
        'payload' => [
            'messageId' => '<route-smoke-' . bin2hex(random_bytes(4)) . '@example.test>',
            'applicationId' => 'APP-NONEXISTENT-' . date('YmdHis'),
            'subject' => 'Re: Employment Verification',
            'body' => 'Smoke test body',
        ],
        'expect_http' => 200,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 1
                && ($json['resolved'] ?? null) === false
                && in_array(($json['reason'] ?? ''), ['NO_MATCH', 'PARTIAL_MATCH'], true);
        },
    ],
];

$failed = false;
foreach ($tests as $test) {
    $result = wrie_test_post($url, $test['payload'], $token ?: '');
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

exit($failed ? 2 : 0);
