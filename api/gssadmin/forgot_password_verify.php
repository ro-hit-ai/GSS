<?php
header('Content-Type: application/json');

session_start();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$otp = trim((string)($input['otp'] ?? ''));

if ($otp === '' || !preg_match('/^[0-9]{6}$/', $otp)) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'OTP is required.']);
    exit;
}

$userId = isset($_SESSION['forgot_password_user_id']) ? (int)$_SESSION['forgot_password_user_id'] : 0;
$hash = (string)($_SESSION['forgot_password_otp_hash'] ?? '');
$expires = isset($_SESSION['forgot_password_otp_expires']) ? (int)$_SESSION['forgot_password_otp_expires'] : 0;
$attempts = isset($_SESSION['forgot_password_otp_attempts']) ? (int)$_SESSION['forgot_password_otp_attempts'] : 0;

if ($userId <= 0 || $hash === '' || $expires <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'No active password reset request found.']);
    exit;
}

if (time() > $expires) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'OTP expired. Please request a new one.']);
    exit;
}

if ($attempts >= 5) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'Too many attempts. Please request a new OTP.']);
    exit;
}

$_SESSION['forgot_password_otp_attempts'] = $attempts + 1;
if (!hash_equals($hash, hash('sha256', $otp))) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'Invalid OTP.']);
    exit;
}

$_SESSION['forgot_password_verified'] = 1;
$_SESSION['forgot_password_verified_at'] = time();

echo json_encode([
    'status' => 1,
    'message' => 'OTP verified.'
]);
