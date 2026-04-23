<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/mail_log.php';
require_once __DIR__ . '/integration.php';
require_once __DIR__ . '/../config/nodemailer.php';


$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

define('NODE_API_URL', env_get('NODE_API_URL', 'http://localhost:3000'));
define('NODE_API_KEY', env_get('NODE_API_KEY', '')); // Set this in your .env
define('NODE_APP_HEADER', 'VATI-GSS-PHP');

function app_mail_debug_enabled(): bool {
    return trim((string)(env_get('APP_MAIL_DEBUG', '0') ?? '0')) === '1';
}

function app_mail_debug_log(string $message): void {
    if (!app_mail_debug_enabled()) return;

    $prefix = '[' . date('Y-m-d H:i:s') . '] ';
    $line = $prefix . $message . "\r\n";

    $configured = trim((string)(env_get('APP_MAIL_DEBUG_LOG', '') ?? ''));
    $default = realpath(__DIR__ . '/..');
    $defaultLog = $default ? ($default . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'mail.log') : '';
    $logFile = $configured !== '' ? $configured : $defaultLog;

    if ($logFile !== '') {
        $dir = dirname($logFile);
        if (is_dir($dir) && is_writable($dir)) {
            @error_log($line, 3, $logFile);
            return;
        }
    }

    @error_log($line);
}

function app_mail_set_log_meta(array $meta): void {
    $GLOBALS['APP_MAIL_LOG_META'] = $meta;
}

function app_mail_clear_log_meta(): void {
    $GLOBALS['APP_MAIL_LOG_META'] = [];
}

function app_mail_get_log_meta(): array {
    $m = $GLOBALS['APP_MAIL_LOG_META'] ?? [];
    return is_array($m) ? $m : [];
}

function app_mail_pick_queue_id(array $metadata): ?string {
    $isObjectId = static function (string $v): bool {
        return (bool)preg_match('/^[a-f0-9]{24}$/i', $v);
    };
    $normalize = static function ($v) use ($isObjectId): ?string {
        $s = trim((string)$v);
        if ($s === '') return null;
        return $isObjectId($s) ? $s : null;
    };

    // Priority:
    // 1) metadata['queueId']
    // 2) metadata['queue_id']
    // 3) metadata['queue']
    // 4) NODE_QUEUE_ID
    // 5) NODE_DEFAULT_QUEUE_ID
    foreach (['queueId', 'queue_id', 'queue'] as $k) {
        if (array_key_exists($k, $metadata)) {
            $picked = $normalize($metadata[$k]);
            if ($picked !== null) return $picked;
        }
    }

    $envQueue = $normalize(env_get('NODE_QUEUE_ID', ''));
    if ($envQueue !== null) return $envQueue;

    $defaultQueue = $normalize(env_get('NODE_DEFAULT_QUEUE_ID', ''));
    if ($defaultQueue !== null) return $defaultQueue;

    return null;
}

function app_node_api_base_url(): string {
    return rtrim(trim((string)NODE_API_URL), '/');
}

function app_node_api_url(string $path): string {
    $base = app_node_api_base_url();
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

function app_node_api_validate_config(): ?string {
    $base = app_node_api_base_url();
    if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL)) {
        return 'Invalid NODE_API_URL: ' . (string)NODE_API_URL;
    }
    if (trim((string)NODE_API_KEY) === '') {
        return 'NODE_API_KEY is empty';
    }
    return null;
}

