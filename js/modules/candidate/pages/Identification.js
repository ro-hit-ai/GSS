class IdentificationManager extends TabManager {
    constructor() {
        super(
            'identification',
            'identificationContainer',
            'identificationTemplate',
            'identificationTabs',
            'identificationCount'
        );
        this.documentTypes = {};
        this.countries = [];
        this.country = 'India';
        this.savedRows = [];
        this.isSubmitting = false;
        this.requirements = [];
    }

    async init() {
        console.log('🆔 IdentificationManager.init() called');
        this.loadPageData();
        await super.init();

        try {
            var req = window.CANDIDATE_CASE_CONFIG && window.CANDIDATE_CASE_CONFIG.required_counts
                ? parseInt(window.CANDIDATE_CASE_CONFIG.required_counts.identification || '0', 10) || 0
                : 0;
            if (req > 0 && this.countSelect) {
                this.countSelect.value = String(req);
                this.handleCountChange();
            }

            if (this.countSelect) {
                this.countSelect.disabled = true;
                var wrap = this.countSelect.closest ? this.countSelect.closest('.form-control') : null;
                if (wrap) {
                    wrap.style.display = 'none';
                } else {
                    this.countSelect.style.display = 'none';
                }
            }
        } catch (e) {
        }

        this.setupCountryLogic();
        this.setupFormHandlers();
        this.setupDocumentTypeHandlers();
        this.setupInsufficientDocsHandlers();
        this.setupFileHandlers();
        this.loadFromLocalStorage();

        console.log('✅ Identification module initialized successfully');
        return this;
    }

   
    normalizeCountry(country) {
        const map = {
            'United States': 'USA',
            'United Kingdom': 'UK',
            'US': 'USA',
            'United States of America': 'USA',
            'United Kingdom of Great Britain and Northern Ireland': 'UK'
        };
        return map[country] || country || 'India';
    }

    getApiEndpoint() {
        return `${window.APP_BASE_URL}/api/candidate/store_identification.php`;
    }

    getTabLabel(index) {
        return `ID ${index + 1}`;
    }

    getRequirementDocTypes() {
        const combined = [];
        this.requirements.forEach((group) => {
            const types = Array.isArray(group.types) ? group.types : [];
            types.forEach((type) => {
                if (type && !combined.includes(type)) {
                    combined.push(type);
                }
            });
        });
        return combined;
    }

    loadPageData() {
        const dataEl = document.getElementById("identificationData");
        if (!dataEl) {
            console.warn('⚠️ Identification data element not found');
            this.documentTypes = {};
            this.countries = [];
            this.savedRows = [];
            return;
        }

        try {
            this.savedRows = JSON.parse(dataEl.dataset.rows || '[]');
            console.log(`📥 Loaded ${this.savedRows.length} identification records`);
            
            // Parse document types - handle both formats
            const rawDocTypes = dataEl.dataset.documentTypes;
            if (rawDocTypes) {
                this.documentTypes = JSON.parse(rawDocTypes);
                console.log('📋 Document types loaded:', this.documentTypes);
            }
            
            // Parse countries
            const rawCountries = dataEl.dataset.countries;
            if (rawCountries) {
                this.countries = JSON.parse(rawCountries);
            }
            
            // Get country from data element
            this.country = this.normalizeCountry(dataEl.dataset.country || 'India');
            this.requirements = [];
            const cfg = window.CANDIDATE_CASE_CONFIG || {};
            const reqs = Array.isArray(cfg.identification_requirements) ? cfg.identification_requirements : [];
            reqs.forEach((group) => {
                const types = Array.isArray(group.types) ? group.types.filter(Boolean) : [];
                this.requirements.push({
                    group_key: group.group_key || '',
                    group_label: group.group_label || '',
                    types: types
                });
            });
            
        } catch (e) {
            console.error("❌ Failed to parse identification data", e);
            this.documentTypes = {};
            this.countries = [];
            this.savedRows = [];
            this.country = 'India';
            this.requirements = [];
        }
    }

    populateCard(card, data = {}, index) {
        card.dataset.cardIndex = index;
        const indexInput = this.findInput(card, 'document_index[]');
        if (indexInput) indexInput.value = index + 1;

        if (data.id) {
            this.findOrCreateInput(card, `id[${index}]`, 'hidden').value = data.id;
        }

        const docNum = card.querySelector('.document-num');
        if (docNum) {
            docNum.textContent = `${index + 1}`;
        }

        const requiredDocTypes = this.getRequirementDocTypes();
        const docTypes = requiredDocTypes.length
            ? requiredDocTypes
            : (this.documentTypes[this.country] || this.documentTypes['Other'] || {});

        this.updateDocumentTypeOptions(card, docTypes);

        const groupedRows = this.normalizeGroupedRows(data);
        this.populateProofGroup(card, 'primary', groupedRows.primary || {});
        this.populateProofGroup(card, 'secondary', groupedRows.secondary || {});
    }

    toggleDocumentFileInput(card, isInsufficient) {
        const groupedFiles = card.querySelectorAll('[data-doc-file-input]');

        groupedFiles.forEach((documentFile) => {
            documentFile.disabled = isInsufficient;
            if (isInsufficient) {
                documentFile.value = '';
                this.clearUploadBox(this.getGroupedUploadBox(card, documentFile.dataset.docGroup || 'primary'));
            }
        });
    }

    getNormalizedDocTypes(docTypes) {
        if (Array.isArray(docTypes)) {
            return docTypes.filter(Boolean).map((item) => ({ label: item, value: item }));
        }

        if (docTypes && typeof docTypes === 'object') {
            return Object.entries(docTypes).map(([label, value]) => ({
                label,
                value: value || label
            }));
        }

        return [];
    }

    getDocGroupForType(type) {
        const normalized = String(type || '').trim().toLowerCase();
        if (!normalized) return 'primary';

        const primaryTypes = [
            'aadhaar',
            'aadhar',
            'driving licence',
            'driving license',
            'driver license',
            'driver licence'
        ];

        return primaryTypes.includes(normalized) ? 'primary' : 'secondary';
    }

    getDocGroupSets(docTypes) {
        const normalized = this.getNormalizedDocTypes(docTypes);
        const groups = { primary: [], secondary: [] };

        normalized.forEach((item) => {
            const group = this.getDocGroupForType(item.value);
            groups[group].push(item);
        });

        return groups;
    }

    getGroupedFileInput(card, group) {
        return card
            ? card.querySelector(`[data-doc-file-input][data-doc-group="${group}"]`)
            : null;
    }

    getGroupedUploadBox(card, group) {
        return card
            ? card.querySelector(`[data-file-upload][data-doc-group="${group}"]`)
            : null;
    }

    getProofFields(card, group) {
        if (!card) return {};
        return {
            select: card.querySelector(`[name="${group}_document_type[]"]`),
            name: card.querySelector(`[name="${group}_name[]"]`),
            idNumber: card.querySelector(`[name="${group}_id_number[]"]`),
            issueDate: card.querySelector(`[name="${group}_issue_date[]"]`),
            expiryDate: card.querySelector(`[name="${group}_expiry_date[]"]`),
            oldFile: card.querySelector(`[name="old_${group}_upload_document[]"]`),
            dateRow: card.querySelector(`[data-proof-date-row="${group}"]`),
            fileInput: this.getGroupedFileInput(card, group),
            uploadBox: this.getGroupedUploadBox(card, group)
        };
    }

    normalizeGroupedRows(data) {
        const grouped = { primary: {}, secondary: {} };
        if (data && data.grouped_rows && typeof data.grouped_rows === 'object') {
            if (data.grouped_rows.primary) grouped.primary = data.grouped_rows.primary;
            if (data.grouped_rows.secondary) grouped.secondary = data.grouped_rows.secondary;
        } else if (data && data.documentId_type) {
            const group = this.getDocGroupForType(data.documentId_type || '');
            grouped[group] = {
                document_type: data.documentId_type || '',
                id_number: data.id_number || '',
                name: data.name || '',
                issue_date: data.issue_date || '',
                expiry_date: data.expiry_date || '',
                upload_document: data.upload_document || ''
            };
        }
        return grouped;
    }

    populateProofGroup(card, group, rowData = {}) {
        const fields = this.getProofFields(card, group);
        if (!fields.select) return;

        fields.select.value = rowData.document_type || '';
        fields.name.value = rowData.name || '';
        fields.idNumber.value = rowData.id_number || '';
        fields.issueDate.value = rowData.issue_date ? String(rowData.issue_date).split(' ')[0] : '';
        fields.expiryDate.value = rowData.expiry_date ? String(rowData.expiry_date).split(' ')[0] : '';
        if (fields.oldFile) {
            fields.oldFile.value = rowData.upload_document || '';
        }

        this.updateDateFieldsForGroup(card, group);

        if (rowData.upload_document && rowData.upload_document !== 'INSUFFICIENT_DOCUMENTS' && fields.uploadBox) {
            const base = window.APP_BASE_URL || '';
            const url = `${base}/uploads/identification/${rowData.upload_document}`;
            this.setUploadBox(fields.uploadBox, rowData.upload_document, url, false);
        } else if (fields.uploadBox) {
            this.clearUploadBox(fields.uploadBox);
        }
    }

    setupFileHandlers() {
        console.log('🔧 Setting up file handlers');

        this.addEventListener(document, 'click', (e) => {
            const trigger = e.target.closest('[data-file-choose]');
            if (!trigger) return;
            e.preventDefault();
            const box = trigger.closest('[data-file-upload]');
            const control = box ? box.closest('.form-control') : null;
            const input = control ? control.querySelector('input[type="file"][data-doc-file-input]') : null;
            if (input) input.click();
        });

        this.addEventListener(document, 'click', (e) => {
            const remove = e.target.closest('[data-file-remove]');
            if (!remove) return;
            const box = remove.closest('[data-file-upload]');
            const card = box ? box.closest('.identification-card') : null;
            const group = box ? (box.dataset.docGroup || 'primary') : 'primary';
            const input = this.getGroupedFileInput(card, group);
            const fields = this.getProofFields(card, group);
            e.preventDefault();
            if (input) input.value = '';
            if (fields.oldFile) fields.oldFile.value = '';
            this.clearUploadBox(box);
            if (window.CandidateNotify && box) {
                window.CandidateNotify.clearFieldError(box);
            }
            this.updateTabStatus();
        });

        this.addEventListener(document, 'change', (e) => {
            if (e.target.matches('[data-doc-file-input]')) {
                const input = e.target;
                const card = input.closest('.identification-card');
                const group = input.dataset.docGroup || 'primary';
                const file = input.files && input.files[0] ? input.files[0] : null;
                const box = this.getGroupedUploadBox(card, group);
                if (box) {
                    const errEl = box.querySelector('[data-file-error]');
                    if (errEl) errEl.textContent = '';
                }

                const allowed = ['pdf', 'jpg', 'jpeg', 'png'];
                const validation = this.validateUploadFile(file, allowed, 10 * 1024 * 1024);
                if (file && !validation.ok) {
                    if (window.CandidateNotify) {
                        window.CandidateNotify.error(validation.message, {
                            title: 'Invalid upload',
                            sticky: false,
                            timeout: 4200
                        });
                        window.CandidateNotify.setFieldError(box || input, validation.message);
                    }
                    input.value = '';
                    this.clearUploadBox(box);
                    if (box) {
                        const errEl = box.querySelector('[data-file-error]');
                        if (errEl) errEl.textContent = validation.message;
                    }
                } else if (box && window.CandidateNotify) {
                    window.CandidateNotify.clearFieldError(box);
                }

                if (card && input.files.length > 0) {
                    if (file && box) {
                        const url = URL.createObjectURL(file);
                        this.setUploadBox(box, file.name, url, true, file.size);
                    }
                    this.updateTabStatus();
                }
            }
        });
    }
    setupInsufficientDocsHandlers() {
        console.log('🔧 Setting up insufficient documents handlers');
        
        this.addEventListener(document, 'change', (e) => {
            if (e.target.matches('input[name="insufficient_documents[]"]')) {
                const checkbox = e.target;
                const card = checkbox.closest('.identification-card');
                if (card) {
                    const cardIndex = card.dataset.cardIndex || 'unknown';
                    console.log(`🔘 Insufficient documents checkbox changed in card ${cardIndex}: ${checkbox.checked}`);
                    this.toggleDocumentFileInput(card, checkbox.checked);
                }
            }
        });
    }

    setupCountryLogic() {
        const select = document.getElementById('identificationCountry');
        const hidden = document.getElementById('identificationCountryField');
        if (!select || !hidden) return;

        // Set initial value
        const exists = [...select.options].some(o => o.value === this.country);
        this.country = exists ? this.country : 'India';

        select.value = this.country;
        hidden.value = this.country;
        console.log(`🌍 Initial country set to: ${this.country}`);

        // Update UI
        this.updateAllDocumentTypeOptions(this.country);

        // Add change listener
        this.addEventListener(select, 'change', () => {
            this.country = select.value;
            hidden.value = this.country;
            console.log(`🌍 Country changed to: ${this.country}`);
            this.updateAllDocumentTypeOptions(this.country);
            this.updateAllDateFields();
        });
    }

    updateAllDocumentTypeOptions(country) {
        console.log(`🔄 Updating document types for country: ${country}`);
        const docTypes = this.documentTypes[country] || this.documentTypes['Other'] || {};
        console.log('📋 Available document types:', docTypes);

        this.cards.forEach((card, index) => {
            if (!card) return;
            const requiredDocTypes = this.getRequirementDocTypes();
            this.updateDocumentTypeOptions(card, requiredDocTypes.length ? requiredDocTypes : docTypes);
        });
    }

    updateDocumentTypeOptions(card, docTypes) {
        const primarySelect = card.querySelector('.primary-doc-select');
        const secondarySelect = card.querySelector('.secondary-doc-select');
        if (!primarySelect || !secondarySelect) {
            console.warn('❌ Grouped document selects not found in card');
            return;
        }

        const groups = this.getDocGroupSets(docTypes);

        const buildOptions = (select, items, placeholder) => {
            const currentValue = select.value || '';
            select.innerHTML = `<option value="">${placeholder}</option>`;
            items.forEach((item) => {
                const opt = document.createElement('option');
                opt.value = item.value;
                opt.textContent = item.label;
                select.appendChild(opt);
            });
            if (currentValue) {
                select.value = currentValue;
            }
        };

        buildOptions(primarySelect, groups.primary, 'Select ID proof');
        buildOptions(secondarySelect, groups.secondary, 'Select ID proof');

        primarySelect.disabled = groups.primary.length === 0;
        secondarySelect.disabled = groups.secondary.length === 0;
    }

    updateAllDateFields() {
        this.cards.forEach(card => {
            if (!card) return;
            this.updateDateFieldsForGroup(card, 'primary');
            this.updateDateFieldsForGroup(card, 'secondary');
        });
    }

    proofTypeUsesDates(type) {
        const normalized = String(type || '').trim().toLowerCase();
        return ['passport', 'driving licence', 'driving license', 'driver license', 'driver licence'].includes(normalized);
    }

    updateDateFieldsForGroup(card, group) {
        const fields = this.getProofFields(card, group);
        if (!fields.select || !fields.dateRow || !fields.issueDate || !fields.expiryDate) return;
        const showDates = this.proofTypeUsesDates(fields.select.value);
        fields.dateRow.style.display = showDates ? 'grid' : 'none';
        fields.issueDate.disabled = !showDates;
        fields.expiryDate.disabled = !showDates;
        if (!showDates) {
            fields.issueDate.value = '';
            fields.expiryDate.value = '';
        }
    }

    setupDocumentTypeHandlers() {
        this.addEventListener(document, 'change', (e) => {
            if (!e.target.classList.contains('grouped-doc-select')) return;
            const card = e.target.closest('.identification-card');
            if (!card) return;
            const group = e.target.dataset.proofGroupSelect || e.target.dataset.docGroup || 'primary';
            this.updateDateFieldsForGroup(card, group);
        });
    }
setupFormHandlers() {
    console.log('🔧 Setting up form handlers');

    let form = document.getElementById('identificationForm');
    if (!form) return;

    // 🔒 DO NOT CLONE THE FORM
    // Just prevent default submit safely
    this.addEventListener(form, 'submit', (e) => {
        e.preventDefault();
        e.stopPropagation();
        console.log('❌ Native form submit prevented');
    });

    // Next button
    const nextBtn = document.querySelector(
        '.external-submit-btn[data-form="identificationForm"]'
    );
    if (nextBtn) {
        this.addEventListener(nextBtn, 'click', async (e) => {
            e.preventDefault();
            console.log('✅ Next button clicked');
            await this.submitForm(false);
        });
    }

    // Save draft
    this.addEventListener(document, 'click', (e) => {
        const btn = e.target.closest('.save-draft-btn[data-page="identification"]');
        if (btn) {
            e.preventDefault();
            console.log('💾 Save draft clicked');
            this.saveDraft();
        }
    });

    // Previous
    const prevBtn = document.querySelector('.prev-btn');
    if (prevBtn) {
        this.addEventListener(prevBtn, 'click', (e) => {
            e.preventDefault();
            if (window.Router) {
                const previousPage = typeof Router.getPreviousPage === 'function' ? Router.getPreviousPage('identification') : 'basic-details';
                Router.navigateTo(previousPage);
            }
        });
    }

    console.log('✅ Form handlers setup complete');
}


    loadFromLocalStorage() {
        try {
            const raw = localStorage.getItem('identification_draft');
            if (!raw) {
                console.log('📭 No identification draft found in localStorage');
                return;
            }

            const data = JSON.parse(raw);
            console.log('📥 Loading identification draft from localStorage');

            const count = Math.max(this.savedRows.length, 0);

            if (!count) return;

            console.log(`🔄 Ensuring ${count} cards for localStorage data`);
            while (this.cards.length < count) {
                this.addCard(this.cards.length, null);
            }

            for (let i = 0; i < count; i++) {
                const card = this.cards[i];
                if (card) {
                    const hasDbData = this.savedRows[i];
                    if (hasDbData) {
                        continue;
                    }

                    const localStorageData = { grouped_rows: {} };
                    this.populateCard(card, localStorageData, i);
                }
            }

            console.log('✅ Identification draft loaded from localStorage');
        } catch (error) {
            console.error('❌ Error loading identification draft from localStorage:', error);
        }
    }

async saveDraft() {
    if (this.isSubmitting) return;
    this.isSubmitting = true;

    try {
        const form = document.getElementById('identificationForm');
        if (!form) return;

        const formData = new FormData(form);
        formData.set('draft', '1');

        const response = await fetch(this.getApiEndpoint(), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (data.success) {
            // 🔧 CHANGED: Use Router.notify
            if (window.Router && window.Router.notify) {
                Router.notify({
                    type: "success",
                    message: "✅ Identification draft saved!"
                });
            } else {
                this.showNotification('✅ Identification Draft saved successfully!');
            }
            localStorage.removeItem('identification_draft');
        } else {
            // 🔧 CHANGED: Use Router.notify
            if (window.Router && window.Router.notify) {
                Router.notify({
                    type: "error",
                    message: data.message || "Save failed"
                });
            } else {
                this.showNotification((data.message || 'Save failed'), true);
            }
        }

    } catch (err) {
        console.error('❌ Save draft error:', err);
        // 🔧 CHANGED: Use Router.notify
        if (window.Router && window.Router.notify) {
            Router.notify({
                type: "error",
                message: "❌ Network error"
            });
        } else {
            this.showNotification('❌ Network / Server error', true);
        }
    } finally {
        this.isSubmitting = false;
    }
}

validateForm(isFinalSubmit = false) {
    console.log(`📋 Validating identification form (isFinalSubmit: ${isFinalSubmit})`);

    const form = document.getElementById('identificationForm');
    if (window.CandidateNotify && form) {
        window.CandidateNotify.clearValidation(form);
    }

    let isValid = true;
    const errors = [];
    const addError = (field, message) => {
        if (window.CandidateNotify) {
            window.CandidateNotify.addFieldError(errors, field, message);
        } else {
            errors.push({ field, message });
        }
        isValid = false;
    };

    for (let i = 0; i < this.cards.length; i++) {
        const card = this.cards[i];
        if (!card) continue;

        let selectedAny = false;
        ['primary', 'secondary'].forEach((group) => {
            const fields = this.getProofFields(card, group);
            if (!fields.select) return;

            const selectedType = String(fields.select.value || '').trim();
            const oldFileValue = fields.oldFile ? String(fields.oldFile.value || '').trim() : '';
            const hasNewFile = !!(fields.fileInput && fields.fileInput.files && fields.fileInput.files.length > 0);
            const hasOldFile = oldFileValue !== '' && oldFileValue !== 'INSUFFICIENT_DOCUMENTS';

            if (!selectedType) {
                return;
            }

            selectedAny = true;

            if (!String(fields.name.value || '').trim()) {
                addError(fields.name || card, `ID ${i + 1}: Name is required for ${selectedType}`);
            }

            if (!String(fields.idNumber.value || '').trim()) {
                addError(fields.idNumber || card, `ID ${i + 1}: ID number is required for ${selectedType}`);
            }

            if (isFinalSubmit && !hasNewFile && !hasOldFile) {
                addError(fields.uploadBox || fields.fileInput || card, `ID ${i + 1}: Upload document is required for ${selectedType}`);
            }

            if (this.proofTypeUsesDates(selectedType)) {
                if (!String(fields.issueDate.value || '').trim()) {
                    addError(fields.issueDate || card, `ID ${i + 1}: From Date is required for ${selectedType}`);
                }
                if (!String(fields.expiryDate.value || '').trim()) {
                    addError(fields.expiryDate || card, `ID ${i + 1}: To Date is required for ${selectedType}`);
                }
                if (fields.issueDate.value && fields.expiryDate.value) {
                    const fromDate = new Date(fields.issueDate.value);
                    const toDate = new Date(fields.expiryDate.value);
                    if (!Number.isNaN(fromDate.getTime()) && !Number.isNaN(toDate.getTime()) && toDate <= fromDate) {
                        addError(fields.expiryDate, `ID ${i + 1}: To Date must be after From Date for ${selectedType}`);
                    }
                }
            }
        });

        if (isFinalSubmit && !selectedAny) {
            const primarySelect = card.querySelector('[name="primary_document_type[]"]');
            addError(primarySelect || card, `ID ${i + 1}: Select at least one ID proof`);
        }
    }

    if (errors.length > 0) {
        console.warn('Identification validation errors:', errors.map((error) => error.message || error));
        if (window.CandidateNotify && form) {
            window.CandidateNotify.validation({
                form,
                title: 'Identification details need attention',
                message: `Please fix ${errors.length} issue${errors.length === 1 ? '' : 's'} before continuing.`,
                errors
            });
        } else if (window.Router && window.Router.notify) {
            Router.notify({
                type: "warning",
                message: 'Please fix the highlighted identification errors before proceeding.'
            });
        } else if (typeof window.showAlert === 'function') {
            window.showAlert({ type: 'warning', message: 'Please fix the highlighted identification errors before proceeding.' });
        }
        return false;
    }

    console.log(` Identification form validation passed`);
    return true;
}
async submitForm(isDraft = false) {
    console.log(`🆔 Identification submit initiated (draft: ${isDraft})`);

    if (this.isSubmitting) {
        console.log(' Identification submit already in progress');
        return;
    }

    if (!isDraft && !this.validateForm(true)) {
        console.log("❌ Validation failed");
        return;
    }

    this.isSubmitting = true;
    console.log('📤 Submitting identification form...');

    try {
        let form = document.getElementById('identificationForm');
        if (!form) {
            throw new Error('Identification form not found');
        }

        // SAFETY: ensure we always reference the latest form in DOM
        if (form.tagName !== 'FORM') {
            form = document.querySelector('form#identificationForm');
            if (!form) {
                throw new Error('Identification form not found after DOM update');
            }
        }

        const formData = new FormData(form);
        formData.set('draft', isDraft ? '1' : '0');

        console.log('📦 Form data prepared:', {
            draft: isDraft,
            cards: this.cards.length,
            country: this.country
        });

        const response = await fetch(this.getApiEndpoint(), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();
        console.log('📥 Server response:', data);

        if (data.success) {
            if (!isDraft) {
                // Mark the page as completed
                if (window.Router && window.Router.markCompleted) {
                    window.Router.markCompleted('identification');
                    window.Router.updateProgress();
                }

                // Clear drafts
                if (window.Forms && typeof window.Forms.clearDraft === 'function') {
                    window.Forms.clearDraft('identification');
                }

                // 🔧 SUCCESS: Let Router handle the success notification
                // The Router will show success alert after submission
                
                // Navigate to next page immediately
                if (window.Router && window.Router.navigateTo) {
                    const nextPage = typeof window.Router.getNextPage === 'function' ? window.Router.getNextPage('identification') : 'contact';
                    console.log(`➡️ Navigating to: ${nextPage}`);
                    window.Router.navigateTo(nextPage);
                } else {
                    window.location.href =
                        `${window.APP_BASE_URL}/modules/candidate/contact.php`;
                }
            } else {
                // 🔧 CHANGED: Use Router.notify for draft success
                if (window.Router && window.Router.notify) {
                    Router.notify({
                        type: "success",
                        message: "✅ Identification draft saved successfully!"
                    });
                } else {
                    this.showNotification('✅ Identification Draft saved successfully!');
                }
            }
        } else {
            const errorMessage = data.message || 'Save failed';
            console.error('❌ Identification save failed:', errorMessage);
            
            // 🔧 CHANGED: Use Router.notify for API errors
            if (window.Router && window.Router.notify) {
                Router.notify({
                    type: "error",
                    message: "❌ " + errorMessage
                });
            } else {
                this.showNotification('❌ ' + errorMessage, true);
            }
        }

    } catch (err) {
        console.error('❌ Identification submit error:', err);
        
        // 🔧 CHANGED: Use Router.notify for network errors
        if (window.Router && window.Router.notify) {
            Router.notify({
                type: "error",
                message: "❌ Network error. Please try again."
            });
        } else {
            this.showNotification('❌ Error: ' + err.message, true);
        }
    } finally {
        this.isSubmitting = false;
        console.log('✅ Identification submit completed');
    }
}

showNotification(message, isError = false) {
    if (window.CandidateNotify) {
        window.CandidateNotify.show({
            type: isError ? "error" : "success",
            title: isError ? 'Identification details not saved' : 'Identification details saved',
            message: String(message || '').replace(/^[^\w]+/, ''),
            sticky: !!isError
        });
        return;
    }

    if (window.Router && window.Router.notify) {
        Router.notify({
            type: isError ? "error" : "success",
            message: message
        });
    }
}
    cardHasData(card) {
        // Check if card has any data
        const inputs = card.querySelectorAll('input:not([type="hidden"]):not([type="file"]), select, textarea');
        for (const input of inputs) {
            if (input.value && input.value.trim() !== '' && !input.disabled) {
                return true;
            }
        }
        
        // Check for file input
        const fileInput = card.querySelector('input[type="file"]');
        if (fileInput && fileInput.files.length > 0) {
            return true;
        }
        
        // Check for old file
        const oldFileInput = card.querySelector('input[name^="old_"]');
        if (oldFileInput && oldFileInput.value && oldFileInput.value !== 'INSUFFICIENT_DOCUMENTS') {
            return true;
        }
        
        return false;
    }

    cleanup() {
        super.cleanup();
        console.log('🧹 Cleaning up IdentificationManager');
    }
}

if (typeof window !== 'undefined') {
    window.IdentificationManager = IdentificationManager;

    window.Identification = {
        onPageLoad: async () => {
            console.log('🆔 Identification.onPageLoad() called');
            
            try {
                if (!window.identificationManager) {
                    console.log('🆕 Creating new IdentificationManager instance');
                    window.identificationManager = new IdentificationManager();
                }
                
                await window.identificationManager.init();
                console.log('✅ Identification page loaded successfully');
            } catch (error) {
                console.error('❌ Error in Identification.onPageLoad:', error);
            }
        },
        
        cleanup: () => {
            console.log('🧹 Cleaning up Identification module');
            if (window.identificationManager) {
                window.identificationManager.cleanup();
                window.identificationManager = null;
            }
        }
    };
}

console.log('✅ Identification.js module loaded');
