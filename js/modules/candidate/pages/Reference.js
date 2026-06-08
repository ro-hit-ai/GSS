class Reference {
    static _initialized = false;
    static _listeners = [];
    static _activeSections = [];
    static _maxReferences = 3;
    static _sectionConfig = {
        education_reference: {
            type: 'education',
            label: 'Education Reference',
            tabId: 'education_reference_tab',
            hiddenName: 'has_education_reference',
            visibleName: 'education_reference_visible_count'
        },
        employment_reference: {
            type: 'employment',
            label: 'Employment Reference',
            tabId: 'employment_reference_tab',
            hiddenName: 'has_employment_reference',
            visibleName: 'employment_reference_visible_count'
        }
    };
    static _groups = {
        education: { rows: [], cards: [], visibleCount: 1, currentIndex: 0 },
        employment: { rows: [], cards: [], visibleCount: 1, currentIndex: 0 }
    };
    static _fields = ['name', 'designation', 'company', 'relationship', 'years_known', 'mobile', 'email'];

    static init() {
        return this;
    }

    static onPageLoad() {
        if (this._initialized) return;

        this._initialized = true;
        this.loadReferenceData();
        this.applySectionVisibility();
        this.renderAllGroups();
        this.initTabs();
        this.initFormHandling();
    }

    static cleanup() {
        this._listeners.forEach(({ el, type, fn }) => el?.removeEventListener(type, fn));
        this._listeners = [];
        this._initialized = false;
        this._activeSections = [];
        this._groups = {
            education: { rows: [], cards: [], visibleCount: 1, currentIndex: 0 },
            employment: { rows: [], cards: [], visibleCount: 1, currentIndex: 0 }
        };
    }

    static on(el, type, fn) {
        if (!el) return;
        el.addEventListener(type, fn);
        this._listeners.push({ el, type, fn });
    }

    static showNotification(message, isError = false) {
        const text = String(message || '').trim();
        if (!text) return;

        if (window.CandidateNotify && typeof window.CandidateNotify.show === 'function') {
            window.CandidateNotify.show({
                type: isError ? 'error' : 'success',
                title: isError ? 'Reference details not saved' : 'Reference details saved',
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
        const correction = config && config.correction_mode ? (config.correction_context || {}) : {};
        const correctionComponents = Array.isArray(correction.components) ? correction.components : [];
        const correctionSet = correctionComponents.reduce((acc, item) => {
            const key = String(item || '').trim().toLowerCase();
            if (key) acc[key] = true;
            return acc;
        }, {});
        if (config && config.correction_mode) {
            if (correctionSet.education_reference || correctionSet.employment_reference) {
                const scoped = [];
                if (correctionSet.education_reference) scoped.push('education_reference');
                if (correctionSet.employment_reference) scoped.push('employment_reference');
                return scoped;
            }
            if (correctionSet.reference) {
                return ['education_reference', 'employment_reference'];
            }
        }
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

    static loadReferenceData() {
        const dataEl = document.getElementById('referenceData');
        let data = {};
        try {
            data = dataEl ? JSON.parse(dataEl.dataset.reference || '{}') : {};
        } catch (e) {
            console.error('Reference data parse error', e);
        }

        ['education', 'employment'].forEach((type) => {
            const rows = Array.isArray(data[type]) ? data[type] : [];
            const normalizedRows = rows.length ? rows : [{}];
            this._groups[type].rows = normalizedRows.slice(0, this._maxReferences);
            this._groups[type].visibleCount = Math.max(1, Math.min(normalizedRows.length || 1, this._maxReferences));
        });
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
        const tabsWrap = document.getElementById('referenceTabsWrap');
        const hasEducation = activeSections.indexOf('education_reference') !== -1;
        const hasEmployment = activeSections.indexOf('employment_reference') !== -1;

        Object.entries(this._sectionConfig).forEach(([sectionKey, config]) => {
            const isActive = activeSections.indexOf(sectionKey) !== -1;
            const hidden = form?.querySelector(`[name="${config.hiddenName}"]`);
            const tabButton = document.querySelector(`[data-tab-target="${config.tabId}"]`);
            const pane = document.getElementById(config.tabId);

            if (hidden) hidden.value = isActive ? '1' : '0';
            if (tabButton) tabButton.style.display = isActive ? '' : 'none';
            if (pane) {
                pane.style.display = isActive ? '' : 'none';
                pane.querySelectorAll('input, select, textarea, button').forEach((input) => {
                    input.disabled = !isActive;
                });
            }
        });

        if (tabsWrap) tabsWrap.style.display = (hasEducation || hasEmployment) ? '' : 'none';
        if (noSectionMessage) noSectionMessage.style.display = activeSections.length ? 'none' : 'block';

        if (hasEducation) {
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
                if (tabId) this.activateTab(tabId);
            });
        });
    }

    static renderAllGroups() {
        this.renderGroup('education');
        this.renderGroup('employment');
    }

    static renderGroup(type) {
        const group = this._groups[type];
        const list = document.querySelector(`[data-reference-list="${type}"]`);
        const tabs = document.querySelector(`[data-reference-tabs="${type}"]`);
        const template = document.getElementById('referenceCardTemplate');
        const visibleInput = document.querySelector(`[name="${type}_reference_visible_count"]`);
        if (!group || !list || !tabs || !template) return;

        list.innerHTML = '';
        tabs.innerHTML = '';
        group.cards = [];

        const visibleCount = Math.max(1, Math.min(group.visibleCount || 1, this._maxReferences));
        group.visibleCount = visibleCount;
        if (visibleInput) visibleInput.value = String(visibleCount);

        for (let index = 0; index < visibleCount; index++) {
            if (!group.rows[index]) group.rows[index] = {};

            const card = this.createCard(type, index, group.rows[index], template);
            list.appendChild(card);
            group.cards[index] = card;

            const tab = document.createElement('div');
            tab.className = `${type === 'education' ? 'education' : 'employment'}-tab tab-item reference-inner-tab`;
            tab.dataset.referenceType = type;
            tab.dataset.index = String(index);
            tab.innerHTML = `Reference ${index + 1} <span class="tab-dot">•</span>`;
            tab.addEventListener('click', () => this.showInnerTab(type, index));
            tabs.appendChild(tab);
        }

        this.showInnerTab(type, Math.min(group.currentIndex || 0, visibleCount - 1));
        this.updateContinuation(type);
    }

    static createCard(type, index, row, template) {
        const fragment = template.content.cloneNode(true);
        const card = fragment.querySelector('[data-reference-card]');
        const prefix = `${type}_reference`;
        card.dataset.referenceType = type;
        card.dataset.referenceIndex = String(index);

        const companyLabel = card.querySelector('[data-company-label]');
        if (companyLabel) {
            companyLabel.innerHTML = `${type === 'education' ? 'Institution / Company' : 'Company'} <span class="required">*</span>`;
        }

        this._fields.forEach((field) => {
            const input = card.querySelector(`[data-field="${field}"]`);
            if (!input) return;
            input.name = `${prefix}_${field}[]`;
            input.value = row[field] || '';
        });

        const checkbox = card.querySelector('.reference-continuation-checkbox');
        if (checkbox) {
            checkbox.addEventListener('change', (event) => this.handleContinuation(type, index, event.target));
        }

        return card;
    }

    static showInnerTab(type, index) {
        const group = this._groups[type];
        if (!group) return;

        const safeIndex = Math.max(0, Math.min(index, group.visibleCount - 1));
        group.cards.forEach((card, cardIndex) => {
            if (card) card.style.display = cardIndex === safeIndex ? '' : 'none';
        });

        document.querySelectorAll(`[data-reference-tabs="${type}"] .reference-inner-tab`).forEach((tab) => {
            tab.classList.toggle('active', parseInt(tab.dataset.index || '0', 10) === safeIndex);
        });

        group.currentIndex = safeIndex;
    }

    static handleContinuation(type, index, checkbox) {
        const group = this._groups[type];
        if (!group) return;

        const isLastVisible = index === group.visibleCount - 1;

        if (checkbox.checked) {
            if (!isLastVisible || group.visibleCount >= this._maxReferences) {
                this.updateContinuation(type);
                return;
            }

            this.captureGroupValues(type);
            group.visibleCount += 1;
            group.currentIndex = group.visibleCount - 1;
            if (!group.rows[group.currentIndex]) group.rows[group.currentIndex] = {};
            this.renderGroup(type);
            this.showInnerTab(type, group.currentIndex);
        } else {
            if (index < group.visibleCount - 1) {
                this.captureGroupValues(type);
                group.visibleCount = Math.max(1, index + 1);
                group.currentIndex = Math.min(index, group.visibleCount - 1);
                this.renderGroup(type);
                this.showInnerTab(type, group.currentIndex);
            } else {
                checkbox.checked = false;
                this.updateContinuation(type);
            }
        }
    }

    static updateContinuation(type) {
        const group = this._groups[type];
        if (!group) return;

        group.cards.forEach((card, index) => {
            const row = card.querySelector('.reference-continuation-row');
            const checkbox = card.querySelector('.reference-continuation-checkbox');
            const label = card.querySelector('[data-continuation-label]');
            const canContinue = index < this._maxReferences - 1;
            const showRow = canContinue && index < group.visibleCount;

            if (row) row.style.display = showRow ? '' : 'none';
            if (checkbox) {
                checkbox.disabled = !showRow;
                checkbox.checked = showRow && index < group.visibleCount - 1;
            }
            if (label) {
                label.textContent = type === 'education'
                    ? 'I have further education references'
                    : 'I have further employment references';
            }
        });
    }

    static captureAllValues() {
        this.captureGroupValues('education');
        this.captureGroupValues('employment');
    }

    static captureGroupValues(type) {
        const group = this._groups[type];
        if (!group) return;

        group.cards.forEach((card, index) => {
            group.rows[index] = this.getCardValues(card);
        });
    }

    static getCardValues(card) {
        const values = {};
        this._fields.forEach((field) => {
            values[field] = card.querySelector(`[data-field="${field}"]`)?.value.trim() || '';
        });
        return values;
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
            form.querySelector('.prev-btn'),
            'click',
            (e) => {
                e.preventDefault();
                window.Router?.navigateTo
                    ? Router.navigateTo('social')
                    : (location.href = '?page=social');
            }
        );
    }

    static validateGroup(type, finalSubmit = false, errors = []) {
        const sectionKey = type === 'education' ? 'education_reference' : 'employment_reference';
        if (this._activeSections.indexOf(sectionKey) === -1) return true;

        const group = this._groups[type];
        const beforeCount = errors.length;
        const label = type === 'education' ? 'Education Reference' : 'Employment Reference';

        group.cards.forEach((card, index) => {
            const values = this.getCardValues(card);
            const filledCount = Object.values(values).filter((value) => value).length;
            const rowLabel = `${label} ${index + 1}`;
            const addError = (field, message) => {
                const input = card.querySelector(`[data-field="${field}"]`) || card;
                if (window.CandidateNotify && typeof window.CandidateNotify.addFieldError === 'function') {
                    window.CandidateNotify.addFieldError(errors, input, message);
                } else {
                    if (input && input.classList) input.classList.add('is-invalid');
                    errors.push({ field: input, message });
                }
            };

            if (!finalSubmit && filledCount === 0) return;
            if (!finalSubmit && filledCount > 0 && filledCount < this._fields.length) {
                const missingField = this._fields.find((field) => !values[field]) || 'name';
                addError(missingField, `Fill all fields or leave all empty for ${rowLabel} draft save`);
                return;
            }

            if (finalSubmit) {
                this._fields.forEach((field) => {
                    if (!values[field]) {
                        addError(field, `${rowLabel}: ${field.replace(/_/g, ' ')} is required`);
                    }
                });
            }

            if (values.email && !this.validateEmail(values.email)) {
                addError('email', `${rowLabel}: Invalid email format`);
            }

            if (values.mobile && !this.validateMobile(values.mobile)) {
                addError('mobile', `${rowLabel}: Mobile must be 10 digits`);
            }

            if (values.years_known && (!this.validateNumber(values.years_known) || parseInt(values.years_known, 10) <= 0)) {
                addError('years_known', `${rowLabel}: Years known must be a positive number`);
            }
        });

        return errors.length === beforeCount;
    }

    static validateForm(finalSubmit = false) {
        if (!this._activeSections.length) return true;

        const form = document.getElementById('referenceForm');
        if (window.CandidateNotify && form && typeof window.CandidateNotify.clearValidation === 'function') {
            window.CandidateNotify.clearValidation(form);
        }

        const errors = [];
        this.validateGroup('education', finalSubmit, errors);
        this.validateGroup('employment', finalSubmit, errors);

        if (errors.length > 0) {
            if (window.CandidateNotify && form && typeof window.CandidateNotify.validation === 'function') {
                window.CandidateNotify.validation({
                    form,
                    title: 'Reference details need attention',
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

        this.captureAllValues();
        Object.entries(this._sectionConfig).forEach(([sectionKey, config]) => {
            const group = this._groups[config.type];
            const visibleInput = form.querySelector(`[name="${config.visibleName}"]`);
            if (visibleInput) visibleInput.value = String(group.visibleCount);
        });

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

            this.showNotification(isDraft ? 'Reference draft saved' : 'Reference details saved successfully');

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
