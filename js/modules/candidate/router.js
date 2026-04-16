class Router {
    static currentPage = "review-confirmation";
    static pageCache = new Map();
    static isInitialized = false;
    static _isNavigating = false;
    static _navigatingTo = null;
    static enabledPages = null;
    static caseConfig = null;
    static _configLoaded = false;
    
    // Performance cache
    static _allowedPagesCache = null;
    static _cacheTimestamp = 0;
    static CACHE_TTL = 1000; // Cache for 1 second
    
    static pageOrder = [
        "review-confirmation",
        "basic-details",
        "identification",
        "contact",
        "social",
        "ecourt",
        "education",
        "employment",
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

    // Special pages that don't need API submission
    static noApiSubmissionPages = ["review-confirmation", "review", "success"];
    
    static selfHandledPages = [
        "basic-details",
        "identification",
        "contact",
        "social",
        "ecourt",
        "education",
        "employment",
        "reference",
        "review"
    ];

    static shouldUseCache(pageId) {
        // Dynamic pages should always be fetched fresh to reflect saved DB values
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

    /* ================= INITIALIZATION ================= */
    static init() {
        if (this.isInitialized) return;
        this.isInitialized = true;

        console.log("🚀 Router initialized - RESPECTS URL PARAMETERS");

        this.fetchCaseConfig().finally(() => {
            this.applyEnabledPagesToUI();
            this.bindStepStrip();

            const params = new URLSearchParams(window.location.search);
            const urlPage = params.get("page");

            // ✅ FIXED: Respect URL parameter first, then fallback to logic
            let startPage;
            if (urlPage) {
                // User requested a specific page via URL
                startPage = this.getCurrentAllowedPage(urlPage);
                console.log(`📌 URL requested: "${urlPage}", allowed page: "${startPage}"`);
            } else {
                // No URL parameter, start with appropriate page
                startPage = this.getCurrentAllowedPage();
                console.log(`📌 No URL param, starting with: "${startPage}"`);
            }
            
            // Only push state if we need to (URL doesn't match or no URL)
            const shouldPushState = !urlPage || urlPage !== startPage;
            
            this.navigateTo(startPage, shouldPushState);
            
            // Handle browser back/forward buttons
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
                this.enabledPages = Array.isArray(json.data.enabled_pages) ? json.data.enabled_pages : null;
                this.caseConfig = json.data;
                window.CANDIDATE_CASE_CONFIG = this.caseConfig;
                console.log('✅ Candidate enabled pages from config:', this.enabledPages);
            } else {
                this.enabledPages = null;
                this.caseConfig = null;
                window.CANDIDATE_CASE_CONFIG = null;
                console.warn('⚠️ Candidate config not available, using default pages');
            }
        } catch (e) {
            this.enabledPages = null;
            this.caseConfig = null;
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

    static isEnabledPage(pageId) {
        if (pageId === 'review' || pageId === 'success' || pageId === 'review-confirmation') {
            return true;
        }
        if (!pageId) return false;
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

    /* ================= PAGE ACCESS CONTROL ================= */
    static getAllowedPages() {
        const now = Date.now();
        
        // Return cached version if recent
        if (this._allowedPagesCache && (now - this._cacheTimestamp < this.CACHE_TTL)) {
            return this._allowedPagesCache;
        }
        
        console.log(`📋 Calculating allowed pages...`);
        
        const allowed = [];
        const enabledOrder = this.getEnabledPageOrder();
        const countablePages = enabledOrder.filter(p => 
            p !== "review-confirmation" && p !== "review" && p !== "success"
        );
        
        // Always allow review-confirmation FIRST
        allowed.push("review-confirmation");
        
        // Check if review-confirmation is completed
        const isReviewCompleted = this.lsGet("completed-review-confirmation") === "1";
        
        if (!isReviewCompleted) {
            console.log(`⏸️  Review not completed, only review-confirmation allowed`);
            this._allowedPagesCache = allowed;
            this._cacheTimestamp = now;
            return allowed;
        }
        
        console.log(`✅ Review completed, checking other pages...`);
        
        // Find the first incomplete page after review
        for (let i = 0; i < countablePages.length; i++) {
            const page = countablePages[i];
            const isCompleted = this.lsGet(`completed-${page}`) === "1";
            
            allowed.push(page); // Allow access to this page
            
            if (!isCompleted) {
                console.log(`⏸️  Found first incomplete page: ${page}, stopping`);
                break;
            }
        }
        
        // If all form pages are completed, allow review page
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
        
        // Cache the result
        this._allowedPagesCache = allowed;
        this._cacheTimestamp = now;
        
        return allowed;
    }

    static getCurrentAllowedPage(requestedPage = null) {
        console.log(`📋 getCurrentAllowedPage called with: "${requestedPage}"`);
        
        // ✅ FIXED: Check if requested page is accessible
        if (requestedPage) {
            const allowedPages = this.getAllowedPages();
            if (allowedPages.includes(requestedPage)) {
                console.log(`✅ Requested page "${requestedPage}" is allowed`);
                return requestedPage;
            } else {
                console.log(`⛔ Requested page "${requestedPage}" not allowed`);
            }
        }
        
        // Always start with review-confirmation unless it's completed
        const isReviewCompleted = this.lsGet("completed-review-confirmation") === "1";
        
        if (!isReviewCompleted) {
            console.log(`📌 Review-confirmation not completed, starting there`);
            return "review-confirmation";
        }
        
        const allowedPages = this.getAllowedPages();
        
        // Return the last allowed page (most recent accessible)
        const lastAllowed = allowedPages[allowedPages.length - 1] || "review-confirmation";
        console.log(`📌 Returning last allowed page: "${lastAllowed}"`);
        return lastAllowed;
    }

    /* ================= NAVIGATION ================= */
    static async navigateTo(pageId, pushState = true) {
        // Prevent double navigation
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
            
            // Quick access check
            const allowedPages = this.getAllowedPages();
            console.log(`📋 Allowed pages for access check:`, allowedPages);
            
            if (!allowedPages.includes(pageId)) {
                console.warn(`⛔ ACCESS DENIED to ${pageId}. Allowed pages:`, allowedPages);
                
                // Find what page they should be on
                const correctPage = this.getCurrentAllowedPage();
                console.log(`🔄 Redirecting to correct page: ${correctPage}`);
                
                // Only redirect if they're trying to access a disallowed page
                if (pageId !== correctPage) {
                    // Update URL without pushState to prevent back button issues
                    window.history.replaceState({ page: correctPage }, "", `?page=${correctPage}`);
                    
                    // Load the correct page
                    pageId = correctPage;
                }
            }
            
            // Clean up previous page
            await this.cleanupPreviousPage();
            
            // Update current page
            this.currentPage = pageId;
            console.log(`✅ Current page updated to: ${pageId}`);
            
            // Update URL
            if (pushState) {
                history.pushState({ page: pageId }, "", `?page=${pageId}`);
                console.log(`🔗 History updated with page: ${pageId}`);
            }
            
            // Load page and update UI in parallel for better performance
            const loadPromise = this.loadPageContent(pageId);
            
            // Update UI immediately (don't wait for page load)
            this.bindStepStrip();
            this.updateSidebar(pageId);
            this.updateProgress();
            
            // Wait for page load to complete
            await loadPromise;

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

    /* ================= PAGE LOADING ================= */
    static async loadPageContent(pageId) {
        const container = document.getElementById("page-content");
        if (!container) {
            console.error("#page-content not found in DOM");
            throw new Error("Page container not found");
        }

        // Check cache first for faster loading
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
            // Use timeout to prevent hanging requests
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 8000);
            
            const response = await fetch(url, {
                credentials: "include",
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
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
            
            // Cache the HTML for faster future navigation
            if (this.shouldUseCache(pageId)) {
                this.pageCache.set(pageId, html);
            }
            
            // Set HTML and initialize
            container.innerHTML = html;
            await this.initializePage(pageId);
            
        } catch (error) {
            console.error("❌ Load error:", error);
            
            // Show fallback content
            const safeMsg = (error && error.message) ? String(error.message) : 'Unknown error';
            container.innerHTML = this.getFallbackContent(pageId)
                + `<div class="container" style="padding: 0 28px 18px;">
                    <div class="alert alert-danger" style="font-size:12px; word-break:break-word;">
                        ${safeMsg.replace(/</g, '&lt;').replace(/>/g, '&gt;')}
                    </div>
                </div>`;
            await this.initializePage(pageId);
            
            // Clear cache for this page to retry next time
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
        
        // Initialize page module
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
        
        // Only bind generic handlers for pages that need it
        if (!this.selfHandledPages.includes(pageId) && !this.noApiSubmissionPages.includes(pageId)) {
            console.log(`🔗 Router binding handlers for ${pageId}`);
            this.bindGenericFormHandlers(pageId);
        } else {
            console.log(`🚫 ${pageId} is self-handled or no-API page - Router WILL NOT bind handlers`);
        }
        
        this.initAutoExpandingTextareas();
    }

    /* ================= FORM HANDLING ================= */
    static bindGenericFormHandlers(pageId) {
        console.log(`🔍 Router.bindGenericFormHandlers called for: ${pageId}`);
        
        // Skip if no API submission needed
        if (this.noApiSubmissionPages.includes(pageId)) {
            console.log(`🚫 Skipping generic handlers for no-API page: ${pageId}`);
            return;
        }
        
        // Skip self-handled pages
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
        
        // Prevent default form submission
        form.addEventListener('submit', async (e) => {
            console.log(`📝 Router handling form submit for ${pageId}`);
            e.preventDefault();
            await this.handleGenericFormSubmission(form, pageId);
        });

        // Next button
        const nextBtn = document.querySelector(`.external-submit-btn[data-form="${pageId}Form"]`);
        if (nextBtn && !nextBtn.dataset.routerBound) {
            nextBtn.dataset.routerBound = "true";
            nextBtn.addEventListener("click", async (e) => {
                console.log(`Router Next button clicked for ${pageId}`);
                e.preventDefault();
                await this.handleGenericFormSubmission(form, pageId);
            });
        }

        // Previous button
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
        
        // ✅ Handle pages that don't need API submission
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
        
        // Block submission for self-handled pages
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
            
            // Mark as completed and update UI
            this.markCompleted(pageId);
            this.showNotification("✅ Saved successfully!", "success");
            
            // Clear drafts
            if (window.Forms && typeof Forms.clearDraft === 'function') {
                Forms.clearDraft(pageId);
            }
            
            // Clear page cache for this page
            this.pageCache.delete(pageId);
            
            // Navigate to next page
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

    /* ================= UI UPDATES ================= */
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
            
            // Clear any existing classes
            item.classList.remove("disabled-step", "current-step", "completed-step", "allowed-step");
            
            // Remove any existing click handlers
            const newItem = item.cloneNode(true);
            item.parentNode.replaceChild(newItem, item);
            
            // Add appropriate classes
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
                
                // Add click handler for allowed pages
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
                
                // Add tooltip for disabled steps
                if (!isCompleted) {
                    newItem.title = "Complete previous steps first";
                }
            }
            
            // Update visual indicators
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
        // Hide navigation on final success page
        const sidebar = document.getElementById('mainSidebar');
        const toggleBtn = document.getElementById('sidebarToggle');
        const overlay = document.getElementById('sidebarOverlay');
        const onSuccess = String(pageId || '') === 'success';
        if (sidebar) sidebar.style.display = onSuccess ? 'none' : '';
        if (toggleBtn) toggleBtn.style.display = onSuccess ? 'none' : '';
        if (overlay) overlay.style.display = onSuccess ? 'none' : '';

        // Update sidebar active states
        document.querySelectorAll(".sidebar-item").forEach(item => {
            const p = item.dataset.page;
            item.classList.toggle("active", p === pageId);
            item.classList.toggle("current", p === pageId);
            const isCompleted = this.lsGet(`completed-${p}`) === "1";
            item.classList.toggle("completed", isCompleted);
        });

        const currentLabelEl = document.getElementById("candidateCurrentStepLabel");
        const currentHintEl = document.getElementById("candidateCurrentStepHint");
        if (currentLabelEl) currentLabelEl.textContent = this.pageLabels[pageId] || "Complete your application";
        if (currentHintEl) currentHintEl.textContent = this.pageHints[pageId] || "Move step by step, upload documents where needed, and review everything before final submission.";

        // Update step strip active states
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
        
        // Clear cache when progress changes
        this._allowedPagesCache = null;
        
        // Update UI
        this.updateProgress();
        this.bindStepStrip();
        
        console.log("🔄 UI updated after marking as completed");
    }

    /* ================= CLEANUP ================= */
    static cleanupPreviousPage() {
        const previousPage = this.currentPage;
        if (!previousPage) return;
        
        console.log(`🧹 Cleaning up previous page: ${previousPage}`);
        
        // Clean up tab managers
        if (previousPage && this.pageManagers[previousPage]) {
            const manager = this.pageManagers[previousPage];
            if (typeof manager.cleanup === 'function') {
                console.log(`🧹 Cleaning up ${previousPage} manager`);
                manager.cleanup();
                this.pageManagers[previousPage] = null;
            }
        }

        // Clean up legacy modules
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

    /* ================= UTILITIES ================= */
    static getPreviousPage(pageId) {
        const order = this.getEnabledPageOrder();
        const index = order.indexOf(pageId);
        if (index > 0) {
            return order[index - 1];
        }
        return null;
    }

    static getNextPage(pageId) {
        const order = this.getEnabledPageOrder();
        const index = order.indexOf(pageId);
        if (pageId === 'reference') return 'review';
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
        
        // Navigate back to review-confirmation
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

// Initialize Router
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
