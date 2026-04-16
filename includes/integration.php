<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';

function integration_bootstrap_json_api(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    error_reporting(E_ALL);

    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function integration_log(string $channel, string $message, array $context = []): void
{
    $channel = preg_replace('/[^a-z0-9_.-]+/i', '_', strtolower(trim($channel)));
    if ($channel === '') {
        $channel = 'integration';
    }

    $root = realpath(__DIR__ . '/..');
    $logDir = $root ? ($root . DIRECTORY_SEPARATOR . 'logs') : '';
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . trim($message);

    if (!empty($context)) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            $line .= ' ' . $json;
        }
    }

    $line .= PHP_EOL;

    if ($logDir !== '' && (is_dir($logDir) || @mkdir($logDir, 0777, true))) {
        @error_log($line, 3, $logDir . DIRECTORY_SEPARATOR . $channel . '.log');
        return;
    }

    @error_log($line);
}

function integration_json_response(int $httpCode, array $payload): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function integration_json_error(int $httpCode, string $message, array $extra = [], ?string $logChannel = null): void
{
    if ($logChannel !== null) {
        integration_log($logChannel, $message, $extra);
    }

    integration_json_response($httpCode, array_merge([
        'status' => 0,
        'message' => $message,
    ], $extra));
}

function integration_request_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return trim((string)$_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $headerName => $value) {
                if (strcasecmp((string)$headerName, $name) === 0) {
                    return trim((string)$value);
                }
            }
        }
    }

    return '';
}

function integration_normalize_application_id(?string $value): string
{
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/\s+/', '', $value);
    return is_string($value) ? $value : '';
}

function integration_normalize_email(?string $value): string
{
    return strtolower(trim((string)$value));
}

function integration_nullable_string($value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function integration_iso_datetime($value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($raw);
        return $dt->format('Y-m-d\TH:i:sP');
    } catch (Throwable $e) {
        return $raw;
    }
}

function integration_deep_links(string $applicationId, ?int $caseId = null): array
{
    $applicationId = integration_normalize_application_id($applicationId);
    $query = '?application_id=' . rawurlencode($applicationId);
    if ($caseId !== null && $caseId > 0) {
        $query .= '&case_id=' . rawurlencode((string)$caseId);
    }

    return [
        'applicationUrl' => app_url('/modules/shared/candidate_report.php' . $query),
        'candidateUrl' => app_url('/modules/shared/candidate_report.php' . $query),
        'timelineUrl' => app_url('/modules/qa/case_review.php' . $query),
    ];
}

function integration_get_service_token(): string
{
    $authHeader = integration_request_header('Authorization');
    if ($authHeader !== '' && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        return trim((string)$m[1]);
    }

    $token = integration_request_header('X-Service-Token');
    if ($token !== '') {
        return $token;
    }

    return trim((string)($_GET['service_token'] ?? $_POST['service_token'] ?? ''));
}

function integration_is_valid_service_token(): bool
{
    $provided = integration_get_service_token();
    if ($provided === '') {
        return false;
    }

    $expected = trim((string)(env_get('MINTLEAF_SERVICE_TOKEN', '') ?? ''));
    if ($expected === '') {
        $expected = trim((string)(env_get('INTEGRATION_SERVICE_TOKEN', '') ?? ''));
    }
    if ($expected === '') {
        return false;
    }

    return hash_equals($expected, $provided);
}

function integration_role_normalized(?string $role): string
{
    $role = strtolower(trim((string)$role));
    if ($role === 'customer_admin') {
        $role = 'client_admin';
    }
    if ($role === 'company_recruiter') {
        $role = 'company_recruiter';
    }
    return $role;
}

