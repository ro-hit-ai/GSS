class EmploymentManager extends TabManager {
    constructor() {
        super(
            'employment',
            'employmentContainer',
            'employmentTemplate',
            'employmentTabs',
            'employmentCount'
        );

        this.savedRows = [];
        this.isSubmitting = false;
        this.isFresher = false;
        this.currentlyEmployed = 'no';
        this.contactEmployer = 'no';
        this.requiredCount = 0;
        this.configuredRequiredCount = 0;
        this.lastNonFresherCount = 1;
        this.docTypeOptions = {
            yes: [
                { value: 'payslip', label: 'Payslip' },
                { value: 'appointment_letter', label: 'Appointment Letter' },
                { value: 'resignation_letter', label: 'Resignation Letter' }
            ],
            no: [
                { value: 'experience_letter', label: 'Experience Letter' },
                { value: 'service_letter', label: 'Service Letter' },
                { value: 'relieving_letter', label: 'Relieving Letter' }
            ]
        };
    }

    async init() {
        console.log('💼 EmploymentManager.init() called');
        this.loadPageData();
        await super.init();

        try {
            var req = window.CANDIDATE_CASE_CONFIG && window.CANDIDATE_CASE_CONFIG.required_counts
                ? parseInt(window.CANDIDATE_CASE_CONFIG.required_counts.employment || '0', 10) || 0
                : 0;
            this.configuredRequiredCount = req > 0 ? req : 0;
            this.requiredCount = this.configuredRequiredCount;

            var initialCount = this.isFresher
                ? 1
                : this.configuredRequiredCount;

            if (initialCount > 0 && this.countSelect) {
                this.countSelect.value = String(initialCount);
                this.handleCountChange();
            }

            if (this.countSelect) {
                this.countSelect.disabled = true;
                try {
                    var wrap = this.countSelect.closest ? this.countSelect.closest('.form-control') : null;
                    if (wrap) {
                        wrap.style.display = 'none';
                        var countWrap = wrap.closest ? wrap.closest('.employment-count') : null;
                        if (countWrap) {
                            countWrap.style.display = 'none';
                        }
                    } else {
                        this.countSelect.style.display = 'none';
                    }
                } catch (e) {
                }
            }
        } catch (e) {
        }

        this.setupFormHandlers();
        this.setupFileHandlers();
        this.setupRelievingDateHandlers();
        this.setupInsufficientDocsHandlers();
        this.loadFromLocalStorage();
        this.lastNonFresherCount = Math.max(this.cards.length, this.configuredRequiredCount || 0, 1);
        this.setupRadioHandlers();
        this.applyFresherUI(this.isFresher);

        console.log('✅ Employment module initialized successfully');
        console.log(`📊 Cards loaded: ${this.cards.length}, Data rows: ${this.savedRows.length}, Fresher: ${this.isFresher}, Currently Employed: ${this.currentlyEmployed}, Contact Employer: ${this.contactEmployer}`);

        return this;
    }

    getApiEndpoint() {
        return `${window.APP_BASE_URL}/api/candidate/store_employment.php`;
    }

    getTabLabel(index) {
        return `Employer ${index + 1}`;
    }

    loadPageData() {
        const el = document.getElementById('employmentData');
        if (!el) {
            console.warn('⚠️ Employment data element not found');
            this.savedRows = [];
            this.isFresher = false;
            this.currentlyEmployed = 'no';
            this.contactEmployer = 'no';
            return;
        }

        try {
            this.savedRows = JSON.parse(el.dataset.rows || '[]');
            this.isFresher = el.dataset.isFresher === 'true';
            if (this.savedRows.length > 0 && this.savedRows[0]) {
                this.currentlyEmployed = this.savedRows[0].currently_employed || 'no';
                this.contactEmployer = this.savedRows[0].contact_employer || 'no';
            }
            
            console.log(`📥 Loaded ${this.savedRows.length} employment records, Fresher: ${this.isFresher}, Currently Employed: ${this.currentlyEmployed}, Contact Employer: ${this.contactEmployer}`);
        } catch (e) {
            console.error('❌ Failed to parse employment data', e);
            this.savedRows = [];
            this.isFresher = false;
            this.currentlyEmployed = 'no';
            this.contactEmployer = 'no';
        }
    }
    
    populateCard(card, data = {}, index) {
        console.log(` EmploymentManager.populateCard() for card ${index}`, data);
        
        // Set index
        const idx = this.findInput(card, 'employment_index[]');
        if (idx) idx.value = index + 1;
        
        // Set record ID if exists
        if (data.id) {
            this.findOrCreateInput(card, `id[${index}]`, 'hidden').value = data.id;
            console.log(`   Set record ID: ${data.id}`);
        }

        // Handle employment document
        const employmentDoc = data.employment_doc || data.employment_doc_path;
        if (employmentDoc) {
            let fileName = employmentDoc;
            // Extract just the filename from path
            if (fileName.includes('uploads/employment/')) {
                fileName = fileName.split('uploads/employment/').pop();
            } else if (fileName.includes('/')) {
                fileName = fileName.split('/').pop();
            }
            
            console.log(`   Extracted file name: ${fileName}`);
            
            // Store old file reference
            this.findOrCreateInput(card, `old_employment_doc[${index}]`, 'hidden').value = fileName;
            
            // Show file info if it's a real file
            if (fileName !== 'INSUFFICIENT_DOCUMENTS') {
                const input = card.querySelector('input[name="employment_doc[]"]');
                const box = this.getUploadBoxFromInput(input);
                const base = window.APP_BASE_URL || '';
                const url = `${base}/uploads/employment/${fileName}`;
                this.setUploadBox(box, fileName, url, false);
                console.log(`   Added employment document preview: ${fileName}`);
            }
        }

        // Populate form fields
        const fieldMap = {
            'employer_name[]': data.employer_name,
            'job_title[]': data.job_title,
            'employee_id[]': data.employee_id,
            'reason_leaving[]': data.reason_leaving,
            'hr_manager_name[]': data.hr_manager_name,
            'hr_manager_phone[]': data.hr_manager_phone,
            'hr_manager_email[]': data.hr_manager_email,
            'manager_name[]': data.manager_name,
            'manager_phone[]': data.manager_phone,
            'manager_email[]': data.manager_email
        };

        Object.entries(fieldMap).forEach(([name, value]) => {
            const el = card.querySelector(`[name="${name}"]`);
            if (el && value !== null && value !== undefined) {
                el.value = value;
            }
        });
        const employmentDocType = card.querySelector('[name="employment_doc_type[]"]');
        if (employmentDocType) {
            employmentDocType.value = data.employment_doc_type || "";
        }

        
        // Set dates
        if (data.joining_date) {
            const joiningInput = card.querySelector('[name="joining_date[]"]');
            if (joiningInput) {
                // Handle both YYYY-MM and full date formats
                if (data.joining_date.match(/^\d{4}-\d{2}$/)) {
                    joiningInput.value = `${data.joining_date}-01`;
                } else {
                    joiningInput.value = data.joining_date.substring(0, 10);
                }
            }
        }

        if (data.relieving_date) {
            const relievingInput = card.querySelector('[name="relieving_date[]"]');
            if (relievingInput) {
                if (data.relieving_date.match(/^\d{4}-\d{2}$/)) {
                    relievingInput.value = `${data.relieving_date}-01`;
                } else {
                    relievingInput.value = data.relieving_date.substring(0, 10);
                }
            }
        }

        // Handle insufficient documents checkbox
        const insufficientCheckbox = card.querySelector('input[name="insufficient_employment_docs[]"]');
        if (insufficientCheckbox) {
            const isInsufficient = employmentDoc === 'INSUFFICIENT_DOCUMENTS' ||
                                 data.insufficient_documents == 1 || 
                                 data.insufficient_documents === true;
            
            insufficientCheckbox.checked = isInsufficient;
            this.toggleEmploymentFileInput(card, isInsufficient);
            console.log(`   Set insufficient_employment_docs for card ${index}: ${isInsufficient}`);
        }

        // Handle radio buttons for first card only
        const radioBlock = card.querySelector('.first-employer-fields');
        if (radioBlock) {
            if (index === 0) {
                radioBlock.style.display = 'block';
                console.log('   📻 Showing radio block for first card');
                
                // Set radio values
                const fresherValue = data.is_fresher || (this.isFresher ? 'yes' : 'no');
                const currentlyEmployedValue = data.currently_employed || this.currentlyEmployed || 'no';
                const contactEmployerValue = data.contact_employer || this.contactEmployer || 'no';
                
                console.log(`   Radio values to set: is_fresher=${fresherValue}, currently_employed=${currentlyEmployedValue}, contact_employer=${contactEmployerValue}`);
                
                // Set radio buttons
                this.setRadio(card, 'is_fresher[0]', fresherValue);
                this.isFresher = fresherValue === 'yes';
                
                this.setRadio(card, 'currently_employed[0]', currentlyEmployedValue);
                this.currentlyEmployed = currentlyEmployedValue;
                
                this.setRadio(card, 'contact_employer[0]', contactEmployerValue);
                this.contactEmployer = contactEmployerValue;
                
                // Update UI based on current employment status
                this.updateContactEmployer(card);
            } else {
                radioBlock.style.display = 'none';
                console.log('   Hiding radio block for card ' + index);
                this.updateEmploymentProofOptions(card, "no");
            }
        }

        console.log(`✅ Card ${index} populated successfully`);
    }

    toggleEmploymentFileInput(card, isInsufficient) {
        const employmentFile = card.querySelector('input[name="employment_doc[]"]');
        
        if (employmentFile) {
            employmentFile.disabled = isInsufficient;
            employmentFile.required = !isInsufficient;
            if (isInsufficient) {
                employmentFile.value = '';
                this.clearUploadBox(this.getUploadBoxFromInput(employmentFile));
            }
        }
    }

    setRadio(card, name, value) {
        console.log(`🎯 setRadio: ${name} = "${value}"`);
        
        setTimeout(() => {
            const radios = card.querySelectorAll(`input[name="${name}"]`);
            
            if (radios.length === 0) {
                console.error(`❌ No radio buttons found with name: ${name} in card`);
                return;
            }
            
            radios.forEach(radio => {
                radio.checked = radio.value === value;
            });
            
            // Trigger change event
            radios.forEach(radio => {
                if (radio.value === value) {
                    const event = new Event('change', { bubbles: true });
                    radio.dispatchEvent(event);
                }
            });
            
            console.log(`✅ Radio ${name} set to: ${value}`);
        }, 50);
    }

    setupInsufficientDocsHandlers() {
        console.log('🔧 Setting up insufficient documents handlers');
        
        document.addEventListener('change', (e) => {
            if (e.target.matches('input[name="insufficient_employment_docs[]"]')) {
                const checkbox = e.target;
                const card = checkbox.closest('.employment-card');
                if (card) {
                    const cardIndex = card.dataset.cardIndex || 'unknown';
                    console.log(`🔘 Insufficient employment docs checkbox changed in card ${cardIndex}: ${checkbox.checked}`);
                    this.toggleEmploymentFileInput(card, checkbox.checked);
                }
            }
        });
    }

    setupFormHandlers() {
        console.log('🔧 Setting up employment form handlers');
        
        const form = document.getElementById('employmentForm');
        if (!form) {
            console.error('❌ Employment form not found');
            return;
        }

        // Prevent default form submission
        form.onsubmit = (e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            console.log('❌ Employment form submission prevented');
            return false;
        };

        // Handle Next button
        const nextBtn = document.querySelector('.external-submit-btn[data-form="employmentForm"]');
        if (nextBtn) {
            // Remove existing listeners
            const newNextBtn = nextBtn.cloneNode(true);
            nextBtn.parentNode.replaceChild(newNextBtn, nextBtn);
            
            newNextBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                console.log('✅ Next button clicked - submitting employment form');
                await this.submitForm(false);
            });
        }

        // Handle Previous button
        const prevBtn = document.querySelector('.prev-btn');
        if (prevBtn) {
            // Remove existing listeners
            const newPrevBtn = prevBtn.cloneNode(true);
            prevBtn.parentNode.replaceChild(newPrevBtn, prevBtn);
            
            newPrevBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                console.log('⬅️ Previous button clicked - navigating to education');
                if (window.Router && window.Router.navigateTo) {
                    window.Router.navigateTo('education');
                }
            });
        }

        // Handle Save Draft button
        document.addEventListener('click', (e) => {
            const draftBtn = e.target.closest('.save-draft-btn[data-page="employment"]');
            if (draftBtn) {
                e.preventDefault();
                e.stopImmediatePropagation();
                console.log('💾 Save draft button clicked');
                this.saveDraft();
            }
        });
        
        console.log('✅ Employment form handlers setup complete');
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
            if (e.target.matches('input[name="employment_doc[]"]')) {
                const input = e.target;
                const card = input.closest('.employment-card');
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
                    console.log(`📄 Employment file selected in card:`, input.files[0].name);
                    const insufficientCheckbox =
                        card.querySelector('input[name="insufficient_employment_docs[]"]');
                    if (insufficientCheckbox) {
                        insufficientCheckbox.checked = false;
                        this.toggleEmploymentFileInput(card, false);
                    }

                    const oldEmploymentDoc =
                        card.querySelector('[name^="old_employment_doc"]');
                    if (oldEmploymentDoc && oldEmploymentDoc.value === 'INSUFFICIENT_DOCUMENTS') {
                        oldEmploymentDoc.value = '';
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

    setupRelievingDateHandlers() {
        document.addEventListener('input', (e) => {
            if (!e.target.matches('input[name="relieving_date[]"]')) return;
            if (e.target.type !== 'text') return;

            e.target.value = this.formatDdMmYyyyInput(e.target.value);
        });
    }

    formatDdMmYyyyInput(value) {
        const digits = String(value || '').replace(/\D/g, '').slice(0, 8);
        const parts = [];

        if (digits.length > 0) {
            parts.push(digits.slice(0, 2));
        }
        if (digits.length > 2) {
            parts.push(digits.slice(2, 4));
        }
        if (digits.length > 4) {
            parts.push(digits.slice(4, 8));
        }

        return parts.join('/');
    }

    setupRadioHandlers() {
        console.log('🔧 Setting up radio handlers');
        
        document.addEventListener('change', e => {
            if (e.target.name === 'is_fresher[0]') {
                this.isFresher = e.target.value === 'yes';
                console.log(`🔄 Fresher changed to: ${this.isFresher}`);
                this.applyFresherUI(this.isFresher);
            }

            if (e.target.name === 'currently_employed[0]') {
                this.currentlyEmployed = e.target.value;
                console.log(`🔄 Currently Employed changed to: ${this.currentlyEmployed}`);
                const firstCard = this.cards[0];
                if (firstCard) {
                    this.updateContactEmployer(firstCard);
                }
            }
            
            if (e.target.name === 'contact_employer[0]') {
                this.contactEmployer = e.target.value;
                console.log(`🔄 Contact Employer changed to: ${this.contactEmployer}`);
            }
        });
    }

    updateContactEmployer(card) {
        if (!card) return;

        const contactEmployerField = card.querySelector(".contact-employer-field");
        const relievingDateInput = card.querySelector('[name="relieving_date[]"]');

        if (!contactEmployerField) return;

        if (this.currentlyEmployed === "yes") {
            contactEmployerField.style.display = "block";
            if (relievingDateInput) {
                relievingDateInput.type = "text";
                relievingDateInput.value = "";
                relievingDateInput.disabled = false;
                relievingDateInput.required = false;
                relievingDateInput.placeholder = "DD/MM/YYYY";
                relievingDateInput.inputMode = "numeric";
                relievingDateInput.pattern = "\\d{2}/\\d{2}/\\d{4}";
            }
        } else {
            contactEmployerField.style.display = "none";
            if (relievingDateInput) {
                relievingDateInput.type = "date";
                relievingDateInput.disabled = false;
                relievingDateInput.required = true;
                relievingDateInput.placeholder = "";
                relievingDateInput.removeAttribute("inputmode");
                relievingDateInput.removeAttribute("pattern");
            }
        }

        this.updateEmploymentProofOptions(card, this.currentlyEmployed);
    }

    updateEmploymentProofOptions(card, employmentStatus) {
        if (!card) return;

        const docTypeSelect = card.querySelector('[name="employment_doc_type[]"]');
        if (!docTypeSelect) return;

        const normalizedStatus = employmentStatus === "yes" ? "yes" : "no";
        const currentValue = docTypeSelect.value;
        const options = this.docTypeOptions[normalizedStatus] || [];

        docTypeSelect.innerHTML = '<option value="">Select document type</option>';
        options.forEach((option) => {
            const opt = document.createElement("option");
            opt.value = option.value;
            opt.textContent = option.label;
            docTypeSelect.appendChild(opt);
        });

        if (options.some((option) => option.value === currentValue)) {
            docTypeSelect.value = currentValue;
        }
    }

    applyFresherUI(isFresher) {
        this.isFresher = isFresher;
        console.log(`🎯 Applying Fresher UI: ${isFresher}`);

        if (this.countSelect) {
            if (isFresher) {
                this.lastNonFresherCount = Math.max(
                    this.lastNonFresherCount,
                    this.cards.length,
                    this.configuredRequiredCount || 0,
                    this.requiredCount || 0,
                    1
                );
                this.countSelect.disabled = true;
            } else {
                var targetCount = Math.max(
                    this.lastNonFresherCount,
                    this.configuredRequiredCount || 0,
                    this.requiredCount || 0,
                    1
                );

                this.countSelect.value = String(targetCount);
                this.countSelect.disabled = true;

                if (this.cards.length !== targetCount) {
                    this.handleCountChange();
                }
            }
        }

        if (this.tabsContainer) {
            this.tabsContainer.style.display = isFresher ? "none" : "";
            const tabs = this.tabsContainer.querySelectorAll(".employment-tab");
            tabs.forEach((tab, i) => {
                if (isFresher && i > 0) {
                    tab.style.pointerEvents = "none";
                    tab.style.opacity = "0.4";
                    tab.style.cursor = "not-allowed";
                } else {
                    tab.style.pointerEvents = "";
                    tab.style.opacity = "";
                    tab.style.cursor = "";
                }
            });
        }

        const fresherMessage = document.getElementById("employmentFresherMessage");
        if (fresherMessage) {
            fresherMessage.style.display = isFresher ? "block" : "none";
        }

        this.cards.forEach((card, index) => {
            if (!card) return;

            const header = card.querySelector(".employment-card-header");
            const body = card.querySelector(".employment-card-body");
            const radioBlock = card.querySelector(".first-employer-fields");

            if (index === 0) {
                if (radioBlock) {
                    radioBlock.style.display = "block";
                }
                if (header) {
                    header.style.display = isFresher ? "none" : "";
                }
                if (body) {
                    body.style.display = isFresher ? "none" : "";
                }
            } else {
                card.style.display = isFresher ? "none" : (index === this.currentTab ? "block" : "none");
            }
        });

        if (isFresher) {
            this.showTab(0);
        } else {
            this.showTab(Math.min(this.currentTab, this.cards.length - 1));
        }
    }

    validateForm(isFinalSubmit = false) {
        console.log(`Validating employment form (isFinalSubmit: ${isFinalSubmit}, isFresher: ${this.isFresher})`);

        const form = document.getElementById('employmentForm');
        if (window.CandidateNotify && form) {
            window.CandidateNotify.clearValidation(form);
        }
        if (this.isFresher) {
            console.log("Skipping employment validation because fresher is selected");
            return true;
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
        
        const requiredEmploymentCount = Math.max(
            this.cards.length,
            this.configuredRequiredCount || 0,
            this.requiredCount || 0,
            1
        );

        for (let i = 0; i < requiredEmploymentCount; i++) {
            const card = this.cards[i];
            if (!card) continue;
            
            // Skip validation for extra cards if fresher
            if (this.isFresher && i > 0) {
                continue;
            }
            
            // Skip validation for first card if fresher
            if (this.isFresher && i === 0) {
                continue;
            }

            // Validate non-fresher cards
            if (!this.isFresher) {
                const requiredFields = [
                    { selector: '[name="employer_name[]"]', label: 'Employer Name' },
                    { selector: '[name="job_title[]"]', label: 'Job Title' },
                    { selector: '[name="employee_id[]"]', label: 'Employee ID' },
                    { selector: '[name="joining_date[]"]', label: 'Start Date' },
                    { selector: '[name="reason_leaving[]"]', label: 'Reason for Leaving' }
                ];

                const shouldRequireRelievingDate = i === 0
                    ? this.currentlyEmployed === 'no'
                    : true;

                if (shouldRequireRelievingDate) {
                    requiredFields.push({ 
                        selector: '[name="relieving_date[]"]', 
                        label: 'Relieving Date' 
                    });
                }

                // Check required fields
                requiredFields.forEach(field => {
                    const input = card.querySelector(field.selector);
                    if (input && !input.value.trim() && !input.disabled) {
                        addError(input, `Employer ${i + 1}: ${field.label} is required`);
                    }
                });

// Validate documents for final submission
if (isFinalSubmit) {

    const insufficientCheckbox =
        card.querySelector('input[name="insufficient_employment_docs[]"]');

        const employmentDocType =
            card.querySelector('[name="employment_doc_type[]"]');
        if (!employmentDocType || !employmentDocType.value.trim()) {
            addError(employmentDocType || card, `Employer ${i + 1}: Employment document type is required`);
        }

    const isInsufficient =
        !!(insufficientCheckbox && insufficientCheckbox.checked);

    if (!isInsufficient) {

        const employmentFile =
            card.querySelector('[name="employment_doc[]"]');

        const oldEmploymentDoc =
            card.querySelector('[name^="old_employment_doc"]');

        const dbRow = this.savedRows?.[i] || {};

        const hasNewFile =
            employmentFile &&
            employmentFile.files &&
            employmentFile.files.length > 0;

        const hasOldFile =
            oldEmploymentDoc &&
            oldEmploymentDoc.value &&
            oldEmploymentDoc.value !== 'INSUFFICIENT_DOCUMENTS';

        const hasDbFile =
            dbRow.employment_doc &&
            dbRow.employment_doc !== 'INSUFFICIENT_DOCUMENTS';

        if (!hasNewFile && !hasOldFile && !hasDbFile) {
            const fileBox =
                card.querySelector('[name="employment_doc[]"]')?.closest('.form-control')?.querySelector('[data-file-upload]');
            addError(fileBox || card.querySelector('[name="employment_doc[]"]') || card, `Employer ${i + 1}: Employment proof is required`);
        }
    }
}


                // Validate dates
                const joiningInput = card.querySelector('[name="joining_date[]"]');
                const relievingInput = card.querySelector('[name="relieving_date[]"]');
                
                if (
                    shouldRequireRelievingDate &&
                    joiningInput &&
                    joiningInput.value &&
                    relievingInput &&
                    relievingInput.value &&
                    !relievingInput.disabled
                ) {
                    const joiningDate = new Date(joiningInput.value);
                    const relievingDate = new Date(relievingInput.value);
                    
                    if (relievingDate <= joiningDate) {
                        addError(relievingInput, `Employer ${i + 1}: Relieving date must be after joining date`);
                    }
                }
            }
        }
        
        if (errors.length > 0) {
            console.warn('Employment validation errors:', errors.map((error) => error.message || error));
            if (window.CandidateNotify && form) {
                window.CandidateNotify.validation({
                    form,
                    title: 'Employment details need attention',
                    message: `Please fix ${errors.length} issue${errors.length === 1 ? '' : 's'} before continuing.`,
                    errors
                });
            } else if (window.Router && typeof window.Router.showNotification === 'function') {
                window.Router.showNotification('Please fix the highlighted employment errors before proceeding.', 'warning');
            } else if (typeof window.showAlert === 'function') {
                window.showAlert({ type: 'warning', message: 'Please fix the highlighted employment errors before proceeding.' });
            }
        }

        console.log(`✅ Employment form validation ${isValid ? 'passed' : 'failed'}`);
        return isValid;
    }

    async saveDraft() {
        if (this.isSubmitting) return;
        this.isSubmitting = true;

        try {
            const form = document.getElementById('employmentForm');
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
                this.showNotification('✅ Employment Draft saved successfully!');
                localStorage.removeItem('employment_draft');
            } else {
                this.showNotification((data.message || 'Save failed'), true);
            }

        } catch (err) {
            console.error('❌ Save draft error:', err);
            this.showNotification('❌ Network / Server error', true);
        } finally {
            this.isSubmitting = false;
        }
    }

    loadFromLocalStorage() {
        try {
            const raw = localStorage.getItem('employment_draft');
            if (!raw) {
                console.log('📭 No employment draft found in localStorage');
                return;
            }

            const data = JSON.parse(raw);
            console.log('📥 Loading employment draft from localStorage');

            const count = Math.max(
                data['employer_name[]']?.length || 0,
                data['job_title[]']?.length || 0,
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
                        employer_name: data['employer_name[]']?.[i],
                        job_title: data['job_title[]']?.[i],
                        employee_id: data['employee_id[]']?.[i],
                        employment_doc_type: data["employment_doc_type[]"]?.[i],
                        reason_leaving: data['reason_leaving[]']?.[i],
                        hr_manager_name: data['hr_manager_name[]']?.[i],
                        hr_manager_phone: data['hr_manager_phone[]']?.[i],
                        hr_manager_email: data['hr_manager_email[]']?.[i],
                        manager_name: data['manager_name[]']?.[i],
                        manager_phone: data['manager_phone[]']?.[i],
                        manager_email: data['manager_email[]']?.[i],
                        joining_date: data['joining_date[]']?.[i],
                        relieving_date: data['relieving_date[]']?.[i],
                        is_fresher: data['is_fresher[0]']?.[0],
                        currently_employed: data['currently_employed[0]']?.[0],
                        contact_employer: data['contact_employer[0]']?.[0],
                        insufficient_documents: data['insufficient_employment_docs[]']?.[i] || false
                    };

                    this.populateCard(card, localStorageData, i);
                }
            }

            console.log('✅ Employment draft loaded from localStorage');
        } catch (error) {
            console.error('❌ Error loading employment draft from localStorage:', error);
        }
    }

    async submitForm(isDraft = false) {
        console.log(`🚀 Employment submit initiated (draft: ${isDraft}, fresher: ${this.isFresher})`);

        if (this.isSubmitting) {
            console.log('⏳ Employment submit already in progress');
            return;
        }

        if (!isDraft && !this.validateForm(true)) {
            console.log("❌ Validation failed");
            return;
        }

        if (!isDraft && this.isFresher) {
            if (window.Forms && typeof window.Forms.clearDraft === "function") {
                window.Forms.clearDraft("employment");
            }

            if (window.Router) {
                if (window.Router.markCompleted) {
                    window.Router.markCompleted("employment");
                }
                if (window.Router.navigateTo) {
                    window.Router.navigateTo("reference");
                    return;
                }
            }

            window.location.href = `${window.APP_BASE_URL}/modules/candidate/reference.php`;
            return;
        }

        this.isSubmitting = true;
        console.log('📤 Submitting employment form...');

        try {
            const form = document.getElementById('employmentForm');
            if (!form) {
                throw new Error('Employment form not found');
            }

            const formData = new FormData(form);
            formData.set('draft', isDraft ? '1' : '0');

            console.log('📦 Form data prepared:', {
                draft: isDraft,
                fresher: this.isFresher,
                currentlyEmployed: this.currentlyEmployed,
                contactEmployer: this.contactEmployer,
                cards: this.cards.length
            });

            const response = await fetch(this.getApiEndpoint(), {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const data = await response.json();
            console.log('Server response:', data);

            if (data.success) {
                if (!isDraft) {
                    // Use Router for navigation
                    if (window.Router) {
                        if (window.Router.markCompleted) {
                            window.Router.markCompleted('employment');
                        }
                        
                        // Navigate to next page using Router
                        if (window.Router.navigateTo) {
                            window.Router.navigateTo('reference');
                        } else {
                            window.location.href = `${window.APP_BASE_URL}/modules/candidate/reference.php`;
                        }
                    } else {
                        window.location.href = `${window.APP_BASE_URL}/modules/candidate/reference.php`;
                    }
                    
                    // Clear draft
                    if (window.Forms && typeof window.Forms.clearDraft === 'function') {
                        window.Forms.clearDraft('employment');
                    }
                }
                
                // Show success message
                this.showNotification(data.message || '✅ Employment details saved successfully!');
            } else {
                const errorMessage = data.message || 'Save failed';
                console.error('Employment save failed:', errorMessage);
                this.showNotification('❌ ' + errorMessage, true);
            }
        } catch (err) {
            console.error('Employment submit error:', err);
            this.showNotification('❌ Error: ' + err.message, true);
        } finally {
            this.isSubmitting = false;
            console.log('Employment submit completed');
        }
    }

    showNotification(message, isError = false) {
        if (window.CandidateNotify) {
            window.CandidateNotify.show({
                type: isError ? 'error' : 'success',
                title: isError ? 'Employment details not saved' : 'Employment details saved',
                message: String(message || '').replace(/^[^\w]+/, ''),
                sticky: !!isError
            });
            return;
        }

        if (window.Router && typeof window.Router.showNotification === 'function') {
            window.Router.showNotification(message, isError ? 'error' : 'success');
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
        console.log('🧹 Cleaning up EmploymentManager');
        super.cleanup();
        
        // Remove event listeners
        const form = document.getElementById('employmentForm');
        if (form) {
            form.replaceWith(form.cloneNode(true));
        }
    }
}

if (typeof window !== 'undefined') {
    window.EmploymentManager = EmploymentManager;

    window.Employment = {
        onPageLoad: async () => {
            console.log('💼 Employment.onPageLoad() called');
            
            try {
                if (!window.employmentManager) {
                    console.log('🆕 Creating new EmploymentManager instance');
                    window.employmentManager = new EmploymentManager();
                }
                
                await window.employmentManager.init();
                console.log('✅ Employment page loaded successfully');
            } catch (error) {
                console.error('❌ Error in Employment.onPageLoad:', error);
            }
        },
        
        cleanup: () => {
            console.log('🧹 Cleaning up Employment module');
            if (window.employmentManager) {
                window.employmentManager.cleanup();
                window.employmentManager = null;
            }
        }
    };
}

console.log('✅ Employment.js module loaded');
