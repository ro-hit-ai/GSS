document.addEventListener('DOMContentLoaded', function () {
    let currentUserId = null;

    const loginForm   = document.getElementById('loginForm');
    const otpForm     = document.getElementById('otpForm');
    const loginMsgEl  = document.getElementById('loginMessage');
    const usernameEl  = document.getElementById('username');
    const passwordEl  = document.getElementById('password');
    const captchaEl   = document.getElementById('captcha');
    const otpEl       = document.getElementById('otp');
    const otpCard     = document.getElementById('otpCard');

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
        if (otpCard) otpCard.style.display = 'block';
    }

    async function handleLoginSubmit(e) {
        e.preventDefault();

        if (!usernameEl || !passwordEl || !captchaEl) {
            console.error('Login form elements not found');
            return;
        }

        const username = usernameEl.value.trim();
        const password = passwordEl.value.trim();
        const captcha  = captchaEl.value.trim();

        if (!username || !password || !captcha) {
            setMessage('Please enter username, password and captcha.', 'error');
            return;
        }

        // Clear any previous message; avoid showing a "please wait" banner during login.
        setMessage('', '');

        try {
            const response = await fetch('api/gssadmin/request_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password, captcha })
            });

            const data = await response.json().catch(function () { return {}; });

            if (!response.ok || !data.success) {
                setMessage(data.message || 'Login failed. Please check credentials and captcha.', 'error');
                if (otpCard) otpCard.style.display = 'none';
                return;
            }

            currentUserId = data.userId;
            setMessage(data.message || 'OTP sent. Please check your mobile/email.', 'success');
            showOtpCard();
            if (otpEl) otpEl.focus();
        } catch (err) {
            console.error(err);
            setMessage('Network error. Please try again.', 'error');
        }
    }

    async function handleOtpSubmit(e) {
        e.preventDefault();
        if (!otpEl) return;

        const otp = otpEl.value.trim();

        if (!currentUserId) {
            setMessage('Session expired. Please login again.', 'error');
            if (otpCard) otpCard.style.display = 'none';
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
                body: JSON.stringify({ userId: currentUserId, otp: otp })
            });

            const data = await response.json().catch(function () { return {}; });

            if (!response.ok || !data.success) {
                setMessage(data.message || 'Invalid or expired OTP.', 'error');
                return;
            }

            var redirectUrl = data.data && data.data.urlPath
                ? data.data.urlPath
                : 'modules/gss_admin/dashboard.php';

            setMessage('Login successful. Redirecting...', 'success');

            setTimeout(function () {
                window.location.href = redirectUrl;
            }, 800);
        } catch (err) {
            console.error(err);
            setMessage('Network error while verifying OTP. Please try again.', 'error');
        }
    }

    if (loginForm) {
        loginForm.addEventListener('submit', handleLoginSubmit);
    }

    if (otpForm) {
        otpForm.addEventListener('submit', handleOtpSubmit);
    }
});
