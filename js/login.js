document.addEventListener('DOMContentLoaded', function () {
    let currentUserId = null;
    let lastLoginPayload = null;

    const loginForm   = document.getElementById('loginForm');
    const otpForm     = document.getElementById('otpForm');
    const loginMsgEl  = document.getElementById('loginMessage');
    const usernameEl  = document.getElementById('username');
    const passwordEl  = document.getElementById('password');
    const captchaEl   = document.getElementById('captcha');
    const otpEl       = document.getElementById('otp');
    const slider      = document.getElementById('loginSlider');
    const loginPane   = document.getElementById('loginPane');
    const otpPane     = document.getElementById('otpPane');
    const resendOtpBtn = document.getElementById('resendOtpBtn');
    const backToLoginBtn = document.getElementById('backToLoginBtn');

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

    function setMessage(message, type) {
        if (!loginMsgEl) return;
        loginMsgEl.style.display = message ? 'block' : 'none';
        loginMsgEl.textContent = message || '';
        loginMsgEl.className = '';
        if (!message) return;
        if (type === 'success') {
            loginMsgEl.classList.add('alert', 'alert-success');
        } else if (type === 'error') {
            loginMsgEl.classList.add('alert', 'alert-danger');
        } else {
            loginMsgEl.classList.add('alert', 'alert-secondary');
        }
    }

    function showOtpCard() {
        if (loginPane) {
            loginPane.style.transform = 'translate3d(-110%,0,0)';
            loginPane.style.opacity = '0';
        }
        if (otpPane) {
            otpPane.style.transform = 'translate3d(0,0,0)';
            otpPane.style.opacity = '1';
        }
        if (otpEl) {
            setTimeout(function () {
                try {
                    otpEl.focus();
                } catch (_e) {
                }
            }, 50);
        }
    }

    function showLoginCard() {
        if (otpPane) {
            otpPane.style.transform = 'translate3d(110%,0,0)';
            otpPane.style.opacity = '0';
        }
        if (loginPane) {
            loginPane.style.transform = 'translate3d(0,0,0)';
            loginPane.style.opacity = '1';
        }
    }

    function isRedirectAllowedForRole(redirectUrl, role) {
        var url = String(redirectUrl || '').trim();
        var r = String(role || '').toLowerCase().trim();
        if (!url) return false;

        url = url.replace(/^https?:\/\/[^/]+/i, '');
        if (url.indexOf('..') !== -1) return false;

        var normalized = url.replace(/^\/+/, '');

        if (normalized === 'index.php' || normalized === 'modules/shared/candidate_report.php') return true;

        var allowPrefixes = {
            'gss_admin': ['modules/gss_admin/'],
            'team_lead': ['modules/team_lead/'],
            'verifier': ['modules/verifier/'],
            'db_verifier': ['modules/db_verifier/'],
            'qa': ['modules/qa/'],
            'client_admin': ['modules/client_admin/'],
            'company_recruiter': ['modules/hr_recruiter/']
        };

        var list = allowPrefixes[r] || [];
        for (var i = 0; i < list.length; i++) {
            if (normalized.indexOf(list[i]) === 0) return true;
        }

        return false;
    }

    function getRedirectFallback(data) {
        var preset = data && data.data && (data.data.passwordReset === 1 || data.data.passwordReset === '1');
        if (preset) {
            var serverCp = data && data.data && data.data.urlPath ? String(data.data.urlPath) : '';
            if (serverCp) return serverCp;
            return 'modules/shared/change_password.php';
        }

        var server = data && data.data && data.data.urlPath ? String(data.data.urlPath) : '';
        if (server) return server;

        var role = data && data.data && data.data.moduleAccess ? String(data.data.moduleAccess) : '';
        var fromLogin = (window.LOGIN_REDIRECT || '').trim();
        if (fromLogin && isRedirectAllowedForRole(fromLogin, role)) return fromLogin;

        return 'index.php';
    }

    async function handleLoginSubmit(e) {
        e.preventDefault();

        if (!usernameEl || !passwordEl || !captchaEl) {
            return;
        }

        const username = usernameEl.value.trim();
        const password = passwordEl.value.trim();
        const captcha  = captchaEl.value.trim();

        if (!username || !password || !captcha) {
            setMessage('Please enter username, password and captcha.', 'error');
            return;
        }

        setMessage('Validating credentials, please wait...', 'info');
        lastLoginPayload = { username: username, password: password, captcha: captcha };

        try {
            const response = await fetch('api/gssadmin/request_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password, captcha }),
                credentials: 'same-origin'
            });

            const data = await response.json().catch(function () { return {}; });

            if (!response.ok || !data.success) {
                setMessage(data.message || 'Login failed. Please check credentials and captcha.', 'error');
                showLoginCard();
                return;
            }

            currentUserId = data.userId;
            setMessage(data.message || 'OTP sent. Please check your mobile/email.', 'success');
            showOtpCard();

            if (otpEl) {
                // Temporary dev-mode: auto-fill OTP if backend returns it
                if (data && data.otp) {
                    otpEl.value = String(data.otp);
                }
                otpEl.focus();
            }
        } catch (_err) {
            setMessage('Network error. Please try again.', 'error');
        }
    }

    async function handleResendOtp() {
        var payload = lastLoginPayload || {
            username: usernameEl ? usernameEl.value.trim() : '',
            password: passwordEl ? passwordEl.value.trim() : '',
            captcha: captchaEl ? captchaEl.value.trim() : ''
        };

        if (!payload.username || !payload.password || !payload.captcha) {
            setMessage('Please login again before requesting a new OTP.', 'error');
            showLoginCard();
            return;
        }

        setMessage('Sending a new OTP, please wait...', 'info');

        try {
            const response = await fetch('api/gssadmin/request_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                credentials: 'same-origin'
            });

            const data = await response.json().catch(function () { return {}; });

            if (!response.ok || !data.success) {
                setMessage(data.message || 'Unable to resend OTP. Please login again.', 'error');
                return;
            }

            currentUserId = data.userId || currentUserId;
            lastLoginPayload = payload;
            setMessage(data.message || 'A new OTP has been sent.', 'success');

            if (otpEl) {
                if (data && data.otp) {
                    otpEl.value = String(data.otp);
                } else {
                    otpEl.value = '';
                }
                otpEl.focus();
            }
        } catch (_err) {
            setMessage('Network error while resending OTP. Please try again.', 'error');
        }
    }

    async function handleOtpSubmit(e) {
        e.preventDefault();
        if (!otpEl) return;

        const otp = otpEl.value.trim();

        if (!currentUserId) {
            setMessage('Session expired. Please login again.', 'error');
            showLoginCard();
            return;
        }

        if (!otp) {
            setMessage('Please enter OTP.', 'error');
            return;
        }

        setMessage('Verifying OTP, please wait...', 'info');

        try {
            const response = await fetch('api/gssadmin/verify_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId: currentUserId, otp: otp }),
                credentials: 'same-origin'
            });

            const data = await response.json().catch(function () { return {}; });

            if (!response.ok || !data.success) {
                setMessage(data.message || 'Invalid or expired OTP.', 'error');
                return;
            }

            var redirectUrl = getRedirectFallback(data);
            setMessage('Login successful. Redirecting...', 'success');
            window.location.href = redirectUrl;
        } catch (_err) {
            setMessage('Network error while verifying OTP. Please try again.', 'error');
        }
    }

    if (loginForm) {
        loginForm.addEventListener('submit', handleLoginSubmit);
    }

    if (otpForm) {
        otpForm.addEventListener('submit', handleOtpSubmit);
    }

    if (resendOtpBtn) {
        resendOtpBtn.addEventListener('click', function (e) {
            e.preventDefault();
            handleResendOtp();
        });
    }

    if (backToLoginBtn) {
        backToLoginBtn.addEventListener('click', function (e) {
            e.preventDefault();
            showLoginCard();
            setMessage('', '');
            if (otpEl) otpEl.value = '';
        });
    }

    bindPasswordToggles();
});
