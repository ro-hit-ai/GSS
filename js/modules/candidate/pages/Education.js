class EducationManager extends TabManager {
    constructor() {
        super(
            'education',
            'educationContainer',
            'educationTemplate',
            'educationTabs',
            'educationCount'
        );

        this.savedRows = [];
        this.isSubmitting = false;
    }

    async init() {
        console.log('📚 EducationManager.init() called');
        this.loadPageData();
        await super.init();

        this.requiredCount = 0;

        try {
            var req = window.CANDIDATE_CASE_CONFIG && window.CANDIDATE_CASE_CONFIG.required_counts
                ? parseInt(window.CANDIDATE_CASE_CONFIG.required_counts.education || '0', 10) || 0
                : 0;
            if (req > 0 && this.countSelect) {
                this.requiredCount = req;
                this.countSelect.value = String(req);
                this.handleCountChange();
            }

            if (this.countSelect) {
                this.countSelect.disabled = true;
                try {
                    var wrap = this.countSelect.closest ? this.countSelect.closest('.form-control') : null;
                    if (wrap) {
                        wrap.style.display = 'none';
                        var countWrap = wrap.closest ? wrap.closest('.education-count') : null;
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

        await this.initCards();
        this.setupFormHandlers();
        this.loadFromLocalStorage();
        this.setupInsufficientDocsHandlers();
        this.setupFileHandlers();

        console.log('✅ Education module initialized successfully');
        console.log(`📊 Cards loaded: ${this.cards.length}, Data rows: ${this.savedRows.length}`);

        return this;
    }

    getApiEndpoint() {
        return `${window.APP_BASE_URL}/api/candidate/store_education.php`;
    }

    getTabLabel(index) {
        return `Education ${index + 1}`;
    }

    loadPageData() {
        const dataEl = document.getElementById('educationData');
        if (!dataEl) {
            console.warn('⚠️ Education data element not found');
            this.savedRows = [];
            return;
        }

        try {
            this.savedRows = JSON.parse(dataEl.dataset.rows || '[]');
            console.log(`📥 Loaded ${this.savedRows.length} education records from DB`);
        } catch (e) {
            console.error('❌ Failed to parse education data', e);
            this.savedRows = [];
        }
    }

    async initCards() {
        if (!this.savedRows || this.savedRows.length === 0) {
            console.log('📭 No saved education data to populate');
            return;
        }

        console.log(`🔄 Initializing cards for ${this.savedRows.length} records`);

        var maxPopulate = this.cards.length;
        if (this.requiredCount && this.requiredCount > 0) {
            maxPopulate = Math.min(maxPopulate, this.requiredCount);
        }

        for (let i = 0; i < maxPopulate; i++) {
            if (this.cards[i] && this.savedRows[i]) {
                console.log(`📝 Populating card ${i} with data:`, this.savedRows[i]);
                this.populateCard(this.cards[i], this.savedRows[i], i);
            }
        }
    }

    populateCard(card, data = {}, index) {
        console.log(` EducationManager.populateCard() for card ${index}`, data);
        
        const idx = this.findInput(card, 'education_index[]');
        if (idx) idx.value = index + 1;

        if (data.id) {
            this.findOrCreateInput(card, `id[${index}]`, 'hidden').value = data.id;
            console.log(`   Set record ID: ${data.id}`);
        }

        // Handle marksheet file
        if (data.marksheet_file) {
            const marksheetInput = this.findOrCreateInput(card, `old_marksheet_file[${index}]`, 'hidden');
            marksheetInput.value = data.marksheet_file;

            if (data.marksheet_file !== 'INSUFFICIENT_DOCUMENTS') {
                const input = card.querySelector('input[name="marksheet_file[]"]');
                const box = this.getUploadBoxFromInput(input);
                const base = window.APP_BASE_URL || '';
                const url = `${base}/uploads/education/${data.marksheet_file}`;
                this.setUploadBox(box, data.marksheet_file, url, false);
                console.log(`   Added marksheet: ${data.marksheet_file}`);
            }
        }

        // Handle degree file
        if (data.degree_file) {
            const degreeInput = this.findOrCreateInput(card, `old_degree_file[${index}]`, 'hidden');
            degreeInput.value = data.degree_file;

            if (data.degree_file !== 'INSUFFICIENT_DOCUMENTS') {
                const input = card.querySelector('input[name="degree_file[]"]');
                const box = this.getUploadBoxFromInput(input);
                const base = window.APP_BASE_URL || '';
                const url = `${base}/uploads/education/${data.degree_file}`;
                this.setUploadBox(box, data.degree_file, url, false);
                console.log(`   Added degree: ${data.degree_file}`);
            }
        }

        // Populate form fields
        const fieldMap = {
            'qualification[]': data.qualification,
            'college_name[]': data.college_name,
            'university_board[]': data.university_board,
            'roll_number[]': data.roll_number,
            'college_website[]': data.college_website,
            'college_address[]': data.college_address
        };

        Object.entries(fieldMap).forEach(([name, value]) => {
            const el = card.querySelector(`[name="${name}"]`);
            if (el && value !== null && value !== undefined) {
                el.value = value;
                console.log(`   Set ${name}: ${value}`);
            }
        });

        // Set date fields
        if (data.year_from) {
            const yf = card.querySelector('[name="year_from[]"]');
            if (yf) {
                const date = new Date(data.year_from);
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                yf.value = `${year}-${month}`;
                console.log(`   Set year_from: ${yf.value}`);
            }
        }

        if (data.year_to) {
            const yt = card.querySelector('[name="year_to[]"]');
            if (yt) {
                const date = new Date(data.year_to);
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                yt.value = `${year}-${month}`;
                console.log(`   Set year_to: ${yt.value}`);
            }
        }

        // Handle insufficient documents checkbox
        const insufficientCheckbox = card.querySelector('input[name="insufficient_education_docs[]"]');
        if (insufficientCheckbox) {
           const hasMarksheet = data.marksheet_file &&data.marksheet_file !== 'INSUFFICIENT_DOCUMENTS';
           const hasDegree = data.degree_file && data.degree_file !== 'INSUFFICIENT_DOCUMENTS';
           const isInsufficient = data.insufficient_documents == 1 ||  data.insufficient_documents === true ||(!hasMarksheet && !hasDegree);
            insufficientCheckbox.checked = isInsufficient;
            this.toggleFileInputs(card, isInsufficient);
            console.log(`   Set insufficient_education_docs for card ${index}: ${isInsufficient}`);
        }

        console.log(`✅ Card ${index} populated successfully`);
    }

    toggleFileInputs(card, isInsufficient) {
        const marksheetFile = card.querySelector('input[name="marksheet_file[]"]');
        const degreeFile = card.querySelector('input[name="degree_file[]"]');
        
        if (marksheetFile) {
            marksheetFile.disabled = isInsufficient;
            marksheetFile.required = !isInsufficient;
            if (isInsufficient) {
                marksheetFile.value = '';
                this.clearUploadBox(this.getUploadBoxFromInput(marksheetFile));
            }
        }
        
        if (degreeFile) {
            degreeFile.disabled = isInsufficient;
            degreeFile.required = !isInsufficient;
            if (isInsufficient) {
                degreeFile.value = '';
                this.clearUploadBox(this.getUploadBoxFromInput(degreeFile));
            }
        }
    }

    setupInsufficientDocsHandlers() {
        console.log('🔧 Setting up insufficient documents handlers');
        
        document.addEventListener('change', (e) => {
            if (e.target.matches('input[name="insufficient_education_docs[]"]')) {
                const checkbox = e.target;
                const card = checkbox.closest('.education-card');
                if (card) {
                    const cardIndex = card.dataset.cardIndex || 'unknown';
                    console.log(`🔘 Insufficient education docs checkbox changed in card ${cardIndex}: ${checkbox.checked}`);
                    this.toggleFileInputs(card, checkbox.checked);
                }
            }
        });
    }

setupFileHandlers() {
    console.log('🔧 Setting up education file handlers');

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
        if (
            e.target.matches('input[name="marksheet_file[]"]') ||
            e.target.matches('input[name="degree_file[]"]')
        ) {
            const input = e.target;
            const card = input.closest('.education-card');
            const file = input.files && input.files[0] ? input.files[0] : null;
            const box = this.getUploadBoxFromInput(input);
            if (box) {
                const errEl = box.querySelector('[data-file-error]');
                if (errEl) errEl.textContent = '';
            }

            const allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            const validation = this.validateUploadFile(file, allowed, 10 * 1024 * 1024);
            if (file && !validation.ok) {
                alert(validation.message);
                input.value = '';
                this.clearUploadBox(box);
                if (box) {
                    const errEl = box.querySelector('[data-file-error]');
                    if (errEl) errEl.textContent = validation.message;
                }
            }

            if (!card || input.files.length === 0) return;

                console.log(`📄 File selected: ${input.files[0].name}`);

                // 🔥 FORCE CLEAR INSUFFICIENT STATE
                const insufficientCheckbox =
                    card.querySelector('input[name="insufficient_education_docs[]"]');

                if (insufficientCheckbox) {
                    insufficientCheckbox.checked = false;
                    this.toggleFileInputs(card, false);
                }

            // 🔥 CLEAR DB "INSUFFICIENT_DOCUMENTS" FALLBACK
            const oldMarksheet =
                card.querySelector(`[name^="old_marksheet_file"]`);
            const oldDegree =
                card.querySelector(`[name^="old_degree_file"]`);

            if (oldMarksheet && oldMarksheet.value === 'INSUFFICIENT_DOCUMENTS') {
                oldMarksheet.value = '';
            }

            if (oldDegree && oldDegree.value === 'INSUFFICIENT_DOCUMENTS') {
                oldDegree.value = '';
            }

            if (file && box) {
                const url = URL.createObjectURL(file);
                this.setUploadBox(box, file.name, url, true);
            }

            // Normal behavior
            this.updateTabStatus();
        }
    });
}


    renderPreview(card, selector, file, label, folder = 'education') {
        console.log(`🖼️ Rendering preview for ${label}: ${file}`);
        
        // Clear any existing content first
        const previewElement = card.querySelector(selector);
        if (previewElement) {
            previewElement.innerHTML = '';
        }
        
        // Use parent class renderPreview
        const result = super.renderPreview(card, selector, file, label, folder);
        
        if (result) {
            console.log(`✅ Preview rendered for ${label} in card`);
        }
        
        return result;
    }

    setupFormHandlers() {
        console.log('🔧 Setting up education form handlers');
        
        const form = document.getElementById('educationForm');
        if (!form) {
            console.error('❌ Education form not found');
            return;
        }

        // Prevent default form submission
        form.onsubmit = (e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            console.log('❌ Form submission prevented (handled by EducationManager)');
            return false;
        };

        // Handle Next button
        const nextBtn = document.querySelector('.external-submit-btn[data-form="educationForm"]');
        if (nextBtn) {
            // Remove any existing listeners and reattach
            const newNextBtn = nextBtn.cloneNode(true);
            nextBtn.parentNode.replaceChild(newNextBtn, nextBtn);
            
            newNextBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                console.log(' Next button clicked - submitting education form');
                await this.submitForm(false);
            });
        } else {
            console.warn('⚠️ Next button not found');
        }

        // Handle Previous button
        const prevBtn = document.querySelector('.prev-btn[data-form="educationForm"]');
        if (prevBtn) {
            const newPrevBtn = prevBtn.cloneNode(true);
            prevBtn.parentNode.replaceChild(newPrevBtn, prevBtn);
            
            newPrevBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                console.log('⬅️ Previous button clicked - navigating to contact');
                if (window.Router && window.Router.navigateTo) {
                    window.Router.navigateTo('contact');
                } else {
                    window.location.href = `${window.APP_BASE_URL}/modules/candidate/contact.php`;
                }
            });
        }

        // Handle Save Draft button
        document.addEventListener('click', (e) => {
            const draftBtn = e.target.closest('.save-draft-btn[data-page="education"]');
            if (draftBtn) {
                e.preventDefault();
                e.stopImmediatePropagation();
                console.log('💾 Save draft button clicked');
                this.saveDraft();
            }
        });
        
        console.log('✅ Education form handlers setup complete');
    }

    loadFromLocalStorage() {
        try {
            const raw = localStorage.getItem('education_draft');
            if (!raw) {
                console.log('📭 No education draft found in localStorage');
                return;
            }

            const data = JSON.parse(raw);
            console.log('📥 Loading education draft from localStorage:', data);

            const count = Math.max(
                data['qualification[]']?.length || 0,
                data['college_name[]']?.length || 0,
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
                        console.log(`Card ${i} has DB data, skipping localStorage`);
                        continue;
                    }

                    console.log(`Loading localStorage data to card ${i}`);
                    
                    const localStorageData = {
                        qualification: data['qualification[]']?.[i],
                        college_name: data['college_name[]']?.[i],
                        university_board: data['university_board[]']?.[i],
                        roll_number: data['roll_number[]']?.[i],
                        college_website: data['college_website[]']?.[i],
                        college_address: data['college_address[]']?.[i],
                        year_from: data['year_from[]']?.[i],
                        year_to: data['year_to[]']?.[i],
                        insufficient_documents: data['insufficient_education_docs[]']?.[i] || false
                    };

                    this.populateCard(card, localStorageData, i);
                }
            }

            console.log('✅ Education draft loaded from localStorage');
        } catch (error) {
            console.error('❌ Error loading education draft from localStorage:', error);
        }
    }

    async saveDraft() {
        if (this.isSubmitting) return;
        this.isSubmitting = true;

        try {
            const form = document.getElementById('educationForm');
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
                this.showNotification('✅ Education Draft saved successfully!');
                localStorage.removeItem('education_draft');
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

validateForm(isFinalSubmit = false) {
    console.log(`📋 Validating education form (isFinalSubmit: ${isFinalSubmit})`);

    let isValid = true;
    const errors = [];

    for (let i = 0; i < this.cards.length; i++) {
        const card = this.cards[i];
        if (!card) continue;

        /* ================= REQUIRED TEXT FIELDS ================= */

        const requiredFields = [
            { selector: '[name="qualification[]"]', label: 'Qualification' },
            { selector: '[name="college_name[]"]', label: 'College/Institution' },
            { selector: '[name="university_board[]"]', label: 'University/Board' },
            { selector: '[name="roll_number[]"]', label: 'Roll Number' },
            { selector: '[name="year_from[]"]', label: 'From Year' },
            { selector: '[name="year_to[]"]', label: 'To Year' },
            { selector: '[name="college_address[]"]', label: 'College Address' }
        ];

        requiredFields.forEach(field => {
            const input = card.querySelector(field.selector);
            if (input && !input.value.trim()) {
                errors.push(`Education ${i + 1}: ${field.label} is required`);
                isValid = false;
            }
        });

        /* ================= DOCUMENT VALIDATION ================= */

        if (isFinalSubmit) {
            const insufficientCheckbox =
                card.querySelector('input[name="insufficient_education_docs[]"]');

            const isInsufficient =
                insufficientCheckbox && insufficientCheckbox.checked === true;

            if (!isInsufficient) {
                // NEW uploads
                const marksheetFile = card.querySelector('[name="marksheet_file[]"]');
                const degreeFile = card.querySelector('[name="degree_file[]"]');

                const hasNewMarksheet =
                    marksheetFile && marksheetFile.files && marksheetFile.files.length > 0;

                const hasNewDegree =
                    degreeFile && degreeFile.files && degreeFile.files.length > 0;

                // OLD hidden inputs
                const oldMarksheet =
                    card.querySelector(`[name="old_marksheet_file[${i}]"]`);

                const oldDegree =
                    card.querySelector(`[name="old_degree_file[${i}]"]`);

                const hasOldMarksheet =
                    oldMarksheet &&
                    oldMarksheet.value &&
                    oldMarksheet.value !== 'INSUFFICIENT_DOCUMENTS';

                const hasOldDegree =
                    oldDegree &&
                    oldDegree.value &&
                    oldDegree.value !== 'INSUFFICIENT_DOCUMENTS';

                // DB fallback (important when page reloads)
                const dbRow = this.savedRows[i] || {};

                const hasDbMarksheet =
                    dbRow.marksheet_file &&
                    dbRow.marksheet_file !== 'INSUFFICIENT_DOCUMENTS';

                const hasDbDegree =
                    dbRow.degree_file &&
                    dbRow.degree_file !== 'INSUFFICIENT_DOCUMENTS';

                const hasAnyDocument =
                    hasNewMarksheet ||
                    hasNewDegree ||
                    hasOldMarksheet ||
                    hasOldDegree ||
                    hasDbMarksheet ||
                    hasDbDegree;

                if (!hasAnyDocument) {
                    errors.push(
                        `Education ${i + 1}: Upload Marksheet or Degree, or mark Insufficient Documents`
                    );
                    isValid = false;
                }
            }
        }
    }

    if (errors.length > 0) {
        console.warn('Education validation errors:', errors);
        if (window.Router && typeof window.Router.showNotification === 'function') {
            window.Router.showNotification('Please fix the highlighted education errors before proceeding.', 'warning');
        } else if (typeof window.showAlert === 'function') {
            window.showAlert({ type: 'warning', message: 'Please fix the highlighted education errors before proceeding.' });
        }
        return false;
    }

    console.log(' Education form validation passed');
    return true;
}

async submitForm(isDraft = false) {
    console.log(` Education submit initiated (draft: ${isDraft})`);

    if (this.isSubmitting) {
        console.log(' Education submit already in progress');
        return;
    }

    if (!isDraft && !this.validateForm(true)) {
        console.log(' Validation failed');
        return;
    }

    this.isSubmitting = true;

    try {
        const form = document.getElementById('educationForm');
        if (!form) {
            throw new Error('Education form not found');
        }

        const formData = new FormData(form);
        formData.set('draft', isDraft ? '1' : '0');

        const response = await fetch(this.getApiEndpoint(), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (data && data.success) {
            this.showNotification(isDraft ? 'Education Draft saved successfully!' : 'Education saved successfully!');
            localStorage.removeItem('education_draft');

            if (!isDraft) {
                try {
                    if (window.Router) {
                        if (window.Router.markCompleted) {
                            window.Router.markCompleted('education');
                        }
                        if (window.Router.navigateTo) {
                            window.Router.navigateTo('employment');
                        } else {
                            window.location.href = `${window.APP_BASE_URL}/modules/candidate/employment.php`;
                        }
                    } else {
                        window.location.href = `${window.APP_BASE_URL}/modules/candidate/employment.php`;
                    }
                } catch (_e) {
                    window.location.href = `${window.APP_BASE_URL}/modules/candidate/employment.php`;
                }
            }
            return;
        }

        this.showNotification(data.message || 'Save failed', true);

    } catch (err) {
        console.error('Education submit error:', err);
        this.showNotification('Network / Server error', true);
    } finally {
        this.isSubmitting = false;
    }
}

cardHasData(card) {
    const inputs = card.querySelectorAll('input:not([type="hidden"]), select, textarea');
    for (const input of inputs) {
        if (input.value && input.value.trim() !== '') {
            return true;
        }
    }
    return false;
}

cleanup() {
    console.log('Cleaning up EducationManager');
    super.cleanup();

    const form = document.getElementById('educationForm');
    if (form && form.onsubmit) {
        form.onsubmit = null;
    }
}
}

if (typeof window !== 'undefined') {
    window.EducationManager = EducationManager;

    window.Education = {
        onPageLoad: async () => {
            console.log('📚 Education.onPageLoad() called');
            
            try {
                if (!window.educationManager) {
                    console.log('🆕 Creating new EducationManager instance');
                    window.educationManager = new EducationManager();
                }
                
                await window.educationManager.init();
                console.log('✅ Education page loaded successfully');
            } catch (error) {
                console.error('❌ Error in Education.onPageLoad:', error);
            }
        },
        
        cleanup: () => {
            console.log('🧹 Cleaning up Education module');
            if (window.educationManager) {
                window.educationManager.cleanup();
                window.educationManager = null;
            }
        }
    };
}

console.log('✅ Education.js module loaded');
