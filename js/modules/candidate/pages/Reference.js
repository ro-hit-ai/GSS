class Reference {

    static _initialized = false;
    static _listeners = [];
    static _activeSections = [];
    static _sectionConfig = {
        education_reference: {
            label: 'Education Reference',
            fields: [
                'education_reference_name',
                'education_reference_designation',
                'education_reference_company',
                'education_reference_relationship',
                'education_reference_years_known',
                'education_reference_mobile',
                'education_reference_email'
            ]
        },
        employment_reference: {
            label: 'Employment Reference',
            fields: [
                'employment_reference_name',
                'employment_reference_designation',
                'employment_reference_company',
                'employment_reference_relationship',
                'employment_reference_years_known',
                'employment_reference_mobile',
                'employment_reference_email'
            ]
        }
    };

    static init() {
        return this;
    }

    static onPageLoad() {
        if (this._initialized) return;

        this._initialized = true;
        this.applySectionVisibility();
        this.hydrateFromDB();
        this.initTabs();
        this.initFormHandling();
    }

    static cleanup() {
        this._listeners.forEach(({ el, type, fn }) => el?.removeEventListener(type, fn));
        this._listeners = [];
        this._initialized = false;
        this._activeSections = [];

        const notif = document.querySelector('.reference-notification');
        if (notif) notif.remove();
    }

    static on(el, type, fn) {
        if (!el) return;
        el.addEventListener(type, fn);
        this._listeners.push({ el, type, fn });
    }

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

    static getConfig() {
        return window.CANDIDATE_CASE_CONFIG || {};
    }

    static getRequiredCounts() {
        const config = this.getConfig();
        return config && config.required_counts ? config.required_counts : {};
    }

    static resolveActiveSections() {
        const counts = this.getRequiredCounts();
        const config = this.getConfig();
        const sectionMap = config && config.reference_sections
            ? config.reference_sections
            : ((config && config.sections && config.sections.reference) ? config.sections.reference : {});
        const out = [];

        Object.keys(this._sectionConfig).forEach((sectionKey) => {
            const hasCount = parseInt(counts[sectionKey] || '0', 10) > 0;
            const isEnabled = !!sectionMap[sectionKey];
            if (hasCount || isEnabled) {
                out.push(sectionKey);
            }
        });

        if (!out.length && (parseInt(counts.reference || '0', 10) > 0)) {
            out.push('education_reference', 'employment_reference');
        }

        if (!out.length) {
            const hasGenericReference = !!(config && Array.isArray(config.components) && config.components.some((item) => String(item && item.candidate_page ? item.candidate_page : '') === 'reference'));
            if (hasGenericReference) {
                out.push('education_reference', 'employment_reference');
            }
        }

        return out;
    }

    static activateTab(tabId) {
        const form = document.getElementById('referenceForm');
        if (!form) return;

        form.querySelectorAll('.address-tab-btn').forEach((btn) => {
            btn.classList.toggle('active', btn.getAttribute('data-tab-target') === tabId);
        });

        form.querySelectorAll('.reference-tab-pane').forEach((pane) => {
            const isActive = pane.id === tabId;
            pane.classList.toggle('active', isActive);
            pane.style.display = isActive ? '' : 'none';
        });
    }

    static applySectionVisibility() {
        const activeSections = this.resolveActiveSections();
        this._activeSections = activeSections.slice();

        const form = document.getElementById('referenceForm');
        const noSectionMessage = document.getElementById('referenceNoSectionMessage');
        const hiddenEducation = form?.querySelector('[name="has_education_reference"]');
        const hiddenEmployment = form?.querySelector('[name="has_employment_reference"]');
        const tabsWrap = document.getElementById('referenceTabsWrap');
        const educationTabBtn = document.getElementById('educationReferenceTabBtn');
        const employmentTabBtn = document.getElementById('employmentReferenceTabBtn');
        const hasEducation = activeSections.indexOf('education_reference') !== -1;
        const hasEmployment = activeSections.indexOf('employment_reference') !== -1;

        if (hiddenEducation) {
            hiddenEducation.value = hasEducation ? '1' : '0';
        }
        if (hiddenEmployment) {
            hiddenEmployment.value = hasEmployment ? '1' : '0';
        }

        if (educationTabBtn) educationTabBtn.style.display = hasEducation ? '' : 'none';
        if (employmentTabBtn) employmentTabBtn.style.display = hasEmployment ? '' : 'none';
        if (tabsWrap) tabsWrap.style.display = (hasEducation && hasEmployment) ? '' : 'none';

        document.querySelectorAll('[data-reference-section]').forEach((sectionEl) => {
            const key = String(sectionEl.getAttribute('data-reference-section') || '').trim();
            const isActive = activeSections.indexOf(key) !== -1;
            sectionEl.style.display = isActive ? '' : 'none';
            sectionEl.querySelectorAll('input, select, textarea').forEach((input) => {
                input.disabled = !isActive;
            });
        });

        if (noSectionMessage) {
            noSectionMessage.style.display = activeSections.length ? 'none' : 'block';
        }

        if (hasEducation && hasEmployment) {
            this.activateTab('education_reference_tab');
        } else if (hasEducation) {
            this.activateTab('education_reference_tab');
        } else if (hasEmployment) {
            this.activateTab('employment_reference_tab');
        }
    }

    static initTabs() {
        const form = document.getElementById('referenceForm');
        if (!form) return;

        form.querySelectorAll('.address-tab-btn').forEach((btn) => {
            this.on(btn, 'click', (e) => {
                e.preventDefault();
                const tabId = String(btn.getAttribute('data-tab-target') || '').trim();
                if (!tabId) return;
                this.activateTab(tabId);
            });
        });
    }

    static hydrateFromDB() {
        const dataEl = document.getElementById('referenceData');
        if (!dataEl) return;

        try {
            const data = JSON.parse(dataEl.dataset.reference || '{}');
            if (Object.keys(data).length) {
                this.populateForm(data);
            }
        } catch (e) {
            console.error('Reference data parse error', e);
        }
    }

    static populateForm(data) {
        Object.keys(this._sectionConfig).forEach((sectionKey) => {
            const prefix = sectionKey + '_';
            this._sectionConfig[sectionKey].fields.forEach((field) => {
                const el = document.querySelector(`[name="${field}"]`);
                if (!el) return;

                const shortKey = field.replace(prefix, '');
                let value = data[field];

                if ((value === null || typeof value === 'undefined' || value === '') && sectionKey === 'employment_reference') {
                    const legacyMap = {
                        name: 'reference_name',
                        designation: 'reference_designation',
                        company: 'reference_company',
                        relationship: 'relationship',
                        years_known: 'years_known',
                        mobile: 'reference_mobile',
                        email: 'reference_email'
                    };
                    value = data[legacyMap[shortKey] || ''];
                }

                if (value !== null && typeof value !== 'undefined') {
                    el.value = value;
                }
            });
        });
    }

    static initFormHandling() {
        const form = document.getElementById('referenceForm');
        if (!form) return;

        this.on(form, 'submit', (e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
        });

        this.on(
            form.querySelector('.save-draft-btn[data-page="reference"]'),
            'click',
            (e) => {
                e.preventDefault();
                this.saveDraft();
            }
        );

        this.on(
            document.querySelector('.external-submit-btn[data-form="referenceForm"]'),
            'click',
            async (e) => {
                e.preventDefault();
                await this.submitForm();
            }
        );

        this.on(
            document.querySelector('.prev-btn[data-form="referenceForm"]'),
            'click',
            (e) => {
                e.preventDefault();
                window.Router?.navigateTo
                    ? Router.navigateTo('employment')
                    : (location.href = '/candidate/employment.php');
            }
        );
    }

    static getSectionValues(form, sectionKey) {
        const fields = this._sectionConfig[sectionKey]?.fields || [];
        const prefix = sectionKey + '_';
        const values = {};

        fields.forEach((field) => {
            const shortKey = field.replace(prefix, '');
            values[shortKey] = form.querySelector(`[name="${field}"]`)?.value.trim() || '';
        });

        return values;
    }

    static validateSection(sectionKey, finalSubmit = false) {
        const form = document.getElementById('referenceForm');
        if (!form) return false;

        const values = this.getSectionValues(form, sectionKey);
        const filledCount = Object.values(values).filter((v) => v).length;
        const totalCount = Object.keys(values).length;
        const label = this._sectionConfig[sectionKey]?.label || 'Reference';

        if (!finalSubmit && filledCount === 0) return true;
        if (!finalSubmit && filledCount > 0 && filledCount < totalCount) {
            this.showNotification(`Fill all fields or leave all empty for ${label} draft save`, true);
            return false;
        }

        if (finalSubmit) {
            for (const [key, value] of Object.entries(values)) {
                if (!value) {
                    this.showNotification(`${label}: ${key.replace(/_/g, ' ')} is required`, true);
                    return false;
                }
            }
        }

        if (values.email && !this.validateEmail(values.email)) {
            this.showNotification(`${label}: Invalid email format`, true);
            return false;
        }

        if (values.mobile && !this.validateMobile(values.mobile)) {
            this.showNotification(`${label}: Mobile must be 10 digits`, true);
            return false;
        }

        if (values.years_known && (!this.validateNumber(values.years_known) || parseInt(values.years_known, 10) <= 0)) {
            this.showNotification(`${label}: Years known must be a positive number`, true);
            return false;
        }

        return true;
    }

    static validateForm(finalSubmit = false) {
        if (!this._activeSections.length) {
            return true;
        }

        for (const sectionKey of this._activeSections) {
            if (!this.validateSection(sectionKey, finalSubmit)) {
                return false;
            }
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

    static async saveDraft() {
        if (!this.validateForm(false)) return;
        await this.persistForm(true);
    }

    static async submitForm() {
        if (!this.validateForm(true)) return;

        if (!this._activeSections.length) {
            window.Router?.markCompleted?.('reference');
            window.Router?.updateProgress?.();
            window.Forms?.clearDraft?.('reference');
            window.Router?.navigateTo
                ? Router.navigateTo('review')
                : (location.href = '?page=review');
            return;
        }

        await this.persistForm(false);
    }

    static async persistForm(isDraft) {
        const form = document.getElementById('referenceForm');
        if (!form) return;

        const fd = new FormData(form);
        fd.set('draft', isDraft ? '1' : '0');

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

            this.showNotification(isDraft ? '✅ Reference draft saved' : '✅ Reference details saved successfully');

            if (!isDraft) {
                window.Router?.markCompleted?.('reference');
                window.Router?.updateProgress?.();
                window.Forms?.clearDraft?.('reference');
                window.Router?.navigateTo
                    ? Router.navigateTo('review')
                    : (location.href = '?page=review');
            }
        } catch (e) {
            this.showNotification(e.message, true);
        }
    }
}

window.Reference = Reference;
