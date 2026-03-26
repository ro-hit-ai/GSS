class Contact {
    static _initialized = false;
    static _listeners = [];

    /* ===================== LIFECYCLE ===================== */

    static onPageLoad() {
        if (this._initialized) this.cleanup();
        this._initialized = true;

        this.initForm();
        this.initSameAddressToggle();
        this.setupFieldValidation();

        console.log("✅ Contact page initialized");
    }

    static cleanup() {
        this._listeners.forEach(({ el, type, fn }) =>
            el.removeEventListener(type, fn)
        );
        this._listeners = [];
        this._initialized = false;

        // Remove any existing notifications
        const existing = document.querySelector('.contact-notification');
        if (existing) existing.remove();
    }

    static on(el, type, fn) {
        if (!el) return;
        el.addEventListener(type, fn);
        this._listeners.push({ el, type, fn });
    }

    static get form() {
        return document.getElementById("contactForm");
    }

    /* ===================== NOTIFICATION (EDUCATION STYLE) ===================== */

    static showNotification(message, isError = false) {
        // Remove existing notification
        const existing = document.querySelector('.contact-notification');
        if (existing) existing.remove();

        const notification = document.createElement('div');
        notification.className = `
            contact-notification
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
            console.warn("❌ Contact form not found");
            return;
        }

        // Prevent native submit
        this.on(form, "submit", (e) => {
            e.preventDefault();
            e.stopPropagation();
        });

        // Save Draft
        const saveBtn = form.querySelector('.save-draft-btn[data-page="contact"]');
        if (saveBtn) {
            this.on(saveBtn, "click", async (e) => {
                e.preventDefault();
                await this.saveDraft();
            });
        }

        // Next / Submit
        const nextBtn = form.querySelector('.external-submit-btn[data-form="contactForm"]');
        if (nextBtn) {
            this.on(nextBtn, "click", async (e) => {
                e.preventDefault();
                await this.submitFinal();
            });
        }

        // Previous
        const prevBtn = form.querySelector(".prev-btn");
        if (prevBtn) {
            this.on(prevBtn, "click", (e) => {
                e.preventDefault();
                if (window.Router?.navigateTo) {
                    Router.navigateTo("identification");
                } else {
                    window.location.href = "?page=identification";
                }
            });
        }

        console.log("✅ Contact form handlers bound");
    }

    /* ===================== ADDRESS SYNC ===================== */

    static initSameAddressToggle() {
        const checkbox = document.getElementById("same_as_current");
        if (!checkbox || !this.form) return;

        const map = [
            ["current_address1", "permanent_address1"],
            ["current_address2", "permanent_address2"],
            ["current_city", "permanent_city"],
            ["current_state", "permanent_state"],
            ["current_country", "permanent_country"],
            ["current_postal_code", "permanent_postal_code"]
        ];

        const sync = () => {
            if (!checkbox.checked) return;
            map.forEach(([src, dst]) => {
                const s = this.form.querySelector(`[name="${src}"]`);
                const d = this.form.querySelector(`[name="${dst}"]`);
                if (s && d) d.value = s.value;
            });
        };

        const setPermanentDisabled = (disabled) => {
            map.forEach(([_src, dst]) => {
                const d = this.form.querySelector(`[name="${dst}"]`);
                if (!d) return;
                d.disabled = !!disabled;
            });
        };

        const apply = () => {
            const on = !!checkbox.checked;
            setPermanentDisabled(on);
            if (on) sync();
        };

        this.on(checkbox, "change", apply);
        map.forEach(([src]) => {
            const s = this.form.querySelector(`[name="${src}"]`);
            if (s) this.on(s, "input", sync);
        });

        apply();
    }

    /* ===================== VALIDATION ===================== */

    static validate(final = true) {
        const form = this.form;
        if (!form) return false;

        form.querySelectorAll('.is-invalid').forEach(el =>
            el.classList.remove('is-invalid')
        );

        if (!final) return true;

        let valid = true;
        const errors = [];

        form.querySelectorAll('[required]').forEach(field => {
            if (!field.value.trim()) {
                field.classList.add('is-invalid');
                valid = false;
                errors.push(
                    field.labels?.[0]?.innerText ||
                    field.placeholder ||
                    field.name.replace(/_/g, ' ')
                );
            }
        });

        const email = form.querySelector('[name="email"]');
        if (email && email.value && !this.isValidEmail(email.value)) {
            email.classList.add('is-invalid');
            valid = false;
            errors.push("Valid Email");
        }

        const phone = form.querySelector('[name="phone"]');
        if (phone && phone.value && !this.isValidPhone(phone.value)) {
            phone.classList.add('is-invalid');
            valid = false;
            errors.push("Valid Phone Number");
        }

        if (!valid) {
            this.showNotification(
                `Please fix: ${errors.slice(0, 3).join(', ')}${errors.length > 3 ? '...' : ''}`,
                true
            );
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
        if (ok) this.showNotification("✅ Contact draft saved");
    }

    static async submitFinal() {
        if (!this.validate(true)) return;

        const fd = new FormData(this.form);
        fd.append("save_draft", "0");

        const ok = await this.send(fd);
        if (!ok) return;

        if (window.Router?.markCompleted) {
            Router.markCompleted("contact");
        } else {
            localStorage.setItem("completed-contact", "1");
        }

        window.Router?.navigateTo
            ? Router.navigateTo("social")
            : (window.location.href = "?page=social");
    }

    /* ===================== API ===================== */

    static async send(formData) {
        try {
            const endpoint = `${window.APP_BASE_URL || ''}/api/candidate/store_contact.php`;

            const res = await fetch(endpoint, {
                method: "POST",
                body: formData,
                credentials: "include"
            });

            const data = await res.json();

            if (!data.success) {
                throw new Error(data.message || "Save failed");
            }

            this.showNotification("✅ Contact details saved successfully");
            return true;

        } catch (err) {
            console.error("❌ Contact error:", err);
            this.showNotification(err.message || "Server error", true);
            return false;
        }
    }

    /* ===================== FIELD VALIDATION ===================== */

    static setupFieldValidation() {
        const form = this.form;
        if (!form) return;

        const email = form.querySelector('[name="email"]');
        if (email) {
            this.on(email, 'blur', () => {
                if (email.value && !this.isValidEmail(email.value)) {
                    email.classList.add('is-invalid');
                }
            });
        }

        const phone = form.querySelector('[name="phone"]');
        if (phone) {
            this.on(phone, 'blur', () => {
                if (phone.value && !this.isValidPhone(phone.value)) {
                    phone.classList.add('is-invalid');
                }
            });
        }

        form.querySelectorAll('[required]').forEach(f => {
            this.on(f, 'input', () => f.classList.remove('is-invalid'));
        });
    }

    static isValidEmail(v) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    }

    static isValidPhone(v) {
        return v.replace(/\D/g, '').length >= 10;
    }
}

window.Contact = Contact;
