<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/candidate_access_resend_service.php';

auth_require_login(null);
auth_session_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST ?: [];
    $role = car_session_role_norm();
    $uid = (int)($_SESSION['auth_user_id'] ?? 0);
    $cid = (int)($_SESSION['auth_client_id'] ?? 0);
    $pdo = getDB();
    $out = car_run_resend($pdo, $in, $role, $uid, $cid);
    http_response_code((int)($out['http'] ?? 200));
    unset($out['http']);
    echo json_encode($out);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}

