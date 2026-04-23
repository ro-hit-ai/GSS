(function () {
    if (window.CandidateNotify) return;

    const DEFAULT_TITLES = {
        success: 'Saved',
        error: 'Something went wrong',
        warn: 'Validation required',
        info: 'Notice'
    };

    function normalizeType(type) {
        const t = String(type || 'info').toLowerCase();
        if (t === 'warning') return 'warn';
        return ['success', 'error', 'warn', 'info'].includes(t) ? t : 'info';
    }

    function ensureToastRoot() {
        let root = document.getElementById('toast-root');
        if (!root) {
            root = document.createElement('div');
            root.id = 'toast-root';
            document.body.appendChild(root);
        }
        return root;
    }

    function resolveTarget(target) {
        if (!target) return null;
        if (!(target instanceof HTMLElement)) return null;
        if (target.matches('input, select, textarea, .file-upload-box, [data-file-upload], .form-control, .photo-upload-box')) {
            return target;
        }
        return target.closest('input, select, textarea, .file-upload-box, [data-file-upload], .form-control, .photo-upload-box');
    }

    function resolveFocusTarget(target) {
        const el = resolveTarget(target);
        if (!el) return null;
        if (el.matches('input, select, textarea, button, a')) return el;
        return el.querySelector('input, select, textarea, button, a, [data-file-choose]');
    }

    function ensureFieldErrorNode(target) {
        const el = resolveTarget(target);
        if (!el) return null;

        let host = el;
        if (el.matches('input, select, textarea')) {
            host = el;
        } else if (el.closest('.form-control')) {
            host = el.closest('.form-control');
        }

        let errorNode = host.nextElementSibling;
        if (!errorNode || !errorNode.classList.contains('candidate-field-error')) {
            errorNode = document.createElement('div');
            errorNode.className = 'candidate-field-error';
            host.insertAdjacentElement('afterend', errorNode);
        }
        return errorNode;
    }

    function bindAutoClear(target) {
        const el = resolveTarget(target);
        if (!el || el.dataset.validationBound === '1') return;
        el.dataset.validationBound = '1';

        const handler = () => window.CandidateNotify.clearFieldError(el);
        el.addEventListener('input', handler);
        el.addEventListener('change', handler);
    }

    const api = {
        show(options, legacyType, legacyTimeout) {
            const opts = typeof options === 'string'
                ? { message: options, type: legacyType, timeout: legacyTimeout }
                : { ...(options || {}) };

            const type = normalizeType(opts.type);
            const message = String(opts.message || '').trim();
            if (!message) return null;

            const title = String(opts.title || DEFAULT_TITLES[type] || DEFAULT_TITLES.info);
            // React-like quick toasts by default.
            const timeout = typeof opts.timeout === 'number' ? opts.timeout : 2800;
            const sticky = !!opts.sticky;
            const dedupeKey = String(opts.id || `${type}:${title}:${message}`);

            const root = ensureToastRoot();
            // Avoid relying on CSS.escape (not available in some environments).
            try {
                const existing = Array.from(root.querySelectorAll('[data-toast-key]'))
                    .find((node) => node && node.dataset && node.dataset.toastKey === dedupeKey);
                if (existing) existing.remove();
            } catch (_e) {
            }

            const toast = document.createElement('div');
            // Use a dedicated class to avoid Bootstrap's `.toast` visibility rules.
            toast.className = `candidate-toast toast-card ${type}`;
            toast.dataset.toastKey = dedupeKey;
            toast.setAttribute('role', type === 'error' || type === 'warn' ? 'alert' : 'status');
            toast.setAttribute('aria-live', type === 'error' || type === 'warn' ? 'assertive' : 'polite');

            const icons = {
                success: 'fa-circle-check',
                error: 'fa-circle-xmark',
                info: 'fa-circle-info',
                warn: 'fa-triangle-exclamation'
            };

            toast.innerHTML = `
                <div class="toast-icon-wrap"><i class="fas ${icons[type] || icons.info}" aria-hidden="true"></i></div>
                <div class="toast-copy">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button type="button" class="toast-close" aria-label="Close notification">&times;</button>
            `;

            const close = () => {
                if (toast.parentNode) toast.remove();
            };

            root.appendChild(toast);

            // Ensure visibility even if Bootstrap toast styles are present.
            try {
                toast.style.display = 'flex';
                toast.style.opacity = '1';
                toast.style.visibility = 'visible';
                toast.style.pointerEvents = 'auto';
            } catch (_e) {
            }

            try {
                const closeBtn = toast.querySelector('.toast-close');
                if (closeBtn) closeBtn.addEventListener('click', close);
            } catch (_e2) {
            }

            if (!sticky) {
                const startClose = () => {
                    try {
                        toast.classList.add('is-closing');
                    } catch (_e3) {
                    }
                    window.setTimeout(close, 220);
                };
                const timer = window.setTimeout(startClose, timeout);
                toast.addEventListener('mouseenter', () => window.clearTimeout(timer), { once: true });
            }

            return toast;
        },

        success(message, opts = {}) {
            return this.show({ ...opts, type: 'success', message });
        },

        error(message, opts = {}) {
            // Auto-fade by default; callers can pass `sticky: true` when needed.
            return this.show({ ...opts, type: 'error', message });
        },

        info(message, opts = {}) {
            return this.show({ ...opts, type: 'info', message });
        },

        warn(message, opts = {}) {
            return this.show({ ...opts, type: 'warn', message });
        },

        clearFieldError(target) {
            const el = resolveTarget(target);
            if (!el) return;
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
            const host = el.matches('input, select, textarea') ? el : (el.closest('.form-control') || el);
            const errorNode = host.nextElementSibling;
            if (errorNode && errorNode.classList.contains('candidate-field-error')) {
                errorNode.remove();
            }
        },

        setFieldError(target, message) {
            const el = resolveTarget(target);
            const text = String(message || '').trim();
            if (!el || !text) return;

            el.classList.add('is-invalid');
            el.setAttribute('aria-invalid', 'true');

            const errorNode = ensureFieldErrorNode(el);
            if (errorNode) errorNode.textContent = text;

            bindAutoClear(el);
        },

        addFieldError(errors, target, message) {
            const entry = { field: resolveTarget(target), message: String(message || '').trim() };
            this.setFieldError(entry.field, entry.message);
            errors.push(entry);
            return entry;
        },

        clearValidation(form) {
            const root = form instanceof HTMLElement ? form : document;
            root.querySelectorAll('.candidate-field-error').forEach((node) => node.remove());
            root.querySelectorAll('.candidate-validation-summary').forEach((node) => node.remove());
            root.querySelectorAll('.is-invalid').forEach((node) => {
                node.classList.remove('is-invalid');
                node.removeAttribute('aria-invalid');
            });
        },

        focusField(target) {
            const focusTarget = resolveFocusTarget(target);
            if (!focusTarget) return;

            const scrollTarget = resolveTarget(target) || focusTarget;
            scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.setTimeout(() => {
                try {
                    focusTarget.focus({ preventScroll: true });
                } catch (_e) {
                    focusTarget.focus();
                }
            }, 120);
        },

        renderValidationSummary(form, title, errors) {
            if (!(form instanceof HTMLElement) || !Array.isArray(errors) || errors.length === 0) return null;

            const summary = document.createElement('div');
            summary.className = 'candidate-validation-summary';
            summary.setAttribute('role', 'alert');

            const list = document.createElement('div');
            list.className = 'candidate-validation-summary-list';

            errors.slice(0, 6).forEach((error) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'candidate-validation-summary-item';
                btn.textContent = error.message;
                btn.addEventListener('click', () => this.focusField(error.field));
                list.appendChild(btn);
            });

            const extraCount = errors.length - 6;
            const extra = extraCount > 0
                ? `<div class="candidate-validation-summary-more">+ ${extraCount} more issue${extraCount > 1 ? 's' : ''}</div>`
                : '';

            summary.innerHTML = `
                <div class="candidate-validation-summary-head">
                    <div>
                        <div class="candidate-validation-summary-title">${title}</div>
                        <div class="candidate-validation-summary-subtitle">Review the highlighted fields below. Click an issue to jump to it.</div>
                    </div>
                    <button type="button" class="candidate-validation-summary-close" aria-label="Close validation summary">&times;</button>
                </div>
            `;
            summary.appendChild(list);
            if (extra) summary.insertAdjacentHTML('beforeend', extra);

            summary.querySelector('.candidate-validation-summary-close').addEventListener('click', () => summary.remove());

            const anchor = form.querySelector('.form-header');
            if (anchor) {
                anchor.insertAdjacentElement('afterend', summary);
            } else {
                form.prepend(summary);
            }
            return summary;
        },

        validation({ form, title, message, errors = [] } = {}) {
            const first = errors.find((entry) => entry && entry.field);
            if (first) {
                this.focusField(first.field);
            }

            return this.warn(
                message || `Please fix ${errors.length} issue${errors.length === 1 ? '' : 's'} before continuing.`,
                {
                    title: title || 'Validation required',
                    sticky: false,
                    timeout: 3000,
                    id: `validation:${form && form.id ? form.id : 'candidate-form'}`
                }
            );
        }
    };

    window.CandidateNotify = api;

    if (!window.__candidateNativeAlert) {
        window.__candidateNativeAlert = window.alert ? window.alert.bind(window) : null;
        window.alert = function candidateAlertBridge(message) {
            const text = String(message || '').trim();
            if (!text) return;
            api.error(text, {
                title: 'Notice',
                sticky: false,
                timeout: 4500,
                id: `legacy-alert:${text}`
            });
        };
    }
})();
