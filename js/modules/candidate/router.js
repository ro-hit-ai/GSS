class Router {
    static currentPage = "review-confirmation";
    static pageCache = new Map();
    static isInitialized = false;
    static _isNavigating = false;
    static _navigatingTo = null;
    static enabledPages = null;
    static caseConfig = null;
    static _configLoaded = false;
    
    static _allowedPagesCache = null;
    static _cacheTimestamp = 0;
    static CACHE_TTL = 1000;
    static correctionConfigError = false;
    static correctionErrorMessage = "Unable to load correction configuration. Please reopen the correction link or contact support.";
    static correctionForbiddenPages = ["review", "review-confirmation", "authorization"];
    
    static pageOrder = [
        "review-confirmation",
        "basic-details",
        "identification",
        "contact",
        "education",
        "employment",
        "ecourt",
        "social",
        "reference",
        "review",
        "success"
    ];

    static pageManagers = {
        "identification": null,
        "education": null,
        "employment": null,
        "reference": null
    };

    static pageLabels = {
        "review-confirmation": "Start Verification",
        "basic-details": "Basic Details",
        "identification": "Identification",
        "contact": "Address",
        "social": "Social Media",
        "ecourt": "E-Court",
        "education": "Education",
        "employment": "Employment",
        "reference": "Reference",
        "review": "Final Review",
        "success": "Submission Complete"
    };

    static pageHints = {
        "review-confirmation": "Review the authorization, confirm your details, and begin the application.",
        "basic-details": "Enter your personal details exactly as they appear on official records.",
        "identification": "Provide your identity documents and supporting proofs for verification.",
        "contact": "Share your current and permanent address information accurately.",
        "social": "Add the social media details requested for your verification profile.",
        "ecourt": "Provide e-court information only where applicable to your case.",
        "education": "List qualifications in order and upload the relevant academic documents.",
        "employment": "Add employer history carefully and attach proof where required.",
        "reference": "Provide references who can verify your employment or background details.",
        "review": "Check every section carefully before you confirm and submit the application.",
        "success": "Your application has been submitted successfully."
    };

    static noApiSubmissionPages = ["review-confirmation", "review", "success"];
    
    static selfHandledPages = [
        "basic-details",
        "identification",
        "contact",
        "education",
        "employment",
        "ecourt",
        "social",
        "reference",
        "review"
    ];

    static shouldUseCache(pageId) {
        if (!pageId) return false;
        if (this.selfHandledPages.includes(pageId)) return false;
        if (pageId === 'review-confirmation') return false;
        return true;
    }

    static stepStripListeners = new Map();

    static storagePrefix() {
        const appId = (window.CANDIDATE_APP_ID || '').toString().trim();
        return appId ? (`candidate:${appId}:`) : 'candidate:';
    }

    static lsGet(key) {
        try {
            return localStorage.getItem(this.storagePrefix() + key);
        } catch (e) {
            return null;
        }
    }

    static lsSet(key, val) {
        try {
            localStorage.setItem(this.storagePrefix() + key, String(val));
        } catch (e) {
        }
    }

    static lsRemove(key) {
        try {
            localStorage.removeItem(this.storagePrefix() + key);
        } catch (e) {
        }
    }

    static init() {
        if (this.isInitialized) return;
        this.isInitialized = true;

        console.log("🚀 Router initialized - RESPECTS URL PARAMETERS");

        this.fetchCaseConfig().finally(() => {
            this.applyEnabledPagesToUI();
            this.bindStepStrip();

            const params = new URLSearchParams(window.location.search);
            const urlPage = params.get("page");

            let startPage;
            if (urlPage) {
                startPage = this.getCurrentAllowedPage(urlPage);
                console.log(`📌 URL requested: "${urlPage}", allowed page: "${startPage}"`);
            } else {
                startPage = this.getCurrentAllowedPage();
                console.log(`📌 No URL param, starting with: "${startPage}"`);
            }
            
            const shouldPushState = !urlPage || urlPage !== startPage;
            
            this.navigateTo(startPage, shouldPushState);
            
            window.onpopstate = (e) => {
                const page = e.state?.page || this.getCurrentAllowedPage();
                console.log(`🔙 Back/Forward navigation to: ${page}`);
                this.navigateTo(page, false);
            };
        });
    }

    static async fetchCaseConfig() {
        if (this._configLoaded) return;
        this._configLoaded = true;

        try {
            const basePath = (window.APP_BASE_URL || '').replace(/\/$/, '');
            const url = `${basePath}/api/candidate/case_verification_config.php?t=${Date.now()}`;
            const res = await fetch(url, { credentials: 'include' });
            const json = await res.json();
            if (json && json.status === 1 && json.data) {
                this.caseConfig = json.data;
                this.correctionConfigError = false;
                this.enabledPages = Array.isArray(json.data.enabled_pages) ? json.data.enabled_pages : null;
                if (Number(this.caseConfig.correction_mode || 0) === 1) {
                    this.enabledPages = this.sanitizeCorrectionPages(this.enabledPages);
                    this.caseConfig.enabled_pages = this.enabledPages;
                    this.caseConfig.pages = this.enabledPages;
                }
                window.CANDIDATE_CASE_CONFIG = this.caseConfig;
                this.reconcileLocalSessionState();
                console.log('✅ Candidate enabled pages from config:', this.enabledPages);
            } else {
                if (this.hasCorrectionBootstrap()) {
                    this.correctionConfigError = true;
                    this.enabledPages = [];
                    this.caseConfig = { correction_mode: 1, correction_config_error: 1 };
                } else {
                    this.enabledPages = null;
                    this.caseConfig = null;
                }
                window.CANDIDATE_CASE_CONFIG = null;
                console.warn('⚠️ Candidate config not available, using default pages');
            }
        } catch (e) {
            if (this.hasCorrectionBootstrap()) {
                this.correctionConfigError = true;
                this.enabledPages = [];
                this.caseConfig = { correction_mode: 1, correction_config_error: 1 };
            } else {
                this.enabledPages = null;
                this.caseConfig = null;
            }
            window.CANDIDATE_CASE_CONFIG = null;
            console.warn('⚠️ Candidate config fetch failed, using default pages');
        }

        this._allowedPagesCache = null;
        this.pageCache.clear();
        try {
            document.body.classList.remove('candidate-config-loading');
        } catch (e) {
        }
    }

    static reconcileLocalSessionState() {
        try {
            const marker = String((this.caseConfig && this.caseConfig.login_marker) || window.CANDIDATE_LOGIN_MARKER || '').trim();
            const markerKey = 'login-marker';
            const storedMarker = this.lsGet(markerKey);

            if (marker && storedMarker !== marker) {
                this.clearCompletionFlags();
                this.lsSet(markerKey, marker);
            }

            if (this.isSubmittedLocked()) {
                this.clearCompletionFlags();
                this.lsSet('completed-review-confirmation', '1');
                this.lsSet('completed-review', '1');
            }
        } catch (e) {
            console.warn('Candidate route state reconcile failed', e);
        }
    }

    static clearCompletionFlags() {
        this.pageOrder.forEach(page => {
            this.lsRemove(`completed-${page}`);
        });
        this._allowedPagesCache = null;
        this.pageCache.clear();
    }

    static isSubmittedLocked() {
        const cfg = this.caseConfig || {};
        return Number(cfg.submitted_locked || 0) === 1 && Number(cfg.correction_mode || 0) !== 1;
    }

    static isCorrectionMode() {
        const cfg = this.caseConfig || {};
        return Number(cfg.correction_mode || 0) === 1;
    }

    static hasCorrectionBootstrap() {
        return Number(window.CANDIDATE_CORRECTION_MODE || 0) === 1;
    }

    static isCorrectionRuntime() {
        return this.isCorrectionMode() || this.hasCorrectionBootstrap();
    }

    static sanitizeCorrectionPages(pages) {
        if (!Array.isArray(pages)) return [];
        const out = [];
        pages.forEach(page => {
            const key = String(page || '').trim().toLowerCase();
            if (!key || this.correctionForbiddenPages.includes(key)) return;
            if (!out.includes(key)) out.push(key);
        });
        return out;
    }

    static isEnabledPage(pageId) {
        if (!pageId) return false;
        if (this.correctionConfigError) return pageId === 'correction-error';
        if (this.isCorrectionRuntime()) {
            if (!Array.isArray(this.enabledPages) || this.enabledPages.length === 0) return pageId === 'success';
            return this.sanitizeCorrectionPages(this.enabledPages).includes(pageId);
        }
        if (pageId === 'review' || pageId === 'success' || pageId === 'review-confirmation') {
            return true;
        }
        if (!Array.isArray(this.enabledPages) || this.enabledPages.length === 0) return true;
        return this.enabledPages.includes(pageId);
    }

    static getEnabledPageOrder() {
        return this.pageOrder.filter(p => this.isEnabledPage(p));
    }

    static applyEnabledPagesToUI() {
        document.querySelectorAll('.sidebar-item').forEach(item => {
            const p = item.dataset.page;
            const enabled = this.isEnabledPage(p);
            item.style.display = enabled ? '' : 'none';
        });

        const strip = document.getElementById('stepStrip');
        if (strip) {
            strip.querySelectorAll('.step-item').forEach(item => {
                const p = item.dataset.page;
                const enabled = this.isEnabledPage(p);
                item.style.display = enabled ? '' : 'none';
            });
        }
    }

    static getAllowedPages() {
        if (this.isSubmittedLocked()) {
            this._allowedPagesCache = ['success'];
            this._cacheTimestamp = Date.now();
            return this._allowedPagesCache;
        }
        if (this.correctionConfigError) {
            this._allowedPagesCache = ['correction-error'];
            this._cacheTimestamp = Date.now();
            return this._allowedPagesCache;
        }
        if (this.isCorrectionRuntime()) {
            const enabled = this.sanitizeCorrectionPages(this.getEnabledPageOrder());
            const correctionPages = enabled.filter(p => p !== 'review' && p !== 'review-confirmation' && p !== 'success');
            const allowed = [];
            for (let i = 0; i < correctionPages.length; i++) {
                const page = correctionPages[i];
                allowed.push(page);
                if (this.lsGet(`completed-${page}`) !== "1") break;
            }
            const allCorrectionPagesCompleted = correctionPages.every(page => this.lsGet(`completed-${page}`) === "1");
            if (allCorrectionPagesCompleted || correctionPages.length === 0) {
                allowed.push("success");
            }
            this._allowedPagesCache = allowed.length ? Array.from(new Set(allowed)) : ['success'];
            this._cacheTimestamp = Date.now();
            return this._allowedPagesCache;
        }
        const now = Date.now();
        
        if (this._allowedPagesCache && (now - this._cacheTimestamp < this.CACHE_TTL)) {
            return this._allowedPagesCache;
        }
        
        console.log(`📋 Calculating allowed pages...`);
        
        const allowed = [];
        const enabledOrder = this.getEnabledPageOrder();
        const countablePages = enabledOrder.filter(p => 
            p !== "review-confirmation" && p !== "review" && p !== "success"
        );
        
        allowed.push("review-confirmation");
        
        const isReviewCompleted = this.lsGet("completed-review-confirmation") === "1";
        
        if (!isReviewCompleted) {
            console.log(`⏸️  Review not completed, only review-confirmation allowed`);
            this._allowedPagesCache = allowed;
            this._cacheTimestamp = now;
            return allowed;
        }
        
        console.log(`✅ Review completed, checking other pages...`);
        
        for (let i = 0; i < countablePages.length; i++) {
            const page = countablePages[i];
            const isCompleted = this.lsGet(`completed-${page}`) === "1";
            
            allowed.push(page);
            
            if (!isCompleted) {
                console.log(`⏸️  Found first incomplete page: ${page}, stopping`);
                break;
            }
        }
        
        const allCompleted = countablePages.every(page => 
            this.lsGet(`completed-${page}`) === "1"
        );
        
        if (allCompleted) {
            console.log(`🎉 All form pages completed, allowing review page`);
            allowed.push("review");
        }

        if (allCompleted && this.lsGet("completed-review") === "1") {
            console.log(`🎉 Review completed, allowing success page`);
            allowed.push("success");
        }
        
        console.log(`📋 Final allowed pages:`, allowed);
        
        this._allowedPagesCache = allowed;
        this._cacheTimestamp = now;
        
        return allowed;
    }

    static getCorrectionLandingPage() {
        const enabled = this.getEnabledPageOrder();
        const correctionPages = enabled.filter(p => p !== 'review' && p !== 'review-confirmation' && p !== 'success');
        const firstIncomplete = correctionPages.find(page => this.lsGet(`completed-${page}`) !== "1");
        return firstIncomplete || (enabled.includes('success') ? 'success' : (correctionPages[0] || 'success'));
    }

    static getCurrentAllowedPage(requestedPage = null) {
        console.log(`📋 getCurrentAllowedPage called with: "${requestedPage}"`);
        const allowedPages = this.getAllowedPages();
        if (this.correctionConfigError && requestedPage !== 'correction-error') {
            return 'correction-error';
        }
        
        if (requestedPage) {
            if (allowedPages.includes(requestedPage)) {
                console.log(`✅ Requested page "${requestedPage}" is allowed`);
                return requestedPage;
            } else {
                console.log(`⛔ Requested page "${requestedPage}" not allowed`);
            }
        }

        if (this.isCorrectionRuntime()) {
            const correctionLanding = this.getCorrectionLandingPage();
            console.log(`📌 Correction mode starting with: "${correctionLanding}"`);
            return correctionLanding;
        }
        
        const isReviewCompleted = this.lsGet("completed-review-confirmation") === "1";
        
        if (!isReviewCompleted) {
            console.log(`📌 Review-confirmation not completed, starting there`);
            return "review-confirmation";
        }
        
        const lastAllowed = allowedPages[allowedPages.length - 1] || "review-confirmation";
        console.log(`📌 Returning last allowed page: "${lastAllowed}"`);
        return lastAllowed;
    }

    static async completeCorrectionSessionBeforeSuccess() {
        if (!this.isCorrectionRuntime()) return true;
        if (this.caseConfig && Number(this.caseConfig.correction_submitted || 0) === 1) return true;

        const base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        const res = await fetch(`${base}/api/candidate/submit.php`, {
            method: 'POST',
            credentials: 'same-origin'
        });
        const json = await res.json();
        if (!json || !json.success) {
            throw new Error((json && json.message) || 'Correction submission failed.');
        }

        if (this.caseConfig) {
            this.caseConfig.correction_submitted = 1;
            this.caseConfig.correction_mode = 0;
            this.caseConfig.application_status = 'submitted';
            window.CANDIDATE_CASE_CONFIG = this.caseConfig;
        }
        this._allowedPagesCache = ['success'];
        return true;
    }

    static async navigateTo(pageId, pushState = true) {
        if (this._isNavigating && this._navigatingTo === pageId) {
            console.log(`⏸️ Already navigating to ${pageId}, ignoring duplicate request`);
            return;
        }
        
        if (this._isNavigating) {
            console.log(`⏸️ Navigation in progress, queuing ${pageId}`);
            setTimeout(() => this.navigateTo(pageId, pushState), 100);
            return;
        }
        
        this._isNavigating = true;
        this._navigatingTo = pageId;
        
        try {
            console.time(`🔄 Navigation to ${pageId}`);
            
            const allowedPages = this.getAllowedPages();
            console.log(`📋 Allowed pages for access check:`, allowedPages);
            
            if (!allowedPages.includes(pageId)) {
                console.warn(`⛔ ACCESS DENIED to ${pageId}. Allowed pages:`, allowedPages);
                
                const correctPage = this.getCurrentAllowedPage();
                console.log(`🔄 Redirecting to correct page: ${correctPage}`);
                
                if (pageId !== correctPage) {
                    window.history.replaceState({ page: correctPage }, "", `?page=${correctPage}`);
                    
                    pageId = correctPage;
                }
            }

            if (this.isCorrectionRuntime() && pageId === 'success') {
                await this.completeCorrectionSessionBeforeSuccess();
            }
            
            await this.cleanupPreviousPage();
            
            this.currentPage = pageId;
            console.log(`✅ Current page updated to: ${pageId}`);
            
            if (pushState) {
                history.pushState({ page: pageId }, "", `?page=${pageId}`);
                console.log(`🔗 History updated with page: ${pageId}`);
            }
            
            const loadPromise = this.loadPageContent(pageId);
            
            this.bindStepStrip();
            this.updateSidebar(pageId);
            this.updateProgress();
            
            await loadPromise;
            if (window.CandidateSidebarStatus && typeof window.CandidateSidebarStatus.focusPendingIssue === 'function') {
                window.CandidateSidebarStatus.focusPendingIssue(pageId);
            }

            console.timeEnd(`🔄 Navigation to ${pageId}`);
            console.log(`✅ Navigation completed: ${pageId}`);
            
        } catch (error) {
            console.error("❌ Navigation error:", error);
            this.showNotification("Page failed to load. Please try again.", "error");
        } finally {
            this._isNavigating = false;
            this._navigatingTo = null;
        }
    }

    static async loadPageContent(pageId) {
        const container = document.getElementById("page-content");
        if (!container) {
            console.error("#page-content not found in DOM");
            throw new Error("Page container not found");
        }

        if (pageId === 'correction-error') {
            container.innerHTML = this.getFallbackContent(pageId);
            return;
        }

        if (this.shouldUseCache(pageId) && this.pageCache.has(pageId)) {
            console.log(`📦 Serving ${pageId} from cache`);
            container.innerHTML = this.pageCache.get(pageId);
            await this.initializePage(pageId);
            return;
        }

        const basePath = window.APP_BASE_URL || '';
        const url = `${basePath}/modules/candidate/${pageId}.php?t=${Date.now()}`;
        
        console.log(`📡 Fetching page from: ${url}`);

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 8000);
            
            const response = await fetch(url, {
                credentials: "include",
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);

            if (response.redirected && /\/modules\/candidate\/index\.php/i.test(response.url || '')) {
                window.location.assign(response.url);
                return;
            }
            
            if (!response.ok) {
                let errText = '';
                try {
                    errText = await response.text();
                } catch (e) {
                    errText = '';
                }

                const snippet = (errText || '').toString().replace(/\s+/g, ' ').trim().slice(0, 500);
                const details = snippet ? ` - ${snippet}` : '';
                throw new Error(`HTTP ${response.status}: Could not load ${pageId}${details}`);
            }

            const html = await response.text();
            if (/^\s*<!doctype html/i.test(html) || /<body[^>]*class=["'][^"']*candidate-page/i.test(html)) {
                window.location.assign(response.url || `?page=${encodeURIComponent(pageId)}`);
                return;
            }
            
            if (this.shouldUseCache(pageId)) {
                this.pageCache.set(pageId, html);
            }
            
            container.innerHTML = html;
            await this.initializePage(pageId);
            
        } catch (error) {
            console.error("❌ Load error:", error);
            
            const safeMsg = (error && error.message) ? String(error.message) : 'Unknown error';
            container.innerHTML = this.getFallbackContent(pageId)
                + `<div class="container" style="padding: 0 28px 18px;">
                    <div class="alert alert-danger" style="font-size:12px; word-break:break-word;">
                        ${safeMsg.replace(/</g, '&lt;').replace(/>/g, '&gt;')}
                    </div>
                </div>`;
            await this.initializePage(pageId);
            
            this.pageCache.delete(pageId);
        }
    }

    static getFallbackContent(pageId) {
        const fallbacks = {
            "review-confirmation": `
                <div class="container py-5">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Review & Confirmation</h4>
                        </div>
                        <div class="card-body">
                            <p class="lead">Please review and confirm the terms before proceeding.</p>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                This is the first step of the background verification process.
                            </div>
                            <button class="btn btn-primary" onclick="Router.markCompleted('review-confirmation'); Router.navigateTo('basic-details');">
                                <i class="fas fa-check me-2"></i>I Agree & Continue
                            </button>
                        </div>
                    </div>
                </div>
            `,
            "success": `
                <div class="text-center py-5">
                    <div class="mb-4">
                        <i class="fas fa-check-circle text-success fa-5x"></i>
                    </div>
                    <h3 class="mb-3">Application Submitted Successfully!</h3>
                    <p class="text-muted">Your background verification form has been submitted.</p>
                </div>
            `,
            "correction-error": `
                <div class="container py-5">
                    <div class="alert alert-danger">
                        <h5>Correction workspace unavailable</h5>
                        <p>${this.correctionErrorMessage}</p>
                    </div>
                </div>
            `,
            "default": `
                <div class="container py-5">
                    <div class="alert alert-warning">
                        <h5>${pageId.replace('-', ' ').toUpperCase()}</h5>
                        <p>Loading content... Please wait.</p>
                        <button class="btn btn-sm btn-primary mt-2" onclick="Router.navigateTo('${pageId}')">
                            <i class="fas fa-redo me-1"></i> Retry
                        </button>
                        <button class="btn btn-sm btn-secondary mt-2 ms-2" onclick="Router.navigateTo('review-confirmation')">
                            <i class="fas fa-home me-1"></i> Go to Review
                        </button>
                    </div>
                </div>
            `
        };
        
        return fallbacks[pageId] || fallbacks.default;
    }

    static async initializePage(pageId) {
        console.log(`🛠 Initializing page: ${pageId}`);
        
        const pageModules = {
            "basic-details": window.BasicDetails,
            "identification": window.Identification,
            "contact": window.Contact,
            "social": window.Social,
            "education": window.Education,
            "employment": window.Employment,
            "reference": window.Reference,
            "review": window.Review,
            "review-confirmation": window.ReviewConfirmation,
            "success": window.Success,
            "ecourt": window.Ecourt,
        };

        const module = pageModules[pageId];
        
        if (module) {
            console.log(`✅ Found module for ${pageId}`);
            
            try {
                if (typeof module.onPageLoad === 'function') {
                    console.log(`🎬 Calling ${pageId}.onPageLoad()`);
                    module.onPageLoad();
                } else if (typeof module.init === 'function') {
                    console.log(`🎬 Calling ${pageId}.init()`);
                    module.init();
                }
            } catch (error) {
                console.error(`❌ Error initializing ${pageId}:`, error);
            }
        } else {
            console.warn(`⚠️ No module found for page: ${pageId}`);
        }
        
        if (!this.selfHandledPages.includes(pageId) && !this.noApiSubmissionPages.includes(pageId)) {
            console.log(`🔗 Router binding handlers for ${pageId}`);
            this.bindGenericFormHandlers(pageId);
        } else {
            console.log(`🚫 ${pageId} is self-handled or no-API page - Router WILL NOT bind handlers`);
        }
        
        this.initAutoExpandingTextareas();
    }

    static bindGenericFormHandlers(pageId) {
        console.log(`🔍 Router.bindGenericFormHandlers called for: ${pageId}`);
        
        if (this.noApiSubmissionPages.includes(pageId)) {
            console.log(`🚫 Skipping generic handlers for no-API page: ${pageId}`);
            return;
        }
        
        if (this.selfHandledPages.includes(pageId)) {
            console.warn(`🚫 Skipping generic handlers for self-handled page: ${pageId}`);
            return;
        }
        
        const form = document.getElementById(`${pageId}Form`);
        if (!form) {
            console.warn(`Form not found: ${pageId}Form`);
            return;
        }

        if (form.dataset.routerBound === 'true') {
            console.log(`Form already bound by router`);
            return;
        }

        console.log(`Binding generic form handlers for ${pageId}`);
        
        form.addEventListener('submit', async (e) => {
            console.log(`📝 Router handling form submit for ${pageId}`);
            e.preventDefault();
            await this.handleGenericFormSubmission(form, pageId);
        });

        const nextBtn = document.querySelector(`.external-submit-btn[data-form="${pageId}Form"]`);
        if (nextBtn && !nextBtn.dataset.routerBound) {
            nextBtn.dataset.routerBound = "true";
            nextBtn.addEventListener("click", async (e) => {
                console.log(`Router Next button clicked for ${pageId}`);
                e.preventDefault();
                await this.handleGenericFormSubmission(form, pageId);
            });
        }

        const prevBtn = document.querySelector(`.prev-btn[data-form="${pageId}Form"]`);
        if (prevBtn && !prevBtn.dataset.routerBound) {
            prevBtn.dataset.routerBound = "true";
            prevBtn.addEventListener("click", (e) => {
                console.log(`Router Previous button clicked for ${pageId}`);
                e.preventDefault();
                const prevPage = this.getPreviousPage(pageId);
                if (prevPage) {
                    this.navigateTo(prevPage);
                }
            });
        }

        form.dataset.routerBound = 'true';
        console.log(`Router handlers bound for ${pageId}`);
    }

    static async handleGenericFormSubmission(form, pageId) {
        console.log(`🔄 handleGenericFormSubmission called for: ${pageId}`);
        
        if (this.noApiSubmissionPages.includes(pageId)) {
            console.log(`✅ ${pageId} doesn't need API submission, marking as completed directly`);
            this.markCompleted(pageId);
            
            const nextPage = this.getNextPage(pageId);
            if (nextPage) {
                console.log(`➡️ Navigating to next page: ${nextPage}`);
                this.navigateTo(nextPage);
            }
            return;
        }
        
        if (this.selfHandledPages.includes(pageId)) {
            console.warn(`🚫 Router blocked submit for self-handled page: ${pageId}`);
            return;
        }

        try {
            const formData = new FormData(form);
            const base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            const primaryEndpoint = `${base}/api/candidate/store_${pageId}.php`;
            const legacyEndpoint = `${base}/api/candidate/store_${pageId.replace(/-/g, '')}.php`;

            console.log(`Submitting ${pageId} to ${primaryEndpoint}`);

            let response = await fetch(primaryEndpoint, {
                method: "POST",
                body: formData,
                credentials: "same-origin"
            });

            // Backward compatibility: older router used store_{pageIdNoHyphen}.php
            if (!response.ok) {
                console.warn(`Primary endpoint failed (${response.status}). Trying legacy endpoint: ${legacyEndpoint}`);
                response = await fetch(legacyEndpoint, {
                    method: "POST",
                    body: formData,
                    credentials: "same-origin"
                });
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || "Save failed");
            }
            
            this.markCompleted(pageId);
            this.showNotification("✅ Saved successfully!", "success");
            
            if (window.Forms && typeof Forms.clearDraft === 'function') {
                Forms.clearDraft(pageId);
            }
            
            this.pageCache.delete(pageId);
            
            const nextPage = this.getNextPage(pageId);
            if (nextPage) {
                console.log(`➡️ Navigating to next page: ${nextPage}`);
                this.navigateTo(nextPage);
            }

        } catch (err) {
            console.error("❌ Submission error:", err);
            this.showNotification(`❌ Error: ${err.message}`, "error");
        }
    }

    static bindStepStrip() {
        const strip = document.getElementById("stepStrip");
        if (!strip) return;

        console.log("🔄 Binding step strip with sequential access control");

        this.cleanupStepStripListeners();

        const allowedPages = this.getAllowedPages();
        
        strip.querySelectorAll(".step-item").forEach((item, index) => {
            const pageId = item.dataset.page;
            if (!pageId) return;
            
            const isAllowed = allowedPages.includes(pageId);
            const isCompleted = this.lsGet(`completed-${pageId}`) === "1";
            const isCurrent = this.currentPage === pageId;
            
            item.classList.remove("disabled-step", "current-step", "completed-step", "allowed-step");
            
            const newItem = item.cloneNode(true);
            item.parentNode.replaceChild(newItem, item);
            
            if (isCurrent) {
                newItem.classList.add("current-step");
            }
            
            if (isCompleted) {
                newItem.classList.add("completed-step");
            }
            
            if (isAllowed) {
                newItem.classList.add("allowed-step");
                newItem.style.cursor = "pointer";
                newItem.style.pointerEvents = "auto";
                
                const clickHandler = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log(`Step clicked: ${pageId} (allowed)`);
                    this.navigateTo(pageId);
                };
                
                newItem.addEventListener("click", clickHandler);
                this.stepStripListeners.set(newItem, clickHandler);
            } else {
                newItem.classList.add("disabled-step");
                newItem.style.cursor = "not-allowed";
                newItem.style.pointerEvents = "none";
                
                if (!isCompleted) {
                    newItem.title = "Complete previous steps first";
                }
            }
            
            const stepNumber = newItem.querySelector('.step-number');
            if (stepNumber) {
                if (isCompleted) {
                    stepNumber.innerHTML = '<i class="fas fa-check"></i>';
                    stepNumber.classList.add("completed-icon");
                } else {
                    stepNumber.innerHTML = (index + 1).toString();
                    stepNumber.classList.remove("completed-icon");
                }
            }
        });
    }

    static cleanupStepStripListeners() {
        this.stepStripListeners.forEach((handler, element) => {
            if (element && element.removeEventListener) {
                element.removeEventListener("click", handler);
            }
        });
        this.stepStripListeners.clear();
    }

    static updateSidebar(pageId) {
        const sidebar = document.getElementById('mainSidebar');
        const toggleBtn = document.getElementById('sidebarToggle');
        const overlay = document.getElementById('sidebarOverlay');
        const onSuccess = String(pageId || '') === 'success';
        if (sidebar) sidebar.style.display = onSuccess ? 'none' : '';
        if (toggleBtn) toggleBtn.style.display = onSuccess ? 'none' : '';
        if (overlay) overlay.style.display = onSuccess ? 'none' : '';

        document.querySelectorAll(".sidebar-item").forEach(item => {
            const p = item.dataset.page;
            item.classList.toggle("active", p === pageId);
            item.classList.toggle("current", p === pageId);
            const isCompleted = this.lsGet(`completed-${p}`) === "1";
            item.classList.toggle("completed", isCompleted);
        });
        if (window.CandidateSidebarStatus && typeof window.CandidateSidebarStatus.refresh === 'function') {
            window.CandidateSidebarStatus.refresh({ quiet: true });
        }

        const currentLabelEl = document.getElementById("candidateCurrentStepLabel");
        const currentHintEl = document.getElementById("candidateCurrentStepHint");
        if (currentLabelEl) currentLabelEl.textContent = this.pageLabels[pageId] || "Complete your application";
        if (currentHintEl) currentHintEl.textContent = this.pageHints[pageId] || "Move step by step, upload documents where needed, and review everything before final submission.";

        const strip = document.getElementById("stepStrip");
        if (strip) {
            strip.querySelectorAll(".step-item").forEach(item => {
                item.classList.toggle("active", item.dataset.page === pageId);
            });
        }
    }

    static updateProgress() {
        const countablePages = this.getEnabledPageOrder().filter(p => p !== "review-confirmation" && p !== "success");
        const total = countablePages.length;
        let completed = 0;

        countablePages.forEach(page => {
            if (this.lsGet(`completed-${page}`) === "1") completed++;
        });

        const percent = total > 0 ? Math.round((completed / total) * 100) : 0;
        const bar = document.getElementById("globalProgressBar");
        const text = document.getElementById("globalProgressText");
        const meta = document.getElementById("candidateProgressMeta");
        if (bar) bar.style.width = `${percent}%`;
        if (text) text.textContent = `${percent}% Complete`;
        if (meta) meta.textContent = `${completed} of ${total} active sections completed. Drafts save as you go.`;

        console.log(`📊 Progress: ${percent}% (${completed}/${total})`);
    }

    static markCompleted(pageId) {
        console.log(`✅ Marking ${pageId} as completed`);
        this.lsSet(`completed-${pageId}`, "1");
        
        this._allowedPagesCache = null;
        
        this.updateProgress();
        this.bindStepStrip();
        if (window.CandidateSidebarStatus && typeof window.CandidateSidebarStatus.refresh === 'function') {
            window.CandidateSidebarStatus.refresh({ quiet: true });
        }
        
        console.log("🔄 UI updated after marking as completed");
    }

    static cleanupPreviousPage() {
        const previousPage = this.currentPage;
        if (!previousPage) return;
        
        console.log(`🧹 Cleaning up previous page: ${previousPage}`);
        
        if (previousPage && this.pageManagers[previousPage]) {
            const manager = this.pageManagers[previousPage];
            if (typeof manager.cleanup === 'function') {
                console.log(`🧹 Cleaning up ${previousPage} manager`);
                manager.cleanup();
                this.pageManagers[previousPage] = null;
            }
        }

        const legacyModules = {
            "identification": window.Identification,
            "education": window.Education,
            "employment": window.Employment,
            "reference": window.Reference,
            "basic-details": window.BasicDetails,
            "contact": window.Contact,
            "social": window.Social,
            "ecourt": window.Ecourt,
            "review-confirmation": window.ReviewConfirmation,
            "review": window.Review,
        };

        const legacyModule = legacyModules[previousPage];
        if (legacyModule && typeof legacyModule.cleanup === 'function') {
            console.log(`🧹 Cleaning up legacy ${previousPage} module`);
            legacyModule.cleanup();
        }
    }

    static getPreviousPage(pageId) {
        const order = this.getEnabledPageOrder();
        const index = order.indexOf(pageId);
        if (index > 0) {
            return order[index - 1];
        }
        return null;
    }

    static getNextPage(pageId) {
        if (this.isCorrectionRuntime()) {
            const order = this.sanitizeCorrectionPages(this.getEnabledPageOrder());
            const index = order.indexOf(pageId);
            if (pageId === 'review' || pageId === 'review-confirmation' || pageId === 'authorization') return 'success';
            if (index === -1 || index >= order.length - 2) return 'success';
            return order[index + 1] || 'success';
        }
        const order = this.getEnabledPageOrder();
        const index = order.indexOf(pageId);
        if (pageId === 'review') return 'success';
        if (index === -1 || index >= order.length - 2) {
            return "success";
        }
        return order[index + 1];
    }

    static initAutoExpandingTextareas() {
        document.querySelectorAll('textarea').forEach(textarea => {
            if (textarea.dataset.autoExpandBound) return;
            textarea.dataset.autoExpandBound = "1";

            const adjust = () => {
                textarea.style.height = 'auto';
                textarea.style.height = textarea.scrollHeight + 'px';
            };

            textarea.addEventListener('input', adjust);
            requestAnimationFrame(adjust);
        });
    }

    static showNotification(message, type = "info") {
        if (window.CandidateNotify && typeof window.CandidateNotify.show === "function") {
            const mapped = (type === "warning") ? "warn" : type;
            window.CandidateNotify.show({ type: mapped, message });
        } else if (typeof window.showAlert === "function") {
            window.showAlert({ type, message });
        } else {
            console[type === "error" ? "error" : type === "warning" ? "warn" : "log"](`[${type}] ${message}`);
        }
    }

    static notify({ type = "info", message = "" } = {}) {
        this.showNotification(message, type);
    }

    static isPageAccessible(pageId) {
        try {
            return this.getAllowedPages().includes(pageId);
        } catch (e) {
            return false;
        }
    }

    static clearCache() {
        this.pageCache.clear();
        this._allowedPagesCache = null;
        console.log("🧹 Router cache cleared");
    }

    static resetProgress() {
        const countablePages = this.pageOrder.filter(p => p !== "review-confirmation" && p !== "success");
        countablePages.forEach(page => {
            this.lsRemove(`completed-${page}`);
        });
        this.lsRemove("completed-review-confirmation");
        
        this._allowedPagesCache = null;
        this.pageCache.clear();
        
        this.bindStepStrip();
        this.updateProgress();
        console.log("🔄 All progress reset");
        
        this.navigateTo("review-confirmation");
    }

    static waitForElement(selector, maxAttempts = 50) {
        return new Promise((resolve, reject) => {
            let attempts = 0;
            const check = setInterval(() => {
                const element = document.querySelector(selector);
                if (element) {
                    clearInterval(check);
                    resolve(element);
                }
                attempts++;
                if (attempts >= maxAttempts) {
                    clearInterval(check);
                    reject(new Error(`Element ${selector} not found after ${maxAttempts} attempts`));
                }
            }, 100);
        });
    }
}

window.Router = Router;

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
        console.log("📄 DOM ready — Initializing Router...");
        Router.init();
    });
} else {
    console.log("📄 DOM already loaded — Initializing Router...");
    Router.init();
}
