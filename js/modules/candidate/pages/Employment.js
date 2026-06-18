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
        this.fullEmploymentCount = 0;
        this.visibleEmploymentCount = 0;
        this.maxEmploymentCount = 5;
        this.deferVisibleEmploymentPersistence = false;
        this.activeEmploymentTimelineWarnings = new Set();
        this.docTypeOptions = {
            currently_employed: [
                { value: 'offer_letter', label: 'Offer Letter' },
                { value: 'payslips', label: 'Payslips' },
                { value: 'appointment_letter', label: 'Appointment Letter' }
            ],
            serving_notice: [
                { value: 'resignation_acceptance', label: 'Resignation Acceptance' },
                { value: 'latest_payslip', label: 'Latest Payslip' },
                { value: 'payslips', label: 'Payslips' }
            ],
            resigned: [
                { value: 'relieving_letter', label: 'Relieving Letter' },
                { value: 'experience_letter', label: 'Experience Letter' }
            ],
            other: [
                { value: 'experience_letter', label: 'Experience Letter' },
                { value: 'service_letter', label: 'Service Letter' },
                { value: 'appointment_letter', label: 'Appointment Letter' },
                { value: 'contract_letter', label: 'Contract Letter' }
            ]
        };
    }

    async init() {
        console.log('💼 EmploymentManager.init() called');
        this.loadPageData();

        // Initialise requiredCount / configuredRequiredCount BEFORE super.init()
        // so renderTabs() can prioritise them over currentData.length.
        {
            var _req = window.CANDIDATE_CASE_CONFIG && window.CANDIDATE_CASE_CONFIG.required_counts
                ? parseInt(window.CANDIDATE_CASE_CONFIG.required_counts.employment || '0', 10) || 0
                : 0;
            this.configuredRequiredCount = _req > 0 ? _req : 0;
            this.requiredCount = this.isFresher ? 0 : this.configuredRequiredCount;
        }

        await super.init();

        try {
            var req = this.configuredRequiredCount; // already resolved above

            var initialCount = this.isFresher
                ? 1
                : this.configuredRequiredCount;

            if (initialCount > 0 && this.countSelect) {
                this.deferVisibleEmploymentPersistence = true;
                this.countSelect.value = String(initialCount);
                this.handleCountChange();
                this.deferVisibleEmploymentPersistence = false;
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
            this.deferVisibleEmploymentPersistence = false;
        }

        this.setupFormHandlers();
        this.setupFileHandlers();
        this.setupRelievingDateHandlers();
        this.setupEmploymentIntelligenceHandlers();
        this.setupNoFurtherEmploymentController();
        this.loadFromLocalStorage();
        this.fullEmploymentCount = this.cards.filter(Boolean).length;
        this.maxEmploymentCount = this.getMaxEmploymentCount();
        this.visibleEmploymentCount = this.isFresher ? 1 : Math.max(this.configuredRequiredCount || 1, this.savedRows.length || 1);
        this.restoreVisibleEmploymentCount();
        this.refreshEmploymentState();
        this.applyEmploymentLayoutReset();
        this.lastNonFresherCount = Math.max(this.cards.length, this.configuredRequiredCount || 0, 1);
        this.setupRadioHandlers();
        this.applyFresherUI(this.isFresher);
        this.applyEmploymentLayoutReset();

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

    handleCountChange() {
        super.handleCountChange();
        this.fullEmploymentCount = this.cards.filter(Boolean).length;
        this.visibleEmploymentCount = this.fullEmploymentCount;
        this.persistVisibleEmploymentCount();
        this.refreshEmploymentState();
        this.applyEmploymentLayoutReset();
    }

    showTab(index) {
        const maxVisibleIndex = Math.max(0, (this.visibleEmploymentCount || 1) - 1);
        const safeIndex = Math.min(index, maxVisibleIndex);
        super.showTab(safeIndex);
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
        
        const idx = this.findInput(card, 'employment_index[]');
        if (idx) idx.value = index + 1;
        
        if (data.id) {
            this.findOrCreateInput(card, `id[${index}]`, 'hidden').value = data.id;
            console.log(`   Set record ID: ${data.id}`);
        }

        const employmentDoc = data.employment_doc || data.employment_doc_path;
        if (employmentDoc) {
            let fileName = employmentDoc;
            if (fileName.includes('uploads/employment/')) {
                fileName = fileName.split('uploads/employment/').pop();
            } else if (fileName.includes('/')) {
                fileName = fileName.split('/').pop();
            }
            
            console.log(`   Extracted file name: ${fileName}`);
            
            this.findOrCreateInput(card, `old_employment_doc[${index}]`, 'hidden').value = fileName;
            
            if (fileName !== 'INSUFFICIENT_DOCUMENTS') {
                const input = card.querySelector('input[name="employment_doc[]"]');
                const box = this.getUploadBoxFromInput(input);
                const base = window.APP_BASE_URL || '';
                const url = `${base}/uploads/employment/${fileName}`;
                this.setUploadBox(box, fileName, url, false);
                console.log(`   Added employment document preview: ${fileName}`);
            }
        }

        const fieldMap = {
            'employer_name[]': data.employer_name,
            'job_title[]': data.job_title,
            'employee_id[]': data.employee_id,
            'employment_status[]': data.employment_status || this.inferEmploymentStatus(data, index),
            'tentative_relieving_note[]': data.tentative_relieving_note,
            'gap_reason[]': data.gap_reason,
            'gap_explanation[]': data.gap_explanation,
            'overlap_explanation[]': data.overlap_explanation,
            'reason_leaving[]': data.reason_leaving,
            'job_location[]': data.job_location,
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
        const savedEmploymentDocType = data.employment_doc_type || "";

        
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

        if (data.tentative_relieving_date) {
            const tentativeInput = card.querySelector('[name="tentative_relieving_date[]"]');
            if (tentativeInput) tentativeInput.value = data.tentative_relieving_date.substring(0, 10);
        }

        const radioBlock = card.querySelector('.first-employer-fields');
        if (radioBlock) {
            if (index === 0) {
                radioBlock.style.display = 'block';
                console.log('   📻 Showing radio block for first card');
                
                const fresherValue = data.is_fresher || (this.isFresher ? 'yes' : 'no');
                const currentlyEmployedValue = data.currently_employed || this.currentlyEmployed || 'no';
                const contactEmployerValue = data.contact_employer || this.contactEmployer || 'no';
                
                console.log(`   Radio values to set: is_fresher=${fresherValue}, currently_employed=${currentlyEmployedValue}, contact_employer=${contactEmployerValue}`);
                
                this.setRadio(card, 'is_fresher[0]', fresherValue);
                this.isFresher = fresherValue === 'yes';
                
                this.setRadio(card, 'currently_employed[0]', currentlyEmployedValue);
                this.currentlyEmployed = currentlyEmployedValue;
                
                this.setRadio(card, 'contact_employer[0]', contactEmployerValue);
                this.contactEmployer = contactEmployerValue;
                
                this.updateContactEmployer(card, true);
                this.updateEmploymentStatusUI(card, savedEmploymentDocType);
            } else {
                radioBlock.style.display = 'none';
                console.log('   Hiding radio block for card ' + index);
                this.updateEmploymentStatusUI(card, savedEmploymentDocType);
            }
        }

        this.debugEmploymentDates('reload-populate-card', {
            cardIndex: index + 1,
            sourceJoiningDate: data.joining_date || '',
            sourceRelievingDate: data.relieving_date || '',
            sourceEmploymentStatus: data.employment_status || ''
        });
        this.refreshTimelineIntelligence();
        this.applyEmploymentLayoutReset();
        console.log(`✅ Card ${index} populated successfully`);
    }

    applyEmploymentLayoutReset() {
        const cards = document.querySelectorAll('.employment-create-compact .employment-card');
        cards.forEach((card) => {
            const body = card.querySelector('.employment-card-body');
            if (!body) return;

            body.style.setProperty('display', 'block', 'important');
            body.style.setProperty('grid-template-columns', 'none', 'important');

            const rows = body.querySelectorAll(':scope > .compact-row');
            rows.forEach((row) => {
                if (row.classList.contains('no-further-employment-row') || row.classList.contains('form-row-1')) {
                    row.style.setProperty('display', 'block', 'important');
                    row.style.setProperty('width', '100%', 'important');
                } else if (row.classList.contains('form-row-2')) {
                    row.style.setProperty('display', 'grid', 'important');
                    row.style.setProperty('grid-template-columns', 'repeat(2, minmax(0, 1fr))', 'important');
                    row.style.setProperty('gap', '8px 14px', 'important');
                    row.style.setProperty('width', '100%', 'important');
                    row.style.setProperty('margin-bottom', '8px', 'important');
                } else {
                    row.style.setProperty('display', 'grid', 'important');
                    row.style.setProperty('grid-template-columns', 'repeat(3, minmax(0, 1fr))', 'important');
                    row.style.setProperty('gap', '8px 14px', 'important');
                    row.style.setProperty('width', '100%', 'important');
                    row.style.setProperty('margin-bottom', '8px', 'important');
                }

                row.querySelectorAll(':scope > .form-field').forEach((field) => {
                    field.style.setProperty('display', 'block', 'important');
                    field.style.setProperty('grid-column', 'auto', 'important');
                    field.style.setProperty('grid-row', 'auto', 'important');
                    field.style.setProperty('width', 'auto', 'important');
                    field.style.setProperty('max-width', 'none', 'important');
                    field.style.setProperty('min-width', '0', 'important');
                    field.style.setProperty('margin', '0', 'important');
                });
            });
        });
    }
    inferEmploymentStatus(data = {}, index = 0) {
        if (data.employment_status) return data.employment_status;
        if (index === 0 && (data.currently_employed || this.currentlyEmployed) === 'yes') {
            return 'currently_employed';
        }
        return 'resigned';
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
            
            radios.forEach(radio => {
                if (radio.value === value) {
                    const event = new Event('change', { bubbles: true });
                    radio.dispatchEvent(event);
                }
            });
            
            console.log(`✅ Radio ${name} set to: ${value}`);
        }, 50);
    }

    setupNoFurtherEmploymentController() {
        this.addEventListener(document, 'change', (e) => {
            if (!e.target.matches('.no-further-employment-checkbox')) return;
            const card = e.target.closest('.employment-card');
            if (!card) return;
            this.handleNoFurtherEmploymentChange(card, e.target);
        });
    }

    handleNoFurtherEmploymentChange(card, trigger) {
        const index = parseInt(card.dataset.cardIndex || '-1', 10);
        if (index < 0) return;

        const visibleCount = Math.max(1, this.visibleEmploymentCount || 1);
        const isLastVisible = index === visibleCount - 1;

        if (trigger.checked) {
            const canContinue = !this.isFresher && isLastVisible && index < this.maxEmploymentCount - 1;
            if (!canContinue) {
                this.refreshEmploymentState();
                return;
            }

            const nextCount = Math.max(index + 2, this.configuredRequiredCount || 1);

            for (let i = index + 1; i < nextCount; i++) {
                if (!this.cards[i]) {
                    this.addCard(i, null);
                }
            }
            this.fullEmploymentCount = this.cards.filter(Boolean).length;

            this.visibleEmploymentCount = Math.min(nextCount, this.maxEmploymentCount);
            this.currentTab = this.visibleEmploymentCount - 1;
            this.persistVisibleEmploymentCount();
            this.refreshEmploymentState();
            return;
        }

        if (index < visibleCount - 1) {
            this.visibleEmploymentCount = Math.max(1, index + 1);
            this.currentTab = Math.min(index, this.visibleEmploymentCount - 1);
            this.persistVisibleEmploymentCount();
            this.refreshEmploymentState();
            return;
        }

        trigger.checked = false;
        this.refreshEmploymentState();
    }

    setupFormHandlers() {
        console.log('🔧 Setting up employment form handlers');
        
        const form = document.getElementById('employmentForm');
        if (!form) {
            console.error(' Employment form not found');
            return;
        }

        form.onsubmit = (e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            console.log('❌ Employment form submission prevented');
            return false;
        };

        const nextBtn = document.querySelector('.external-submit-btn[data-form="employmentForm"]');
        if (nextBtn) {
            const newNextBtn = nextBtn.cloneNode(true);
            nextBtn.parentNode.replaceChild(newNextBtn, nextBtn);
            
            newNextBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                console.log('✅ Next button clicked - submitting employment form');
                await this.submitForm(false);
            });
        }

        const prevBtn = document.querySelector('.prev-btn');
        if (prevBtn) {
            const newPrevBtn = prevBtn.cloneNode(true);
            prevBtn.parentNode.replaceChild(newPrevBtn, prevBtn);
            
            newPrevBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                console.log('⬅️ Previous button clicked - navigating to education');
                if (window.Router && window.Router.navigateTo) {
                    const previousPage = typeof window.Router.getPreviousPage === 'function' ? window.Router.getPreviousPage('employment') : 'education';
                    window.Router.navigateTo(previousPage);
                }
            });
        }

        this.addEventListener(document, 'click', (e) => {
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

        this.addEventListener(document, 'click', (e) => {
            const trigger = e.target.closest('[data-file-choose]');
            if (!trigger) return;
            e.preventDefault();
            const box = trigger.closest('[data-file-upload]');
            const control = box ? box.closest('.form-control') : null;
            const input = control ? control.querySelector('input[type="file"][data-file-input]') : null;
            if (input) input.click();
        });

        this.addEventListener(document, 'click', (e) => {
            const remove = e.target.closest('[data-file-remove]');
            if (!remove) return;
            const box = remove.closest('[data-file-upload]');
            const control = box ? box.closest('.form-control') : null;
            const input = control ? control.querySelector('input[name="employment_doc[]"]') : null;
            const card = remove.closest('.employment-card');
            e.preventDefault();
            if (input) input.value = '';
            this.clearUploadBox(box);
            if (card) this.updateTabStatus();
        });

        this.addEventListener(document, 'change', (e) => {
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
                const validation = this.validateUploadFile(file, allowed, 5 * 1024 * 1024);
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
        this.addEventListener(document, 'input', (e) => {
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
        
        this.addEventListener(document, 'change', e => {
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

    setupEmploymentIntelligenceHandlers() {
        this.addEventListener(document, 'change', (e) => {
            if (e.target.matches('[name="employment_status[]"], [name="joining_date[]"], [name="relieving_date[]"]')) {
                const card = e.target.closest('.employment-card');
                if (card) this.updateEmploymentStatusUI(card);
                this.refreshTimelineIntelligence();
            }
        });

        this.addEventListener(document, 'input', (e) => {
            if (e.target.matches('[name="joining_date[]"], [name="relieving_date[]"]')) {
                this.refreshTimelineIntelligence();
            }
        });
    }

    showEmploymentTimelineNotification(id, message) {
        if (!id || !message || this.activeEmploymentTimelineWarnings.has(id)) return;
        this.activeEmploymentTimelineWarnings.add(id);

        if (window.CandidateNotify && typeof window.CandidateNotify.warn === 'function') {
            window.CandidateNotify.warn(message, {
                id: `employment-timeline-${id}`,
                title: 'Employment timeline needs review',
                timeout: 4200
            });
        } else if (window.Router && typeof window.Router.showNotification === 'function') {
            window.Router.showNotification(message, 'warning');
        }
    }

    clearEmploymentTimelineNotification(id) {
        const toastKey = `employment-timeline-${id}`;
        try {
            const toast = Array.from(document.querySelectorAll('[data-toast-key]'))
                .find((node) => node && node.dataset && node.dataset.toastKey === toastKey);
            if (toast) {
                toast.classList.add('is-closing');
                window.setTimeout(() => toast.remove(), 160);
            }
        } catch (_e) {
        }
    }

    syncEmploymentTimelineNotifications(currentWarnings) {
        const activeIds = currentWarnings instanceof Set ? currentWarnings : new Set();
        this.activeEmploymentTimelineWarnings.forEach((id) => {
            if (!activeIds.has(id)) {
                this.clearEmploymentTimelineNotification(id);
                this.activeEmploymentTimelineWarnings.delete(id);
            }
        });
    }

    normalizeEmploymentStatus(status) {
        if (status === 'currently_employed' || status === 'serving_notice' || status === 'resigned') {
            return status;
        }
        return 'other';
    }

    updateEmploymentStatusUI(card, preferredDocType = null) {
        if (!card) return;
        const statusSelect = card.querySelector('[name="employment_status[]"]');
        const status = statusSelect && statusSelect.value
            ? statusSelect.value
            : this.inferEmploymentStatus({}, this.cards.indexOf(card));
        if (statusSelect && !statusSelect.value) {
            statusSelect.value = status;
        }

        const currentLike = ['currently_employed', 'serving_notice'].includes(status);
        const relievingInput = card.querySelector('[name="relieving_date[]"]');
        if (relievingInput) {
            relievingInput.required = !currentLike;
            if (currentLike) relievingInput.value = '';
        }

        const tentativeRow = card.querySelector('.employment-tentative-row');
        if (tentativeRow) tentativeRow.style.display = currentLike ? '' : 'none';

        this.updateEmploymentProofOptions(card, status, preferredDocType);
    }

    refreshTimelineIntelligence() {
        const periods = [];
        const currentWarnings = new Set();
        this.cards.forEach((card, index) => {
            if (!card) return;
            card.dataset.timelineOverlap = '0';
            if (card.dataset.suppressed === '1') return;

            const startRaw = card.querySelector('[name="joining_date[]"]')?.value || '';
            const endRaw = card.querySelector('[name="relieving_date[]"]')?.value || '';
            if (!startRaw) return;
            const start = this.parseEmploymentDate(startRaw);
            const end = endRaw ? this.parseEmploymentDate(endRaw) : new Date();
            if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return;
            periods.push({ card, index, start, end });
        });

        for (let i = 0; i < periods.length - 1; i++) {
            const newer = periods[i];
            const older = periods[i + 1];
            const targetCard = older.card;

            if (older.end > newer.start) {
                targetCard.dataset.timelineOverlap = '1';
                const warningId = `overlap-${newer.index}-${older.index}`;
                currentWarnings.add(warningId);
                this.showEmploymentTimelineNotification(
                    warningId,
                    `Employer ${older.index + 1} overlaps with Employer ${newer.index + 1}. Check the date order.`
                );
                continue;
            }

        }

        this.syncEmploymentTimelineNotifications(currentWarnings);
    }

    convertIsoToDisplayDate(value) {
        const raw = String(value || '').trim();
        if (!raw) return '';
        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) return raw;
        return `${match[3]}/${match[2]}/${match[1]}`;
    }

    convertDisplayToIsoDate(value) {
        const raw = String(value || '').trim();
        if (!raw) return '';
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
        const match = raw.match(/^(\d{2})\s*[\/-]\s*(\d{2})\s*[\/-]\s*(\d{4})$/);
        if (!match) return raw;
        return `${match[3]}-${match[2]}-${match[1]}`;
    }

    parseEmploymentDate(value) {
        const raw = String(value || '').trim();
        if (!raw) return new Date(NaN);

        let year;
        let month;
        let day;

        const isoMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (isoMatch) {
            year = Number(isoMatch[1]);
            month = Number(isoMatch[2]);
            day = Number(isoMatch[3]);
        } else {
            const displayMatch = raw.match(/^(\d{2})\s*[\/-]\s*(\d{2})\s*[\/-]\s*(\d{4})$/);
            if (!displayMatch) return new Date(NaN);
            day = Number(displayMatch[1]);
            month = Number(displayMatch[2]);
            year = Number(displayMatch[3]);
        }

        const parsed = new Date(year, month - 1, day);
        if (
            parsed.getFullYear() !== year ||
            parsed.getMonth() !== month - 1 ||
            parsed.getDate() !== day
        ) {
            return new Date(NaN);
        }

        parsed.setHours(0, 0, 0, 0);
        return parsed;
    }

    getDesiredVisibleEmploymentCount() {
        const minReq = this.isFresher ? 1 : 1;
        let visibleCount = Math.max(
            minReq,
            Math.min(this.visibleEmploymentCount || this.fullEmploymentCount || 1, this.fullEmploymentCount || 1)
        );

        return Math.max(minReq, Math.min(visibleCount || 1, this.fullEmploymentCount || 1));
    }

    refreshEmploymentState() {
        this.fullEmploymentCount = this.cards.filter(Boolean).length;
        this.visibleEmploymentCount = this.getDesiredVisibleEmploymentCount();
        this.persistVisibleEmploymentCount();
        this.applyVisibleEmploymentCount();
        this.updateNoFurtherEmploymentVisibility();
        this.refreshTimelineIntelligence();
    }

    getEmploymentStoragePrefix() {
        const applicationId = String(window.CANDIDATE_APP_ID || '').trim();
        return applicationId ? `candidate:${applicationId}:` : 'candidate:';
    }

    getNoFurtherEmploymentStorageKey() {
        return `${this.getEmploymentStoragePrefix()}employment_visible_count`;
    }

    getEmploymentDraftStorageKey() {
        return `${this.getEmploymentStoragePrefix()}employment_draft`;
    }

    persistVisibleEmploymentCount() {
        try {
            if (this.deferVisibleEmploymentPersistence) return;
            const visibleCount = Math.max(
                1,
                Math.min(this.visibleEmploymentCount || this.fullEmploymentCount || 1, this.fullEmploymentCount || 1)
            );
            localStorage.setItem(this.getNoFurtherEmploymentStorageKey(), String(visibleCount));
            localStorage.removeItem('employment_visible_count');
        } catch (_e) {
        }
    }

    debugEmploymentDates(stage, extra = {}) {
        try {
            const dates = this.cards.map((card, index) => {
                if (!card) return null;
                return {
                    index: index + 1,
                    visible: index < (this.visibleEmploymentCount || this.cards.length || 1),
                    joining_date: card.querySelector('[name="joining_date[]"]')?.value || '',
                    relieving_date: card.querySelector('[name="relieving_date[]"]')?.value || '',
                    employment_status: card.querySelector('[name="employment_status[]"]')?.value || ''
                };
            }).filter(Boolean);
            console.debug('[Employment date debug]', stage, {
                visibleEmploymentCount: this.visibleEmploymentCount,
                fullEmploymentCount: this.fullEmploymentCount,
                dates,
                ...extra
            });
        } catch (_e) {
        }
    }

    restoreVisibleEmploymentCount() {
        try {
            const scopedKey = this.getNoFurtherEmploymentStorageKey();
            let raw = localStorage.getItem(scopedKey);
            localStorage.removeItem('employment_visible_count');
            if (!raw) {
                raw = document.getElementById('employmentForm')?.dataset?.serverVisibleCount || '';
            }
            if (!raw) return;
            const parsed = parseInt(raw, 10);
            if (!parsed || parsed < 1) return;
            this.visibleEmploymentCount = Math.min(parsed, this.fullEmploymentCount || parsed);
        } catch (_e) {
        }
    }

    applyVisibleEmploymentCount() {
        const minReq = this.isFresher ? 1 : 1;
        const visibleCount = Math.max(minReq, this.visibleEmploymentCount || this.cards.filter(Boolean).length || 1);
        const tabs = document.querySelectorAll('.employment-tab');
        const visibleCountInput = document.getElementById('visibleEmploymentCount');

        if (visibleCountInput) {
            visibleCountInput.value = String(visibleCount);
        }

        this.cards.forEach((card, index) => {
            if (!card) return;
            const isVisible = index < visibleCount;
            card.dataset.suppressed = isVisible ? '0' : '1';
            card.style.display = isVisible && index === this.currentTab ? 'block' : 'none';

            card.querySelectorAll('input, select, textarea, button').forEach((el) => {
                if (el.classList.contains('no-further-employment-checkbox')) {
                    el.disabled = !isVisible;
                    return;
                }
                if (el.type === 'hidden') {
                    el.disabled = !isVisible;
                    return;
                }
                el.disabled = !isVisible;
            });
        });

        tabs.forEach((tab, index) => {
            tab.style.display = index < visibleCount ? '' : 'none';
        });

        if (this.currentTab >= visibleCount) {
            this.currentTab = visibleCount - 1;
        }

        super.showTab(this.currentTab);
    }

    updateNoFurtherEmploymentVisibility() {
        const total = Math.max(1, this.visibleEmploymentCount || this.cards.filter(Boolean).length);
        this.cards.forEach((card, index) => {
            if (!card) return;
            const row = card.querySelector('.no-further-employment-row');
            const checkbox = card.querySelector('.no-further-employment-checkbox');
            if (!row || !checkbox) return;

            const isVisible = index < total;
            const canContinue = !this.isFresher && isVisible && index < this.maxEmploymentCount - 1;
            row.style.display = canContinue ? '' : 'none';
            checkbox.disabled = !canContinue;
            checkbox.checked = canContinue && index < total - 1;
        });
    }

    getMaxEmploymentCount() {
        const optionValues = this.countSelect
            ? Array.from(this.countSelect.options || []).map((option) => parseInt(option.value || '0', 10)).filter(Boolean)
            : [];
        return Math.max(1, optionValues.length ? Math.max(...optionValues) : this.maxEmploymentCount || 5);
    }

    updateContactEmployer(card, preserveExistingDate = false) {
        if (!card) return;

        const contactEmployerField = card.querySelector(".contact-employer-field");
        const relievingDateInput = card.querySelector('[name="relieving_date[]"]');
        const relievingDateField = relievingDateInput ? relievingDateInput.closest('.form-field') : null;

        if (!contactEmployerField) return;

        if (this.currentlyEmployed === "yes") {
            contactEmployerField.style.display = "block";
            if (relievingDateInput) {
                if (relievingDateField) {
                    relievingDateField.style.display = '';
                }
                relievingDateInput.type = "date";
                relievingDateInput.disabled = false;
                relievingDateInput.required = false;
                relievingDateInput.placeholder = "";
                relievingDateInput.removeAttribute("inputmode");
                relievingDateInput.removeAttribute("pattern");
            }
        } else {
            contactEmployerField.style.display = "none";
            if (relievingDateInput) {
                if (relievingDateField) {
                    relievingDateField.style.display = '';
                }
                relievingDateInput.type = "date";
                relievingDateInput.disabled = false;
                relievingDateInput.required = true;
                relievingDateInput.placeholder = "";
                relievingDateInput.removeAttribute("inputmode");
                relievingDateInput.removeAttribute("pattern");
            }
        }

        this.updateEmploymentStatusUI(card);
    }

    updateEmploymentProofOptions(card, employmentStatus, preferredValue = null) {
        if (!card) return;

        const docTypeSelect = card.querySelector('[name="employment_doc_type[]"]');
        if (!docTypeSelect) return;

        const normalizedStatus = this.normalizeEmploymentStatus(employmentStatus === "yes" ? "currently_employed" : employmentStatus);
        const currentValue = preferredValue !== null ? preferredValue : docTypeSelect.value;
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
                const isWithinVisibleRange = index < (this.visibleEmploymentCount || this.cards.length || 1);
                card.style.display = isFresher
                    ? "none"
                    : (isWithinVisibleRange && index === this.currentTab ? "block" : "none");
            }
        });

        this.updateNoFurtherEmploymentVisibility();

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
        this.refreshTimelineIntelligence();
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
        const addBlockingNotice = (field, message) => {
            errors.push({ field, message });
            isValid = false;
        };
        
        const requiredEmploymentCount = Math.max(
            Math.min(this.visibleEmploymentCount || this.cards.length || 1, this.cards.length || 1),
            1
        );

        for (let i = 0; i < requiredEmploymentCount; i++) {
            const card = this.cards[i];
            if (!card) continue;
            
            if (this.isFresher && i > 0) {
                continue;
            }
            
            if (this.isFresher && i === 0) {
                continue;
            }

            if (!this.isFresher) {
                const requiredFields = [
                    { selector: '[name="employer_name[]"]', label: 'Employer Name' },
                    { selector: '[name="job_title[]"]', label: 'Job Title' },
                    { selector: '[name="employee_id[]"]', label: 'Employee ID' },
                    { selector: '[name="joining_date[]"]', label: 'Start Date' },
                    { selector: '[name="reason_leaving[]"]', label: 'Reason for Leaving' }
                ];

                const employmentStatus = this.inferEmploymentStatus({}, i);
                const shouldRequireRelievingDate = !['currently_employed', 'serving_notice'].includes(employmentStatus);

                if (shouldRequireRelievingDate) {
                    requiredFields.push({ 
                        selector: '[name="relieving_date[]"]', 
                        label: 'Relieving Date' 
                    });
                }

                requiredFields.forEach(field => {
                    const input = card.querySelector(field.selector);
                    if (input && !input.value.trim() && !input.disabled) {
                        addError(input, `Employer ${i + 1}: ${field.label} is required`);
                    }
                });

                if (isFinalSubmit) {
                    const employmentDocType =
                        card.querySelector('[name="employment_doc_type[]"]');
                    if (!employmentDocType || !employmentDocType.value.trim()) {
                        addError(employmentDocType || card, `Employer ${i + 1}: Employment document type is required`);
                    }

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

                const joiningInput = card.querySelector('[name="joining_date[]"]');
                const relievingInput = card.querySelector('[name="relieving_date[]"]');
                const tentativeInput = card.querySelector('[name="tentative_relieving_date[]"]');
                
                if (
                    shouldRequireRelievingDate &&
                    joiningInput &&
                    joiningInput.value &&
                    relievingInput &&
                    relievingInput.value &&
                    !relievingInput.disabled
                ) {
                    const joiningDate = this.parseEmploymentDate(joiningInput.value);
                    const relievingDate = this.parseEmploymentDate(relievingInput.value);
                    
                    if (relievingDate <= joiningDate) {
                        addError(relievingInput, `Employer ${i + 1}: Relieving date must be after joining date`);
                    }
                }

                if (tentativeInput && tentativeInput.value && ['currently_employed', 'serving_notice'].includes(employmentStatus)) {
                    const tentativeDate = this.parseEmploymentDate(tentativeInput.value);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    if (tentativeDate <= today) {
                        addError(tentativeInput, `Employer ${i + 1}: Tentative relieving date must be a future date`);
                    }
                }

                const overlapDetected = card.dataset.timelineOverlap === '1';
                if (overlapDetected) {
                    const targetField = card.querySelector('[name="joining_date[]"]') || card;
                    addBlockingNotice(
                        targetField,
                        `Employer ${i + 1}: Employment dates overlap or are out of order. Correct the timeline before continuing.`
                    );
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
                localStorage.removeItem(this.getEmploymentDraftStorageKey());
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
            localStorage.removeItem('employment_draft');
            const raw = localStorage.getItem(this.getEmploymentDraftStorageKey());
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
                        employment_status: data['employment_status[]']?.[i],
                        tentative_relieving_date: data['tentative_relieving_date[]']?.[i],
                        tentative_relieving_note: data['tentative_relieving_note[]']?.[i],
                        gap_reason: data['gap_reason[]']?.[i],
                        gap_explanation: data['gap_explanation[]']?.[i],
                        overlap_explanation: data['overlap_explanation[]']?.[i],
                        employment_doc_type: data["employment_doc_type[]"]?.[i],
                        reason_leaving: data['reason_leaving[]']?.[i],
                        job_location: data['job_location[]']?.[i],
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
                        contact_employer: data['contact_employer[0]']?.[0]
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
                    const nextPage = typeof window.Router.getNextPage === 'function' ? window.Router.getNextPage("employment") : "ecourt";
                    window.Router.navigateTo(nextPage);
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
            this.cards.forEach((card, index) => {
                formData.set(`insufficient_employment_docs[${index}]`, '0');
            });
            formData.set('draft', isDraft ? '1' : '0');
            this.debugEmploymentDates('before-save');
            console.debug('[Employment date debug]', 'sent-to-api', {
                visibleEmploymentCount: formData.get('visibleEmploymentCount') || '',
                joining_date: formData.getAll('joining_date[]'),
                relieving_date: formData.getAll('relieving_date[]'),
                employment_status: formData.getAll('employment_status[]')
            });

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
                    localStorage.removeItem(this.getEmploymentDraftStorageKey());
                    localStorage.removeItem('employment_draft');
                    if (window.Router && window.Router.pageCache && typeof window.Router.pageCache.delete === 'function') {
                        window.Router.pageCache.delete('employment');
                    }

                    if (window.Router) {
                        if (window.Router.markCompleted) {
                            window.Router.markCompleted('employment');
                        }
                        
                        if (window.Router.navigateTo) {
                            const nextPage = typeof window.Router.getNextPage === 'function' ? window.Router.getNextPage('employment') : 'ecourt';
                            window.Router.navigateTo(nextPage);
                        } else {
                            window.location.href = `${window.APP_BASE_URL}/modules/candidate/ecourt.php`;
                        }
                    } else {
                        window.location.href = `${window.APP_BASE_URL}/modules/candidate/ecourt.php`;
                    }
                    
                    if (window.Forms && typeof window.Forms.clearDraft === 'function') {
                        window.Forms.clearDraft('employment');
                    }
                }
                
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
        const inputs = card.querySelectorAll('input:not([type="hidden"]):not([type="file"]), select, textarea');
        for (const input of inputs) {
            if (input.value && input.value.trim() !== '' && !input.disabled) {
                return true;
            }
        }
        
        const fileInput = card.querySelector('input[type="file"]');
        if (fileInput && fileInput.files.length > 0) {
            return true;
        }
        
        const oldFileInput = card.querySelector('input[name^="old_"]');
        if (oldFileInput && oldFileInput.value && oldFileInput.value !== 'INSUFFICIENT_DOCUMENTS') {
            return true;
        }
        
        return false;
    }

    cleanup() {
        console.log('🧹 Cleaning up EmploymentManager');
        super.cleanup();
        
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
