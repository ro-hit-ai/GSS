document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('changePasswordForm');
    var msg = document.getElementById('changePasswordMessage');
    var btn = document.getElementById('cpSubmitBtn');

    function setMessage(text, type) {
        if (!msg) return;
        msg.textContent = text || '';
        msg.className = type ? ('alert alert-' + type) : '';
        msg.style.display = text ? 'block' : 'none';
    }

    function qs(name) {
        try {
            return new URLSearchParams(window.location.search || '').get(name);
        } catch (_e) {
            return null;
        }
    }

    function resolveNextUrl(next) {
        next = (next == null ? '' : String(next)).trim();
        if (!next) return '';

        if (next.indexOf('..') !== -1) return '';

        if (/^https?:\/\//i.test(next)) return next;

        if (next.charAt(0) === '/') return next;

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        return base + '/' + next.replace(/^\/+/, '');
    }

    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        setMessage('', '');

        var current = (document.getElementById('cpCurrent') || {}).value || '';
        var np = (document.getElementById('cpNew') || {}).value || '';
        var cp = (document.getElementById('cpConfirm') || {}).value || '';

        current = String(current).trim();
        np = String(np).trim();
        cp = String(cp).trim();

        if (!current || !np || !cp) {
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

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/shared/change_password.php';

        if (btn) {
            btn.disabled = true;
            btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
            btn.textContent = 'Updating...';
        }

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ current_password: current, new_password: np })
        })
            .then(function (res) {
                return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; });
            })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    setMessage((data && data.message) ? data.message : 'Failed to change password.', 'danger');
                    return;
                }

                setMessage('Password updated successfully. Redirecting...', 'success');
                var next = qs('next');
                if (!next) next = (data.data && data.data.redirect) ? String(data.data.redirect) : '';
                if (!next) next = 'index.php';

                var target = resolveNextUrl(next);
                if (!target) {
                    target = resolveNextUrl('index.php');
                }

                setTimeout(function () {
                    window.location.href = target;
                }, 600);
            })
            .catch(function () {
                setMessage('Network error. Please try again.', 'danger');
            })
            .finally(function () {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = btn.dataset.originalText || 'Update Password';
                }
            });
    });
});
