<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/audit_log.php';
session_start();

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$userId = isset($input['userId']) ? (int)$input['userId'] : 0;
$otp    = isset($input['otp']) ? (int)$input['otp'] : 0;

if ($userId <= 0 || $otp <= 0) {
    audit_log_event('login', 'otp_verify', 'failed', [
        'reason' => 'missing_fields',
        'user_id' => $userId
    ], $userId > 0 ? $userId : null, null, null);
    echo json_encode([
        'success' => false,
        'message' => 'User ID and OTP are required.'
    ]);
    exit;
}

// Optional: ensure this matches the user stored in session from request_otp
if (!empty($_SESSION['login_user_id']) && (int)$_SESSION['login_user_id'] !== $userId) {
    audit_log_event('login', 'otp_verify', 'failed', [
        'reason' => 'session_mismatch',
        'session_user_id' => (int)($_SESSION['login_user_id'] ?? 0),
        'user_id' => $userId
    ], $userId, null, null);
    echo json_encode([
        'success' => false,
        'message' => 'Session mismatch. Please login again.'
    ]);
    exit;
}

// Call stored procedure SP_Vati_Payfiller_LoginUser(uid, txtOTP)
try {
    $pdo = getDB();
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_LoginUser(?, ?)');
    $stmt->execute([$userId, $otp]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    while ($stmt->nextRowset()) {
    }
    $stmt->closeCursor();
} catch (Throwable $e) {
    audit_log_event('login', 'otp_verify', 'failed', [
        'reason' => 'db_error_login_user',
        'user_id' => $userId,
        'error' => $e->getMessage()
    ], $userId, null, null);
    echo json_encode([
        'success' => false,
        'message' => 'DB error while preparing stored procedure: ' . $e->getMessage()
    ]);
    exit;
}

if (!$row) {
    audit_log_event('login', 'otp_verify', 'failed', [
        'reason' => 'empty_result',
        'user_id' => $userId
    ], $userId, null, null);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected empty result.'
    ]);
    exit;
}

// SP_Vati_Payfiller_LoginUser SELECTs:
// uid, uname, uemail, mnumber, maccess, allowed_sections, tid, cid, urlpath, lstatus, preset, pexpired
$uid        = (int)($row[0] ?? 0);
$uname      = (string)($row[1] ?? '');
$uemail     = (string)($row[2] ?? '');
$mnumber    = (string)($row[3] ?? '');
$maccess    = (string)($row[4] ?? '');
$allowedSections = (string)($row[5] ?? '');
$tenantId   = (int)($row[6] ?? 0);
$clientId   = (int)($row[7] ?? 0);
$urlPath    = (string)($row[8] ?? '');
$lstatus    = (string)($row[9] ?? '');
$preset     = (int)($row[10] ?? 0);
$pexpired   = (int)($row[11] ?? 0);

if ($lstatus !== 'Valid OTP') {
    audit_log_event('login', 'otp_verify', 'failed', [
        'reason' => 'invalid_otp',
        'user_id' => $uid,
        'status' => $lstatus
    ], $uid > 0 ? $uid : $userId, null, null);
    echo json_encode([
        'success' => false,
        'message' => $lstatus !== '' ? $lstatus : 'OTP not valid.'
    ]);
    exit;
}

// Normalize legacy role naming
if (strtolower(trim($maccess)) === 'customer_admin') {
    $maccess = 'client_admin';
}

// Mark user as logged in in PHP session
$_SESSION['auth_user_id']    = $uid;
$_SESSION['auth_user_name']  = $uname;
$_SESSION['auth_all_moduleAccess'] = $maccess;
$_SESSION['auth_moduleAccess'] = $maccess;
$_SESSION['auth_allowed_sections'] = $allowedSections;
$_SESSION['auth_tenant_id']  = $tenantId;
$_SESSION['auth_client_id']  = $clientId;
$_SESSION['auth_user_email'] = $uemail;
$_SESSION['auth_user_mobile'] = $mnumber;

audit_log_event('login', 'otp_verify', 'success', [
    'user_id' => $uid,
    'module_access' => $maccess,
    'tenant_id' => $tenantId,
    'client_id' => $clientId,
    'redirect' => $urlPath
], $uid, $maccess, $clientId);

// Compute safe redirect based on module access (prevents cross-role redirects)
$access = strtolower(trim($maccess));
$defaultMap = [
    'gss_admin' => 'modules/gss_admin/dashboard.php',
    'client_admin' => 'modules/client_admin/dashboard.php',
    'company_recruiter' => 'modules/hr_recruiter/dashboard.php',
    'team_lead' => 'modules/team_lead/dashboard.php',
    'verifier' => 'modules/verifier/dashboard.php',
    'db_verifier' => 'modules/db_verifier/candidates_list.php',
    'validator' => 'modules/validator/dashboard.php',
    'qa' => 'modules/qa/review_list.php'
];

$defaultRedirect = $defaultMap[$access] ?? 'index.php';
$redirectUrl = $defaultRedirect;

$candidate = ltrim($urlPath, '/');
if ($candidate !== '' && strpos($candidate, '..') === false) {
    $allowedPrefixes = [
        'gss_admin' => ['modules/gss_admin/'],
        'client_admin' => ['modules/client_admin/'],
        'company_recruiter' => ['modules/hr_recruiter/'],
        'team_lead' => ['modules/team_lead/'],
        'verifier' => ['modules/verifier/'],
        'db_verifier' => ['modules/db_verifier/'],
        'validator' => ['modules/validator/'],
        'qa' => ['modules/qa/']
    ];

    $prefixes = $allowedPrefixes[$access] ?? [];
    foreach ($prefixes as $pfx) {
        if (strpos($candidate, $pfx) === 0) {
            $redirectUrl = $candidate;
            break;
        }
    }
}

// If user must change password, force redirect to change password page
if ($preset === 1) {
    $redirectUrl = 'modules/shared/change_password.php?next=' . rawurlencode($defaultRedirect);
}

echo json_encode([
    'success' => true,
    'data'    => [
        'userId'        => $uid,
        'userName'      => $uname,
        'email'         => $uemail,
        'mobile'        => $mnumber,
        'moduleAccess'  => $maccess,
        'allowedSections' => $allowedSections,
        'tenantId'      => $tenantId,
        'clientId'      => $clientId,
        'urlPath'       => $redirectUrl,
        'accessStatus'  => $lstatus,
        'passwordReset' => $preset,
        'passwordExpired' => $pexpired
    ]
]);
