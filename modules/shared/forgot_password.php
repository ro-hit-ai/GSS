<?php
require_once __DIR__ . '/../../config/env.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VATI GSS - Forgot Password</title>
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
</script>

<div class="login-shell">
    <div class="login-card">
        <div style="display:flex; flex-direction:column; align-items:center; text-align:center; margin-bottom:18px;">
            <div style="display:flex; align-items:center; justify-content:center; margin-bottom:10px;">
                <img src="<?php echo htmlspecialchars(app_url('/assets/img/gss-logo.svg')); ?>" alt="GSS" style="height:44px; width:auto; object-fit:contain;">
            </div>
            <h2 style="font-size:22px; font-weight:600; margin:0 0 4px; color:#0f172a;">Forgot Password</h2>
            <p style="font-size:12px; color:#6b7280; margin:0;">Reset password for client admin, validator, verifier, DB verifier, QA, team lead, or GSS admin.</p>
        </div>

        <div id="forgotPasswordMessage" class="mb-2" style="display:none;"></div>

        <div class="login-form-pane" style="background:transparent; padding:0; overflow:hidden;">
            <div id="forgotPasswordSlider" style="position:relative; min-height:260px;">
                <div id="forgotRequestPane" style="width:100%; transition:transform .28s ease, opacity .28s ease; transform:translate3d(0,0,0); opacity:1;">
                    <form id="forgotPasswordRequestForm" class="mb-3" autocomplete="off">
                        <div class="mb-3">
                            <label for="forgotIdentifier" class="form-label">Username or Email</label>
                            <input type="text" class="form-control" id="forgotIdentifier" name="identifier" placeholder="Enter username or email" required>
                        </div>
                        <button type="submit" class="btn w-100" style="border-radius:999px; background:#eef2ff; border:none; color:#111827; font-weight:500; box-shadow: 6px 6px 16px rgba(148,163,184,0.7), -6px -6px 18px rgba(255,255,255,1); height:42px;">Send OTP</button>
                    </form>
                    <div style="text-align:center; font-size:12px;">
                        <a href="<?php echo htmlspecialchars(app_url('/login.php')); ?>" style="color:#2563eb; text-decoration:none;">Back to Login</a>
                    </div>
                </div>

                <div id="forgotVerifyPane" style="position:absolute; inset:0; width:100%; transition:transform .28s ease, opacity .28s ease; transform:translate3d(110%,0,0); opacity:0;">
                    <form id="forgotPasswordVerifyForm" class="mb-3" autocomplete="off">
                        <div class="mb-3">
                            <label for="forgotOtp" class="form-label">One Time Password</label>
                            <input type="text" class="form-control" id="forgotOtp" name="otp" inputmode="numeric" maxlength="6" placeholder="Enter OTP" required>
                        </div>
                        <button type="submit" class="btn w-100" style="border-radius:999px; background:#e0fbe2; border:none; color:#166534; font-weight:500; box-shadow: 6px 6px 16px rgba(148,163,184,0.4), -6px -6px 18px rgba(255,255,255,1); height:42px;">Verify OTP</button>
                    </form>
                </div>

                <div id="forgotResetPane" style="position:absolute; inset:0; width:100%; transition:transform .28s ease, opacity .28s ease; transform:translate3d(110%,0,0); opacity:0;">
                    <form id="forgotPasswordResetForm" class="mb-3" autocomplete="off">
                        <div class="mb-3">
                            <label for="forgotNewPassword" class="form-label">New Password</label>
                            <div class="password-toggle-wrap">
                                <input type="password" class="form-control" id="forgotNewPassword" name="new_password" placeholder="Enter new password" required>
                                <button type="button" class="password-toggle-btn" data-password-toggle="forgotNewPassword" aria-label="Show password" aria-pressed="false">
                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="forgotConfirmPassword" class="form-label">Confirm New Password</label>
                            <div class="password-toggle-wrap">
                                <input type="password" class="form-control" id="forgotConfirmPassword" name="confirm_password" placeholder="Confirm new password" required>
                                <button type="button" class="password-toggle-btn" data-password-toggle="forgotConfirmPassword" aria-label="Show password" aria-pressed="false">
                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn w-100" style="border-radius:999px; background:#e0fbe2; border:none; color:#166534; font-weight:500; box-shadow: 6px 6px 16px rgba(148,163,184,0.4), -6px -6px 18px rgba(255,255,255,1); height:42px;">Reset Password</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="env-foot">
            Staff forgot-password via OTP
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(app_url('/js/modules/shared/forgot_password.js?v=' . (string)@filemtime(__DIR__ . '/../../js/modules/shared/forgot_password.js'))); ?>"></script>
</body>
</html>
