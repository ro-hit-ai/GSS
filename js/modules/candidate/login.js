document.addEventListener('DOMContentLoaded', function () {
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
});
