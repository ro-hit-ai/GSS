<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../../includes/audit_log.php';
session_start();

function login_otp_finish_response(array $payload): void
{
    $json = json_encode($payload);
    if ($json === false) {
        $json = '{"success":false,"message":"Unable to encode response."}';
    }

    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
    }

    echo $json;

    if (function_exists('session_write_close')) {
        @session_write_close();
    }

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();

    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');
$captcha  = trim($input['captcha'] ?? '');

if ($username === '' || $password === '' || $captcha === '') {
    audit_log_event('login', 'otp_request', 'failed', [
        'reason' => 'missing_fields',
        'username' => $username
    ], null, null, null);
    echo json_encode([
        'success' => false,
        'message' => 'Username, password and captcha are required.'
    ]);
    exit;
}

$expectedCaptcha = $_SESSION['login_captcha'] ?? '';

if ($expectedCaptcha === '' || strcasecmp($captcha, $expectedCaptcha) !== 0) {
    audit_log_event('login', 'otp_request', 'failed', [
        'reason' => 'invalid_captcha',
        'username' => $username
    ], null, null, null);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid captcha.'
    ]);
    exit;
}

// Validate user using stored procedure SP_Vati_Payfiller_CheckLogin
try {
    $pdo = getDB();

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_CheckLogin(?, ?)');
    $stmt->execute([$username, $password]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }
    $stmt->closeCursor();
} catch (Throwable $e) {
    audit_log_event('login', 'otp_request', 'failed', [
        'reason' => 'db_error_check_login',
        'username' => $username,
        'error' => $e->getMessage()
    ], null, null, null);
    echo json_encode([
        'success' => false,
        'message' => 'DB error while preparing login stored procedure: ' . $e->getMessage()
    ]);
    exit;
}

$userId      = isset($row['UserID']) ? (int)$row['UserID'] : 0;
$loginStatus = isset($row['LoginStatus']) ? (string)$row['LoginStatus'] : '';

if ($userId <= 0 || $loginStatus !== 'Valid Login') {
    audit_log_event('login', 'otp_request', 'failed', [
        'reason' => 'invalid_credentials',
        'username' => $username
    ], $userId > 0 ? $userId : null, null, null);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid username or password.'
    ]);
    exit;
}

// Generate OTP and persist via stored procedure
$otp = rand(100000, 999999);

try {
    $otpStmt = $pdo->prepare('CALL SP_Vati_Payfiller_GenerateOTP(?, ?)');
    $otpStmt->execute([$userId, $otp]);
    while ($otpStmt->nextRowset()) {
    }
    $otpStmt->closeCursor();
} catch (Throwable $e) {
    audit_log_event('login', 'otp_request', 'failed', [
        'reason' => 'db_error_generate_otp',
        'username' => $username,
        'user_id' => $userId,
        'error' => $e->getMessage()
    ], $userId, null, null);
    echo json_encode([
        'success' => false,
        'message' => 'DB error while preparing OTP stored procedure: ' . $e->getMessage()
    ]);
    exit;
}

$_SESSION['login_user_id'] = $userId;
$responsePayload = [
    'success' => true,
    'userId'  => $userId,
    'otp'     => (string)(env_get('APP_OTP_DEBUG', '0') ?? '0') === '1' ? $otp : null,
    'message' => 'OTP generated and sent to your registered contact.'
];

audit_log_event('login', 'otp_request', 'success', [
    'username' => $username,
    'user_id' => $userId
], $userId, null, null);

login_otp_finish_response($responsePayload);

// Send OTP by email after responding so the OTP screen appears immediately.
try {
    $uStmt = $pdo->prepare('SELECT email, first_name, last_name FROM Vati_Payfiller_Users WHERE user_id = ?');
    $uStmt->execute([$userId]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $uEmail = isset($uRow['email']) ? trim((string)$uRow['email']) : '';
    $uName = trim((string)($uRow['first_name'] ?? '') . ' ' . (string)($uRow['last_name'] ?? ''));

    if ($uEmail !== '' && filter_var($uEmail, FILTER_VALIDATE_EMAIL)) {
        $subject = 'Your OTP for VATI GSS Login';
        $safeName = htmlspecialchars($uName !== '' ? $uName : $username);
        $body = ''
            . '<div style="font-family:Arial, sans-serif; font-size:14px; color:#0f172a; line-height:1.5;">'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Your One-Time Password (OTP) for login is:</p>'
            . '<div style="font-size:22px; letter-spacing:3px; font-weight:800; margin:12px 0;">' . htmlspecialchars((string)$otp) . '</div>'
            . '<p style="font-size:12px; color:#64748b;">Do not share this OTP with anyone.</p>'
            . '<p>Thanks,<br>VATI GSS</p>'
            . '</div>';

        send_app_mail($uEmail, $subject, $body, 'VATI GSS');
    }
} catch (Throwable $e) {
}
exit;
