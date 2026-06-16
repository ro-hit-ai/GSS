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
        this.fullEducationCount = 0;
        this.visibleEducationCount = 0;
        this.deferVisibleEducationPersistence = false;
        this.marksheetSelectedFiles = new Map();
        this.institutionSearchTimers = new Map();
        this.institutionSearchControllers = new Map();
        this.institutionSearchQueries = new Map();
        this.activeEducationTimelineWarnings = new Set();
        this.qualificationRules = {
            '10th': {
                showDegree: false,
                requireMarksheet: true,
                multiMarksheet: false,
                requireDegree: false,
                marksheetInstruction: 'Upload marksheet (Max 5MB)',
                degreeInstruction: ''
            },
            '12th': {
                showDegree: false,
                requireMarksheet: true,
                multiMarksheet: false,
                requireDegree: false,
                marksheetInstruction: 'Upload marksheet (Max 5MB)',
                degreeInstruction: ''
            },
            'SSLC': {
                showDegree: false,
                requireMarksheet: true,
                multiMarksheet: false,
                requireDegree: false,
                marksheetInstruction: 'Upload marksheet/certificate (Max 5MB)',
                degreeInstruction: ''
            },
            'PUC': {
                showDegree: false,
                requireMarksheet: true,
                multiMarksheet: false,
                requireDegree: false,
                marksheetInstruction: 'Upload marksheet/certificate (Max 5MB)',
                degreeInstruction: ''
            },
            'Diploma': {
                showDegree: true,
                requireMarksheet: true,
                multiMarksheet: false,
                requireDegree: true,
                marksheetInstruction: 'Upload all semester marksheets in one PDF + degree/PDC',
                degreeInstruction: 'Upload all semester marksheets in one PDF + degree/PDC'
            },
            'UG': {
                showDegree: true,
                requireMarksheet: true,
                multiMarksheet: true,
                requireDegree: true,
                marksheetInstruction: 'Upload all semester marksheets separately. Multiple files allowed.',
                degreeInstruction: 'Upload all semester marksheets in one PDF + degree/PDC'
            },
            'PG': {
                showDegree: true,
                requireMarksheet: true,
                multiMarksheet: true,
                requireDegree: true,
                marksheetInstruction: 'Upload all semester marksheets separately. Multiple files allowed.',
                degreeInstruction: 'Upload all semester marksheets in one PDF + degree/PDC'
            },
            'PhD': {
                showDegree: true,
                requireMarksheet: false,
                multiMarksheet: false,
                requireDegree: true,
                marksheetInstruction: '',
                degreeInstruction: 'Upload doctorate certificate / provisional certificate'
            },
            'International': {
                showDegree: true,
                requireMarksheet: true,
                multiMarksheet: false,
                requireDegree: true,
                marksheetInstruction: 'Upload transcript / equivalent marksheet',
                degreeInstruction: 'Upload certificate / equivalency document'
            }
        };
        this.qualificationOrder = {
            'PhD': 9,
            'PG': 8,
            'UG': 7,
            'International': 7,
            'Diploma': 6,
            'PUC': 5,
            '12th': 5,
            'SSLC': 4,
            '10th': 4
        };
        this.educationGapThresholdDays = 365;
        this.maxEducationCount = 8;
        this.STATES = {
            ACTIVE: 'ACTIVE',
            INSUFFICIENT_DOCUMENTS: 'INSUFFICIENT_DOCUMENTS',
            NO_FURTHER_EDUCATION: 'NO_FURTHER_EDUCATION'
        };
    }

    async init() {
        console.log('Ã°Å¸â€œÅ¡ EducationManager.init() called');
        this.loadPageData();

        // Initialise requiredCount BEFORE super.init() so renderTabs() can
        // prioritise it over currentData.length when the role demands multiple tabs.
        this.requiredCount = (window.CANDIDATE_CASE_CONFIG &&
            window.CANDIDATE_CASE_CONFIG.required_counts
                ? parseInt(window.CANDIDATE_CASE_CONFIG.required_counts.education || '0', 10) || 0
                : 0);

        await super.init();

        try {
            var req = this.requiredCount; // already resolved above
            if (req > 0 && this.countSelect) {
                this.deferVisibleEducationPersistence = true;
                this.countSelect.value = String(req);
                this.handleCountChange();
                this.deferVisibleEducationPersistence = false;
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
            this.deferVisibleEducationPersistence = false;
        }

        await this.initCards();
        this.maxEducationCount = this.getMaxEducationCount();
        this.cards.forEach((card, index) => card && this.prepareExtendedInputs(card, index));
        this.fullEducationCount = this.cards.filter(Boolean).length;
        this.visibleEducationCount = Math.max(this.requiredCount || 1, this.savedRows.length || 1);
        this.setupFormHandlers();
        this.setupStateController();
        this.setupInstitutionSearch();
        this.loadFromLocalStorage();
        this.restoreVisibleEducationCount();
        this.setupFileHandlers();
        this.refreshEducationState();

        console.log('Ã¢Å“â€¦ Education module initialized successfully');
        console.log(`Ã°Å¸â€œÅ  Cards loaded: ${this.cards.length}, Data rows: ${this.savedRows.length}`);

        return this;
    }

    getApiEndpoint() {
        return `${window.APP_BASE_URL}/api/candidate/store_education.php`;
    }

    getTabLabel(index) {
        return `Education ${index + 1}`;
    }

    handleCountChange() {
        super.handleCountChange();
        this.fullEducationCount = this.cards.filter(Boolean).length;
        this.visibleEducationCount = this.fullEducationCount;
        this.pruneMarksheetSelectionState();
        this.persistVisibleEducationCount();
        this.refreshEducationState();
    }

    getMarksheetCardKey(card) {
        return String(parseInt(card?.dataset.cardIndex || '-1', 10));
    }

    getSelectedMarksheetFiles(card) {
        const key = this.getMarksheetCardKey(card);
        return Array.isArray(this.marksheetSelectedFiles.get(key))
            ? this.marksheetSelectedFiles.get(key)
            : [];
    }

    setSelectedMarksheetFiles(card, files) {
        const key = this.getMarksheetCardKey(card);
        this.marksheetSelectedFiles.set(key, Array.isArray(files) ? files : []);
    }

    pruneMarksheetSelectionState() {
        const activeKeys = new Set(this.cards.filter(Boolean).map((card) => this.getMarksheetCardKey(card)));
        Array.from(this.marksheetSelectedFiles.keys()).forEach((key) => {
            if (!activeKeys.has(key)) {
                this.marksheetSelectedFiles.delete(key);
            }
        });
    }

    filesToDataTransfer(files) {
        const dataTransfer = new DataTransfer();
        Array.from(files || []).forEach((item) => dataTransfer.items.add(item.file || item));
        return dataTransfer;
    }

    createSelectedMarksheetRecord(file) {
        const previewUrl = URL.createObjectURL(file);
        const extension = String(file.name || '').split('.').pop().toLowerCase();
        return {
            source: 'selected',
            sourceIndex: 0,
            file,
            file_name: file.name,
            original_name: file.name,
            size: file.size,
            previewUrl,
            previewType: ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension) ? 'image' : (extension === 'pdf' ? 'pdf' : 'other')
        };
    }

    uniqueFiles(existingRecords, newFiles) {
        const seen = new Set();
        const merged = [];
        const pushRecord = (record) => {
            const file = record && record.file ? record.file : record;
            if (!file) return;
            const key = [file.name, file.size, file.lastModified || 0].join('|');
            if (seen.has(key)) return;
            seen.add(key);
            if (record && record.file) {
                merged.push(record);
            } else {
                merged.push(this.createSelectedMarksheetRecord(file));
            }
        };
        Array.from(existingRecords || []).forEach(pushRecord);
        Array.from(newFiles || []).forEach((file) => pushRecord(file));
        return merged;
    }

    showTab(index) {
        const maxVisibleIndex = Math.max(0, this.visibleEducationCount - 1);
        const safeIndex = Math.min(index, maxVisibleIndex);
        super.showTab(safeIndex);
    }

    loadPageData() {
        const dataEl = document.getElementById('educationData');
        if (!dataEl) {
            console.warn('Ã¢Å¡Â Ã¯Â¸Â Education data element not found');
            this.savedRows = [];
            return;
        }

        try {
            this.savedRows = JSON.parse(dataEl.dataset.rows || '[]');
            console.log(`Ã°Å¸â€œÂ¥ Loaded ${this.savedRows.length} education records from DB`);
        } catch (e) {
            console.error('Ã¢ÂÅ’ Failed to parse education data', e);
            this.savedRows = [];
        }
    }

    async initCards() {
        if (!this.savedRows || this.savedRows.length === 0) {
            console.log('Ã°Å¸â€œÂ­ No saved education data to populate');
            return;
        }

        console.log(`Ã°Å¸â€â€ž Initializing cards for ${this.savedRows.length} records`);

        var maxPopulate = this.cards.length;
        if (this.requiredCount && this.requiredCount > 0) {
            maxPopulate = Math.min(maxPopulate, this.requiredCount);
        }

        for (let i = 0; i < maxPopulate; i++) {
            if (this.cards[i] && this.savedRows[i]) {
                console.log(`Ã°Å¸â€œÂ Populating card ${i} with data:`, this.savedRows[i]);
                this.populateCard(this.cards[i], this.savedRows[i], i);
            }
        }
    }

    populateCard(card, data = {}, index) {
        console.log(` EducationManager.populateCard() for card ${index}`, data);
        this.prepareExtendedInputs(card, index);
        
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
                this.syncSingleFileExistingState(box, data.marksheet_file, url);
                console.log(`   Added marksheet: ${data.marksheet_file}`);
            }
        } else {
            const input = card.querySelector('input[name="marksheet_file[]"]');
            const box = this.getUploadBoxFromInput(input);
            this.syncSingleFileExistingState(box, '', '');
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
                this.syncSingleFileExistingState(box, data.degree_file, url);
                console.log(`   Added degree: ${data.degree_file}`);
            }
        } else {
            const input = card.querySelector('input[name="degree_file[]"]');
            const box = this.getUploadBoxFromInput(input);
            this.syncSingleFileExistingState(box, '', '');
        }

        // Populate form fields
        const fieldMap = {
            'qualification[]': data.qualification,
            'college_name[]': data.college_name,
            'university_board[]': data.university_board,
            'roll_number[]': data.roll_number,
            'college_website[]': data.college_website,
            'college_address[]': data.college_address,
            'year_of_passing[]': data.year_of_passing,
            'ca_membership_number[]': data.ca_membership_number
        };

        Object.entries(fieldMap).forEach(([name, value]) => {
            const el = card.querySelector(`[name="${name}"]`);
            if (el && value !== null && value !== undefined) {
                el.value = value;
                console.log(`   Set ${name}: ${value}`);
            }
        });

        this.setInstitutionFields(card, {
            id: data.institution_id || '',
            name: data.institution_display_name || data.college_name || '',
            manualName: data.manual_institution_name || '',
            matchStatus: data.institution_match_status || (data.institution_id ? 'verified_master' : 'manual_pending')
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
            console.log(`   Set insufficient_education_docs for card ${index}: ${isInsufficient}`);
        }

        const supportingInput = card.querySelector('.supporting-documents-input');
        const marksheetDocsInput = card.querySelector('.marksheet-documents-input');
        const marksheetDocsHidden = this.findOrCreateInput(card, `old_marksheet_documents_json[${index}]`, 'hidden');
        const supportingHidden = this.findOrCreateInput(card, `old_supporting_documents_json[${index}]`, 'hidden');
        const qualification = this.normalizeQualification(data.qualification);
        const isMultiMarksheet = qualification === 'UG' || qualification === 'PG';

        if (supportingInput) {
            supportingInput.name = `supporting_documents_${index}[]`;
        }
        if (marksheetDocsInput) {
            marksheetDocsInput.name = `marksheet_documents_${index}[]`;
        }
        const marksheetDocs = Array.isArray(data.marksheet_documents) ? data.marksheet_documents : [];
        this.setSelectedMarksheetFiles(card, []);
        if (marksheetDocsHidden) {
            let existingMarksheetDocs = marksheetDocs;
            if (!existingMarksheetDocs.length && isMultiMarksheet && data.marksheet_file && data.marksheet_file !== 'INSUFFICIENT_DOCUMENTS') {
                existingMarksheetDocs = [{
                    document_slot: 'marksheet',
                    file_name: data.marksheet_file,
                    original_name: data.marksheet_file
                }];
            }
            marksheetDocsHidden.value = JSON.stringify(existingMarksheetDocs);
        }
        if (supportingHidden) {
            supportingHidden.value = JSON.stringify(Array.isArray(data.supporting_documents) ? data.supporting_documents : []);
        }

        this.renderMarksheetDocumentList(
            card,
            isMultiMarksheet
                ? (
                    marksheetDocs.length
                        ? marksheetDocs
                        : (data.marksheet_file && data.marksheet_file !== 'INSUFFICIENT_DOCUMENTS'
                            ? [{ file_name: data.marksheet_file, original_name: data.marksheet_file }]
                            : [])
                )
                : []
        );
        const supportingList = card.querySelector('.supporting-doc-list');
        if (supportingList) {
            const docs = Array.isArray(data.supporting_documents) ? data.supporting_documents : [];
            supportingList.innerHTML = docs.length
                ? docs.map((doc) => `<div>${this.escapeHtml(doc.original_name || doc.file_name || 'Document')}</div>`).join('')
                : '';
        }

        const stateInput = card.querySelector('[name="education_state[]"]');
        if (stateInput) {
            stateInput.value = insufficientCheckbox && insufficientCheckbox.checked
                ? this.STATES.INSUFFICIENT_DOCUMENTS
                : this.STATES.ACTIVE;
        }

        this.applyQualificationRules(card);
        this.applyCardState(card);

        console.log(`Ã¢Å“â€¦ Card ${index} populated successfully`);
    }

    prepareExtendedInputs(card, index) {
        const supportingInput = card.querySelector('.supporting-documents-input');
        const marksheetDocsInput = card.querySelector('.marksheet-documents-input');
        if (supportingInput) {
            supportingInput.name = `supporting_documents_${index}[]`;
        }
        if (marksheetDocsInput) {
            marksheetDocsInput.name = `marksheet_documents_${index}[]`;
        }
        const marksheetHidden = card.querySelector('[data-marksheet-json]');
        if (marksheetHidden) {
            marksheetHidden.name = `old_marksheet_documents_json[${index}]`;
        } else {
            this.findOrCreateInput(card, `old_marksheet_documents_json[${index}]`, 'hidden');
        }
        const hidden = card.querySelector('[data-supporting-json]');
        if (hidden) {
            hidden.name = `old_supporting_documents_json[${index}]`;
        } else {
            this.findOrCreateInput(card, `old_supporting_documents_json[${index}]`, 'hidden');
        }
    }

    setupInstitutionSearch() {
        this.institutionSearchTimers = this.institutionSearchTimers || new Map();
        this.institutionSearchControllers = this.institutionSearchControllers || new Map();
        this.institutionSearchQueries = this.institutionSearchQueries || new Map();
        this.addEventListener(document, 'input', (e) => {
            const input = e.target.closest('[data-institution-search]');
            if (!input) return;
            this.handleInstitutionInput(input);
        });
        this.addEventListener(document, 'focusin', (e) => {
            const input = e.target.closest('[data-institution-search]');
            if (!input) return;
            this.handleInstitutionFocus(input);
        });
        this.addEventListener(document, 'click', (e) => {
            const trigger = e.target.closest('[data-institution-trigger]');
            if (trigger) {
                e.preventDefault();
                const card = trigger.closest('.education-card');
                const input = card?.querySelector('[data-institution-search]');
                if (input) {
                    input.focus();
                    this.handleInstitutionFocus(input);
                }
                return;
            }
            const option = e.target.closest('[data-institution-option]');
            if (option) {
                e.preventDefault();
                this.selectInstitutionOption(option);
                return;
            }
            const manual = e.target.closest('[data-institution-manual]');
            if (manual) {
                e.preventDefault();
                this.selectManualInstitution(manual);
                return;
            }
            if (!e.target.closest('.form-control')) {
                document.querySelectorAll('[data-institution-panel]').forEach((panel) => {
                    panel.style.display = 'none';
                });
            }
        });
    }

    handleInstitutionFocus(input) {
        const card = input.closest('.education-card');
        if (!card) return;
        const panel = this.getInstitutionPanel(card, input);
        if (!panel) return;

        const value = input.value.trim();
        if (value.length < 2) {
            panel.innerHTML = '<div class="institution-search-empty">Type at least 2 characters</div>';
            panel.style.display = value ? 'block' : 'none';
            return;
        }

        panel.style.display = 'block';
        this.scheduleInstitutionSearch(card, value);
    }

    handleInstitutionInput(input) {
        const card = input.closest('.education-card');
        if (!card) return;
        const value = input.value.trim();
        const key = this.getMarksheetCardKey(card);
        clearTimeout(this.institutionSearchTimers.get(key));
        this.institutionSearchQueries.set(key, value);

        this.setInstitutionFields(card, {
            id: '',
            name: value,
            manualName: value,
            matchStatus: value ? 'manual_pending' : ''
        });

        const panel = this.getInstitutionPanel(card, input);
        if (!panel) return;

        if (value.length < 2) {
            this.abortInstitutionSearch(key);
            panel.innerHTML = '<div class="institution-search-empty">Type at least 2 characters</div>';
            panel.style.display = value ? 'block' : 'none';
            return;
        }

        this.scheduleInstitutionSearch(card, value);
    }

    getInstitutionPanel(card, input) {
        const panel = card?.querySelector('[data-institution-panel]');
        if (!panel) return null;
        const shell = input?.closest('.institution-select-shell');
        if (shell && panel.parentElement !== shell) {
            shell.appendChild(panel);
        }
        return panel;
    }

    scheduleInstitutionSearch(card, value) {
        const key = this.getMarksheetCardKey(card);
        clearTimeout(this.institutionSearchTimers.get(key));
        this.institutionSearchQueries.set(key, value);
        this.institutionSearchTimers.set(key, setTimeout(() => {
            this.fetchInstitutionResults(card, value);
        }, 300));
    }

    abortInstitutionSearch(key) {
        const controller = this.institutionSearchControllers.get(key);
        if (controller) {
            controller.abort();
            this.institutionSearchControllers.delete(key);
        }
    }

    clearInstitutionSearchState(card, value = '') {
        const key = this.getMarksheetCardKey(card);
        clearTimeout(this.institutionSearchTimers.get(key));
        this.institutionSearchQueries.set(key, value);
        this.abortInstitutionSearch(key);
    }

    async fetchInstitutionResults(card, query) {
        const key = this.getMarksheetCardKey(card);
        if (this.institutionSearchQueries.get(key) !== query) return;
        const panel = card.querySelector('[data-institution-panel]');
        if (!panel) return;
        panel.innerHTML = '<div class="institution-search-empty">Searching institutions...</div>';
        panel.style.display = 'block';
        this.abortInstitutionSearch(key);
        const controller = new AbortController();
        this.institutionSearchControllers.set(key, controller);

        try {
            const qualification = card.querySelector('select[name="qualification[]"]')?.value || '';
            const type = this.inferInstitutionTypeFilter(qualification);
            const params = new URLSearchParams({ q: query, limit: '10' });
            if (type) params.set('type', type);
            const base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            const response = await fetch(`${base}/api/master/institutions/search.php?${params.toString()}`, {
                credentials: 'same-origin',
                signal: controller.signal
            });
            const data = await response.json();
            if (
                this.institutionSearchControllers.get(key) !== controller ||
                this.institutionSearchQueries.get(key) !== query ||
                (card.querySelector('[data-institution-search]')?.value || '').trim() !== query
            ) {
                return;
            }
            const rows = Array.isArray(data.data) ? data.data : [];
            this.renderInstitutionResults(card, query, rows);
        } catch (e) {
            if (e && e.name === 'AbortError') return;
            panel.innerHTML = '<div class="institution-search-empty">Institution search unavailable. You can enter manually.</div>';
        } finally {
            if (this.institutionSearchControllers.get(key) === controller) {
                this.institutionSearchControllers.delete(key);
            }
        }
    }

    renderInstitutionResults(card, query, rows) {
        const panel = card.querySelector('[data-institution-panel]');
        if (!panel) return;
        const safeQuery = this.escapeHtml(query);
        const options = rows.map((row) => `
            <button type="button" class="institution-search-option" data-institution-option
                    data-id="${this.escapeAttr(row.id)}"
                    data-name="${this.escapeAttr(row.name)}"
                    data-university="${this.escapeAttr(row.university || row.board || '')}">
                <span class="institution-search-name">${this.escapeHtml(row.name)}</span>
                <span class="institution-search-meta">${this.escapeHtml([row.city, row.state, row.university || row.board].filter(Boolean).join(' Â· '))}</span>
            </button>
        `).join('');

        panel.innerHTML = `
            ${options || '<div class="institution-search-empty">No matching institution found.</div>'}
            <button type="button" class="institution-search-manual" data-institution-manual data-name="${this.escapeAttr(query)}">
                Institution not found? Use "${safeQuery}" manually
            </button>
        `;
        panel.style.display = 'block';
    }

    selectInstitutionOption(option) {
        const card = option.closest('.education-card');
        if (!card) return;
        const name = option.dataset.name || '';
        const university = option.dataset.university || '';
        const input = card.querySelector('[data-institution-search]');
        if (input) input.value = name;
        this.clearInstitutionSearchState(card, name);
        this.setInstitutionFields(card, {
            id: option.dataset.id || '',
            name,
            manualName: '',
            matchStatus: 'verified_master'
        });
        const universityInput = card.querySelector('[name="university_board[]"]');
        if (universityInput && !universityInput.value.trim() && university) {
            universityInput.value = university;
        }
        const panel = card.querySelector('[data-institution-panel]');
        if (panel) panel.style.display = 'none';
    }

    selectManualInstitution(button) {
        const card = button.closest('.education-card');
        if (!card) return;
        const name = button.dataset.name || card.querySelector('[data-institution-search]')?.value || '';
        const input = card.querySelector('[data-institution-search]');
        if (input) input.value = name;
        this.clearInstitutionSearchState(card, name);
        this.setInstitutionFields(card, {
            id: '',
            name,
            manualName: name,
            matchStatus: 'manual_pending'
        });
        this.captureManualInstitutionSuggestion(card, name);
        const panel = card.querySelector('[data-institution-panel]');
        if (panel) panel.style.display = 'none';
    }

    async captureManualInstitutionSuggestion(card, name) {
        if (!name.trim()) return;
        try {
            const base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            await fetch(`${base}/api/master/institutions/manual-suggestion.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    institution_name: name,
                    university_or_board: card.querySelector('[name="university_board[]"]')?.value || '',
                    qualification: card.querySelector('[name="qualification[]"]')?.value || '',
                    application_id: window.CANDIDATE_APP_ID || ''
                })
            });
        } catch (_e) {
        }
    }

    setInstitutionFields(card, values) {
        if (!card) return;
        const set = (selector, value) => {
            const el = card.querySelector(selector);
            if (el) el.value = value || '';
        };
        set('[data-institution-id]', values.id || '');
        set('[data-institution-display-name]', values.name || '');
        set('[data-manual-institution-name]', values.manualName || '');
        set('[data-institution-match-status]', values.matchStatus || '');
    }

    inferInstitutionTypeFilter(qualification) {
        const normalized = this.normalizeQualification(qualification);
        if (['10th', '12th', 'SSLC'].includes(normalized)) return 'School';
        if (normalized === 'PUC') return 'PU College';
        if (normalized === 'Diploma') return 'Diploma Institute';
        if (normalized === 'International') return 'International University';
        return '';
    }

    escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        }[ch]));
    }

    escapeAttr(value) {
        return this.escapeHtml(value);
    }

    getMarksheetInputs(card) {
        return {
            single: card ? card.querySelector('input[name="marksheet_file[]"]') : null,
            multiple: card ? card.querySelector('.marksheet-documents-input') : null
        };
    }

    parseMarksheetDocuments(rawValue) {
        if (!rawValue) return [];
        try {
            const parsed = JSON.parse(rawValue);
            return Array.isArray(parsed) ? parsed.filter((doc) => doc && typeof doc === 'object') : [];
        } catch (_e) {
            return [];
        }
    }

    getPreviewTypeFromName(name) {
        const extension = String(name || '').split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension)) return 'image';
        if (extension === 'pdf') return 'pdf';
        return 'other';
    }

    getCombinedMarksheetDocuments(card) {
        if (!card) return [];

        const hidden = card.querySelector('[data-marksheet-json]');
        const existingDocs = this.parseMarksheetDocuments(hidden ? hidden.value : '').map((doc, index) => ({
            ...doc,
            source: 'existing',
            sourceIndex: index
        })).filter((doc) => String(doc.file_name || '').trim() !== '');

        const selectedDocs = this.getSelectedMarksheetFiles(card).map((record, index) => ({
            ...record,
            source: 'selected',
            sourceIndex: index,
            file_name: record.file_name || record.original_name || record.file?.name || '',
            original_name: record.original_name || record.file_name || record.file?.name || '',
            previewUrl: record.previewUrl || (record.file ? URL.createObjectURL(record.file) : record.previewUrl),
            previewType: record.previewType || 'other'
        }));

        return [...existingDocs, ...selectedDocs];
    }

    removeMarksheetDocument(card, source, index) {
        if (!card || !source || index < 0) return;

        if (source === 'selected') {
            const input = card.querySelector('.marksheet-documents-input');
            const current = this.getSelectedMarksheetFiles(card);
            if (current.length) {
                const remaining = current.filter((_file, fileIndex) => fileIndex !== index);
                const removed = current[index];
                if (removed && removed.previewUrl) {
                    try {
                        URL.revokeObjectURL(removed.previewUrl);
                    } catch (_e) {
                    }
                }
                this.setSelectedMarksheetFiles(card, remaining);
                if (input) {
                    input.files = this.filesToDataTransfer(remaining).files;
                }
            }
        } else if (source === 'existing') {
            const hidden = card.querySelector('[data-marksheet-json]');
            if (hidden) {
                const docs = this.parseMarksheetDocuments(hidden.value);
                docs.splice(index, 1);
                hidden.value = JSON.stringify(docs);
            }
        }

        this.renderMarksheetDocumentList(card, this.getCombinedMarksheetDocuments(card));
        this.refreshEducationState();
        this.updateTabStatus();
    }

    syncMarksheetLegacyValue(card, docs) {
        if (!card) return;
        const hidden = card.querySelector(`[name^="old_marksheet_file["]`);
        if (!hidden) return;

        const first = Array.isArray(docs) && docs.length ? docs[0] : null;
        if (!first) {
            hidden.value = '';
            return;
        }

        hidden.value = String(first.file_name || first.original_name || '').trim();
    }

    syncSingleFileExistingState(box, fileName, previewUrl) {
        if (!box) return;
        if (fileName) {
            box.dataset.existingFileName = fileName;
            box.dataset.existingFileUrl = previewUrl || '';
        } else {
            delete box.dataset.existingFileName;
            delete box.dataset.existingFileUrl;
        }
    }

    handleSingleFileRemove(card, input) {
        if (!card || !input) return;
        const box = this.getUploadBoxFromInput(input);
        const nameEl = box ? box.querySelector('[data-file-name]') : null;
        const hiddenSelector = input.name.includes('marksheet_file')
            ? '[name^="old_marksheet_file["]'
            : '[name^="old_degree_file["]';
        const hidden = card.querySelector(hiddenSelector);
        const hasLocalSelection = !!(nameEl && nameEl.dataset.localFile);
        const existingName = box ? (box.dataset.existingFileName || '') : '';
        const existingUrl = box ? (box.dataset.existingFileUrl || '') : '';

        input.value = '';

        if (hasLocalSelection && existingName) {
            if (hidden) hidden.value = existingName;
            if (box) {
                this.setUploadBox(box, existingName, existingUrl || '', false);
            }
        } else {
            if (hidden) hidden.value = '';
            if (box) {
                this.clearUploadBox(box);
            }
            if (box) {
                this.syncSingleFileExistingState(box, '', '');
            }
        }

        this.refreshEducationState();
        this.updateTabStatus();
    }

    renderMarksheetDocumentList(card, docs) {
        const items = Array.isArray(docs) ? docs : [];
        const list = card ? card.querySelector('.marksheet-doc-list') : null;
        const control = card ? card.querySelector('.marksheet-label')?.closest('.form-control') : null;
        const box = control ? control.querySelector('[data-file-upload]') : null;
        const btn = box ? box.querySelector('[data-file-name]') : null;
        const rules = this.getQualificationRules(card);
        const isMultiMarksheet = !!rules.multiMarksheet;
        const first = items.length ? items[0] : null;
        const firstName = first ? (first.original_name || first.file_name || 'Marksheet') : '';
        const firstUrl = first ? (first.url || first.previewUrl || '') : '';
        const firstType = this.getPreviewTypeFromName(firstName);

        if (box) {
            box.classList.toggle('has-chip-list', isMultiMarksheet);
        }

        this.syncMarksheetLegacyValue(card, items);

        if (list) {
            if (isMultiMarksheet) {
                list.innerHTML = items.length
                    ? items.map((doc) => {
                        const docName = this.escapeHtml(doc.original_name || doc.file_name || 'Marksheet');
                        const sourceIndex = Number.isInteger(doc.sourceIndex) ? doc.sourceIndex : 0;
                        const url = doc.url || doc.previewUrl || '';
                        const type = doc.previewType || this.getPreviewTypeFromName(doc.original_name || doc.file_name || '');
                        return `
                            <div class="marksheet-doc-item ${doc.source === 'selected' ? 'is-new' : 'is-saved'}" title="${docName}">
                                <button
                                    type="button"
                                    class="marksheet-doc-preview preview-btn"
                                    data-url="${this.escapeHtml(url)}"
                                    data-name="${docName}"
                                    data-type="${type}"
                                >
                                    <i class="fas fa-${type === 'image' ? 'image' : type === 'pdf' ? 'file-pdf' : 'file'}"></i>
                                    <span class="marksheet-doc-name">${docName}</span>
                                </button>
                                <button
                                    type="button"
                                    class="marksheet-doc-remove"
                                    data-marksheet-remove
                                    data-doc-source="${doc.source || 'existing'}"
                                    data-doc-index="${sourceIndex}"
                                    aria-label="Remove ${docName}"
                                >
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `;
                    }).join('')
                    : '';
            } else {
                list.innerHTML = items.length
                    ? items.map((doc) => {
                        const docName = this.escapeHtml(doc.original_name || doc.file_name || 'Marksheet');
                        const sourceIndex = Number.isInteger(doc.sourceIndex) ? doc.sourceIndex : 0;
                        return `
                            <div class="marksheet-doc-item is-saved" title="${docName}">
                                <span class="marksheet-doc-name">${docName}</span>
                                <button
                                    type="button"
                                    class="marksheet-doc-remove"
                                    data-marksheet-remove
                                    data-doc-source="${doc.source || 'existing'}"
                                    data-doc-index="${sourceIndex}"
                                    aria-label="Remove ${docName}"
                                >
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `;
                    }).join('')
                    : '';
            }
        }

        if (btn) {
            if (isMultiMarksheet) {
                btn.textContent = '';
                btn.style.display = 'none';
                btn.disabled = true;
                btn.classList.remove('preview-btn');
                btn.removeAttribute('data-url');
                btn.removeAttribute('data-name');
                btn.removeAttribute('data-type');
            } else {
                btn.style.display = '';
                btn.textContent = items.length
                    ? (firstName || `${items.length} file${items.length === 1 ? '' : 's'} selected`)
                    : 'No file chosen';
                btn.disabled = !items.length;
            }
            btn.dataset.hasValue = items.length ? '1' : '';
        }

        if (box) {
            if (isMultiMarksheet) {
                box.removeAttribute('data-object-url');
                if (btn) {
                    btn.dataset.hasValue = items.length ? '1' : '';
                }
            } else if (items.length) {
                const previewUrl = firstUrl;
                if (previewUrl) box.setAttribute('data-object-url', previewUrl);
                else box.removeAttribute('data-object-url');
                if (btn) {
                    btn.classList.add('preview-btn');
                    btn.removeAttribute('disabled');
                    btn.setAttribute('data-name', firstName);
                    btn.setAttribute('data-type', firstType);
                    if (previewUrl) btn.setAttribute('data-url', previewUrl);
                    btn.dataset.hasValue = '1';
                    btn.innerHTML = `<i class="fas fa-${firstType === 'image' ? 'image' : firstType === 'pdf' ? 'file-pdf' : 'file'}"></i><span>${this.escapeHtml(firstName)}</span>`;
                }
            } else if (btn) {
                btn.classList.remove('preview-btn');
                btn.removeAttribute('data-url');
                btn.removeAttribute('data-name');
                btn.removeAttribute('data-type');
                delete btn.dataset.hasValue;
                box.removeAttribute('data-object-url');
            }
        }
    }

    toggleFileInputs(card, isInsufficient) {
        const marksheetFile = card.querySelector('input[name="marksheet_file[]"]');
        const marksheetDocsInput = card.querySelector('.marksheet-documents-input');
        const degreeFile = card.querySelector('input[name="degree_file[]"]');
        const supportingInput = card.querySelector('.supporting-documents-input');
        const degreeField = card.querySelector('.degree-upload-field');
        const rules = this.getQualificationRules(card);
        const useMultiMarksheet = !!rules.multiMarksheet;
        const marksheetFileRow = marksheetFile ? marksheetFile.closest('.form-control') : null;
        
        if (marksheetFile) {
            marksheetFile.disabled = isInsufficient || useMultiMarksheet;
            marksheetFile.required = !isInsufficient && !!rules.requireMarksheet && !useMultiMarksheet;
            if (marksheetFileRow) {
                marksheetFileRow.dataset.marksheetMode = useMultiMarksheet ? 'multiple' : 'single';
            }
            if (isInsufficient) {
                marksheetFile.value = '';
                this.clearUploadBox(this.getUploadBoxFromInput(marksheetFile));
            }
        }
        if (marksheetDocsInput) {
            marksheetDocsInput.disabled = isInsufficient || !useMultiMarksheet;
            if (isInsufficient) {
                marksheetDocsInput.value = '';
                const marksheetList = card.querySelector('.marksheet-doc-list');
                if (marksheetList) {
                    marksheetList.innerHTML = '';
                }
            }
        }
        
        if (degreeFile) {
            const shouldShowDegree = !!rules.showDegree;
            if (degreeField) {
                degreeField.style.display = shouldShowDegree ? '' : 'none';
            }
            degreeFile.disabled = isInsufficient || !shouldShowDegree;
            degreeFile.required = !isInsufficient && !!rules.requireDegree;
            if (isInsufficient) {
                degreeFile.value = '';
                this.clearUploadBox(this.getUploadBoxFromInput(degreeFile));
            }
        }

        if (supportingInput) {
            supportingInput.disabled = isInsufficient;
            if (isInsufficient) {
                supportingInput.value = '';
            }
        }
    }

    setupStateController() {
        this.addEventListener(document, 'change', (e) => {
            if (
                e.target.matches('select[name="qualification[]"]') ||
                e.target.matches('input[name="year_from[]"]') ||
                e.target.matches('input[name="year_to[]"]') ||
                e.target.matches('input[name="year_of_passing[]"]') ||
                e.target.matches('input[name="insufficient_education_docs[]"]') ||
                e.target.matches('.no-further-education-checkbox')
            ) {
                const card = e.target.closest('.education-card');
                if (!card) return;
                this.handleStateChange(card, e.target);
            }
        });
    }

    handleStateChange(card, trigger) {
        const index = parseInt(card.dataset.cardIndex || '-1', 10);
        if (index < 0) return;

        if (trigger.classList.contains('no-further-education-checkbox')) {
            const lastVisibleIndex = Math.max(0, (this.visibleEducationCount || 1) - 1);
            const canTerminate = index < lastVisibleIndex;
            const canContinue = index === lastVisibleIndex && index < this.maxEducationCount - 1;

            if (!trigger.checked || (!canContinue && !canTerminate)) {
                trigger.checked = false;
                this.refreshEducationState();
                return;
            }

            if (canTerminate) {
                this.visibleEducationCount = index + 1;
                this.currentTab = index;
                this.persistVisibleEducationCount();
                this.refreshEducationState();
                return;
            }

            if (!this.cards[index + 1]) {
                this.addCard(index + 1, null);
                this.fullEducationCount = this.cards.filter(Boolean).length;
            }

            this.cards.forEach((otherCard) => {
                const otherCheckbox = otherCard?.querySelector('.no-further-education-checkbox');
                if (otherCheckbox) otherCheckbox.checked = false;
            });

            this.visibleEducationCount = Math.min(index + 2, this.maxEducationCount);
            this.currentTab = this.visibleEducationCount - 1;
            const nextLabel = this.cards[this.currentTab]?.querySelector('.no-further-education-row .compact-checkbox-label');
            if (nextLabel) {
                nextLabel.textContent = 'I have further educations';
            }
            this.persistVisibleEducationCount();
        }

        this.refreshEducationState();
    }

    normalizeQualification(value) {
        return String(value || '').trim();
    }

    parseEducationMonth(value) {
        if (!value || !/^\d{4}-\d{2}$/.test(String(value))) {
            return null;
        }
        const parsed = new Date(`${value}-01T00:00:00`);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    parseEducationYear(value) {
        const match = String(value || '').trim().match(/\b(19|20)\d{2}\b/);
        return match ? parseInt(match[0], 10) : null;
    }

    getQualificationRank(qualification) {
        return this.qualificationOrder[this.normalizeQualification(qualification)] || 0;
    }

    getEducationTimelineRows() {
        const visibleLimit = Math.max(1, this.visibleEducationCount || this.cards.filter(Boolean).length || 1);
        return this.cards
            .map((card, index) => {
                if (!card || card.dataset.suppressed === '1' || index >= visibleLimit) return null;
                const qualification = card.querySelector('select[name="qualification[]"]')?.value || '';
                const fromValue = card.querySelector('[name="year_from[]"]')?.value || '';
                const toValue = card.querySelector('[name="year_to[]"]')?.value || '';
                const passingValue = card.querySelector('[name="year_of_passing[]"]')?.value || '';
                return {
                    card,
                    index,
                    qualification,
                    rank: this.getQualificationRank(qualification),
                    from: this.parseEducationMonth(fromValue),
                    to: this.parseEducationMonth(toValue),
                    passingYear: this.parseEducationYear(passingValue)
                };
            })
            .filter(Boolean);
    }

    resetEducationTimelineWarnings() {
        this.cards.forEach((card) => {
            if (!card) return;
            card.dataset.educationTimelineIssue = '0';
        });
    }

    showEducationTimelineNotification(id, message) {
        if (!id || !message || this.activeEducationTimelineWarnings.has(id)) return;
        this.activeEducationTimelineWarnings.add(id);

        if (window.CandidateNotify && typeof window.CandidateNotify.warn === 'function') {
            window.CandidateNotify.warn(message, {
                id: `education-timeline-${id}`,
                title: 'Education timeline needs review',
                timeout: 4200
            });
        } else if (window.Router && typeof window.Router.showNotification === 'function') {
            window.Router.showNotification(message, 'warning');
        }
    }

    clearEducationTimelineNotification(id) {
        const toastKey = `education-timeline-${id}`;
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

    syncEducationTimelineNotifications(currentWarnings) {
        const activeIds = currentWarnings instanceof Set ? currentWarnings : new Set();
        this.activeEducationTimelineWarnings.forEach((id) => {
            if (!activeIds.has(id)) {
                this.clearEducationTimelineNotification(id);
                this.activeEducationTimelineWarnings.delete(id);
            }
        });
    }

    monthGapDays(newerStart, olderEnd) {
        if (!newerStart || !olderEnd) return 0;
        return Math.floor((newerStart.getTime() - olderEnd.getTime()) / 86400000);
    }

    refreshEducationTimelineIntelligence() {
        this.resetEducationTimelineWarnings();
        const rows = this.getEducationTimelineRows();
        const currentWarnings = new Set();

        rows.forEach((row) => {
            if (!row.to || !row.passingYear) return;
            const toYear = row.to.getFullYear();
            if (toYear !== row.passingYear) {
                const warningId = `passing-${row.index}`;
                currentWarnings.add(warningId);
                this.showEducationTimelineNotification(
                    warningId,
                    `Education ${row.index + 1}: To Year and Year of Passing should match.`
                );
            }
        });

        for (let i = 0; i < rows.length - 1; i++) {
            const higher = rows[i];

            for (let j = i + 1; j < rows.length; j++) {
                const lower = rows[j];
                const issueCard = higher.rank < lower.rank ? higher.card : lower.card;
                const rankIssue = higher.rank > 0 && lower.rank > 0 && higher.rank < lower.rank;
                const dateOrderIssue = higher.from && lower.to && lower.to.getTime() > higher.from.getTime();

                if (rankIssue || dateOrderIssue) {
                    issueCard.dataset.educationTimelineIssue = '1';
                    const warningId = dateOrderIssue
                        ? `date-${higher.index}-${lower.index}`
                        : `order-${higher.index}-${lower.index}`;
                    const warningMessage = dateOrderIssue
                        ? `Education ${lower.index + 1} ends after Education ${higher.index + 1} starts.`
                        : `Education ${higher.index + 1} should be higher than Education ${lower.index + 1}.`;
                    currentWarnings.add(warningId);
                    this.showEducationTimelineNotification(warningId, warningMessage);
                }
            }
        }

        for (let i = 0; i < rows.length - 1; i++) {
            const higher = rows[i];
            const lower = rows[i + 1];
            const dateOrderIssue = higher.from && lower.to && lower.to.getTime() > higher.from.getTime();
            const gapDays = this.monthGapDays(higher.from, lower.to);

            if (!dateOrderIssue && gapDays > this.educationGapThresholdDays) {
                const months = Math.max(1, Math.round(gapDays / 30));
                const warningId = `gap-${higher.index}-${lower.index}`;
                currentWarnings.add(warningId);
                this.showEducationTimelineNotification(
                    warningId,
                    `Education gap detected: approx. ${months} months.`
                );
            }
        }

        this.syncEducationTimelineNotifications(currentWarnings);
    }

    getQualificationRules(card) {
        const qualificationInput = card.querySelector('select[name="qualification[]"]');
        const qualification = this.normalizeQualification(qualificationInput ? qualificationInput.value : '');

        return this.qualificationRules[qualification] || {
            showDegree: false,
            requireMarksheet: false,
            multiMarksheet: false,
            requireDegree: false,
            marksheetInstruction: 'Upload marksheet (Max 5MB)',
            degreeInstruction: ''
        };
    }

    applyQualificationRules(card) {
        const rules = this.getQualificationRules(card);
        const marksheetInstruction = card.querySelector('.marksheet-instruction');
        const degreeInstruction = card.querySelector('.degree-instruction');
        const degreeField = card.querySelector('.degree-upload-field');
        const marksheetLabel = card.querySelector('.marksheet-label');
        const degreeLabel = card.querySelector('.degree-label');

        if (marksheetLabel) {
            marksheetLabel.textContent = rules.requireMarksheet ? 'Marksheet *' : 'Marksheet';
        }
        if (degreeLabel) {
            degreeLabel.textContent = rules.requireDegree ? 'Degree Certificate *' : 'Degree Certificate';
        }
        if (marksheetInstruction) {
            marksheetInstruction.textContent = rules.marksheetInstruction;
        }
        if (degreeInstruction) {
            degreeInstruction.textContent = rules.showDegree ? rules.degreeInstruction : '';
        }
        if (degreeField) {
            degreeField.style.display = rules.showDegree ? '' : 'none';
        }
        const marksheetList = card.querySelector('.marksheet-doc-list');
        if (marksheetList) {
            marksheetList.style.display = rules.multiMarksheet ? '' : 'none';
            if (!rules.multiMarksheet) {
                marksheetList.innerHTML = '';
            }
        }

        const marksheetChooseBtn = card.querySelector('.marksheet-label')?.closest('.form-control')?.querySelector('[data-file-choose]');
        const marksheetNameBtn = card.querySelector('.marksheet-label')?.closest('.form-control')?.querySelector('[data-file-name]');
        if (marksheetChooseBtn) {
            marksheetChooseBtn.textContent = rules.multiMarksheet ? 'Choose Files' : 'Choose File';
        }
        if (marksheetNameBtn && !marksheetNameBtn.dataset.hasValue) {
            marksheetNameBtn.textContent = rules.multiMarksheet ? 'No files chosen' : 'No file chosen';
        }
    }

    getDesiredVisibleEducationCount() {
        let visibleCount = Math.max(1, Math.min(this.visibleEducationCount || this.fullEducationCount || 1, this.fullEducationCount || 1));

        return Math.max(1, Math.min(visibleCount || 1, this.fullEducationCount || 1));
    }

    getCardState(card) {
        const insufficientCheckbox = card.querySelector('input[name="insufficient_education_docs[]"]');
        if (insufficientCheckbox && insufficientCheckbox.checked) return this.STATES.INSUFFICIENT_DOCUMENTS;
        return this.STATES.ACTIVE;
    }

    applyCardState(card) {
        if (!card) return;
        const state = this.getCardState(card);
        const stateInput = card.querySelector('[name="education_state[]"]');
        const isInsufficient = state === this.STATES.INSUFFICIENT_DOCUMENTS;

        if (stateInput) {
            stateInput.value = state;
        }

        this.toggleFileInputs(card, isInsufficient);
    }

    refreshEducationState() {
        this.fullEducationCount = this.cards.filter(Boolean).length;
        this.visibleEducationCount = this.getDesiredVisibleEducationCount();

        this.cards.forEach((card) => {
            if (!card) return;
            this.applyQualificationRules(card);
            this.applyCardState(card);
        });
        this.persistVisibleEducationCount();
        this.applyVisibleEducationCount();
        this.updateNoFurtherEducationVisibility();
        this.refreshEducationTimelineIntelligence();
    }

    getNoFurtherEducationStorageKey() {
        return `${this.getEducationStoragePrefix()}education_visible_count`;
    }

    getEducationStoragePrefix() {
        const applicationId = String(window.CANDIDATE_APP_ID || '').trim();
        return applicationId ? `candidate:${applicationId}:` : 'candidate:';
    }

    getEducationDraftStorageKey() {
        return `${this.getEducationStoragePrefix()}education_draft`;
    }

    persistVisibleEducationCount() {
        try {
            if (this.deferVisibleEducationPersistence) return;
            const visibleCount = Math.max(1, Math.min(this.visibleEducationCount || this.fullEducationCount || 1, this.fullEducationCount || 1));
            localStorage.setItem(this.getNoFurtherEducationStorageKey(), String(visibleCount));
            localStorage.removeItem('education_visible_count');
        } catch (_e) {
        }
    }

    restoreVisibleEducationCount() {
        try {
            const scopedKey = this.getNoFurtherEducationStorageKey();
            let raw = localStorage.getItem(scopedKey);
            localStorage.removeItem('education_visible_count');
            if (!raw) {
                raw = document.getElementById('educationForm')?.dataset?.serverVisibleCount || '';
            }
            if (!raw) return;
            const parsed = parseInt(raw, 10);
            if (!parsed || parsed < 1) return;
            this.visibleEducationCount = Math.min(parsed, this.fullEducationCount || parsed);
        } catch (_e) {
        }
    }

    applyVisibleEducationCount() {
        const visibleCount = Math.max(1, this.visibleEducationCount || this.cards.filter(Boolean).length || 1);
        const tabs = document.querySelectorAll('.education-tab');
        const visibleCountInput = document.getElementById('visibleEducationCount');

        if (visibleCountInput) {
            visibleCountInput.value = String(visibleCount);
        }

        this.cards.forEach((card, index) => {
            if (!card) return;
            const isVisible = index < visibleCount;
            card.dataset.suppressed = isVisible ? '0' : '1';
            card.style.display = isVisible && index === this.currentTab ? 'block' : 'none';

            card.querySelectorAll('input, select, textarea, button').forEach((el) => {
                if (el.classList.contains('no-further-education-checkbox')) {
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

    updateNoFurtherEducationVisibility() {
        const total = Math.max(1, this.visibleEducationCount || this.cards.filter(Boolean).length);
        this.cards.forEach((card, index) => {
            if (!card) return;
            const row = card.querySelector('.no-further-education-row');
            const checkbox = card.querySelector('.no-further-education-checkbox');
            const label = card.querySelector('.no-further-education-row .compact-checkbox-label');
            if (!row || !checkbox) return;

            const isLastVisible = index === total - 1;
            const isVisibleBeforeLast = index < total - 1;
            const canContinue = isLastVisible && index < this.maxEducationCount - 1;
            const canTerminate = isVisibleBeforeLast;
            row.style.display = canContinue || canTerminate ? '' : 'none';
            checkbox.disabled = !(canContinue || canTerminate);
            checkbox.checked = false;
            if (label) {
                label.textContent = canContinue ? 'I have further educations' : "I don't have further educations";
            }
        });
    }

    getMaxEducationCount() {
        const optionValues = this.countSelect
            ? Array.from(this.countSelect.options || []).map((option) => parseInt(option.value || '0', 10)).filter(Boolean)
            : [];
        return Math.max(1, optionValues.length ? Math.max(...optionValues) : this.maxEducationCount || 8);
    }

    setupInsufficientDocsHandlers() {
        console.log('Ã°Å¸â€Â§ Setting up insufficient documents handlers');
        
        document.addEventListener('change', (e) => {
            if (e.target.matches('input[name="insufficient_education_docs[]"]')) {
                const checkbox = e.target;
                const card = checkbox.closest('.education-card');
                if (card) {
                    const cardIndex = card.dataset.cardIndex || 'unknown';
                    console.log(`Ã°Å¸â€Ëœ Insufficient education docs checkbox changed in card ${cardIndex}: ${checkbox.checked}`);
                    this.applyCardState(card);
                }
            }
        });
    }

setupFileHandlers() {
    console.log('Ã°Å¸â€Â§ Setting up education file handlers');

    this.addEventListener(document, 'click', (e) => {
        const trigger = e.target.closest('[data-file-choose]');
        if (!trigger) return;
        e.preventDefault();
        const box = trigger.closest('[data-file-upload]');
        const control = box ? box.closest('.form-control') : null;
        let input = control ? control.querySelector('input[type="file"][data-file-input]') : null;
        const card = trigger.closest('.education-card');
        if (card && control && control.querySelector('.marksheet-label')) {
            const marksheetInputs = this.getMarksheetInputs(card);
            input = this.getQualificationRules(card).multiMarksheet ? marksheetInputs.multiple : marksheetInputs.single;
        }
        if (input) input.click();
    });

    this.addEventListener(document, 'click', (e) => {
        const remove = e.target.closest('[data-marksheet-remove]');
        if (!remove) return;
        e.preventDefault();

        const card = remove.closest('.education-card');
        if (!card) return;

        const source = remove.dataset.docSource || 'existing';
        const index = parseInt(remove.dataset.docIndex || '-1', 10);
        if (Number.isNaN(index) || index < 0) return;

        this.removeMarksheetDocument(card, source, index);
    });

    this.addEventListener(document, 'click', (e) => {
        const remove = e.target.closest('[data-file-remove]');
        if (!remove) return;
        e.preventDefault();

        const box = remove.closest('[data-file-upload]');
        const card = remove.closest('.education-card');
        if (!box || !card) return;

        const input = box.closest('.form-control')?.querySelector('input[type="file"][data-file-input]');
        if (!input) return;

        this.handleSingleFileRemove(card, input);
    });

    this.addEventListener(document, 'change', (e) => {
            if (
                e.target.matches('input[name="marksheet_file[]"]') ||
                e.target.matches('.marksheet-documents-input') ||
                e.target.matches('input[name="degree_file[]"]') ||
                e.target.matches('.supporting-documents-input')
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
            const files = Array.from(input.files || []);
            const validationTarget = input.classList.contains('marksheet-documents-input') || input.classList.contains('supporting-documents-input')
                ? files
                : (file ? [file] : []);
            const invalid = validationTarget.map((candidate) => this.validateUploadFile(candidate, allowed, 5 * 1024 * 1024)).find((result) => !result.ok);
            if (invalid) {
                if (window.CandidateNotify) {
                    window.CandidateNotify.error(invalid.message, {
                        title: 'Invalid upload',
                        sticky: false,
                        timeout: 4200
                    });
                    window.CandidateNotify.setFieldError(box || input, invalid.message);
                }
                input.value = '';
                this.clearUploadBox(box);
                if (box) {
                    const errEl = box.querySelector('[data-file-error]');
                    if (errEl) errEl.textContent = invalid.message;
                }
                if (input.classList.contains('marksheet-documents-input') && card) {
                    this.renderMarksheetDocumentList(card, this.getCombinedMarksheetDocuments(card));
                }
            } else if (box && window.CandidateNotify) {
                window.CandidateNotify.clearFieldError(box);
            }

            if (!card || input.files.length === 0) return;

                console.log(`Ã°Å¸â€œâ€ž File selected: ${input.files[0].name}`);

                // Ã°Å¸â€Â¥ FORCE CLEAR INSUFFICIENT STATE
                const insufficientCheckbox =
                    card.querySelector('input[name="insufficient_education_docs[]"]');

                if (insufficientCheckbox) {
                    insufficientCheckbox.checked = false;
                }

            // Ã°Å¸â€Â¥ CLEAR DB "INSUFFICIENT_DOCUMENTS" FALLBACK
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

            if (box) {
                if (input.classList.contains('marksheet-documents-input')) {
                    const selected = this.getSelectedMarksheetFiles(card);
                    const incoming = Array.from(input.files || []);
                    const incomingRecords = incoming.map((file) => this.createSelectedMarksheetRecord(file));
                    const merged = this.uniqueFiles(selected, incomingRecords);
                    this.setSelectedMarksheetFiles(card, merged);
                    input.files = this.filesToDataTransfer(merged).files;
                    this.renderMarksheetDocumentList(card, this.getCombinedMarksheetDocuments(card));

                    const latest = merged.length ? merged[merged.length - 1] : null;
                    if (latest && latest.previewUrl && typeof window.openDocumentPreview === 'function') {
                        window.openDocumentPreview(
                            latest.previewUrl,
                            latest.original_name || latest.file_name || 'Marksheet',
                            latest.previewType || this.getPreviewTypeFromName(latest.original_name || latest.file_name || '')
                        );
                    }
                } else if (input.classList.contains('supporting-documents-input')) {
                    const names = Array.from(input.files || []).map((f) => f.name).join(', ');
                    const btn = box.querySelector('[data-file-name]');
                    if (btn) {
                        btn.textContent = names || 'No files chosen';
                        btn.disabled = !names;
                    }
                    const list = card.querySelector('.supporting-doc-list');
                    if (list) {
                        list.innerHTML = Array.from(input.files || []).map((f) => `<div>${this.escapeHtml(f.name)}</div>`).join('');
                    }
                } else if (file) {
                    const url = URL.createObjectURL(file);
                    this.setUploadBox(box, file.name, url, true, file.size);
                    this.syncSingleFileExistingState(box, box.dataset.existingFileName || '', box.dataset.existingFileUrl || '');
                }
            }

            this.refreshEducationState();
            this.updateTabStatus();
        }
    });
}


    renderPreview(card, selector, file, label, folder = 'education') {
        console.log(`Ã°Å¸â€“Â¼Ã¯Â¸Â Rendering preview for ${label}: ${file}`);
        
        // Clear any existing content first
        const previewElement = card.querySelector(selector);
        if (previewElement) {
            previewElement.innerHTML = '';
        }
        
        // Use parent class renderPreview
        const result = super.renderPreview(card, selector, file, label, folder);
        
        if (result) {
            console.log(`Ã¢Å“â€¦ Preview rendered for ${label} in card`);
        }
        
        return result;
    }

    setupFormHandlers() {
        console.log('Ã°Å¸â€Â§ Setting up education form handlers');
        
        const form = document.getElementById('educationForm');
        if (!form) {
            console.error('Ã¢ÂÅ’ Education form not found');
            return;
        }

        // Prevent default form submission
        form.onsubmit = (e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            console.log('Ã¢ÂÅ’ Form submission prevented (handled by EducationManager)');
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
            console.warn('Ã¢Å¡Â Ã¯Â¸Â Next button not found');
        }

        // Handle Previous button
        const prevBtn = document.querySelector('.prev-btn[data-form="educationForm"]');
        if (prevBtn) {
            const newPrevBtn = prevBtn.cloneNode(true);
            prevBtn.parentNode.replaceChild(newPrevBtn, prevBtn);
            
            newPrevBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                console.log('Ã¢Â¬â€¦Ã¯Â¸Â Previous button clicked - navigating to contact');
                if (window.Router && window.Router.navigateTo) {
                    const previousPage = typeof window.Router.getPreviousPage === 'function' ? window.Router.getPreviousPage('education') : 'contact';
                    window.Router.navigateTo(previousPage);
                } else {
                    window.location.href = `${window.APP_BASE_URL}/modules/candidate/contact.php`;
                }
            });
        }

        // Handle Save Draft button
        this.addEventListener(document, 'click', (e) => {
            const draftBtn = e.target.closest('.save-draft-btn[data-page="education"]');
            if (draftBtn) {
                e.preventDefault();
                e.stopImmediatePropagation();
                console.log('Ã°Å¸â€™Â¾ Save draft button clicked');
                this.saveDraft();
            }
        });
        
        console.log('Ã¢Å“â€¦ Education form handlers setup complete');
    }

    loadFromLocalStorage() {
        try {
            localStorage.removeItem('education_draft');
            const raw = localStorage.getItem(this.getEducationDraftStorageKey());
            if (!raw) {
                console.log('Ã°Å¸â€œÂ­ No education draft found in localStorage');
                return;
            }

            const data = JSON.parse(raw);
            console.log('Ã°Å¸â€œÂ¥ Loading education draft from localStorage:', data);

            const count = Math.max(
                data['qualification[]']?.length || 0,
                data['college_name[]']?.length || 0,
                this.savedRows.length 
            );

            if (!count) return;

            console.log(`Ã°Å¸â€â€ž Ensuring ${count} cards for localStorage data`);
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

            console.log('Ã¢Å“â€¦ Education draft loaded from localStorage');
        } catch (error) {
            console.error('Ã¢ÂÅ’ Error loading education draft from localStorage:', error);
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
            formData.set('visibleEducationCount', String(this.visibleEducationCount || this.cards.filter(Boolean).length || 1));

            const response = await fetch(this.getApiEndpoint(), {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification('Ã¢Å“â€¦ Education Draft saved successfully!');
                localStorage.removeItem(this.getEducationDraftStorageKey());
                localStorage.removeItem('education_draft');
            } else {
                this.showNotification((data.message || 'Save failed'), true);
            }

        } catch (err) {
            console.error('Ã¢ÂÅ’ Save draft error:', err);
            this.showNotification('Ã¢ÂÅ’ Network / Server error', true);
        } finally {
            this.isSubmitting = false;
        }
    }

validateForm(isFinalSubmit = false) {
    console.log(`Ã°Å¸â€œâ€¹ Validating education form (isFinalSubmit: ${isFinalSubmit})`);
    this.refreshEducationTimelineIntelligence();

    const form = document.getElementById('educationForm');
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
        if (card.dataset.suppressed === '1' || i >= this.visibleEducationCount) continue;

        /* ================= REQUIRED TEXT FIELDS ================= */

        const requiredFields = [
            { selector: 'select[name="qualification[]"]', label: 'Qualification' },
            { selector: '[name="college_name[]"]', label: 'College/Institution' },
            { selector: '[name="university_board[]"]', label: 'University/Board' },
            { selector: '[name="roll_number[]"]', label: 'Roll Number' },
            { selector: '[name="year_from[]"]', label: 'From Year' },
            { selector: '[name="year_to[]"]', label: 'To Year' },
            { selector: '[name="college_address[]"]', label: 'Place of the Institution' }
        ];

        requiredFields.forEach(field => {
            const input = card.querySelector(field.selector);
            if (input && !input.value.trim()) {
                addError(input, `Education ${i + 1}: ${field.label} is required`);
            }
        });

        const fromInput = card.querySelector('[name="year_from[]"]');
        const toInput = card.querySelector('[name="year_to[]"]');
        const passingInput = card.querySelector('[name="year_of_passing[]"]');
        if (fromInput && toInput && fromInput.value && toInput.value) {
            const fromDate = new Date(`${fromInput.value}-01`);
            const toDate = new Date(`${toInput.value}-01`);

            if (!Number.isNaN(fromDate.getTime()) && !Number.isNaN(toDate.getTime()) && toDate < fromDate) {
                addError(toInput, `Education ${i + 1}: To Year must be the same as or later than From Year`);
            }

            const passingYear = this.parseEducationYear(passingInput ? passingInput.value : '');
            if (!Number.isNaN(toDate.getTime()) && passingYear && toDate.getFullYear() !== passingYear) {
                addError(passingInput || toInput, `Education ${i + 1}: Year of Passing must match To Year`);
            }
        }

        /* ================= DOCUMENT VALIDATION ================= */

        if (isFinalSubmit) {
            const state = this.getCardState(card);
            const isInsufficient = state === this.STATES.INSUFFICIENT_DOCUMENTS;
            const rules = this.getQualificationRules(card);

            if (!isInsufficient) {
                const marksheetFile = card.querySelector('[name="marksheet_file[]"]');
                const marksheetDocsInput = card.querySelector('.marksheet-documents-input');
                const degreeFile = card.querySelector('[name="degree_file[]"]');
                const marksheetDocs = card.querySelector(`[name="old_marksheet_documents_json[${i}]"]`);

                const hasNewMarksheet =
                    marksheetFile && marksheetFile.files && marksheetFile.files.length > 0;
                const hasNewMarksheetDocs =
                    marksheetDocsInput && marksheetDocsInput.files && marksheetDocsInput.files.length > 0;

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
                const oldMarksheetDocs = (() => {
                    if (!marksheetDocs || !marksheetDocs.value) return [];
                    try {
                        const parsed = JSON.parse(marksheetDocs.value);
                        return Array.isArray(parsed) ? parsed : [];
                    } catch (_e) {
                        return [];
                    }
                })();

                const hasOldDegree =
                    oldDegree &&
                    oldDegree.value &&
                    oldDegree.value !== 'INSUFFICIENT_DOCUMENTS';

                // DB fallback (important when page reloads)
                const dbRow = this.savedRows[i] || {};

                const hasDbMarksheet =
                    dbRow.marksheet_file &&
                    dbRow.marksheet_file !== 'INSUFFICIENT_DOCUMENTS';
                const hasDbMarksheetDocs =
                    Array.isArray(dbRow.marksheet_documents) && dbRow.marksheet_documents.length > 0;

                const hasDbDegree =
                    dbRow.degree_file &&
                    dbRow.degree_file !== 'INSUFFICIENT_DOCUMENTS';

                const requiresMultiMarksheet = !!rules.multiMarksheet;
                const hasMarksheet =
                    requiresMultiMarksheet
                        ? (hasNewMarksheetDocs || oldMarksheetDocs.length > 0 || hasDbMarksheetDocs || hasOldMarksheet || hasDbMarksheet)
                        : (hasNewMarksheet || hasOldMarksheet || hasDbMarksheet);
                const hasDegree =
                    hasNewDegree || hasOldDegree || hasDbDegree;

                if (rules.requireMarksheet && !hasMarksheet) {
                    const fileBox = card.querySelector('[name="marksheet_file[]"]')?.closest('.form-control')?.querySelector('[data-file-upload]');
                    addError(
                        fileBox || card.querySelector('[name="marksheet_file[]"]') || card,
                        `Education ${i + 1}: Marksheet is required`
                    );
                }

                if (rules.requireDegree && !hasDegree) {
                    const degreeBox = card.querySelector('[name="degree_file[]"]')?.closest('.form-control')?.querySelector('[data-file-upload]');
                    addError(
                        degreeBox || card.querySelector('[name="degree_file[]"]') || card,
                        `Education ${i + 1}: Degree certificate is required`
                    );
                }
            }
        }
    }

    if (errors.length > 0) {
        console.warn('Education validation errors:', errors.map((error) => error.message || error));
        if (window.CandidateNotify && form) {
            window.CandidateNotify.validation({
                form,
                title: 'Education details need attention',
                message: `Please fix ${errors.length} issue${errors.length === 1 ? '' : 's'} before continuing.`,
                errors
            });
        } else if (window.Router && typeof window.Router.showNotification === 'function') {
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
        formData.set('visibleEducationCount', String(this.visibleEducationCount || this.cards.filter(Boolean).length || 1));

        const response = await fetch(this.getApiEndpoint(), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (data && data.success) {
            this.showNotification(isDraft ? 'Education Draft saved successfully!' : 'Education saved successfully!');
            localStorage.removeItem(this.getEducationDraftStorageKey());
            localStorage.removeItem('education_draft');

            if (!isDraft) {
                if (window.Router && window.Router.pageCache && typeof window.Router.pageCache.delete === 'function') {
                    window.Router.pageCache.delete('education');
                }
                try {
                    if (window.Router) {
                        if (window.Router.markCompleted) {
                            window.Router.markCompleted('education');
                        }
                        if (window.Router.navigateTo) {
                            const nextPage = typeof window.Router.getNextPage === 'function' ? window.Router.getNextPage('education') : 'employment';
                            window.Router.navigateTo(nextPage);
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
    if (card && card.dataset.suppressed === '1') {
        return false;
    }
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
            console.log('Ã°Å¸â€œÅ¡ Education.onPageLoad() called');
            
            try {
                if (!window.educationManager) {
                    console.log('Ã°Å¸â€ â€¢ Creating new EducationManager instance');
                    window.educationManager = new EducationManager();
                }
                
                await window.educationManager.init();
                console.log('Ã¢Å“â€¦ Education page loaded successfully');
            } catch (error) {
                console.error('Ã¢ÂÅ’ Error in Education.onPageLoad:', error);
            }
        },
        
        cleanup: () => {
            console.log('Ã°Å¸Â§Â¹ Cleaning up Education module');
            if (window.educationManager) {
                window.educationManager.cleanup();
                window.educationManager = null;
            }
        }
    };
}

console.log('Ã¢Å“â€¦ Education.js module loaded');