function app_node_api_json_request(string $method, string $path, ?array $payload = null, int $timeout = 30): array {
    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'error' => 'cURL extension is not enabled',
            'http_code' => 0
        ];
    }

    $configError = app_node_api_validate_config();
    if ($configError !== null) {
        return [
            'success' => false,
            'error' => $configError,
            'http_code' => 0
        ];
    }

    $url = app_node_api_url($path);
    $method = strtoupper(trim($method));
    $jsonBody = null;
    if ($payload !== null) {
        $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonBody === false) {
            return [
                'success' => false,
                'error' => 'JSON encode failed: ' . json_last_error_msg(),
                'http_code' => 0
            ];
        }
    }

    $scheme = (string)parse_url($url, PHP_URL_SCHEME);
    $verifySsl = strtolower($scheme) === 'https'
        ? trim((string)(env_get('NODE_API_VERIFY_SSL', '1') ?? '1')) !== '0'
        : false;

    $headers = [
        'Accept: application/json',
        'X-API-Key: ' . trim((string)NODE_API_KEY),
        'X-Application: ' . NODE_APP_HEADER
    ];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $timeout));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    }

    $rawBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrNo = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlErrNo !== 0) {
        return [
            'success' => false,
            'error' => 'cURL error #' . $curlErrNo . ': ' . $curlError,
            'http_code' => $httpCode
        ];
    }

    $responseBody = is_string($rawBody) ? trim($rawBody) : '';
    $decoded = null;
    if ($responseBody !== '') {
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded) && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response: ' . json_last_error_msg(),
                'http_code' => $httpCode,
                'raw_response' => $responseBody
            ];
        }
    }

    $isHttpOk = $httpCode >= 200 && $httpCode < 300;
    $apiSuccess = is_array($decoded) ? (($decoded['success'] ?? true) === true) : $isHttpOk;
    $success = $isHttpOk && $apiSuccess;

    return [
        'success' => $success,
        'http_code' => $httpCode,
        'response' => is_array($decoded) ? $decoded : null,
        'raw_response' => $responseBody
    ];
}

/**
 * Send email via Node.js service
 */
function send_via_node(string $to, string $subject, string $htmlBody, ?string $fromName = null, array $attachments = [], array $metadata = [], array $headers = []): array {
    if (!function_exists('sendNodeMailer')) {
        return ['success' => false, 'error' => 'sendNodeMailer() not found'];
    }

    $queueId = app_mail_pick_queue_id($metadata);
    $response = sendNodeMailer($to, $subject, $htmlBody, $queueId, $headers, $metadata);
    if (!is_array($response)) {
        return ['success' => false, 'error' => 'Invalid Node mailer response'];
    }

    $ok = (bool)($response['success'] ?? false);
    return [
        'success' => $ok,
        'error' => $ok ? null : (string)($response['error'] ?? $response['message'] ?? 'Unknown Node error'),
        'response' => $response
    ];
}

/**
 * Send bulk emails via Node.js
 */
function send_bulk_via_node(array $emails): array {
    $payload = ['emails' => []];
    $fromEmail = (string)(env_get('APP_MAIL_FROM', '') ?? '');
    
    foreach ($emails as $email) {
        $payload['emails'][] = [
            'to' => $email['to'],
            'subject' => $email['subject'],
            'htmlBody' => $email['htmlBody'],
            'html' => $email['htmlBody'],
            'fromName' => $email['fromName'] ?? null,
            'fromEmail' => $email['fromEmail'] ?? $fromEmail
        ];
    }

    $result = app_node_api_json_request('POST', '/api/php/send-bulk', $payload, 60);
    if ($result['success']) {
        return $result['response'] ?? ['success' => true];
    }

    return [
        'success' => false,
        'error' => $result['error'] ?? ($result['response']['error'] ?? 'Bulk email failed'),
        'http_code' => $result['http_code'] ?? 0
    ];
}

/**
 * Check Node.js service health
 */
function is_node_service_healthy(): bool {
    $result = app_node_api_json_request('GET', '/api/php/health', null, 5);
    return $result['success'] === true;
}

function app_mail_resolve_application_id(array $meta, array $options): string {
    $raw = '';
    if (isset($options['application_id'])) {
        $raw = trim((string)$options['application_id']);
    }
    if ($raw === '' && isset($meta['application_id'])) {
        $raw = trim((string)$meta['application_id']);
    }

    if ($raw === '') {
        return '';
    }

    if (function_exists('integration_normalize_application_id')) {
        return integration_normalize_application_id($raw);
    }

    return strtoupper($raw);
}

function app_mail_apply_application_subject_tag(string $subject, string $applicationId): string {
    $subject = trim($subject);
    if ($applicationId === '') {
        return $subject;
    }

    if (preg_match('/\[\s*APP-[^\]]+\]/i', $subject) === 1) {
        return $subject;
    }

    return '[' . $applicationId . '] ' . $subject;
}

