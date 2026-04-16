<?php
require_once __DIR__ . '/env.php';

function sendNodeMailer($to, $subject, $htmlBody, $queueId = null, array $headers = [], array $metadata = []): array {
    $nodeBase = trim((string)(function_exists('env_get') ? (env_get('NODE_API_URL', '') ?? '') : ''));
    if ($nodeBase === '') $nodeBase = trim((string)getenv('NODE_API_URL'));
    $nodeBase = rtrim($nodeBase, '/');

    $apiKey = trim((string)(function_exists('env_get') ? (env_get('NODE_API_KEY', '') ?? '') : ''));
    if ($apiKey === '') $apiKey = trim((string)getenv('NODE_API_KEY'));

    if ($nodeBase === '') {
        return ['success' => false, 'error' => 'NODE_API_URL is empty'];
    }
    if ($apiKey === '') {
        return ['success' => false, 'error' => 'NODE_API_KEY is empty'];
    }

    $to = trim((string)$to);  
    $subject = trim((string)$subject);
    $htmlBody = (string)$htmlBody;
    if ($to === '' || $subject === '' || $htmlBody === '') {
        return ['success' => false, 'error' => 'Missing required fields: to/subject/htmlBody'];
    }

    $nodeUrl = $nodeBase . '/api/v1/php/send-email';
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
        return ['success' => false, 'error' => 'JSON encode failed: ' . json_last_error_msg()];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . $apiKey
    ];

    $ch = curl_init($nodeUrl);
    if ($ch === false) {
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
