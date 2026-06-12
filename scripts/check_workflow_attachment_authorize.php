<?php
/**
 * Smoke tests for /api/shared/workflow_attachment_authorize.php.
 *
 * Usage:
 *   php scripts/check_workflow_attachment_authorize.php
 *
 * Optional env for live attachment checks:
 *   GSS_BASE_URL=http://localhost/GSS
 *   MINTLEAF_SERVICE_TOKEN=...
 *   INTEGRATION_SERVICE_TOKEN=...
 *   WAA_USER_ID=255
 *   WAA_USER_ROLE=verifier
 *   WAA_APP_ID=APP-...
 *   WAA_COMPONENT=employment
 *   WAA_OWNER_ROLE=verifier
 *   WAA_THREAD_ID=wf:APP-...:employment:verifier
 *   WAA_MESSAGE_ID=<message@example.com>
 *   WAA_ATTACHMENT_ID=node-attachment-id
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
$url = $base . '/api/shared/workflow_attachment_authorize.php';

if (trim((string)$token) === '') {
    echo "SKIP: MINTLEAF_SERVICE_TOKEN or INTEGRATION_SERVICE_TOKEN is required to run endpoint smoke tests.\n";
    exit(0);
}

function waa_test_post(string $url, array $payload, string $token): array
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
        'name' => 'missing attachment returns attachment_not_found',
        'payload' => [
            'userId' => 1,
            'applicationId' => 'APP-NONEXISTENT',
            'componentKey' => 'employment',
            'ownerRole' => 'verifier',
            'messageId' => '<missing@example.com>',
        ],
        'expect_http' => 200,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 1
                && ($json['allowed'] ?? null) === false
                && ($json['reason'] ?? '') === 'attachment_not_found'
                && ($json['messageAllowed'] ?? null) === false;
        },
    ],
    [
        'name' => 'invalid user follows message authorization',
        'payload' => [
            'userId' => 999999999,
            'applicationId' => 'APP-NONEXISTENT',
            'componentKey' => 'employment',
            'ownerRole' => 'verifier',
            'messageId' => '<missing@example.com>',
            'attachmentId' => 'node-attachment-id',
        ],
        'expect_http' => 404,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 0
                && ($json['code'] ?? '') === 'USER_NOT_FOUND';
        },
    ],
];

$userId = (int)(getenv('WAA_USER_ID') ?: 0);
$appId = trim((string)(getenv('WAA_APP_ID') ?: ''));
$messageId = trim((string)(getenv('WAA_MESSAGE_ID') ?: ''));
$attachmentId = trim((string)(getenv('WAA_ATTACHMENT_ID') ?: 'node-attachment-id'));
if ($userId > 0 && $appId !== '') {
    $role = trim((string)(getenv('WAA_USER_ROLE') ?: 'verifier'));
    $component = trim((string)(getenv('WAA_COMPONENT') ?: 'employment'));
    $ownerRole = trim((string)(getenv('WAA_OWNER_ROLE') ?: $role));
    $threadId = trim((string)(getenv('WAA_THREAD_ID') ?: ''));

    $missingPayload = [
        'userId' => $userId,
        'role' => $role,
        'applicationId' => $appId,
        'componentKey' => $component,
        'ownerRole' => $ownerRole,
        'messageId' => '<missing-attachment-authorize@example.invalid>',
        'attachmentId' => $attachmentId,
    ];
    $tests[] = [
        'name' => 'missing message returns message_not_found',
        'payload' => $missingPayload,
        'expect_http' => 200,
        'expect' => static function (array $json): bool {
            return ($json['status'] ?? null) === 1
                && ($json['allowed'] ?? null) === false
                && ($json['reason'] ?? '') === 'message_not_found'
                && ($json['messageAllowed'] ?? null) === false;
        },
    ];

    $tests[] = [
        'name' => 'missing application returns APPLICATION_NOT_FOUND',
        'payload' => array_merge($missingPayload, [
            'applicationId' => 'APP-NONEXISTENT-WAA',
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
            'attachmentId' => $attachmentId,
        ];
        if ($threadId !== '') {
            $basePayload['threadId'] = $threadId;
        }

        $tests[] = [
            'name' => 'visible attachment inherits message decision',
            'payload' => $basePayload,
            'expect_http' => 200,
            'expect' => static function (array $json): bool {
                return ($json['status'] ?? null) === 1
                    && array_key_exists('allowed', $json)
                    && array_key_exists('messageAllowed', $json)
                    && isset($json['messageDecision']);
            },
        ];
        $tests[] = [
            'name' => 'wrong component denies through message visibility',
            'payload' => array_merge($basePayload, [
                'componentKey' => $component === 'employment' ? 'education' : 'employment',
            ]),
            'expect_http' => 200,
            'expect' => static function (array $json): bool {
                return ($json['status'] ?? null) === 1
                    && ($json['allowed'] ?? null) === false
                    && in_array(($json['reason'] ?? ''), ['message_not_visible', 'message_not_found'], true);
            },
        ];
        $tests[] = [
            'name' => 'wrong thread denies through message visibility',
            'payload' => array_merge($basePayload, [
                'threadId' => 'wf:mismatch:' . strtolower($component) . ':' . strtolower($ownerRole),
            ]),
            'expect_http' => 200,
            'expect' => static function (array $json): bool {
                return ($json['status'] ?? null) === 1
                    && ($json['allowed'] ?? null) === false
                    && in_array(($json['reason'] ?? ''), ['message_not_visible', 'message_not_found'], true);
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
    $result = waa_test_post($url, $test['payload'], $token);
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
    echo "NOTE: set WAA_USER_ID, WAA_APP_ID and WAA_MESSAGE_ID to run live attachment visibility checks.\n";
}

exit($failed ? 2 : 0);