function send_app_mail(string $to, string $subject, string $htmlBody, ?string $fromName = null, array $options = []): bool {
    $to = trim($to);
    $meta = app_mail_get_log_meta();
    $driver = 'node';
    $applicationId = app_mail_resolve_application_id($meta, $options);
    $subject = app_mail_apply_application_subject_tag($subject, $applicationId);
    $headersMap = integration_mail_headers($meta, $options);

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        mail_log_event('failed', $driver, (string)(env_get('APP_MAIL_FROM', '') ?? ''), $to, $subject, $meta, 'Invalid recipient email');
        return false;
    }

    $fromEmail = (string)(env_get('APP_MAIL_FROM', '') ?? '');
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        mail_log_event('failed', $driver, $fromEmail, $to, $subject, $meta, 'Invalid from email');
        return false;
    }

    // Check if Node service is available
    $useNode = env_get('USE_NODE_EMAIL', '1') === '1';
    
    if ($useNode && NODE_API_KEY) {
        app_mail_debug_log('Attempting to send via Node.js service');
        
        // Extract ticket ID from meta if present
        $metadata = [
            'template_id' => $meta['template_id'] ?? null,
            'application_id' => $applicationId !== '' ? $applicationId : ($meta['application_id'] ?? null),
            'case_id' => $meta['case_id'] ?? null,
            'role' => $meta['role'] ?? null,
            'event_type' => $meta['event_type'] ?? ($options['event_type'] ?? null),
            'queue_id' => $meta['queue_id'] ?? ($meta['queueId'] ?? ($meta['queue'] ?? null)),
        ];

        $result = send_via_node($to, $subject, $htmlBody, $fromName, [], $metadata, $headersMap);
        
        if ($result['success']) {
            app_mail_debug_log('Email sent successfully via Node.js');
            mail_log_event('sent', $driver, $fromEmail, $to, $subject, $meta, null);
            return true;
        } else {
            $error = $result['error'] ?? ($result['response']['error'] ?? 'Unknown error');
            app_mail_debug_log('Node.js service failed: ' . $error);
            
            // Fallback to PHP mail if configured
            if (env_get('FALLBACK_TO_PHP_MAIL', '0') === '1') {
                app_mail_debug_log('Falling back to PHP mail()');
                return send_app_mail_php_fallback($to, $subject, $htmlBody, $fromName, $fromEmail, $meta, $headersMap);
            }
            
            mail_log_event('failed', $driver, $fromEmail, $to, $subject, $meta, 'Node service error: ' . $error);
            return false;
        }
    } else {
        // Use PHP mail directly
        return send_app_mail_php_fallback($to, $subject, $htmlBody, $fromName, $fromEmail, $meta, $headersMap);
    }
}

/**
 * Original PHP mail fallback
 */
function send_app_mail_php_fallback(string $to, string $subject, string $htmlBody, ?string $fromName, string $fromEmail, array $meta, array $extraHeaders = []): bool {
    $envFromName = (string)(env_get('APP_MAIL_FROM_NAME', '') ?? '');
    $effectiveFromName = trim((string)($fromName ?? ''));
    if ($effectiveFromName === '' && $envFromName !== '') $effectiveFromName = $envFromName;
    if ($effectiveFromName === '') $effectiveFromName = 'VATI GSS';

    $effectiveFromName = str_replace(["\r", "\n"], '', $effectiveFromName);
    $fromEmail = str_replace(["\r", "\n"], '', $fromEmail);

    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
        : $subject;

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . $effectiveFromName . ' <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $effectiveFromName . ' <' . $fromEmail . '>';
    $headers[] = 'X-Mailer: PHP/' . PHP_VERSION;
    foreach ($extraHeaders as $headerName => $headerValue) {
        $safeName = trim(str_replace(["\r", "\n"], '', (string)$headerName));
        $safeValue = trim(str_replace(["\r", "\n"], '', (string)$headerValue));
        if ($safeName === '' || $safeValue === '') {
            continue;
        }
        $headers[] = $safeName . ': ' . $safeValue;
    }

    $params = '-f' . $fromEmail;
    $ok = @mail($to, $encodedSubject, $htmlBody, implode("\r\n", $headers), $params);
    
    mail_log_event($ok ? 'sent' : 'failed', 'php_fallback', $fromEmail, $to, $subject, $meta, $ok ? null : 'mail() returned false');
    return $ok;
}
