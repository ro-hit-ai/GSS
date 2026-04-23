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
        return `Document ${index + 1}`;
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
            
        } catch (e) {
            console.error("❌ Failed to parse identification data", e);
            this.documentTypes = {};
            this.countries = [];
            this.savedRows = [];
            this.country = 'India';
        }
    }

    populateCard(card, data = {}, index) {
        card.dataset.cardIndex = index;
        console.log(`🆔 IdentificationManager.populateCard() for card ${index}`, data);
        
        // Set index
        const indexInput = this.findInput(card, 'document_index[]');
        if (indexInput) indexInput.value = index + 1;

        // Set record ID
        if (data.id) {
            this.findOrCreateInput(card, `id[${index}]`, 'hidden').value = data.id;
            console.log(`   Set record ID: ${data.id}`);
        }

        // Handle document file
        if (data.upload_document) {
            const oldFileInput = this.findOrCreateInput(card, `old_upload_document[${index}]`, 'hidden');
            oldFileInput.value = data.upload_document;
            console.log(`   Set old file reference: ${data.upload_document}`);

            if (data.upload_document !== 'INSUFFICIENT_DOCUMENTS') {
                const input = card.querySelector('input[name="upload_document[]"]');
                const box = this.getUploadBoxFromInput(input);
                const base = window.APP_BASE_URL || '';
                const url = `${base}/uploads/identification/${data.upload_document}`;
                this.setUploadBox(box, data.upload_document, url, false);
                console.log(`   Added document preview: ${data.upload_document}`);
            }
        }

        // Populate form fields
        const idNum = card.querySelector('[name="id_number[]"]');
        if (idNum && data.id_number) {
            idNum.value = data.id_number;
            console.log(`   Set ID number: ${data.id_number}`);
        }

        const name = card.querySelector('[name="name[]"]');
        if (name && data.name) {
            name.value = data.name;
            console.log(`   Set name: ${data.name}`);
        }

        // Set dates
        const issue = card.querySelector('[name="issue_date[]"]');
        if (issue && data.issue_date) {
            const issueDate = data.issue_date.split(' ')[0];
            issue.value = issueDate;
            console.log(`   Set issue date: ${issueDate}`);
        }

        const expiry = card.querySelector('[name="expiry_date[]"]');
        if (expiry && data.expiry_date) {
            const expiryDate = data.expiry_date.split(' ')[0];
            expiry.value = expiryDate;
            console.log(`   Set expiry date: ${expiryDate}`);
        }

        // Get document types for current country
        const docTypes = this.documentTypes[this.country] || this.documentTypes['Other'] || {};
        console.log(`📋 Document types for ${this.country}:`, docTypes);
        
        // Update document type options with proper labels
        this.updateDocumentTypeOptions(card, docTypes);

        // Set document type - handle both label and value
        const typeSelect = card.querySelector('[name="documentId_type[]"]');
        if (typeSelect && data.documentId_type) {
            console.log(`   Setting document type: ${data.documentId_type}`);
            
            setTimeout(() => {
                // First try to find by exact value
                let optionFound = false;
                for (const option of typeSelect.options) {
                    if (option.value === data.documentId_type) {
                        typeSelect.value = data.documentId_type;
                        optionFound = true;
                        break;
                    }
                }
                
                // If not found, try to find by label
                if (!optionFound) {
                    for (const option of typeSelect.options) {
                        if (option.textContent === data.documentId_type) {
                            typeSelect.value = option.value;
                            optionFound = true;
                            break;
                        }
                    }
                }
                
                if (optionFound) {
                    console.log(`   Document type set to: ${typeSelect.value}`);
                    this.updateIdNumberHint(card);
                    this.updateDateFieldsForCard(card);
                    // Trigger change event to update UI
                    typeSelect.dispatchEvent(new Event('change'));
                } else {
                    console.warn(`   Document type "${data.documentId_type}" not found in options`);
                }
            }, 100);
        }

        // Handle insufficient documents checkbox
        const insufficientCheckbox = card.querySelector('input[name="insufficient_documents[]"]');
        if (insufficientCheckbox) {
            const isInsufficient = data.upload_document === 'INSUFFICIENT_DOCUMENTS' || 
                                 data.insufficient_documents == 1 || 
                                 data.insufficient_documents === true;
            
            insufficientCheckbox.checked = isInsufficient;
            this.toggleDocumentFileInput(card, isInsufficient);
            console.log(`   Set insufficient_documents for card ${index}: ${isInsufficient}`);
        }

        console.log(`✅ Card ${index} populated successfully`);
    }

    toggleDocumentFileInput(card, isInsufficient) {
        const documentFile = card.querySelector('input[name="upload_document[]"]');
        
        if (documentFile) {
            documentFile.disabled = isInsufficient;
            documentFile.required = !isInsufficient;
            if (isInsufficient) {
                documentFile.value = '';
                this.clearUploadBox(this.getUploadBoxFromInput(documentFile));
            }
        }
    }

    setupFileHandlers() {
        console.log('🔧 Setting up file handlers');

        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-file-choose]');
            if (!trigger) return;
            e.preventDefault();
            const box = trigger.closest('[data-file-upload]');
            const control = box ? box.closest('.form-control') : null;
            const input = control ? control.querySelector('input[type="file"][data-file-input]') : null;
            if (input) input.click();
        });

        document.addEventListener('change', (e) => {
            if (e.target.matches('input[name="upload_document[]"]')) {
                const input = e.target;
                const card = input.closest('.identification-card');
                const file = input.files && input.files[0] ? input.files[0] : null;
                const box = this.getUploadBoxFromInput(input);
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
                    console.log(`📄 Document file selected in card:`, input.files[0].name);
                    const insufficientCheckbox =
                        card.querySelector('input[name="insufficient_documents[]"]');
                    if (insufficientCheckbox) {
                        insufficientCheckbox.checked = false;
                        this.toggleDocumentFileInput(card, false);
                    }

                    const oldFile = card.querySelector('[name^="old_upload_document"]');
                    if (oldFile && oldFile.value === 'INSUFFICIENT_DOCUMENTS') {
                        oldFile.value = '';
                    }

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
        
        document.addEventListener('change', (e) => {
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
            this.updateAllIdNumberHints();
            this.updateAllDateFields();
        });
    }

    updateAllDocumentTypeOptions(country) {
        console.log(`🔄 Updating document types for country: ${country}`);
        const docTypes = this.documentTypes[country] || this.documentTypes['Other'] || {};
        console.log('📋 Available document types:', docTypes);
        
        this.cards.forEach(card => {
            if (card) this.updateDocumentTypeOptions(card, docTypes);
        });
    }

    updateDocumentTypeOptions(card, docTypes) {
        const select = card.querySelector('.document-type-select');
        if (!select) {
            console.warn('❌ Document type select not found in card');
            return;
        }

        const currentValue = select.value;
        
        // Clear existing options
        select.innerHTML = '<option value="">Select Document Type</option>';
        
        // Check if docTypes is an array or object
        if (Array.isArray(docTypes)) {
            // If it's an array, use values as both label and value
            docTypes.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item;
                opt.textContent = item;
                select.appendChild(opt);
            });
        } else if (typeof docTypes === 'object') {
            // If it's an object, iterate through key-value pairs
            Object.entries(docTypes).forEach(([label, value]) => {
                const opt = document.createElement('option');
                opt.value = value || label; // Use value if provided, otherwise use label
                opt.textContent = label;
                select.appendChild(opt);
            });
        }
        
        // Restore previous value if it exists
        if (currentValue) {
            for (const option of select.options) {
                if (option.value === currentValue) {
                    select.value = currentValue;
                    break;
                }
            }
        }
        
        console.log(`📝 Updated document type options for card, total options: ${select.options.length}`);
        
        // Update hints
        this.updateIdNumberHint(card);
        this.updateDateFieldsForCard(card);
    }

    updateAllIdNumberHints() {
        this.cards.forEach(card => card && this.updateIdNumberHint(card));
    }

    updateAllDateFields() {
        this.cards.forEach(card => card && this.updateDateFieldsForCard(card));
    }

    updateIdNumberHint(card) {
        const select = card.querySelector('.document-type-select');
        const hintElement = card.querySelector('.id-number-hint');
        if (!select || !hintElement) return;

        const value = select.value;
        const text = select.options[select.selectedIndex]?.textContent || '';
        
        const hintMap = {
            'Aadhaar': '12-digit Aadhaar number',
            'PAN': '10-character PAN (ABCDE1234F)',
            'SSN': '9-digit Social Security Number',
            'SIN': '9-digit Social Insurance Number',
            'NIN': 'National Insurance Number',
            'NRIC': 'Singapore NRIC',
            'Emirates ID': 'Emirates ID number',
            'Passport': 'Passport number',
            'Driving Licence': 'Driving license number',
            'Driver License': 'Driver license number',
            'Voter ID': 'Voter ID number',
            'Ration Card': 'Ration card number',
            'State ID': 'State ID number',
            'Birth Certificate': 'Birth certificate number',
            'Green Card': 'Green card number',
            'BRP': 'Biometric Residence Permit number',
            'National ID': 'National ID number'
        };

        // Try to find hint by select value or text
        let hint = hintMap[value] || hintMap[text] || 'Enter the document number as shown';
        hintElement.textContent = hint;
    }

    updateDateFieldsForCard(card) {
        const select = card.querySelector('.document-type-select');
        const datesRow = card.querySelector('.identification-dates-row');
        const issueField = card.querySelector('.issue-date-field');
        const field = card.querySelector('.expiry-date-field');
        const issueInput = card.querySelector('[name="issue_date[]"]');
        const input = card.querySelector('.expiry-date-input');
        const hintElement = card.querySelector('.expiry-date-hint');

        if (!select || !datesRow || !issueField || !field || !issueInput || !input || !hintElement) return;

        const value = select.value;
        const text = select.options[select.selectedIndex]?.textContent || '';

        const docsWithDates = ['Passport', 'Driving Licence', 'Driver License', 'Driving License'];
        const showDates = docsWithDates.includes(value) || docsWithDates.includes(text);

        if (!showDates) {
            datesRow.style.display = 'none';
            issueField.style.display = 'none';
            field.style.display = 'none';
            issueInput.value = '';
            issueInput.disabled = true;
            input.value = '';
            input.disabled = true;
            hintElement.textContent = '';
        } else {
            datesRow.style.display = 'grid';
            issueField.style.display = 'block';
            field.style.display = 'block';
            issueInput.disabled = false;
            input.disabled = false;
            hintElement.textContent = 'Enter expiry date if applicable';
        }
    }

    cardRequiresDocumentDates(card) {
        const select = card ? card.querySelector('.document-type-select') : null;
        if (!select) return false;

        const value = select.value;
        const text = select.options[select.selectedIndex]?.textContent || '';
        const docsWithDates = ['Passport', 'Driving Licence', 'Driver License', 'Driving License'];

        return docsWithDates.includes(value) || docsWithDates.includes(text);
    }

    setupDocumentTypeHandlers() {
        this.addEventListener(document, 'change', (e) => {
            if (!e.target.classList.contains('document-type-select')) return;
            const card = e.target.closest('.identification-card');
            if (!card) return;
            this.updateIdNumberHint(card);
            this.updateDateFieldsForCard(card);
        });
    }

