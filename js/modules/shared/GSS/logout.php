<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/includes/audit_log.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$uid = !empty($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : null;
$role = !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : null;
$cid = !empty($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : null;

audit_log_event('login', 'logout', 'success', [], $uid, $role, $cid);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool)($params['secure'] ?? false),
        (bool)($params['httponly'] ?? true)
    );
}

session_destroy();

header('Location: ' . app_url('/login.php'));
exit;
