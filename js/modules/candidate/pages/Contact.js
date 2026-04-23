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
    }

    static on(el, type, fn) {
        if (!el) return;
        el.addEventListener(type, fn);
        this._listeners.push({ el, type, fn });
    }

    static get form() {
        return document.getElementById("contactForm");
    }

    static showNotification(message, isError = false, opts = {}) {
        const text = String(message || '').trim();
        if (!text) return;

        if (window.CandidateNotify && typeof window.CandidateNotify.show === 'function') {
            window.CandidateNotify.show({
                type: isError ? 'error' : 'success',
                title: isError ? 'Address details not saved' : 'Address details saved',
                message: text.replace(/^[^\w]+/, ''),
                ...(opts || {})
            });
            return;
        }

        // Fallback
        if (typeof window.showAlert === 'function') {
            window.showAlert({ type: isError ? 'error' : 'success', message: text });
            return;
        }

        console[isError ? 'error' : 'log'](text);
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

        // Default: validate both sections (permanent can still be "same as current").
        return out.length ? out : ['current_address', 'permanent_address'];
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

                if (file.size > 5 * 1024 * 1024) {
                    e.target.value = '';
                    if (errEl) errEl.textContent = 'File too large. Maximum 5MB allowed.';
                    this.showNotification('File must be under 5MB', true);
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

        if (window.CandidateNotify && typeof window.CandidateNotify.clearValidation === 'function') {
            window.CandidateNotify.clearValidation(form);
        } else {
            form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        }
        if (!final) return true;

        const errors = [];
        const isSame = this.getSameAsCurrentValue();
        const active = (this._activeAddressSections && this._activeAddressSections.length)
            ? this._activeAddressSections.slice()
            : ['current_address', 'permanent_address'];
        const hasCurrent = active.includes('current_address');
        const hasPermanent = active.includes('permanent_address');

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
            const value = field.value ? String(field.value).trim() : '';
            if (value) return;

            const label = (field.labels && field.labels[0] && field.labels[0].innerText)
                ? field.labels[0].innerText
                : name.replace(/_/g, ' ');

            if (window.CandidateNotify && typeof window.CandidateNotify.addFieldError === 'function') {
                window.CandidateNotify.addFieldError(errors, field, `Please enter ${label.replace(/\*/g, '').trim()}`);
            } else {
                field.classList.add('is-invalid');
                errors.push({ field, message: `Please enter ${label.replace(/\*/g, '').trim()}` });
            }
        });

        // Postal code rules
        const validatePostal = (countryName, postalField, labelPrefix) => {
            if (!postalField || postalField.disabled) return;
            const country = String(countryName || '').trim().toLowerCase();
            const code = String(postalField.value || '').trim();
            if (!code) return;

            if (country === 'india') {
                if (!/^\d{6}$/.test(code)) {
                    if (window.CandidateNotify && typeof window.CandidateNotify.addFieldError === 'function') {
                        window.CandidateNotify.addFieldError(errors, postalField, `${labelPrefix} pin code must be 6 digits`);
                    } else {
                        postalField.classList.add('is-invalid');
                        errors.push({ field: postalField, message: `${labelPrefix} pin code must be 6 digits` });
                    }
                }
                return;
            }

            if (code.length < 3) {
                if (window.CandidateNotify && typeof window.CandidateNotify.addFieldError === 'function') {
                    window.CandidateNotify.addFieldError(errors, postalField, `${labelPrefix} postal code is too short`);
                } else {
                    postalField.classList.add('is-invalid');
                    errors.push({ field: postalField, message: `${labelPrefix} postal code is too short` });
                }
            }
        };

        if (hasCurrent) {
            const cCountry = form.querySelector('[name="current_country"]');
            const cPostal = form.querySelector('[name="current_postal_code"]');
            validatePostal(cCountry ? cCountry.value : '', cPostal, 'Current');
        }

        if (hasPermanent && (!hasCurrent || !isSame)) {
            const pCountry = form.querySelector('[name="permanent_country"]');
            const pPostal = form.querySelector('[name="permanent_postal_code"]');
            validatePostal(pCountry ? pCountry.value : '', pPostal, 'Permanent');
        }

        // Current address proof required (if field exists)
        if (hasCurrent) {
            const proofInput = form.querySelector('input[type="file"][name="current_address_proof"]');
            const hasNew = !!(proofInput && proofInput.files && proofInput.files.length > 0);
            const hasExisting = !!form.querySelector('input[name="existing_current_address_proof"]');
            if (!hasNew && !hasExisting && proofInput && !proofInput.disabled) {
                if (window.CandidateNotify && typeof window.CandidateNotify.addFieldError === 'function') {
                    const box = proofInput.closest('.form-control') || proofInput;
                    window.CandidateNotify.addFieldError(errors, box, 'Please upload current address proof');
                } else {
                    proofInput.classList.add('is-invalid');
                    errors.push({ field: proofInput, message: 'Please upload current address proof' });
                }
            }
        }

        if (errors.length) {
            if (window.CandidateNotify && typeof window.CandidateNotify.validation === 'function') {
                window.CandidateNotify.validation({
                    form,
                    title: 'Please correct the highlighted fields',
                    errors: errors
                });
            } else {
                this.showNotification(errors[0].message || 'Please fix highlighted fields', true);
                try { (errors[0].field || form.querySelector('.is-invalid'))?.focus(); } catch (_e) {}
            }
            return false;
        }

        return true;
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

            // Backward compatibility: API expects `address_proof_file`
            if (!formData.has('address_proof_file') && formData.has('current_address_proof')) {
                const f = formData.get('current_address_proof');
                if (f instanceof File) {
                    formData.append('address_proof_file', f);
                }
            }

            const res = await fetch(endpoint, {
                method: "POST",
                body: formData,
                credentials: "include"
            });

            const data = await res.json();
            if (!data.success) {
                throw new Error(data.message || "Save failed");
            }

            this.showNotification(data.message || "Contact details saved successfully");
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
                if (window.CandidateNotify && typeof window.CandidateNotify.clearFieldError === 'function') {
                    window.CandidateNotify.clearFieldError(field);
                } else {
                    field.classList.remove('is-invalid');
                }
            });
        });
    }
}

window.Contact = Contact;