function integration_resolve_actor(bool $allowServiceToken = true, array $allowedRoles = []): array
{
    auth_session_start();

    $userId = auth_user_id();
    $role = integration_role_normalized(auth_module_access());

    if ($userId > 0) {
        if (!empty($allowedRoles) && !in_array($role, $allowedRoles, true)) {
            integration_log('auth', 'Forbidden API role', ['role' => $role, 'allowedRoles' => $allowedRoles, 'path' => $_SERVER['REQUEST_URI'] ?? '']);
            integration_json_error(403, 'Forbidden');
        }

        return [
            'authType' => 'session',
            'userId' => $userId,
            'role' => $role,
            'clientId' => isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0,
            'username' => (string)($_SESSION['auth_user_name'] ?? ''),
            'email' => integration_normalize_email((string)($_SESSION['auth_user_email'] ?? '')),
            'service' => false,
        ];
    }

    if ($allowServiceToken && integration_is_valid_service_token()) {
        return [
            'authType' => 'service_token',
            'userId' => 0,
            'role' => 'service',
            'clientId' => 0,
            'username' => 'MintLeaf',
            'email' => null,
            'service' => true,
        ];
    }

    integration_log('auth', 'Unauthorized API request', ['path' => $_SERVER['REQUEST_URI'] ?? '']);
    integration_json_error(401, 'Unauthorized');
}

function integration_mail_headers(array $meta = [], array $overrides = []): array
{
    $applicationId = integration_normalize_application_id((string)($overrides['application_id'] ?? $meta['application_id'] ?? ''));
    $eventType = trim((string)($overrides['event_type'] ?? $meta['event_type'] ?? ''));
    $userId = trim((string)($overrides['user_id'] ?? $meta['user_id'] ?? ($_SESSION['auth_user_id'] ?? '')));
    $userRole = trim((string)($overrides['user_role'] ?? $meta['user_role'] ?? $meta['role'] ?? ($_SESSION['auth_moduleAccess'] ?? '')));

    $headers = [];
    if ($applicationId !== '') {
        $headers['X-Application-Id'] = $applicationId;
        $headers['X-SourceCaseId'] = $applicationId;
    }
    if ($eventType !== '') {
        $headers['X-VATI-Event-Type'] = $eventType;
    }
    if ($userId !== '') {
        $headers['X-VATI-User-Id'] = $userId;
    }
    if ($userRole !== '') {
        $headers['X-VATI-User-Role'] = $userRole;
    }

    return $headers;
}

function integration_webhook_url(): string
{
    $url = trim((string)(env_get('MINTLEAF_WEBHOOK_URL', '') ?? ''));
    if ($url === '') {
        $url = trim((string)(env_get('MINTLEAF_EVENTS_WEBHOOK_URL', '') ?? ''));
    }
    return $url;
}

function integration_webhook_secret(): string
{
    $secret = trim((string)(env_get('MINTLEAF_WEBHOOK_SECRET', '') ?? ''));
    if ($secret === '') {
        $secret = trim((string)(env_get('INTEGRATION_WEBHOOK_SECRET', '') ?? ''));
    }
    return $secret;
}

