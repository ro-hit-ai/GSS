class Social {
    static _initialized = false;
    static _listeners = [];

    /* ===================== LIFECYCLE ===================== */

    static onPageLoad() {
        if (this._initialized) this.cleanup();
        this._initialized = true;

        this.initForm();
        this.setupFieldValidation();
        this.prefillFormData();

        console.log("✅ Social page initialized");
    }

    static cleanup() {
        this._listeners.forEach(({ el, type, fn }) =>
            el.removeEventListener(type, fn)
        );
        this._listeners = [];
        this._initialized = false;

        // Remove any existing notifications
        const existing = document.querySelector('.social-notification');
        if (existing) existing.remove();
    }

    static on(el, type, fn) {
        if (!el) return;
        el.addEventListener(type, fn);
        this._listeners.push({ el, type, fn });
    }

    static get form() {
        return document.getElementById("socialForm");
    }

    /* ===================== NOTIFICATION ===================== */

    static showNotification(message, isError = false) {
        // Remove existing notification
        const existing = document.querySelector('.social-notification');
        if (existing) existing.remove();

        const notification = document.createElement('div');
        notification.className = `
            social-notification
            alert
            ${isError ? 'alert-danger' : 'alert-success'}
            alert-dismissible
            fade
            show
        `;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 320px;
            max-width: 420px;
        `;

        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        document.body.appendChild(notification);

        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }

    /* ===================== FORM INIT ===================== */

    static initForm() {
        const form = this.form;
        if (!form) {
            console.warn("❌ Social form not found");
            return;
        }

        // Prevent native submit
        this.on(form, "submit", (e) => {
            e.preventDefault();
            e.stopPropagation();
        });

        // Save Draft
        const saveBtn = form.querySelector('.save-draft-btn[data-page="social"]');
        if (saveBtn) {
            this.on(saveBtn, "click", async (e) => {
                e.preventDefault();
                await this.saveDraft();
            });
        }

        // Next / Submit
        const nextBtn = form.querySelector('.external-submit-btn[data-form="socialForm"]');
        if (nextBtn) {
            this.on(nextBtn, "click", async (e) => {
                e.preventDefault();
                await this.submitFinal();
            });
        }

        // Previous button
        const prevBtn = form.querySelector(".prev-btn");
        if (prevBtn) {
            this.on(prevBtn, "click", (e) => {
                e.preventDefault();
                // Navigate to contact page (previous in flow)
                if (window.Router?.navigateTo) {
                    Router.navigateTo("contact");
                } else {
                    window.location.href = "?page=contact";
                }
            });
        }

        console.log("✅ Social form handlers bound");
    }

    /* ===================== PREFILL DATA ===================== */

    static prefillFormData() {
        if (!window.SOCIAL_DATA) {
            const dataEl = document.getElementById('socialData');
            if (dataEl && dataEl.dataset && dataEl.dataset.social) {
                try {
                    window.SOCIAL_DATA = JSON.parse(dataEl.dataset.social || '{}');
                } catch (e) {
                    window.SOCIAL_DATA = null;
                }
            }
        }

        // Prefill from window.SOCIAL_DATA if available
        if (window.SOCIAL_DATA) {
            const form = this.form;
            if (!form) return;

            const fields = [
                'linkedin_url', 'facebook_url', 'twitter_url',
                'instagram_url', 'other_url', 'content'
            ];

            fields.forEach(field => {
                const input = form.querySelector(`[name="${field}"]`);
                if (input && window.SOCIAL_DATA[field] !== undefined) {
                    input.value = window.SOCIAL_DATA[field];
                }
            });

            // Handle consent checkbox
            const consentCheckbox = form.querySelector('[name="consent_bgv"]');
            if (consentCheckbox && window.SOCIAL_DATA.consent_bgv !== undefined) {
                consentCheckbox.checked = Boolean(window.SOCIAL_DATA.consent_bgv);
            }
        }
    }

    /* ===================== VALIDATION ===================== */

    static validate(final = true) {
        const form = this.form;
        if (!form) return false;

        // Clear previous errors
        form.querySelectorAll('.is-invalid').forEach(el =>
            el.classList.remove('is-invalid')
        );

        // For draft saves, skip strict validation
        if (!final) return true;

        let valid = true;
        const errors = [];

        // Check required fields
        const requiredFields = [
            { name: 'linkedin_url', label: 'LinkedIn Profile' },
            { name: 'facebook_url', label: 'Facebook Profile' }
        ];

        requiredFields.forEach(field => {
            const input = form.querySelector(`[name="${field.name}"]`);
            if (input && !input.value.trim()) {
                input.classList.add('is-invalid');
                valid = false;
                errors.push(field.label);
            }
        });

        // Check consent checkbox (required for final submission)
        const consentCheckbox = form.querySelector('[name="consent_bgv"]');
        if (consentCheckbox && !consentCheckbox.checked) {
            consentCheckbox.classList.add('is-invalid');
            valid = false;
            errors.push("Consent to verification");
        }

        // Validate URL formats
        const urlInputs = form.querySelectorAll('input[type="url"]');
        urlInputs.forEach(input => {
            if (input.value && !this.isValidUrl(input.value)) {
                input.classList.add('is-invalid');
                valid = false;
                const label = input.previousElementSibling?.textContent || input.name;
                errors.push(`Valid URL for ${label}`);
            }
        });

        if (!valid) {
            this.showNotification(
                `Please fix: ${errors.slice(0, 3).join(', ')}${errors.length > 3 ? '...' : ''}`,
                true
            );
            // Focus first invalid field
            form.querySelector('.is-invalid')?.focus();
        }

        return valid;
    }

    /* ===================== ACTIONS ===================== */

    static async saveDraft() {
        if (!this.validate(false)) return;

        const fd = new FormData(this.form);
        fd.append("save_draft", "1");

        const ok = await this.send(fd);
        if (ok) this.showNotification("✅ Social media draft saved");
    }

    static async submitFinal() {
        if (!this.validate(true)) return;

        const fd = new FormData(this.form);
        fd.append("save_draft", "0");

        const ok = await this.send(fd);
        if (!ok) return;

        // Mark as completed in Router
        if (window.Router?.markCompleted) {
            Router.markCompleted("social");
        } else {
            localStorage.setItem("completed-social", "1");
        }

        // Navigate to ecourt page after successful submission
        if (window.Router?.navigateTo) {
            Router.navigateTo("ecourt");
        } else {
            window.location.href = "?page=ecourt";
        }
    }

    /* ===================== API ===================== */

    static async send(formData) {
        try {
            const endpoint = `${window.APP_BASE_URL || ''}/api/candidate/store_social.php`;

            const res = await fetch(endpoint, {
                method: "POST",
                body: formData,
                credentials: "include"
            });

            const data = await res.json();

            if (!data.success) {
                throw new Error(data.message || "Save failed");
            }

            this.showNotification("✅ Social media information saved successfully");
            return true;

        } catch (err) {
            console.error("❌ Social error:", err);
            this.showNotification(err.message || "Server error", true);
            return false;
        }
    }

    /* ===================== FIELD VALIDATION ===================== */

    static setupFieldValidation() {
        const form = this.form;
        if (!form) return;

        // URL validation on blur
        const urlInputs = form.querySelectorAll('input[type="url"]');
        urlInputs.forEach(input => {
            this.on(input, 'blur', () => {
                if (input.value && !this.isValidUrl(input.value)) {
                    input.classList.add('is-invalid');
                }
            });
            this.on(input, 'input', () => input.classList.remove('is-invalid'));
        });

        // Consent checkbox validation
        const consentCheckbox = form.querySelector('[name="consent_bgv"]');
        if (consentCheckbox) {
            this.on(consentCheckbox, 'change', () => 
                consentCheckbox.classList.remove('is-invalid')
            );
        }

        // Clear errors on input for required fields
        form.querySelectorAll('[required]').forEach(f => {
            this.on(f, 'input', () => f.classList.remove('is-invalid'));
        });
    }

    /* ===================== UTILITIES ===================== */

    static isValidUrl(string) {
        try {
            const url = new URL(string);
            return url.protocol === 'http:' || url.protocol === 'https:';
        } catch (_) {
            return false;
        }
    }

    static isValidEmail(v) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    }

    static isValidPhone(v) {
        return v.replace(/\D/g, '').length >= 10;
    }
}

// Make available globally
window.Social = Social;
