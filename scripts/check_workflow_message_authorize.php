<?php
/**
 * Smoke tests for /api/shared/workflow_message_authorize.php.
 *
 * Usage:
 *   php scripts/check_workflow_message_authorize.php
 *
 * Optional env for live message checks:
 *   GSS_BASE_URL=http://localhost/GSS
 *   MINTLEAF_SERVICE_TOKEN=...
 *   INTEGRATION_SERVICE_TOKEN=...
 *   WMA_USER_ID=255
 *   WMA_USER_ROLE=verifier
 *   WMA_APP_ID=APP-...
 *   WMA_COMPONENT=employment
 *   WMA_OWNER_ROLE=verifier
 *   WMA_THREAD_ID=wf:APP-...:employment:verifier
 *   WMA_MESSAGE_ID=<message@example.com>
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
$url = $base . '/api/shared/workflow_message_authorize.php';

if (trim((string)$token) === '') {
    echo "SKIP: MINTLEAF_SERVICE_TOKEN or INTEGRATION_SERVICE_TOKEN is required to run endpoint smoke tests.\n";
    exit(0);
}

function wma_test_post(string $url, array $payload, string $token): array
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
        'name' => 'invalid user returns USER_NOT_FOUND before message lookup',
        'payload' => [
            'userId' => 999999999,
            'applicationId' => 'APP-NONEXISTENT',
            'componentKey' => 'employment',
            'ownerRole' => 'verifier',
            'messageId' => '<missing@example.com>',
        ],
        'expect_http' => 404,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 0
                && ($json['code'] ?? '') === 'USER_NOT_FOUND';
        },
    ],
];

$userId = (int)(getenv('WMA_USER_ID') ?: 0);
$appId = trim((string)(getenv('WMA_APP_ID') ?: ''));
$messageId = trim((string)(getenv('WMA_MESSAGE_ID') ?: ''));
if ($userId > 0 && $appId !== '') {
    $role = trim((string)(getenv('WMA_USER_ROLE') ?: 'verifier'));
    $component = trim((string)(getenv('WMA_COMPONENT') ?: 'employment'));
    $ownerRole = trim((string)(getenv('WMA_OWNER_ROLE') ?: $role));
    $threadId = trim((string)(getenv('WMA_THREAD_ID') ?: ''));

    $missingPayload = [
        'userId' => $userId,
        'role' => $role,
        'applicationId' => $appId,
        'componentKey' => $component,
        'ownerRole' => $ownerRole,
        'messageId' => '<missing-message-authorize@example.invalid>',
    ];
    $tests[] = [
        'name' => 'missing message returns message_not_found',
        'payload' => $missingPayload,
        'expect_http' => 200,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 1
                && ($json['allowed'] ?? null) === false
                && ($json['reason'] ?? '') === 'message_not_found';
        },
    ];

    $tests[] = [
        'name' => 'missing application returns APPLICATION_NOT_FOUND',
        'payload' => array_merge($missingPayload, [
            'applicationId' => 'APP-NONEXISTENT-WMA',
        ]),
        'expect_http' => 404,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 0
                && ($json['code'] ?? '') === 'APPLICATION_NOT_FOUND';
        },
    ];

    if ($messageId !== '') {
        $basePayload = [
            'userId' => $userId,
            'role' => $role,
            'applicationId' => $appId,
            'componentKey' => $component,
            'ownerRole' => $ownerRole,
            'messageId' => $messageId,
        ];
        if ($threadId !== '') {
            $basePayload['threadId'] = $threadId;
        }

        $tests[] = [
            'name' => 'visible message returns authorization decision',
            'payload' => $basePayload,
            'expect_http' => 200,
            'expect' => static function (array $json): bool {
                return ($json['status'] ?? null) === 1
                    && array_key_exists('allowed', $json)
                    && isset($json['messageId'])
                    && isset($json['componentKey'])
                    && isset($json['threadOwnerRole']);
            },
        ];
        $tests[] = [
            'name' => 'wrong component returns hidden or missing decision',
            'payload' => array_merge($basePayload, [
                'componentKey' => $component === 'employment' ? 'education' : 'employment',
            ]),
            'expect_http' => 200,
            'expect' => static function (array $json): bool {
                return ($json['status'] ?? null) === 1
                    && ($json['allowed'] ?? null) === false
                    && in_array(($json['reason'] ?? ''), ['component_not_visible', 'message_not_visible'], true);
            },
        ];
        $tests[] = [
            'name' => 'wrong owner role returns denied decision',
            'payload' => array_merge($basePayload, [
                'ownerRole' => $ownerRole === 'verifier' ? 'validator' : 'verifier',
            ]),
            'expect_http' => 200,
            'expect' => static function (array $json): bool {
                return ($json['status'] ?? null) === 1
                    && ($json['allowed'] ?? null) === false
                    && in_array(($json['reason'] ?? ''), ['thread_not_visible', 'message_not_visible'], true);
            },
        ];
        $tests[] = [
            'name' => 'wrong thread returns thread_not_visible when target has a thread',
            'payload' => array_merge($basePayload, [
                'threadId' => 'wf:mismatch:' . strtolower($component) . ':' . strtolower($ownerRole),
            ]),
            'expect_http' => 200,
            'expect' => static function (array $json): bool {
                return ($json['status'] ?? null) === 1
                    && ($json['allowed'] ?? null) === false
                    && in_array(($json['reason'] ?? ''), ['thread_not_visible', 'message_not_visible'], true);
            },
        ];
        $tests[] = [
            'name' => 'split reference component key is accepted',
            'payload' => array_merge($basePayload, [
                'componentKey' => 'education_reference',
            ]),
            'expect_http' => 200,
            'expect' => static function (array $json): bool {
                return ($json['status'] ?? null) === 1
                    && array_key_exists('allowed', $json);
            },
        ];
    }
}

$failed = false;
foreach ($tests as $test) {
    $result = wma_test_post($url, $test['payload'], $token);
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

if ($userId <= 0 || $appId === '' || $messageId === '') {
    echo "NOTE: set WMA_USER_ID, WMA_APP_ID and WMA_MESSAGE_ID to run live message visibility checks.\n";
}

exit($failed ? 2 : 0);