function integration_event_id(string $eventType, string $applicationId, ?int $caseId, array $metadata = []): string
{
    $seed = [
        'eventType' => $eventType,
        'applicationId' => integration_normalize_application_id($applicationId),
        'caseId' => $caseId ?: null,
        'metadata' => $metadata,
    ];
    return 'vati_' . sha1(json_encode($seed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function integration_fetch_case_summary(PDO $pdo, string $applicationId): ?array
{
    $applicationId = integration_normalize_application_id($applicationId);
    if ($applicationId === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT c.case_id,
                    c.application_id,
                    c.candidate_first_name,
                    c.candidate_last_name,
                    c.candidate_email,
                    c.case_status,
                    app.status AS application_status
               FROM Vati_Payfiller_Cases c
               LEFT JOIN Vati_Payfiller_Candidate_Applications app ON app.application_id = c.application_id
              WHERE c.application_id = ?
              LIMIT 1"
        );
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        return [
            'applicationId' => integration_normalize_application_id((string)($row['application_id'] ?? $applicationId)),
            'caseId' => isset($row['case_id']) ? (int)$row['case_id'] : null,
            'candidateName' => trim((string)($row['candidate_first_name'] ?? '') . ' ' . (string)($row['candidate_last_name'] ?? '')),
            'candidateEmail' => integration_normalize_email((string)($row['candidate_email'] ?? '')),
            'status' => trim((string)($row['case_status'] ?? $row['application_status'] ?? '')),
        ];
    } catch (Throwable $e) {
        integration_log('integration_failures', 'Failed to resolve case summary', ['applicationId' => $applicationId, 'error' => $e->getMessage()]);
        return null;
    }
}

function integration_send_webhook(string $eventType, array $payload, array $options = []): array
{
    $url = integration_webhook_url();
    if ($url === '') {
        return ['success' => false, 'skipped' => true, 'message' => 'MINTLEAF_WEBHOOK_URL is not configured'];
    }

    $applicationId = integration_normalize_application_id((string)($payload['applicationId'] ?? $payload['application_id'] ?? ''));
    $caseId = isset($payload['caseId']) ? (int)$payload['caseId'] : (isset($payload['case_id']) ? (int)$payload['case_id'] : null);
    $triggeredAt = integration_iso_datetime($payload['triggeredAt'] ?? gmdate('c')) ?? gmdate('c');
    $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
    $eventId = trim((string)($options['eventId'] ?? $payload['eventId'] ?? ''));
    if ($eventId === '') {
        $eventId = integration_event_id($eventType, $applicationId, $caseId, $metadata);
    }

    $body = [
        'eventId' => $eventId,
        'eventType' => trim($eventType),
        'applicationId' => $applicationId,
        'caseId' => $caseId,
        'candidateEmail' => integration_normalize_email((string)($payload['candidateEmail'] ?? '')),
        'candidateName' => trim((string)($payload['candidateName'] ?? '')),
        'currentStage' => trim((string)($payload['currentStage'] ?? '')),
        'status' => trim((string)($payload['status'] ?? '')),
        'triggeredBy' => $payload['triggeredBy'] ?? null,
        'triggeredAt' => $triggeredAt,
        'metadata' => $metadata,
    ];

    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        integration_log('webhooks', 'Webhook JSON encode failed', ['eventType' => $eventType, 'applicationId' => $applicationId]);
        return ['success' => false, 'message' => 'JSON encode failed'];
    }

    $secret = integration_webhook_secret();
    $timestamp = (string)time();
    $signature = $secret !== '' ? hash_hmac('sha256', $timestamp . '.' . $json, $secret) : '';

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-VATI-Event-Id: ' . $eventId,
        'X-VATI-Event-Type: ' . trim($eventType),
        'X-VATI-Source: VATI',
        'X-VATI-Timestamp: ' . $timestamp,
    ];
    if ($signature !== '') {
        $headers[] = 'X-VATI-Signature: sha256=' . $signature;
    }

    $attempts = max(1, (int)($options['attempts'] ?? 2));
    $timeout = max(3, (int)($options['timeout'] ?? 15));
    $lastError = 'Unknown error';
    $lastHttpCode = 0;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $ch = curl_init($url);
        if ($ch === false) {
            $lastError = 'Failed to initialize cURL';
            break;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $raw = curl_exec($ch);
        $lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErrNo === 0 && $lastHttpCode >= 200 && $lastHttpCode < 300) {
            integration_log('webhooks', 'Webhook sent', ['eventId' => $eventId, 'eventType' => $eventType, 'applicationId' => $applicationId, 'httpCode' => $lastHttpCode]);
            return ['success' => true, 'eventId' => $eventId, 'httpCode' => $lastHttpCode, 'response' => is_string($raw) ? trim($raw) : ''];
        }

        $lastError = $curlErrNo !== 0 ? ('cURL error #' . $curlErrNo . ': ' . $curlErr) : ('HTTP ' . $lastHttpCode);
    }

    integration_log('webhooks', 'Webhook failed', ['eventId' => $eventId, 'eventType' => $eventType, 'applicationId' => $applicationId, 'httpCode' => $lastHttpCode, 'error' => $lastError]);
    return ['success' => false, 'eventId' => $eventId, 'httpCode' => $lastHttpCode, 'message' => $lastError];
}

