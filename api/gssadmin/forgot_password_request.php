<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../includes/mail.php';

session_start();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$identifier = trim((string)($input['identifier'] ?? ''));

if ($identifier === '') {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'Username or email is required.']);
    exit;
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT user_id, username, email, first_name, last_name, role
           FROM Vati_Payfiller_Users
          WHERE (LOWER(TRIM(username)) = LOWER(TRIM(?)) OR LOWER(TRIM(email)) = LOWER(TRIM(?)))
            AND LOWER(TRIM(role)) IN ('client_admin','validator','verifier','db_verifier','qa','team_lead','gss_admin')
            AND COALESCE(is_active, 1) = 1
          LIMIT 1"
    );
    $stmt->execute([$identifier, $identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

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

    if ($row) {
        $otp = strval(rand(100000, 999999));
        $_SESSION['forgot_password_user_id'] = (int)($row['user_id'] ?? 0);
        $_SESSION['forgot_password_username'] = (string)($row['username'] ?? '');
        $_SESSION['forgot_password_email'] = (string)($row['email'] ?? '');
        $_SESSION['forgot_password_otp_hash'] = hash('sha256', $otp);
        $_SESSION['forgot_password_otp_expires'] = time() + 600;
        $_SESSION['forgot_password_otp_attempts'] = 0;
        $_SESSION['forgot_password_verified'] = 0;
        $_SESSION['forgot_password_verified_at'] = 0;

        $safeName = htmlspecialchars(trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')));
        if ($safeName === '') {
            $safeName = htmlspecialchars((string)($row['username'] ?? 'User'));
        }
        $body = ''
            . '<div style="font-family:Arial, sans-serif; font-size:14px; color:#0f172a; line-height:1.6;">'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Your password reset OTP is:</p>'
            . '<div style="font-size:22px; font-weight:800; letter-spacing:4px; padding:12px 16px; border-radius:12px; display:inline-block; background:#0f172a; color:#fff;">' . htmlspecialchars($otp) . '</div>'
            . '<p style="margin-top:12px;">This code will expire in 10 minutes.</p>'
            . '<p>If you did not request this, please ignore this email.</p>'
            . '<p>Thanks,<br>VATI GSS</p>'
            . '</div>';
        $to = trim((string)($row['email'] ?? ''));
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            send_app_mail($to, 'Your OTP for VATI GSS Password Reset', $body, 'VATI GSS');
        }
    }

    echo json_encode([
        'status' => 1,
        'message' => 'If the account exists, an OTP has been sent to the registered email.',
        'data' => [
            'otp' => (string)(env_get('APP_OTP_DEBUG', '0') ?? '0') === '1' && $row ? $otp : null
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Unable to process password reset request.']);
}
