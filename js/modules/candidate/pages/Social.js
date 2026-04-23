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
        const text = String(message || '').trim();
        if (!text) return;

        if (window.CandidateNotify && typeof window.CandidateNotify.show === 'function') {
            window.CandidateNotify.show({
                type: isError ? 'error' : 'success',
                title: isError ? 'Social media details not saved' : 'Social media details saved',
                message: text.replace(/^[^\w]+/, ''),
                sticky: !!isError
            });
            return;
        }

        if (typeof window.showAlert === 'function') {
            window.showAlert({ type: isError ? 'error' : 'success', message: text });
            return;
        }

        console[isError ? 'error' : 'log'](text);
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
        // Always re-read the latest server-provided data for this page load.
        const dataEl = document.getElementById('socialData');
        if (dataEl && dataEl.dataset && dataEl.dataset.social) {
            try {
                window.SOCIAL_DATA = JSON.parse(dataEl.dataset.social || '{}');
            } catch (e) {
                window.SOCIAL_DATA = null;
            }
        }

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

        if (window.CandidateNotify && typeof window.CandidateNotify.clearValidation === 'function') {
            window.CandidateNotify.clearValidation(form);
        } else {
            form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        }

        // For draft saves, skip strict validation
        if (!final) return true;

        const errors = [];
        const addError = (field, message) => {
            if (window.CandidateNotify && typeof window.CandidateNotify.addFieldError === 'function') {
                window.CandidateNotify.addFieldError(errors, field, message);
            } else {
                if (field && field.classList) field.classList.add('is-invalid');
                errors.push({ field, message });
            }
        };

        // Check required fields
        const requiredFields = [
            { name: 'linkedin_url', label: 'LinkedIn Profile' },
            { name: 'facebook_url', label: 'Facebook Profile' }
        ];

        requiredFields.forEach(field => {
            const input = form.querySelector(`[name="${field.name}"]`);
            if (input && !input.value.trim()) {
                addError(input, `${field.label} is required`);
            }
        });

        // Check consent checkbox (required for final submission)
        const consentCheckbox = form.querySelector('[name="consent_bgv"]');
        if (consentCheckbox && !consentCheckbox.checked) {
            addError(consentCheckbox, 'You must consent to social media verification');
        }

        // Validate URL formats
        const urlInputs = form.querySelectorAll('input[type="url"]');
        urlInputs.forEach(input => {
            if (input.value && !this.isValidUrl(input.value)) {
                const label = input.previousElementSibling?.textContent || input.name;
                addError(input, `Please enter a valid URL for ${String(label).replace(/\*/g, '').trim()}`);
            }
        });

        const otherUrl = form.querySelector('[name="other_url"]');
        if (otherUrl && otherUrl.value && !this.isValidUrl(otherUrl.value)) {
            addError(otherUrl, 'Please enter a valid URL for Other Profile/Portfolio');
        }

        if (errors.length > 0) {
            if (window.CandidateNotify && typeof window.CandidateNotify.validation === 'function') {
                window.CandidateNotify.validation({
                    form,
                    title: 'Social media details need attention',
                    message: `Please fix ${errors.length} issue${errors.length === 1 ? '' : 's'} before continuing.`,
                    errors
                });
            } else {
                this.showNotification(errors[0].message || 'Please fix highlighted fields', true);
            }
            return false;
        }

        return true;
    }

    /* ===================== ACTIONS ===================== */

    static async saveDraft() {
        if (!this.validate(false)) return;

        const fd = new FormData(this.form);
        fd.append("save_draft", "1");

        const ok = await this.send(fd);
        if (ok) this.showNotification("Social media draft saved");
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

            // Keep client-side cache in sync (helps when navigating back).
            try {
                const form = this.form;
                if (form) {
                    window.SOCIAL_DATA = {
                        content: String(form.querySelector('[name="content"]')?.value || ''),
                        linkedin_url: String(form.querySelector('[name="linkedin_url"]')?.value || ''),
                        facebook_url: String(form.querySelector('[name="facebook_url"]')?.value || ''),
                        instagram_url: String(form.querySelector('[name="instagram_url"]')?.value || ''),
                        twitter_url: String(form.querySelector('[name="twitter_url"]')?.value || ''),
                        other_url: String(form.querySelector('[name="other_url"]')?.value || ''),
                        consent_bgv: (form.querySelector('[name="consent_bgv"]')?.checked ? 1 : 0),
                    };
                }
            } catch (_e) {
            }

            this.showNotification("Social media information saved successfully");
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
                    if (window.CandidateNotify && typeof window.CandidateNotify.setFieldError === 'function') {
                        const label = input.previousElementSibling?.textContent || input.name;
                        window.CandidateNotify.setFieldError(input, `Please enter a valid URL for ${String(label).replace(/\*/g, '').trim()}`);
                    } else {
                        input.classList.add('is-invalid');
                    }
                }
            });
            this.on(input, 'input', () => {
                if (window.CandidateNotify && typeof window.CandidateNotify.clearFieldError === 'function') {
                    window.CandidateNotify.clearFieldError(input);
                } else {
                    input.classList.remove('is-invalid');
                }
            });
        });

        const otherUrl = form.querySelector('[name="other_url"]');
        if (otherUrl) {
            this.on(otherUrl, 'blur', () => {
                if (otherUrl.value && !this.isValidUrl(otherUrl.value)) {
                    if (window.CandidateNotify && typeof window.CandidateNotify.setFieldError === 'function') {
                        window.CandidateNotify.setFieldError(otherUrl, 'Please enter a valid URL for Other Profile/Portfolio');
                    } else {
                        otherUrl.classList.add('is-invalid');
                    }
                }
            });
            this.on(otherUrl, 'input', () => {
                if (window.CandidateNotify && typeof window.CandidateNotify.clearFieldError === 'function') {
                    window.CandidateNotify.clearFieldError(otherUrl);
                } else {
                    otherUrl.classList.remove('is-invalid');
                }
            });
        }

        // Consent checkbox validation
        const consentCheckbox = form.querySelector('[name="consent_bgv"]');
        if (consentCheckbox) {
            this.on(consentCheckbox, 'change', () => {
                if (window.CandidateNotify && typeof window.CandidateNotify.clearFieldError === 'function') {
                    window.CandidateNotify.clearFieldError(consentCheckbox);
                } else {
                    consentCheckbox.classList.remove('is-invalid');
                }
            });
        }

        // Clear errors on input for required fields
        form.querySelectorAll('[required]').forEach(f => {
            this.on(f, 'input', () => {
                if (window.CandidateNotify && typeof window.CandidateNotify.clearFieldError === 'function') {
                    window.CandidateNotify.clearFieldError(f);
                } else {
                    f.classList.remove('is-invalid');
                }
            });
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
