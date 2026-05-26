777<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../shared/candidate_access_resend_service.php';

auth_require_login('client_admin');

auth_session_start();

function resolve_client_id(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cid = isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
    if ($cid > 0) return $cid;

    http_response_code(401);
    echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
    exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function post_int(string $key, int $default = 0): int {
    return isset($_POST[$key]) && $_POST[$key] !== '' ? (int)$_POST[$key] : $default;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $caseId = post_int('case_id', 0);
    if ($caseId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'case_id is required']);
        exit;
    }

    $pdo = getDB();
    $clientId = resolve_client_id();
    $uid = (int)($_SESSION['auth_user_id'] ?? 0);
    $out = car_run_resend($pdo, [
        'case_id' => $caseId,
        'role' => 'client_admin',
        'reason' => trim((string)($_POST['reason'] ?? '')),
        'request_id' => trim((string)($_POST['request_id'] ?? ''))
    ], 'client_admin', $uid, $clientId);
    http_response_code((int)($out['http'] ?? 200));
    unset($out['http']);
    echo json_encode($out);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
