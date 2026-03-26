class Reference {

    static _initialized = false;
    static _listeners = [];

    /* ===================== LIFECYCLE ===================== */

    static init() {
        return this;
    }

    static onPageLoad() {
        if (this._initialized) return;

        this._initialized = true;

        this.hydrateFromDB();
        this.initFormHandling();
    }

    static cleanup() {
        this._listeners.forEach(({ el, type, fn }) =>
            el?.removeEventListener(type, fn)
        );
        this._listeners = [];
        this._initialized = false;

        const notif = document.querySelector('.reference-notification');
        if (notif) notif.remove();
    }

    static on(el, type, fn) {
        if (!el) return;
        el.addEventListener(type, fn);
        this._listeners.push({ el, type, fn });
    }

    /* ===================== NOTIFICATION (EDUCATION STYLE) ===================== */

    static showNotification(message, isError = false) {
        const existing = document.querySelector('.reference-notification');
        if (existing) existing.remove();

        const notif = document.createElement('div');
        notif.className = `
            reference-notification
            alert
            ${isError ? 'alert-danger' : 'alert-success'}
            alert-dismissible
            fade
            show
        `;
        notif.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 320px;
            max-width: 420px;
        `;

        notif.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        document.body.appendChild(notif);

        setTimeout(() => {
            if (notif.parentNode) notif.remove();
        }, 5000);
    }

    /* ===================== DATA HYDRATION ===================== */

    static hydrateFromDB() {
        const dataEl = document.getElementById("referenceData");
        if (!dataEl) return;

        try {
            const data = JSON.parse(dataEl.dataset.reference || "{}");
            if (Object.keys(data).length) {
                this.populateForm(data);
            }
        } catch (e) {
            console.error("Reference data parse error", e);
        }
    }

    static populateForm(data) {
        [
            'reference_name',
            'reference_designation',
            'reference_company',
            'reference_mobile',
            'reference_email',
            'relationship',
            'years_known'
        ].forEach(field => {
            const el = document.querySelector(`[name="${field}"]`);
            if (el && data[field] !== undefined) {
                el.value = data[field];
            }
        });
    }

    /* ===================== FORM HANDLING ===================== */

    static initFormHandling() {
        const form = document.getElementById('referenceForm');
        if (!form) return;

        this.on(form, 'submit', e => {
            e.preventDefault();
            e.stopImmediatePropagation();
        });

        this.on(
            form.querySelector('.save-draft-btn[data-page="reference"]'),
            'click',
            e => {
                e.preventDefault();
                this.saveDraft();
            }
        );

        this.on(
            document.querySelector('.external-submit-btn[data-form="referenceForm"]'),
            'click',
            async e => {
                e.preventDefault();
                await this.submitForm();
            }
        );

        this.on(
            document.querySelector('.prev-btn[data-form="referenceForm"]'),
            'click',
            e => {
                e.preventDefault();
                window.Router?.navigateTo
                    ? Router.navigateTo('employment')
                    : (location.href = '/candidate/employment.php');
            }
        );
    }

    /* ===================== VALIDATION ===================== */

    static validateForm(finalSubmit = false) {
        const form = document.getElementById('referenceForm');
        if (!form) return false;

        const get = n => form.querySelector(`[name="${n}"]`)?.value.trim() || '';

        const values = {
            reference_name: get('reference_name'),
            reference_designation: get('reference_designation'),
            reference_company: get('reference_company'),
            reference_mobile: get('reference_mobile'),
            reference_email: get('reference_email'),
            relationship: get('relationship'),
            years_known: get('years_known')
        };

        const filledCount = Object.values(values).filter(v => v).length;

        if (!finalSubmit && filledCount === 0) return true;
        if (!finalSubmit && filledCount > 0 && filledCount < 7) {
            this.showNotification(
                'Fill all fields or leave all empty for draft save',
                true
            );
            return false;
        }

        if (finalSubmit) {
            for (const [k, v] of Object.entries(values)) {
                if (!v) {
                    this.showNotification(`${k.replace(/_/g, ' ')} is required`, true);
                    return false;
                }
            }
        }

        if (values.reference_email && !this.validateEmail(values.reference_email)) {
            this.showNotification('Invalid email format', true);
            return false;
        }

        if (values.reference_mobile && !this.validateMobile(values.reference_mobile)) {
            this.showNotification('Mobile must be 10 digits', true);
            return false;
        }

        if (
            values.years_known &&
            (!this.validateNumber(values.years_known) || parseInt(values.years_known) <= 0)
        ) {
            this.showNotification('Years known must be a positive number', true);
            return false;
        }

        return true;
    }

    static validateEmail(v) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    }

    static validateMobile(v) {
        return /^[0-9]{10}$/.test(v);
    }

    static validateNumber(v) {
        return /^[0-9]+$/.test(v);
    }

    /* ===================== API ===================== */

    static async saveDraft() {
        if (!this.validateForm(false)) return;

        const form = document.getElementById('referenceForm');
        const fd = new FormData(form);
        fd.append('draft', '1');

        try {
            const res = await fetch(
                `${window.APP_BASE_URL}/api/candidate/store_reference.php`,
                {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }
            );

            const data = await res.json();

            data.success
                ? this.showNotification('✅ Reference draft saved')
                : this.showNotification(data.message || 'Save failed', true);

        } catch (e) {
            this.showNotification(e.message, true);
        }
    }

    static async submitForm() {
        if (!this.validateForm(true)) return;

        const form = document.getElementById('referenceForm');
        const fd = new FormData(form);
        fd.append('draft', '0');

        try {
            const res = await fetch(
                `${window.APP_BASE_URL}/api/candidate/store_reference.php`,
                {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }
            );

            const data = await res.json();

            if (!data.success) {
                this.showNotification(data.message || 'Save failed', true);
                return;
            }

            this.showNotification('✅ Reference details saved successfully');

            window.Router?.markCompleted?.('reference');
            window.Router?.updateProgress?.();
            window.Forms?.clearDraft?.('reference');

            window.Router?.navigateTo
                ? Router.navigateTo('success')
                : (location.href = '?page=success');

        } catch (e) {
            this.showNotification(e.message, true);
        }
    }
}

window.Reference = Reference;
