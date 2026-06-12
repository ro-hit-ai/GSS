<?php
require_once __DIR__ . '/env.php';

if (!function_exists('php_sendnodemailer_trace')) {
    function php_sendnodemailer_trace(string $event, array $data = []): void {
        try {
            $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
            $dir = $root . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $file = $dir . DIRECTORY_SEPARATOR . 'php_sendnodemailer_runtime_trace.log';
            $safe = [];
            foreach ($data as $key => $value) {
                $lower = strtolower((string)$key);
                if (strpos($lower, 'key') !== false || strpos($lower, 'token') !== false || strpos($lower, 'password') !== false || strpos($lower, 'secret') !== false) {
                    $safe[$key] = is_string($value) && $value !== '' ? ('***masked:length=' . strlen($value)) : '***masked';
                } elseif ($key === 'headers' && is_array($value)) {
                    $safeHeaders = [];
                    foreach ($value as $headerKey => $headerValue) {
                        $headerName = is_string($headerKey) ? $headerKey : '';
                        $headerLine = is_string($headerValue) ? $headerValue : '';
                        $headerLower = strtolower($headerName !== '' ? $headerName : $headerLine);
                        if (strpos($headerLower, 'api-key') !== false || strpos($headerLower, 'authorization') !== false || strpos($headerLower, 'token') !== false) {
                            $safeHeaders[$headerKey] = '***masked';
                        } else {
                            $safeHeaders[$headerKey] = $headerValue;
                        }
                    }
                    $safe[$key] = $safeHeaders;
                } elseif (is_string($value) && strlen($value) > 2000) {
                    $safe[$key] = substr($value, 0, 2000) . '...<truncated>';
                } else {
                    $safe[$key] = $value;
                }
            }
            $line = json_encode([
                'ts' => date('c'),
                'event' => $event,
                'data' => $safe,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line !== false) {
                @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable $e) {
        }
    }
}

function sendNodeMailer($to, $subject, $htmlBody, $queueId = null, array $headers = [], array $metadata = []): array {
    php_sendnodemailer_trace('enter_sendNodeMailer', [
        'to_present' => trim((string)$to) !== '',
        'subject' => $subject,
        'queueId' => $queueId,
        'headers' => $headers,
        'metadata' => $metadata,
    ]);
    $nodeBase = trim((string)(function_exists('env_get') ? (env_get('NODE_API_URL', '') ?? '') : ''));
    if ($nodeBase === '') $nodeBase = trim((string)getenv('NODE_API_URL'));
    $nodeBase = rtrim($nodeBase, '/');

    $apiKey = trim((string)(function_exists('env_get') ? (env_get('NODE_API_KEY', '') ?? '') : ''));
    if ($apiKey === '') $apiKey = trim((string)getenv('NODE_API_KEY'));

    if ($nodeBase === '') {
        php_sendnodemailer_trace('sendNodeMailer_config_error', ['error' => 'NODE_API_URL is empty']);
        return ['success' => false, 'error' => 'NODE_API_URL is empty'];
    }
    if ($apiKey === '') {
        php_sendnodemailer_trace('sendNodeMailer_config_error', ['error' => 'NODE_API_KEY is empty']);
        return ['success' => false, 'error' => 'NODE_API_KEY is empty'];
    }

    $to = trim((string)$to);  
    $subject = trim((string)$subject);
    $htmlBody = (string)$htmlBody;
    if ($to === '' || $subject === '' || $htmlBody === '') {
        php_sendnodemailer_trace('sendNodeMailer_validation_error', [
            'to_present' => $to !== '',
            'subject_present' => $subject !== '',
            'htmlBody_present' => $htmlBody !== '',
        ]);
        return ['success' => false, 'error' => 'Missing required fields: to/subject/htmlBody'];
    }

    $nodeUrl = $nodeBase . '/api/v1/php/send-email';
    php_sendnodemailer_trace('sendNodeMailer_url_resolved', [
        'nodeUrl' => $nodeUrl,
        'nodeBase' => $nodeBase,
        'apiKey' => $apiKey,
    ]);
    $payload = [
        'to' => $to,
        'subject' => $subject,
        'htmlBody' => $htmlBody
    ];
    $effectiveQueueId = trim((string)$queueId);
    if ($effectiveQueueId === '') {
        $effectiveQueueId = trim((string)(env_get('NODE_QUEUE_ID', '') ?? ''));
    }
    if ($effectiveQueueId === '') {
        $effectiveQueueId = trim((string)(env_get('NODE_DEFAULT_QUEUE_ID', '') ?? ''));
    }
    // Always send queueId when resolved from any source.
    if ($effectiveQueueId !== '') {
        $payload['queueId'] = $effectiveQueueId;
    }
    if (!empty($headers)) {
        $payload['headers'] = $headers;
    }
    if (!empty($metadata)) {
        $payload['metadata'] = $metadata;
    }

    $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonBody === false) {
        php_sendnodemailer_trace('sendNodeMailer_json_error', ['error' => json_last_error_msg()]);
        return ['success' => false, 'error' => 'JSON encode failed: ' . json_last_error_msg()];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . $apiKey
    ];
    php_sendnodemailer_trace('sendNodeMailer_before_curl_exec', [
        'nodeUrl' => $nodeUrl,
        'headers' => $headers,
        'payload_keys' => array_keys($payload),
        'payload_metadata' => $metadata,
    ]);

    $ch = curl_init($nodeUrl);
    if ($ch === false) {
        php_sendnodemailer_trace('sendNodeMailer_curl_init_failed', ['nodeUrl' => $nodeUrl]);
        return ['success' => false, 'error' => 'Failed to initialize cURL'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    php_sendnodemailer_trace('sendNodeMailer_after_curl_exec', [
        'curl_errno' => $curlErrNo,
        'curl_error' => $curlErr,
        'http_code' => $httpCode,
        'response_body' => is_string($raw) ? $raw : null,
    ]);

    if ($curlErrNo !== 0) {
        return [
            'success' => false,
            'error' => 'cURL error #' . $curlErrNo . ': ' . $curlErr,
            'http_code' => $httpCode,
        ];
    }

    $decoded = null;
    $responseBody = is_string($raw) ? trim($raw) : '';
    if ($responseBody !== '') {
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded) && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response: ' . json_last_error_msg(),
                'http_code' => $httpCode,
                'raw_response' => $responseBody,
            ];
        }
    }

    $httpOk = $httpCode >= 200 && $httpCode < 300;
    $apiOk = is_array($decoded) ? (($decoded['success'] ?? true) === true) : $httpOk;
    $ok = $httpOk && $apiOk;

    return [
        'success' => $ok,
        'http_code' => $httpCode,
        'response' => is_array($decoded) ? $decoded : null,
        'raw_response' => $responseBody,
        'error' => $ok ? null : (is_array($decoded) ? (string)($decoded['error'] ?? 'Node API returned failure') : 'Node API request failed'),
    ];
}
