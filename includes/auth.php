<?php
require_once __DIR__ . '/../config/env.php';

function auth_is_api_request(): bool {
    $uri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));
    $script = strtolower((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    if (strpos($uri, '/api/') !== false || strpos($script, '/api/') !== false) return true;
    if (strpos($accept, 'application/json') !== false) return true;
    if ($xhr === 'xmlhttprequest') return true;
    return false;
}

function auth_respond_unauthorized(): void {
    if (auth_is_api_request()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $redirect = $_SERVER['REQUEST_URI'] ?? '';
    $to = app_url('/login.php');
    if ($redirect !== '') {
        $to .= '?redirect=' . rawurlencode($redirect);
    }
    header('Location: ' . $to);
    exit;
}

function auth_respond_forbidden(): void {
    if (auth_is_api_request()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 0, 'message' => 'Forbidden']);
        exit;
    }

    http_response_code(403);
    echo 'Access denied';
    exit;
}

function auth_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
}

function auth_user_id(): int {
    auth_session_start();
    return !empty($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
}

function auth_module_access(): string {
    auth_session_start();
    return !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : '';
}

function auth_is_logged_in(): bool {
    return auth_user_id() > 0;
}

function auth_is_disabled_role(?string $role = null): bool {
    $role = strtolower(trim((string)($role ?? auth_module_access())));
    return $role === 'validator';
}

function auth_has_access(string $required): bool {
    $required = trim($required);
    if ($required === '') return true;

    $access = strtolower(auth_module_access());
    $required = strtolower($required);

    if ($access === '') return false;
    if ($access === $required) return true;

    $parts = preg_split('/[\s,|]+/', $access) ?: [];
    foreach ($parts as $p) {
        if (trim($p) === $required) return true;
    }

    return (strpos($access, $required) !== false);
}

function auth_require_login(?string $requiredAccess = null): void {
    auth_session_start();

    // Prevent cached authenticated pages from being shown after logout via browser back button
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');

    if (!auth_is_logged_in()) {
        auth_respond_unauthorized();
    }

    if (auth_is_disabled_role()) {
        auth_respond_forbidden();
    }

    if ($requiredAccess !== null && $requiredAccess !== '' && !auth_has_access($requiredAccess)) {
        auth_respond_forbidden();
    }
}

function auth_require_any_access(array $requiredAny): void {
    auth_session_start();

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');

    if (!auth_is_logged_in()) {
        auth_respond_unauthorized();
    }

    if (auth_is_disabled_role()) {
        auth_respond_forbidden();
    }

    $requiredAny = array_values(array_filter(array_map(function ($v) {
        return strtolower(trim((string)$v));
    }, $requiredAny), function ($v) {
        return $v !== '';
    }));

    if (empty($requiredAny)) {
        return;
    }

    foreach ($requiredAny as $r) {
        if (auth_has_access($r)) {
            return;
        }
    }

    auth_respond_forbidden();
}
