<?php
require_once __DIR__ . '/config/env.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!function_exists('generateCaptchaCode')) {
    function generateCaptchaCode(int $length = 6): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max   = strlen($chars) - 1;
        $code  = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        return $code;
    }
}

$_SESSION['login_captcha'] = generateCaptchaCode();
$redirect = isset($_GET['redirect']) ? (string)$_GET['redirect'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VATI GSS - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/css/login.css')); ?>">
    <style>
        .password-toggle-wrap { position: relative; }
        .password-toggle-wrap .form-control { padding-right: 44px; }
        .password-toggle-btn {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #64748b;
            width: 28px;
            height: 28px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .password-toggle-btn:hover { color: #0f172a; }
        .password-toggle-btn svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    </style>
</head>
<body>
<script>
    window.APP_BASE_URL = <?php echo json_encode(app_base_url()); ?>;
    window.LOGIN_REDIRECT = <?php echo json_encode($redirect); ?>;
</script>

<div class="login-shell">
    <div class="login-card">
        <div style="display:flex; flex-direction:column; align-items:center; text-align:center; margin-bottom:18px;">
            <div style="display:flex; align-items:center; justify-content:center; margin-bottom:10px;">
                <img src="<?php echo htmlspecialchars(app_url('/assets/img/gss-logo.svg')); ?>" alt="GSS" style="height:44px; width:auto; object-fit:contain;">
            </div>
            <h2 style="font-size:22px; font-weight:600; margin:0 0 4px; color:#0f172a;">Welcome back</h2>
            <p style="font-size:12px; color:#6b7280; margin:0;">Please sign in once to continue to VATI GSS.</p>
        </div>

        <div id="loginMessage" class="mb-2" style="display:block;"></div>

        <div class="login-form-pane" style="background:transparent; padding:0; overflow:hidden;">
            <div id="loginSlider" style="position:relative; min-height:230px;">
                <div id="loginPane" style="width:100%; transition:transform .28s ease, opacity .28s ease; transform:translate3d(0,0,0); opacity:1;">
                    <form id="loginForm" class="mb-3">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" autocomplete="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="password-toggle-wrap">
                                <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                                <button type="button" class="password-toggle-btn" data-password-toggle="password" aria-label="Show password" aria-pressed="false">
                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="captcha" class="form-label">Captcha</label>
                            <div class="d-flex align-items-center gap-2">
                                <div class="captcha-box"><?php echo htmlspecialchars($_SESSION['login_captcha'] ?? '------'); ?></div>
                                <input type="text" class="form-control" id="captcha" name="captcha" placeholder="Enter code" required>
                            </div>
                            <div class="form-text" style="color:#9ca3af; font-size:11px; text-align:left;">Type the characters shown to prove you are a real user.</div>
                        </div>
                        <div class="mb-3" style="text-align:right; font-size:12px;">
                            <a href="<?php echo htmlspecialchars(app_url('/modules/shared/forgot_password.php')); ?>" style="color:#2563eb; text-decoration:none;">Forgot Password?</a>
                        </div>
                        <button type="submit" class="btn w-100" style="border-radius:999px; background:#eef2ff; border:none; color:#111827; font-weight:500; box-shadow: 6px 6px 16px rgba(148,163,184,0.7), -6px -6px 18px rgba(255,255,255,1); height:42px;">Request OTP</button>
                    </form>
                </div>

                <div id="otpPane" style="position:absolute; inset:0; width:100%; transition:transform .28s ease, opacity .28s ease; transform:translate3d(110%,0,0); opacity:0;">
                    <h6 class="mb-1" style="color:#0f172a; font-size:13px;">Enter OTP</h6>
                    <p class="mb-2" style="font-size:11px; color:#6b7280;">We have sent a one-time password to your registered contact.</p>
                    <form id="otpForm">
                        <div class="mb-3">
                            <label for="otp" class="form-label">One Time Password</label>
                            <input type="text" class="form-control" id="otp" name="otp" placeholder="Enter OTP" required>
                        </div>
                        <button type="submit" class="btn w-100" style="border-radius:999px; background:#e0fbe2; border:none; color:#166534; font-weight:500; box-shadow: 6px 6px 16px rgba(148,163,184,0.4), -6px -6px 18px rgba(255,255,255,1); height:40px;">Verify &amp; Continue</button>
                    </form>
                    <div style="margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <button type="button" id="resendOtpBtn" class="btn btn-link p-0" style="font-size:12px; text-decoration:none;">Resend OTP</button>
                        <button type="button" id="backToLoginBtn" class="btn btn-link p-0" style="font-size:12px; text-decoration:none;">Back to login</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="env-foot">
            Authentication enabled · Login (username, password, captcha, OTP)
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/login.js')); ?>"></script>
</body>
</html>
