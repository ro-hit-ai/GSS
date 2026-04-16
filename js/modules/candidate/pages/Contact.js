class Contact {
    static _initialized = false;
    static _listeners = [];
    static _activeAddressSections = [];

    static onPageLoad() {
        if (this._initialized) this.cleanup();
        this._initialized = true;

        this.initForm();
        this.initAddressTabs();
        this.initFileHandlers();
        this.setupFieldValidation();

        console.log("✅ Contact page initialized");
    }

    static cleanup() {
        this._listeners.forEach(({ el, type, fn }) => {
            if (el) el.removeEventListener(type, fn);
        });
        this._listeners = [];
        this._initialized = false;
        this._activeAddressSections = [];

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

    static showNotification(message, isError = false) {
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

    static initForm() {
        const form = this.form;
        if (!form) {
            console.warn(" Contact form not found");
            return;
        }

        this.on(form, "submit", (e) => {
            e.preventDefault();
            e.stopPropagation();
        });

        const saveBtn = form.querySelector('.save-draft-btn[data-page="contact"]');
        if (saveBtn) {
            this.on(saveBtn, "click", async (e) => {
                e.preventDefault();
                await this.saveDraft();
            });
        }

        const nextBtn = form.querySelector('.external-submit-btn[data-form="contactForm"]');
        if (nextBtn) {
            this.on(nextBtn, "click", async (e) => {
                e.preventDefault();
                await this.submitFinal();
            });
        }

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
    }

    static getSameAsCurrentValue() {
        const checked = this.form?.querySelector('input[name="same_as_current"]:checked');
        return checked ? String(checked.value || '1') === '1' : true;
    }

    static getConfig() {
        return window.CANDIDATE_CASE_CONFIG || {};
    }

    static getRequiredCounts() {
        const config = this.getConfig();
        return config && config.required_counts ? config.required_counts : {};
    }

    static getActiveAddressSections() {
        const config = this.getConfig();
        const counts = this.getRequiredCounts();
        const sectionMap = config && config.contact_sections ? config.contact_sections : {};
        const out = [];

        ['current_address', 'permanent_address'].forEach((key) => {
            const hasCount = parseInt(counts[key] || '0', 10) > 0;
            const isEnabled = !!sectionMap[key];
            if (hasCount || isEnabled) {
                out.push(key);
            }
        });

        if (!out.length) {
            const hasGenericContact = parseInt(counts.contact || '0', 10) > 0
                || !!(config && Array.isArray(config.components) && config.components.some((item) => String(item && item.candidate_page ? item.candidate_page : '') === 'contact'));
            if (hasGenericContact) {
                out.push('current_address', 'permanent_address');
            }
        }

        return out;
    }

    static activateTab(tabId) {
        const form = this.form;
        if (!form) return;

        form.querySelectorAll('.address-tab-btn').forEach((btn) => {
            btn.classList.toggle('active', btn.getAttribute('data-tab-target') === tabId);
        });

        form.querySelectorAll('.tab-pane').forEach((pane) => {
            const isActive = pane.id === tabId;
            pane.classList.toggle('active', isActive);
            pane.style.display = isActive ? '' : 'none';
        });
    }

    static clearPermanentInputs() {
        const form = this.form;
        if (!form) return;

        [
            'permanent_address1',
            'permanent_address2',
            'permanent_city',
            'permanent_state',
            'permanent_country',
            'permanent_postal_code'
        ].forEach((name) => {
            const input = form.querySelector(`[name="${name}"]`);
            if (!input) return;
            if (input.tagName === 'SELECT') {
                input.selectedIndex = 0;
            } else {
                input.value = '';
            }
        });

        const proof = form.querySelector('[name="permanent_address_proof"]');
        if (proof) {
            proof.value = '';
        }
    }

    static togglePermanentFields(showPermanent) {
        const form = this.form;
        if (!form) return;

        [
            'permanent_address1',
            'permanent_address2',
            'permanent_city',
            'permanent_state',
            'permanent_country',
            'permanent_postal_code',
            'permanent_address_proof'
        ].forEach((name) => {
            const input = form.querySelector(`[name="${name}"]`);
            if (!input) return;
            input.disabled = !showPermanent;
        });
    }

    static toggleCurrentFields(showCurrent) {
        const form = this.form;
        if (!form) return;

        [
            'current_address1',
            'current_address2',
            'current_city',
            'current_state',
            'current_country',
            'current_postal_code',
            'current_address_proof'
        ].forEach((name) => {
            const input = form.querySelector(`[name="${name}"]`);
            if (!input) return;
            input.disabled = !showCurrent;
        });
    }

    static syncCurrentToPermanent() {
        const form = this.form;
        if (!form || !this.getSameAsCurrentValue()) return;

        const map = [
            ["current_address1", "permanent_address1"],
            ["current_address2", "permanent_address2"],
            ["current_city", "permanent_city"],
            ["current_state", "permanent_state"],
            ["current_country", "permanent_country"],
            ["current_postal_code", "permanent_postal_code"]
        ];

        map.forEach(([src, dst]) => {
            const source = form.querySelector(`[name="${src}"]`);
            const target = form.querySelector(`[name="${dst}"]`);
            if (source && target) {
                target.value = source.value;
            }
        });
    }

    static setUploadBox(box, fileName, previewUrl, isLocalFile = false) {
        if (!box) return;

        const nameEl = box.querySelector('[data-file-name]');
        const errEl = box.querySelector('[data-file-error]');
        if (errEl) errEl.textContent = '';

        if (nameEl) {
            nameEl.textContent = fileName || 'No file chosen';
            nameEl.disabled = !fileName;

            if (fileName && previewUrl) {
                nameEl.classList.add('preview-btn');
                nameEl.setAttribute('data-url', previewUrl);
                nameEl.setAttribute('data-name', fileName);
                nameEl.setAttribute('data-type', /\.pdf$/i.test(fileName) ? 'pdf' : 'image');
                if (isLocalFile) {
                    nameEl.setAttribute('data-local-file', '1');
                } else {
                    nameEl.removeAttribute('data-local-file');
                }
            } else {
                nameEl.classList.remove('preview-btn');
                nameEl.removeAttribute('data-url');
                nameEl.removeAttribute('data-name');
                nameEl.removeAttribute('data-type');
                nameEl.removeAttribute('data-local-file');
            }
        }
    }

    static clearUploadBox(box) {
        if (!box) return;
        const nameEl = box.querySelector('[data-file-name]');
        const errEl = box.querySelector('[data-file-error]');
        if (nameEl) {
            nameEl.textContent = 'No file chosen';
            nameEl.disabled = true;
            nameEl.classList.remove('preview-btn');
            nameEl.removeAttribute('data-url');
            nameEl.removeAttribute('data-name');
            nameEl.removeAttribute('data-type');
            nameEl.removeAttribute('data-local-file');
        }
        if (errEl) errEl.textContent = '';
    }

    static initFileHandlers() {
        const form = this.form;
        if (!form) return;

        form.querySelectorAll('[data-file-upload]').forEach((box) => {
            const control = box.closest('.form-control');
            const input = control ? control.querySelector('input[type="file"][data-file-input]') : null;
            if (!input) return;

            const chooseBtn = box.querySelector('[data-file-choose]');
            if (chooseBtn) {
                this.on(chooseBtn, 'click', (e) => {
                    e.preventDefault();
                    input.click();
                });
            }

            const nameEl = box.querySelector('[data-file-name]');
            if (nameEl) {
                this.on(nameEl, 'click', (e) => {
                    if (!nameEl.classList.contains('preview-btn')) return;
                    e.preventDefault();
                });
            }

            this.on(input, 'change', (e) => {
                const file = e.target.files && e.target.files[0] ? e.target.files[0] : null;
                if (!file) {
                    this.clearUploadBox(box);
                    return;
                }

                const allowed = ['pdf', 'jpg', 'jpeg', 'png'];
                const name = String(file.name || '').toLowerCase();
                const ext = name.includes('.') ? name.split('.').pop() : '';
                const errEl = box.querySelector('[data-file-error]');

                if (!allowed.includes(ext)) {
                    e.target.value = '';
                    if (errEl) errEl.textContent = 'Only PDF, JPG, JPEG, PNG allowed.';
                    this.showNotification('Only PDF, JPG, JPEG, PNG allowed', true);
                    this.clearUploadBox(box);
                    return;
                }

                if (file.size > 10 * 1024 * 1024) {
                    e.target.value = '';
                    if (errEl) errEl.textContent = 'File too large. Maximum 10MB allowed.';
                    this.showNotification('File must be under 10MB', true);
                    this.clearUploadBox(box);
                    return;
                }

                const url = URL.createObjectURL(file);
                this.setUploadBox(box, file.name, url, true);
            });
        });
    }

    static applyAddressMode() {
        const form = this.form;
        if (!form) return;

        const isSame = this.getSameAsCurrentValue();
        const activeSections = this._activeAddressSections.length ? this._activeAddressSections.slice() : ['current_address', 'permanent_address'];
        const hasCurrent = activeSections.includes('current_address');
        const hasPermanent = activeSections.includes('permanent_address');
        const currentTabBtn = document.getElementById('currentAddressTabBtn');
        const permanentTabBtn = document.getElementById('permanentAddressTabBtn');
        const tabsWrap = document.getElementById('addressTabsWrap');
        const sameAsWrap = document.getElementById('sameAsCurrentWrap');
        const currentPane = document.getElementById('current_address_tab');
        const permanentPane = document.getElementById('permanent_address_tab');
        const hiddenCurrent = form.querySelector('[name="has_current_address"]');
        const hiddenPermanent = form.querySelector('[name="has_permanent_address"]');

        if (hiddenCurrent) hiddenCurrent.value = hasCurrent ? '1' : '0';
        if (hiddenPermanent) hiddenPermanent.value = hasPermanent ? '1' : '0';

        if (currentTabBtn) currentTabBtn.style.display = hasCurrent ? '' : 'none';
        if (permanentTabBtn) permanentTabBtn.style.display = (hasPermanent && !isSame && hasCurrent) || (!hasCurrent && hasPermanent) ? '' : 'none';
        if (sameAsWrap) sameAsWrap.style.display = (hasCurrent && hasPermanent) ? '' : 'none';
        if (tabsWrap) tabsWrap.style.display = (hasCurrent && hasPermanent) ? '' : 'none';
        if (currentPane) currentPane.style.display = hasCurrent ? '' : 'none';
        if (permanentPane) permanentPane.style.display = hasPermanent ? '' : 'none';

        this.toggleCurrentFields(hasCurrent);
        this.togglePermanentFields(hasPermanent && (!isSame || !hasCurrent));

        if (hasCurrent && hasPermanent && isSame) {
            this.clearPermanentInputs();
            this.activateTab('current_address_tab');
        } else if (hasCurrent && hasPermanent) {
            this.activateTab('current_address_tab');
        } else if (hasCurrent) {
            this.activateTab('current_address_tab');
        } else if (hasPermanent) {
            this.activateTab('permanent_address_tab');
        } else {
            this.activateTab('current_address_tab');
        }
    }

    static initAddressTabs() {
        const form = this.form;
        if (!form) return;

        this._activeAddressSections = this.getActiveAddressSections();

        form.querySelectorAll('input[name="same_as_current"]').forEach((radio) => {
            this.on(radio, 'change', () => {
                this.applyAddressMode();
            });
        });

        form.querySelectorAll('.address-tab-btn').forEach((btn) => {
            this.on(btn, 'click', (e) => {
                e.preventDefault();
                const tabId = btn.getAttribute('data-tab-target') || '';
                if (!tabId) return;
                if (tabId === 'permanent_address_tab' && this.getSameAsCurrentValue()) return;
                this.activateTab(tabId);
            });
        });

        [
            'current_address1',
            'current_address2',
            'current_city',
            'current_state',
            'current_country',
            'current_postal_code'
        ].forEach((name) => {
            const input = form.querySelector(`[name="${name}"]`);
            if (!input) return;
            this.on(input, input.tagName === 'SELECT' ? 'change' : 'input', () => {
                this.syncCurrentToPermanent();
            });
        });

        this.applyAddressMode();
    }

    static validate(final = true) {
        const form = this.form;
        if (!form) return false;

        form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        if (!final) return true;

        let valid = true;
        const errors = [];
        const isSame = this.getSameAsCurrentValue();
        const hasCurrent = this._activeAddressSections.includes('current_address');
        const hasPermanent = this._activeAddressSections.includes('permanent_address');

        const requiredFields = [];

        if (hasCurrent) {
            requiredFields.push(
                'current_address1',
                'current_city',
                'current_state',
                'current_country',
                'current_postal_code'
            );
        }

        if (hasPermanent && (!hasCurrent || !isSame)) {
            requiredFields.push(
                'permanent_address1',
                'permanent_city',
                'permanent_state',
                'permanent_country',
                'permanent_postal_code'
            );
        }

        requiredFields.forEach((name) => {
            const field = form.querySelector(`[name="${name}"]`);
            if (!field || field.disabled) return;
            const value = field.value ? field.value.trim() : '';
            if (!value) {
                field.classList.add('is-invalid');
                valid = false;
                errors.push(field.labels?.[0]?.innerText || name.replace(/_/g, ' '));
            }
        });

        if (!valid) {
            this.showNotification(
                `Please fix: ${errors.slice(0, 3).join(', ')}${errors.length > 3 ? '...' : ''}`,
                true
            );
            form.querySelector('.is-invalid')?.focus();
        }

        return valid;
    }

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

    static setupFieldValidation() {
        const form = this.form;
        if (!form) return;

        form.querySelectorAll('input, select').forEach((field) => {
            this.on(field, field.tagName === 'SELECT' ? 'change' : 'input', () => {
                field.classList.remove('is-invalid');
            });
        });
    }
}

window.Contact = Contact;