setupFormHandlers() {
    console.log('🔧 Setting up form handlers');

    let form = document.getElementById('identificationForm');
    if (!form) return;

    // 🔒 DO NOT CLONE THE FORM
    // Just prevent default submit safely
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        e.stopPropagation();
        console.log('❌ Native form submit prevented');
    });

    // Next button
    const nextBtn = document.querySelector(
        '.external-submit-btn[data-form="identificationForm"]'
    );
    if (nextBtn) {
        nextBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            console.log('✅ Next button clicked');
            await this.submitForm(false);
        });
    }

    // Save draft
    document.addEventListener('click', (e) => {
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
        prevBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (window.Router) {
                Router.navigateTo('basic-details');
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

            const count = Math.max(
                data['documentId_type[]']?.length || 0,
                data['id_number[]']?.length || 0,
                data['name[]']?.length || 0,
                this.savedRows.length
            );

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

                    const localStorageData = {
                        documentId_type: data['documentId_type[]']?.[i] || '',
                        id_number: data['id_number[]']?.[i] || '',
                        name: data['name[]']?.[i] || '',
                        issue_date: data['issue_date[]']?.[i] || '',
                        expiry_date: data['expiry_date[]']?.[i] || '',
                        upload_document: data['upload_document[]']?.[i] || '',
                        insufficient_documents: data['insufficient_documents[]']?.[i] || false
                    };

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
        
        const typeSelect = card.querySelector('[name="documentId_type[]"]');
        const idInput = card.querySelector('[name="id_number[]"]');
        const nameInput = card.querySelector('[name="name[]"]');
        const fileInput = card.querySelector('[name="upload_document[]"]');
        const oldFile = card.querySelector('[name^="old_upload_document"]');
        const insufficientCheckbox = card.querySelector('input[name="insufficient_documents[]"]');
        
        const isInsufficient = !!(insufficientCheckbox && insufficientCheckbox.checked);

        if (!typeSelect || !String(typeSelect.value || '').trim()) {
            addError(typeSelect || card, `Document ${i + 1}: Document type is required`);
        }

        if (!idInput || !String(idInput.value || '').trim()) {
            addError(idInput || card, `Document ${i + 1}: ID number is required`);
        }

        if (!nameInput || !String(nameInput.value || '').trim()) {
            addError(nameInput || card, `Document ${i + 1}: Name on document is required`);
        }

        const issueDateInput = card.querySelector('[name="issue_date[]"]');
        const expiryDateInput = card.querySelector('[name="expiry_date[]"]');
        const requiresDates = this.cardRequiresDocumentDates(card);

        if (requiresDates) {
            if (!issueDateInput || !String(issueDateInput.value || '').trim()) {
                addError(issueDateInput || card, `Document ${i + 1}: Issue date is required`);
            }

            if (!expiryDateInput || !String(expiryDateInput.value || '').trim()) {
                addError(expiryDateInput || card, `Document ${i + 1}: Expiry date is required`);
            }

            if (
                issueDateInput &&
                expiryDateInput &&
                issueDateInput.value &&
                expiryDateInput.value
            ) {
                const issueDate = new Date(issueDateInput.value);
                const expiryDate = new Date(expiryDateInput.value);

                if (
                    !Number.isNaN(issueDate.getTime()) &&
                    !Number.isNaN(expiryDate.getTime()) &&
                    expiryDate <= issueDate
                ) {
                    addError(expiryDateInput, `Document ${i + 1}: Expiry date must be after issue date`);
                }
            }
        }

if (isFinalSubmit) {

    const insufficientCheckbox =
        card.querySelector('input[name="insufficient_documents[]"]');

    const isInsufficient =
        !!(insufficientCheckbox && insufficientCheckbox.checked);

    if (!isInsufficient) {

        const fileInput =
            card.querySelector('[name="upload_document[]"]');

        const oldFile =
            card.querySelector('[name^="old_upload_document"]');

        const dbRow = this.savedRows?.[i] || {};

        const hasNewFile =
            fileInput &&
            fileInput.files &&
            fileInput.files.length > 0;

        const hasOldFile =
            oldFile &&
            oldFile.value &&
            oldFile.value !== 'INSUFFICIENT_DOCUMENTS';

        const hasDbFile =
            dbRow.upload_document &&
            dbRow.upload_document !== 'INSUFFICIENT_DOCUMENTS';

        if (!hasNewFile && !hasOldFile && !hasDbFile) {
            const fileBox =
                card.querySelector('[name="upload_document[]"]')?.closest('.form-control')?.querySelector('[data-file-upload]');
            addError(fileBox || fileInput || card, `Document ${i + 1}: Identification document is required`);
        }
    }
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
                    const nextPage = 'contact';
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

