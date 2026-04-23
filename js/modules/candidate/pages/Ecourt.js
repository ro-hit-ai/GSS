class Ecourt {
    static _initialized = false;
    static _eventListeners = [];
    static _savedData = null;
    static _isSubmitting = false;

    /* ===================== NOTIFICATION ===================== */

    static showNotification(message, isError = false) {
        const text = String(message || '').trim();
        if (!text) return;

        if (window.CandidateNotify && typeof window.CandidateNotify.show === 'function') {
            window.CandidateNotify.show({
                type: isError ? 'error' : 'success',
                title: isError ? 'E-court details not saved' : 'E-court details saved',
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

    /* ===================== LIFECYCLE ===================== */

    static init() {
        return this;
    }

    static onPageLoad() {
        this.cleanupEventListeners();

        if (!window.ECOURT_DATA) {
            const dataEl = document.getElementById('ecourtData');
            if (dataEl && dataEl.dataset && dataEl.dataset.ecourt) {
                try {
                    window.ECOURT_DATA = JSON.parse(dataEl.dataset.ecourt || '{}');
                } catch (e) {
                    window.ECOURT_DATA = null;
                }
            }
        }

        this._savedData = window.ECOURT_DATA || null;
        this._initialized = true;

        this.populateForm();
        this.initFormHandling();
        this.initActionButtons(); // REQUIRED FOR ROUTER
        this.initFileHandling();
        this.initAutoCalculateDuration();

        const dobInput = document.querySelector('[name="dob"]');
        const dataEl = document.getElementById('ecourtData');
        const dobMax = dataEl && dataEl.dataset ? dataEl.dataset.dobMax : '';
        if (dobInput && dobMax) {
            try {
                dobInput.max = dobInput.max || String(dobMax);
            } catch (e) {
            }
        }

        console.log("✅ Ecourt page initialized");
    }

    static cleanup() {
        this.cleanupEventListeners();
        this._initialized = false;
        this._savedData = null;
        this._isSubmitting = false;
    }

    /* ===================== REQUIRED NO-OP ===================== */

    static initActionButtons() {
        // Required for Router compatibility
        // (matches Contact / Education lifecycle)
    }

    /* ===================== EVENT HANDLING ===================== */

    static cleanupEventListeners() {
        this._eventListeners.forEach(({ element, type, handler }) => {
            element?.removeEventListener(type, handler);
        });
        this._eventListeners = [];
    }

    static addEventListener(element, type, handler) {
        if (!element) return;
        element.addEventListener(type, handler);
        this._eventListeners.push({ element, type, handler });
    }

    /* ===================== FORM POPULATION ===================== */

    static populateForm() {
        const d = this._savedData;
        if (!d) return;

        this.setFormValue('current_address', d.current_address);
        this.setFormValue('permanent_address', d.permanent_address);
        this.setFormValue('period_from_date', d.period_from_date);
        this.setFormValue('period_to_date', d.period_to_date);
        this.setFormValue('period_duration_years', d.period_duration_years);
        this.setFormValue('dob', d.dob);
        this.setFormValue('comments', d.comments);
        this.setFormValue('on_hold', d.on_hold);
        this.setFormValue('not_applicable', d.not_applicable);

        if (d.evidence_document) {
            const fileInput = document.querySelector('[name="evidence_document"]');
            const box = fileInput ? fileInput.closest('.form-control')?.querySelector('[data-file-upload]') : null;
            const base = window.APP_BASE_URL || '';
            const url = `${base}/uploads/ecourt/${d.evidence_document}`;
            if (box) {
                // Use TabManager helper if available
                if (window.TabManager && typeof window.TabManager.prototype.setUploadBox === 'function') {
                    window.TabManager.prototype.setUploadBox.call(window.TabManager.prototype, box, d.evidence_document, url, false);
                } else {
                    const nameEl = box.querySelector('[data-file-name]');
                    if (nameEl) {
                        nameEl.textContent = d.evidence_document;
                        nameEl.classList.add('preview-btn');
                        nameEl.removeAttribute('disabled');
                        nameEl.setAttribute('data-url', url);
                        nameEl.setAttribute('data-name', d.evidence_document);
                        nameEl.setAttribute('data-type', d.evidence_document.toLowerCase().endsWith('.pdf') ? 'pdf' : 'image');
                    }
                }
            }
        }

        if (d.period_from_date && d.period_to_date && !d.period_duration_years) {
            this.calculateDuration();
        }
    }

    static setFormValue(name, value) {
        const el = document.querySelector(`[name="${name}"]`);
        if (!el) return;

        if (el.type === 'checkbox') {
            el.checked = value === true || value === 1;
        } else if (el.type === 'date') {
            el.value = value ? value.split(' ')[0] : '';
        } else {
            el.value = value ?? '';
        }

        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /* ===================== FORM HANDLING ===================== */

    static initFormHandling() {
        const form = document.getElementById('ecourtForm');
        if (!form) return;

        this.addEventListener(form, 'submit', e => {
            e.preventDefault();
            e.stopPropagation();
        });

        this.addEventListener(
            form.querySelector('.save-draft-btn[data-page="ecourt"]'),
            'click',
            e => {
                e.preventDefault();
                this.saveDraft();
            }
        );

        this.addEventListener(
            document.querySelector('.external-submit-btn[data-form="ecourtForm"]'),
            'click',
            e => {
                e.preventDefault();
                this.submitForm();
            }
        );

        this.addEventListener(
            document.querySelector('.prev-btn[data-form="ecourtForm"]'),
            'click',
            e => {
                e.preventDefault();
                window.Router?.navigateTo
                    ? Router.navigateTo('social')
                    : (location.href = '?page=social');
            }
        );

        const notApplicable = form.querySelector('[name="not_applicable"]');
        if (notApplicable) {
            this.addEventListener(notApplicable, 'change', e => {
                const file = form.querySelector('[name="evidence_document"]');
                file.disabled = e.target.checked;
                file.required = !e.target.checked;
                if (e.target.checked) {
                    file.value = '';
                    const box = file.closest('.form-control')?.querySelector('[data-file-upload]');
                    if (window.TabManager && typeof window.TabManager.prototype.clearUploadBox === 'function') {
                        window.TabManager.prototype.clearUploadBox.call(window.TabManager.prototype, box);
                    }
                    if (window.CandidateNotify && typeof window.CandidateNotify.clearFieldError === 'function') {
                        window.CandidateNotify.clearFieldError(box || file);
                    }
                }
            });
            notApplicable.dispatchEvent(new Event('change'));
        }
    }

    /* ===================== FILE HANDLING ===================== */

    static initFileHandling() {
        const fileInput = document.querySelector('[name="evidence_document"]');
        if (!fileInput) return;

        const box = fileInput.closest('.form-control')?.querySelector('[data-file-upload]');

        this.addEventListener(document, 'click', (e) => {
            const trigger = e.target.closest('[data-file-choose]');
            if (!trigger) return;
            if (!trigger.closest('#ecourtForm')) return;
            e.preventDefault();
            fileInput.click();
        });

        this.addEventListener(fileInput, 'change', e => {
            const file = e.target.files[0];
            if (!file) return;

            const allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            const name = String(file.name || '').toLowerCase();
            const ext = name.includes('.') ? name.split('.').pop() : '';
            if (!allowed.includes(ext)) {
                this.showNotification('Only PDF, JPG, JPEG, PNG allowed', true);
                e.target.value = '';
                if (box) {
                    const errEl = box.querySelector('[data-file-error]');
                    if (errEl) errEl.textContent = 'Invalid file type. Only PDF, JPG, JPEG, PNG allowed.';
                }
                if (window.CandidateNotify && typeof window.CandidateNotify.setFieldError === 'function') {
                    window.CandidateNotify.setFieldError(box || fileInput, 'Invalid file type. Only PDF, JPG, JPEG, PNG allowed.');
                }
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                this.showNotification('File must be under 5MB', true);
                e.target.value = '';
                if (box) {
                    const errEl = box.querySelector('[data-file-error]');
                    if (errEl) errEl.textContent = 'File too large. Maximum 5MB allowed.';
                }
                if (window.CandidateNotify && typeof window.CandidateNotify.setFieldError === 'function') {
                    window.CandidateNotify.setFieldError(box || fileInput, 'File too large. Maximum 5MB allowed.');
                }
                return;
            }

            if (box) {
                if (window.TabManager && typeof window.TabManager.prototype.setUploadBox === 'function') {
                    const url = URL.createObjectURL(file);
                    window.TabManager.prototype.setUploadBox.call(window.TabManager.prototype, box, file.name, url, true, file.size);
                } else {
                    const nameEl = box.querySelector('[data-file-name]');
                    if (nameEl) {
                        nameEl.textContent = file.name;
                        nameEl.classList.add('preview-btn');
                        nameEl.removeAttribute('disabled');
                        const url = URL.createObjectURL(file);
                        nameEl.setAttribute('data-url', url);
                        nameEl.setAttribute('data-name', file.name);
                        nameEl.setAttribute('data-type', file.name.toLowerCase().endsWith('.pdf') ? 'pdf' : 'image');
                    }
                }
            }

            if (window.CandidateNotify && typeof window.CandidateNotify.clearFieldError === 'function') {
                window.CandidateNotify.clearFieldError(box || fileInput);
            }
        });
    }

    /* ===================== GLOBAL PREVIEW INTEGRATION ===================== */

    static showFilePreview(filename, isExisting) {
        const el = document.getElementById('evidenceDocumentPreview');
        if (!el) return;

        const fileUrl = `/uploads/ecourt/${filename}`;
        const type = filename.toLowerCase().endsWith('.pdf') ? 'pdf' : 'image';

        el.innerHTML = `
            <div class="file-preview-pill">
                <i class="fas fa-file me-2"></i>
                ${isExisting ? 'Current file:' : 'Selected file:'}
                <a href="#"
                   class="preview-btn ms-2 text-decoration-none fw-medium"
                   data-url="${fileUrl}"
                   data-name="${filename}"
                   data-type="${type}">
                    ${filename}
                </a>
            </div>
        `;
    }

    /* ===================== DURATION ===================== */

    static initAutoCalculateDuration() {
        const f = document.querySelector('[name="period_from_date"]');
        const t = document.querySelector('[name="period_to_date"]');
        const d = document.querySelector('[name="period_duration_years"]');
        if (!f || !t || !d) return;

        const calc = () => {
            if (f.value && t.value && new Date(f.value) <= new Date(t.value)) {
                d.value = (
                    (new Date(t.value) - new Date(f.value)) /
                    (1000 * 60 * 60 * 24 * 365.25)
                ).toFixed(1);
            }
        };

        this.addEventListener(f, 'change', calc);
        this.addEventListener(t, 'change', calc);
    }

    static calculateDuration() {
        this.initAutoCalculateDuration();
    }

    /* ===================== VALIDATION ===================== */

    static validateForm() {
        const form = document.getElementById('ecourtForm');
        if (!form) return false;

        if (window.CandidateNotify && typeof window.CandidateNotify.clearValidation === 'function') {
            window.CandidateNotify.clearValidation(form);
        }

        const errors = [];
        const addError = (field, message) => {
            if (window.CandidateNotify && typeof window.CandidateNotify.addFieldError === 'function') {
                window.CandidateNotify.addFieldError(errors, field, message);
            } else {
                if (field && field.classList) field.classList.add('is-invalid');
                errors.push({ field, message });
            }
        };

        const requiredFields = [
            { name: 'current_address', label: 'Current Address' },
            { name: 'permanent_address', label: 'Permanent Address' },
            { name: 'period_from_date', label: 'From Date' },
            { name: 'period_to_date', label: 'To Date' },
            { name: 'period_duration_years', label: 'Duration (Years)' },
            { name: 'dob', label: 'Date of Birth' }
        ];

        requiredFields.forEach(({ name, label }) => {
            const el = form.querySelector(`[name="${name}"]`);
            const value = el && !el.disabled ? String(el.value || '').trim() : '';
            if (el && !el.disabled && !value) {
                addError(el, `${label} is required`);
            }
        });

        const fromEl = form.querySelector('[name="period_from_date"]');
        const toEl = form.querySelector('[name="period_to_date"]');
        if (fromEl && toEl && fromEl.value && toEl.value) {
            const fromDate = new Date(fromEl.value + 'T00:00:00');
            const toDate = new Date(toEl.value + 'T00:00:00');
            if (!Number.isNaN(fromDate.getTime()) && !Number.isNaN(toDate.getTime()) && fromDate > toDate) {
                addError(toEl, 'To Date must be the same as or later than From Date');
            }
        }

        // DOB validation (candidate must be 18 or older)
        const dobEl = form.querySelector('[name="dob"]');
        if (dobEl && dobEl.value) {
            const dob = new Date(dobEl.value + 'T00:00:00');
            if (Number.isNaN(dob.getTime())) {
                addError(dobEl, 'Please enter a valid date of birth');
            }
            const cutoff = new Date();
            cutoff.setHours(0, 0, 0, 0);
            cutoff.setFullYear(cutoff.getFullYear() - 18);
            if (dob > cutoff) {
                addError(dobEl, 'Candidate must be at least 18 years old');
            }
        }

        const notApplicable = form.querySelector('[name="not_applicable"]');
        const fileInput = form.querySelector('[name="evidence_document"]');
        const existingFile = this._savedData && this._savedData.evidence_document
            ? String(this._savedData.evidence_document).trim()
            : '';
        const hasNewFile = !!(fileInput && fileInput.files && fileInput.files.length > 0);
        const requiresFile = !(notApplicable && notApplicable.checked);
        if (requiresFile && !hasNewFile && !existingFile) {
            const box = fileInput ? fileInput.closest('.form-control')?.querySelector('[data-file-upload]') : null;
            addError(box || fileInput || form, 'Evidence document is required unless Not Applicable is selected');
        }

        if (errors.length > 0) {
            if (window.CandidateNotify && typeof window.CandidateNotify.validation === 'function') {
                window.CandidateNotify.validation({
                    form,
                    title: 'E-court details need attention',
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

    /* ===================== API ===================== */

    static getApiEndpoint() {
        return `${window.APP_BASE_URL || ''}/api/candidate/store_ecourt.php`;
    }

    static async saveDraft() {
        if (this._isSubmitting) return;

        const form = document.getElementById('ecourtForm');
        if (!form) return;

        const fd = new FormData(form);
        fd.append('draft', '1');

        this._isSubmitting = true;

        try {
            const res = await fetch(this.getApiEndpoint(), {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await res.json();

            data.success
                ? this.showNotification('Draft saved')
                : this.showNotification(data.message || 'Save failed', true);
        } catch (e) {
            this.showNotification(e.message, true);
        } finally {
            this._isSubmitting = false;
        }
    }

    static async submitForm() {
        if (this._isSubmitting || !this.validateForm()) return;

        const form = document.getElementById('ecourtForm');
        const fd = new FormData(form);
        fd.append('draft', '0');

        this._isSubmitting = true;

        try {
            const res = await fetch(this.getApiEndpoint(), {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await res.json();

            if (!data.success) throw new Error(data.message);

            this.showNotification('E-court details submitted successfully');

            if (window.Router?.markCompleted) {
                Router.markCompleted('ecourt');
            } else {
                localStorage.setItem('completed-ecourt', '1');
            }

            Router?.navigateTo?.('education');
        } catch (e) {
            this.showNotification(e.message, true);
        } finally {
            this._isSubmitting = false;
        }
    }
}

window.Ecourt = Ecourt;
