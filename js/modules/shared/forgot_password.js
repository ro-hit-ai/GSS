document.addEventListener('DOMContentLoaded', function () {
    var msgEl = document.getElementById('forgotPasswordMessage');
    var requestForm = document.getElementById('forgotPasswordRequestForm');
    var verifyForm = document.getElementById('forgotPasswordVerifyForm');
    var resetForm = document.getElementById('forgotPasswordResetForm');
    var requestPane = document.getElementById('forgotRequestPane');
    var verifyPane = document.getElementById('forgotVerifyPane');
    var resetPane = document.getElementById('forgotResetPane');
    var forgotOtp = document.getElementById('forgotOtp');

    function setMessage(text, type) {
        if (!msgEl) return;
        msgEl.textContent = text || '';
        msgEl.className = type ? ('alert alert-' + type) : '';
        msgEl.style.display = text ? 'block' : 'none';
    }

    function showPane(name) {
        var map = {
            request: { pane: requestPane, transform: 'translate3d(0,0,0)', opacity: '1' },
            verify: { pane: verifyPane, transform: 'translate3d(0,0,0)', opacity: '1' },
            reset: { pane: resetPane, transform: 'translate3d(0,0,0)', opacity: '1' }
        };

        [
            { pane: requestPane, off: 'translate3d(-110%,0,0)' },
            { pane: verifyPane, off: 'translate3d(110%,0,0)' },
            { pane: resetPane, off: 'translate3d(110%,0,0)' }
        ].forEach(function (item) {
            if (!item.pane) return;
            item.pane.style.transform = item.off;
            item.pane.style.opacity = '0';
        });

        if (map[name] && map[name].pane) {
            map[name].pane.style.transform = map[name].transform;
            map[name].pane.style.opacity = map[name].opacity;
        }
    }

    function bindPasswordToggles() {
        var buttons = document.querySelectorAll('[data-password-toggle]');
        buttons.forEach(function (btn) {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-password-toggle') || '';
                var input = targetId ? document.getElementById(targetId) : null;
                if (!input) return;
                var nextType = input.type === 'password' ? 'text' : 'password';
                input.type = nextType;
                btn.setAttribute('aria-pressed', nextType === 'text' ? 'true' : 'false');
                btn.setAttribute('aria-label', nextType === 'text' ? 'Hide password' : 'Show password');
                btn.style.color = nextType === 'text' ? '#0f172a' : '#64748b';
            });
        });
    }

    function postJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload || {})
        }).then(function (res) {
            return res.json().catch(function () {
                return { status: 0, message: 'Invalid server response.' };
            });
        });
    }

    var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
    bindPasswordToggles();
    showPane('request');

    if (requestForm) {
        requestForm.addEventListener('submit', function (e) {
            e.preventDefault();
            setMessage('', '');

            var identifier = ((document.getElementById('forgotIdentifier') || {}).value || '').trim();
            if (!identifier) {
                setMessage('Username or email is required.', 'danger');
                return;
            }

            postJson(base + '/api/gssadmin/forgot_password_request.php', { identifier: identifier })
                .then(function (data) {
                    var ok = data && (data.status === 1 || data.status === '1');
                    if (!ok) {
                        setMessage((data && data.message) ? data.message : 'Unable to start password reset.', 'danger');
                        return;
                    }
                    setMessage((data && data.message) ? data.message : 'If the account exists, an OTP has been sent.', 'success');
                    showPane('verify');
                    if (forgotOtp) {
                        try { forgotOtp.focus(); } catch (_e) {}
                    }
                })
                .catch(function () {
                    setMessage('Network error. Please try again.', 'danger');
                });
        });
    }

    if (verifyForm) {
        verifyForm.addEventListener('submit', function (e) {
            e.preventDefault();
            setMessage('', '');

            var otp = (forgotOtp && forgotOtp.value ? String(forgotOtp.value) : '').trim();
            if (!otp) {
                setMessage('OTP is required.', 'danger');
                return;
            }

            postJson(base + '/api/gssadmin/forgot_password_verify.php', { otp: otp })
                .then(function (data) {
                    var ok = data && (data.status === 1 || data.status === '1');
                    if (!ok) {
                        setMessage((data && data.message) ? data.message : 'Invalid or expired OTP.', 'danger');
                        return;
                    }
                    setMessage('OTP verified. Please set your new password.', 'success');
                    showPane('reset');
                })
                .catch(function () {
                    setMessage('Network error. Please try again.', 'danger');
                });
        });
    }

    if (resetForm) {
        resetForm.addEventListener('submit', function (e) {
            e.preventDefault();
            setMessage('', '');

            var np = ((document.getElementById('forgotNewPassword') || {}).value || '').trim();
            var cp = ((document.getElementById('forgotConfirmPassword') || {}).value || '').trim();
            if (!np || !cp) {
                setMessage('All fields are required.', 'danger');
                return;
            }
            if (np.length < 6) {
                setMessage('New password must be at least 6 characters.', 'danger');
                return;
            }
            if (np !== cp) {
                setMessage('New password and confirm password do not match.', 'danger');
                return;
            }

            postJson(base + '/api/shared/forgot_password_reset.php', { new_password: np, confirm_password: cp })
                .then(function (data) {
                    var ok = data && (data.status === 1 || data.status === '1');
                    if (!ok) {
                        setMessage((data && data.message) ? data.message : 'Failed to reset password.', 'danger');
                        return;
                    }
                    setMessage('Password reset successful. Redirecting to login...', 'success');
                    setTimeout(function () {
                        window.location.href = base + '/login.php';
                    }, 700);
                })
                .catch(function () {
                    setMessage('Network error. Please try again.', 'danger');
                });
        });
    }
});
