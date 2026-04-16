<?php
session_start();
require_once __DIR__ . '/../../includes/layout.php';

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/mail.php';

$error = '';

$prefillUsername = '';

$info = '';

$step = isset($_GET['step']) ? trim((string)$_GET['step']) : '';
$hasPendingOtp = !empty($_SESSION['candidate_pending_application_id'])
    && !empty($_SESSION['candidate_pending_user_email'])
    && !empty($_SESSION['candidate_otp_hash'])
    && !empty($_SESSION['candidate_otp_expires']);

$showOtp = ($step === 'otp') || $hasPendingOtp;

if (!empty($_SESSION['logged_in']) && !empty($_SESSION['application_id'])) {
    header('Location: ' . app_url('/modules/candidate/index.php'));
    exit;
}

if (!empty($_SESSION['candidate_otp_error'])) {
    $error = (string)$_SESSION['candidate_otp_error'];
    unset($_SESSION['candidate_otp_error']);
}
if (!empty($_SESSION['candidate_otp_info'])) {
    $info = (string)$_SESSION['candidate_otp_info'];
    unset($_SESSION['candidate_otp_info']);
}

if (!empty($_GET['token'])) {
    try {
        $token = trim((string)$_GET['token']);
        if ($token === '') {
            throw new Exception('Invalid invite link.');
        }

        $pdo = getDB();

        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetCaseByInviteToken(?)');
        $stmt->execute([$token]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        while ($stmt->nextRowset()) {
        }

        $caseId = isset($case['case_id']) ? (int)$case['case_id'] : 0;
        $applicationId = isset($case['application_id']) ? (string)$case['application_id'] : '';

        if ($caseId <= 0 || $applicationId === '') {
            throw new Exception('Invalid or expired invite link.');
        }

        $prefillUsername = $applicationId;
        $info = 'Invite link verified. Please login using the username and temporary password shared with you.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'login'));

    if ($action !== 'login') {
        // OTP verify/resend
        $pendingApp = (string)($_SESSION['candidate_pending_application_id'] ?? '');
        $pendingEmail = (string)($_SESSION['candidate_pending_user_email'] ?? '');

        if ($pendingApp === '' || $pendingEmail === '' || empty($_SESSION['candidate_otp_hash']) || empty($_SESSION['candidate_otp_expires'])) {
            header('Location: ' . app_url('/modules/candidate/login.php'));
            exit;
        }

        if ($action === 'resend') {
            $otp = strval(rand(100000, 999999));
            $_SESSION['candidate_otp_hash'] = hash('sha256', $otp);
            $_SESSION['candidate_otp_expires'] = time() + 600;
            $_SESSION['candidate_otp_attempts'] = 0;

            $safeOtp = htmlspecialchars($otp);
            $safeApp = htmlspecialchars($pendingApp);
            $body = ''
                . '<div style="font-family:Arial, sans-serif; font-size:14px; color:#0f172a; line-height:1.6;">'
                . '<p>Your one-time verification code is:</p>'
                . '<div style="font-size:22px; font-weight:800; letter-spacing:4px; padding:12px 16px; border-radius:12px; display:inline-block; background:#0f172a; color:#fff;">' . $safeOtp . '</div>'
                . '<p style="margin-top:12px;">This code will expire in 10 minutes.</p>'
                . '<p style="font-size:12px; color:#64748b;">Application ID: ' . $safeApp . '</p>'
                . '</div>';
            send_app_mail($pendingEmail, 'Your OTP for Candidate Login', $body, 'VATI GSS');

            $_SESSION['candidate_otp_info'] = 'A new OTP has been sent to your email.';
            header('Location: ' . app_url('/modules/candidate/login.php?step=otp'));
            exit;
        }

        $otpInput = trim((string)($_POST['otp'] ?? ''));
        $attempts = isset($_SESSION['candidate_otp_attempts']) ? (int)$_SESSION['candidate_otp_attempts'] : 0;
        $expires = isset($_SESSION['candidate_otp_expires']) ? (int)$_SESSION['candidate_otp_expires'] : 0;
        $hash = (string)($_SESSION['candidate_otp_hash'] ?? '');

        if ($otpInput === '' || !preg_match('/^[0-9]{6}$/', $otpInput)) {
            $_SESSION['candidate_otp_error'] = 'OTP is required.';
            header('Location: ' . app_url('/modules/candidate/login.php?step=otp'));
            exit;
        }
        if ($expires <= 0 || time() > $expires) {
            $_SESSION['candidate_otp_error'] = 'OTP expired. Please resend OTP.';
            header('Location: ' . app_url('/modules/candidate/login.php?step=otp'));
            exit;
        }
        if ($attempts >= 5) {
            $_SESSION['candidate_otp_error'] = 'Too many attempts. Please resend OTP.';
            header('Location: ' . app_url('/modules/candidate/login.php?step=otp'));
            exit;
        }

        $attempts++;
        $_SESSION['candidate_otp_attempts'] = $attempts;

        if (!hash_equals($hash, hash('sha256', $otpInput))) {
            $_SESSION['candidate_otp_error'] = 'Invalid OTP.';
            header('Location: ' . app_url('/modules/candidate/login.php?step=otp'));
            exit;
        }

        $caseId = (int)($_SESSION['candidate_pending_case_id'] ?? 0);
        $applicationId = (string)($_SESSION['candidate_pending_application_id'] ?? '');
        $candidateUserId = (int)($_SESSION['candidate_pending_user_id'] ?? 0);
        $mustChange = (int)($_SESSION['candidate_pending_must_change'] ?? 0);
        $userName = (string)($_SESSION['candidate_pending_user_name'] ?? '');
        $userEmail = (string)($_SESSION['candidate_pending_user_email'] ?? '');

        $_SESSION['case_id'] = $caseId;
        $_SESSION['application_id'] = $applicationId;
        $_SESSION['logged_in'] = true;
        $_SESSION['candidate_user_id'] = $candidateUserId;
        $_SESSION['user_name'] = $userName;
        $_SESSION['user_email'] = $userEmail;

        try {
            session_regenerate_id(true);
        } catch (Throwable $e) {
        }

        unset(
            $_SESSION['candidate_pending_case_id'],
            $_SESSION['candidate_pending_application_id'],
            $_SESSION['candidate_pending_user_id'],
            $_SESSION['candidate_pending_must_change'],
            $_SESSION['candidate_pending_user_name'],
            $_SESSION['candidate_pending_user_email'],
            $_SESSION['candidate_otp_hash'],
            $_SESSION['candidate_otp_expires'],
            $_SESSION['candidate_otp_attempts'],
            $_SESSION['candidate_otp_error'],
            $_SESSION['candidate_otp_info']
        );

        if ($caseId > 0) {
            try {
                $pdo = getDB();
                $upd = $pdo->prepare('CALL SP_Vati_Payfiller_UpdateCaseStatus(?, ?)');
                $upd->execute([$caseId, 'IN_PROGRESS']);
                while ($upd->nextRowset()) {
                }
            } catch (Throwable $e) {
            }
        }

        if ($mustChange === 1) {
            session_write_close();
            header('Location: ' . app_url('/modules/candidate/change_password.php'));
            exit;
        }

        session_write_close();
        header('Location: ' . app_url('/modules/candidate/index.php'));
        exit;
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));
    $entered  = trim($_POST['captcha'] ?? '');
    $expected = $_SESSION['candidate_captcha'] ?? '';

    if ($entered === '' || $entered !== $expected) {
        $error = 'Invalid captcha. Please try again.';
    }

    if ($error === '' && ($username === '' || $password === '')) {
        $error = 'Username and password are required.';
    }

    if ($error === '') {
        try {
            $pdo = getDB();

            $caseLookup = $pdo->prepare('SELECT case_id, client_id, application_id, candidate_first_name, candidate_last_name, candidate_email FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
            $caseLookup->execute([$username]);
            $case = $caseLookup->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$case) {
                throw new Exception('Case not found for this username.');
            }

            $caseId = isset($case['case_id']) ? (int)$case['case_id'] : 0;
            $clientId = isset($case['client_id']) ? (int)$case['client_id'] : 0;
            $applicationId = (string)($case['application_id'] ?? '');
            if ($caseId <= 0 || $clientId <= 0 || $applicationId === '') {
                throw new Exception('Case not found for this username.');
            }

            $mustChange = 0;
            $candidateUserId = 0;

            // Prefer candidate-only credentials (client-wise)
            $c2 = null;
            try {
                $cand = $pdo->prepare(
                    'SELECT user_id, must_change_password '
                    . 'FROM Vati_Payfiller_Candidate_Credentials '
                    . 'WHERE client_id = ? '
                    . 'AND username = ? '
                    . 'AND password_plain = ? '
                    . 'AND is_active = 1 '
                    . ' LIMIT 1'
                );
                $cand->execute([$clientId, $username, $password]);
                $c2 = $cand->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                $c2 = null;
            }

            if ($c2) {
                $candidateUserId = isset($c2['user_id']) ? (int)$c2['user_id'] : 0;
                $mustChange = isset($c2['must_change_password']) ? (int)$c2['must_change_password'] : 0;
            } else {
                // Legacy fallback
                $cred = $pdo->prepare(
                    'SELECT user_id, username, must_change_password '
                    . 'FROM Vati_Payfiller_User_Credentials '
                    . 'WHERE username = ? '
                    . 'AND password_plain = ? '
                    . 'AND is_active = 1 '
                    . 'LIMIT 1'
                );
                $cred->execute([$username, $password]);
                $cRow = $cred->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($cRow) {
                    $candidateUserId = isset($cRow['user_id']) ? (int)$cRow['user_id'] : 0;
                    $mustChange = isset($cRow['must_change_password']) ? (int)$cRow['must_change_password'] : 0;
                } else {
                    // Final fallback: Vati_Payfiller_Users (core login table)
                    $u = $pdo->prepare(
                        'SELECT user_id, must_change_password '
                        . 'FROM Vati_Payfiller_Users '
                        . 'WHERE username = ? '
                        . 'AND password_plain = ? '
                        . 'AND is_active = 1 '
                        . 'LIMIT 1'
                    );
                    $u->execute([$username, $password]);
                    $uRow = $u->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$uRow) {
                        throw new Exception('Invalid username or password.');
                    }
                    $candidateUserId = isset($uRow['user_id']) ? (int)$uRow['user_id'] : 0;
                    $mustChange = isset($uRow['must_change_password']) ? (int)$uRow['must_change_password'] : 0;
                }
            }

            $otp = strval(rand(100000, 999999));
            $otpHash = hash('sha256', $otp);
            $otpExp = time() + 600;

            $_SESSION['candidate_pending_case_id'] = $caseId;
            $_SESSION['candidate_pending_application_id'] = $applicationId;
            $_SESSION['candidate_pending_user_id'] = $candidateUserId;
            $_SESSION['candidate_pending_must_change'] = $mustChange;
            $_SESSION['candidate_pending_user_name'] = trim((string)($case['candidate_first_name'] ?? '') . ' ' . (string)($case['candidate_last_name'] ?? ''));
            $_SESSION['candidate_pending_user_email'] = (string)($case['candidate_email'] ?? '');
            $_SESSION['candidate_otp_hash'] = $otpHash;
            $_SESSION['candidate_otp_expires'] = $otpExp;
            $_SESSION['candidate_otp_attempts'] = 0;

            $to = (string)($case['candidate_email'] ?? '');
            $safeOtp = htmlspecialchars($otp);
            $safeApp = htmlspecialchars($applicationId);
            $body = ''
                . '<div style="font-family:Arial, sans-serif; font-size:14px; color:#0f172a; line-height:1.6;">'
                . '<p>Your one-time verification code is:</p>'
                . '<div style="font-size:22px; font-weight:800; letter-spacing:4px; padding:12px 16px; border-radius:12px; display:inline-block; background:#0f172a; color:#fff;">' . $safeOtp . '</div>'
                . '<p style="margin-top:12px;">This code will expire in 10 minutes.</p>'
                . '<p style="font-size:12px; color:#64748b;">Application ID: ' . $safeApp . '</p>'
                . '</div>';
            send_app_mail($to, 'Your OTP for Candidate Login', $body, 'VATI GSS');

            header('Location: ' . app_url('/modules/candidate/login.php?step=otp'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    // After handling POST, generate a new code for the next view
    $_SESSION['candidate_captcha'] = strval(rand(10000, 99999));
} else {
    // On initial load or simple refresh (GET), always generate a fresh code
    $_SESSION['candidate_captcha'] = strval(rand(10000, 99999));
}

$menu = [
    ['label' => 'Login', 'href' => 'login.php'],
    ['label' => 'Application Wizard', 'href' => 'portal.php'],
];

ob_start();
?>
<div class="login-shell">
    <div class="login-card">
        <div style="display:flex; flex-direction:column; align-items:center; text-align:center; margin-bottom:18px;">
            <div style="display:flex; align-items:center; justify-content:center; margin-bottom:10px;">
                <img src="<?php echo htmlspecialchars(app_url('/assets/img/gss-logo.svg')); ?>" alt="GSS" style="height:44px; width:auto; object-fit:contain;">
            </div>
            <h2 style="font-size:22px; font-weight:600; margin:0 0 4px; color:#0f172a;">Candidate Login</h2>
            <p style="font-size:12px; color:#6b7280; margin:0;">Sign in to continue your Background Verification.</p>
        </div>

        <?php if (!empty($info) && empty($error)): ?>
            <div class="alert alert-success" style="margin-bottom:10px; font-size:12px;">
                <?php echo htmlspecialchars($info); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" style="margin-bottom:10px; font-size:12px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="login-form-pane" style="background:transparent; padding:0; overflow:hidden;">
            <div id="candidateLoginSlider" style="position:relative; min-height:260px;">
                <div id="candidateLoginPane" style="width:100%; transition:transform .28s ease, opacity .28s ease; transform:<?php echo $showOtp ? 'translate3d(-110%,0,0)' : 'translate3d(0,0,0)'; ?>; opacity:<?php echo $showOtp ? '0' : '1'; ?>;">
                    <form method="post" class="mb-3" autocomplete="off">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" placeholder="Enter username" value="<?php echo htmlspecialchars($prefillUsername); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <div style="position:relative;">
                                <input type="password" class="form-control" id="candidatePassword" name="password" placeholder="Enter password" required style="padding-right:44px;">
                                <button type="button" data-password-toggle="candidatePassword" aria-label="Show password" aria-pressed="false" style="position:absolute; top:50%; right:10px; transform:translateY(-50%); border:0; background:transparent; color:#64748b; width:28px; height:28px; padding:0; display:inline-flex; align-items:center; justify-content:center; cursor:pointer;">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" style="width:18px; height:18px; stroke:currentColor; fill:none; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round;">
                                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Captcha</label>
                            <div class="d-flex align-items-center gap-2">
                                <div class="captcha-box"><?php echo htmlspecialchars($_SESSION['candidate_captcha'] ?? '-----'); ?></div>
                                <input type="text" class="form-control" name="captcha" placeholder="Enter code" required>
                            </div>
                            <div class="form-text" style="color:#9ca3af; font-size:11px; text-align:left;">Type the code shown to continue.</div>
                        </div>

                        <button type="submit" class="btn w-100" style="border-radius:999px; background:#eef2ff; border:none; color:#111827; font-weight:500; box-shadow: 6px 6px 16px rgba(148,163,184,0.7), -6px -6px 18px rgba(255,255,255,1); height:42px;">Request OTP</button>
                    </form>
                </div>

                <div id="candidateOtpPane" style="position:absolute; inset:0; width:100%; transition:transform .28s ease, opacity .28s ease; transform:<?php echo $showOtp ? 'translate3d(0,0,0)' : 'translate3d(110%,0,0)'; ?>; opacity:<?php echo $showOtp ? '1' : '0'; ?>;">
                    <div style="font-size:12px; color:#6b7280; margin-bottom:10px;">Enter the 6-digit OTP sent to your registered email.</div>
                    <form method="post" class="mb-2" autocomplete="off">
                        <input type="hidden" name="action" value="verify">
                        <div class="mb-3">
                            <label class="form-label">OTP</label>
                            <input type="text" class="form-control" name="otp" inputmode="numeric" maxlength="6" placeholder="Enter 6-digit OTP" required>
                        </div>
                        <button type="submit" class="btn w-100" style="border-radius:999px; background:#e0fbe2; border:none; color:#166534; font-weight:500; box-shadow: 6px 6px 16px rgba(148,163,184,0.4), -6px -6px 18px rgba(255,255,255,1); height:42px;">Verify & Continue</button>
                    </form>

                    <form method="post" style="margin-top:10px;">
                        <input type="hidden" name="action" value="resend">
                        <button type="submit" class="btn btn-link p-0" style="font-size:12px;">Resend OTP</button>
                    </form>
                </div>
            </div>
        </div>

        
    </div>
</div>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/login.js?v=' . (string)@filemtime(__DIR__ . '/../../js/modules/candidate/login.js'))); ?>"></script>
<?php
$content = ob_get_clean();
render_layout('Candidate Login', 'Candidate', $menu, $content);
