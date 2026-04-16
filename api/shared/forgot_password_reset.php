<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';

session_start();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$newp = trim((string)($input['new_password'] ?? ''));
$conf = trim((string)($input['confirm_password'] ?? ''));

if ($newp === '' || $conf === '') {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'new_password and confirm_password are required']);
    exit;
}

if (strlen($newp) < 6) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'New password must be at least 6 characters']);
    exit;
}

if ($newp !== $conf) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'New password and confirm password do not match']);
    exit;
}

$userId = isset($_SESSION['forgot_password_user_id']) ? (int)$_SESSION['forgot_password_user_id'] : 0;
$username = trim((string)($_SESSION['forgot_password_username'] ?? ''));
$verified = isset($_SESSION['forgot_password_verified']) ? (int)$_SESSION['forgot_password_verified'] : 0;
$verifiedAt = isset($_SESSION['forgot_password_verified_at']) ? (int)$_SESSION['forgot_password_verified_at'] : 0;

if ($userId <= 0 || $username === '' || $verified !== 1 || $verifiedAt <= 0 || (time() - $verifiedAt) > 900) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'Password reset session expired. Please start again.']);
    exit;
}

try {
    $pdo = getDB();

    try {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_SetUserPassword(?, ?, ?, ?)');
        $stmt->execute([$userId, $username, $newp, 0]);
        while ($stmt->nextRowset()) {
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 0, 'message' => 'Unable to reset password.']);
        exit;
    }

    try {
        $pdo->prepare(
            'UPDATE Vati_Payfiller_User_Credentials
             SET password_plain = ?, must_change_password = 0, is_active = 1, updated_at = NOW()
             WHERE user_id = ?'
        )->execute([$newp, $userId]);
    } catch (Throwable $e) {
    }

    try {
        $pdo->prepare(
            'UPDATE Vati_Payfiller_Users
             SET must_change_password = 0, updated_at = NOW()
             WHERE user_id = ?'
        )->execute([$userId]);
    } catch (Throwable $e) {
    }

    unset(
        $_SESSION['forgot_password_user_id'],
        $_SESSION['forgot_password_username'],
        $_SESSION['forgot_password_email'],
        $_SESSION['forgot_password_otp_hash'],
        $_SESSION['forgot_password_otp_expires'],
        $_SESSION['forgot_password_otp_attempts'],
        $_SESSION['forgot_password_verified'],
        $_SESSION['forgot_password_verified_at']
    );

    echo json_encode([
        'status' => 1,
        'message' => 'Password reset successful.'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Unable to reset password.']);
}
