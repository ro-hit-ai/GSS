// /js/modules/candidate/common/TabManager.js
class TabManager {
    constructor(pageName, containerId, templateId, tabsId, countSelectId) {
        this.pageName = pageName;
        this.containerId = containerId;
        this.templateId = templateId;
        this.tabsId = tabsId;
        this.countSelectId = countSelectId;
        
        this.cards = [];
        this.currentData = [];
        this.currentTab = 0;
        this.eventListeners = [];
        this.container = null;
        this.template = null;
        this.tabsContainer = null;
        this.countSelect = null;
    }
    
    async init() {
        console.log(`📍 ${this.pageName}.init() called`);
        await this.loadSavedData();
        this.initUI();
        this.bindEvents();
        this.renderTabs();
        this.showTab(0);
        return this;
    }
    
    async loadSavedData() {
        const dataEl = document.getElementById(`${this.pageName}Data`);
        if (!dataEl) {
            this.currentData = [];
            return;
        }
        
        try {
            this.currentData = JSON.parse(dataEl.dataset.rows || "[]");
            console.log(`📊 Loaded ${this.currentData.length} ${this.pageName} records`);
        } catch (e) {
            console.error(`❌ Failed to parse ${this.pageName} data`, e);
            this.currentData = [];
        }
    }
    
    initUI() {
        this.container = document.getElementById(this.containerId);
        this.template = document.getElementById(this.templateId);
        this.tabsContainer = document.getElementById(this.tabsId);
        this.countSelect = document.getElementById(this.countSelectId);
    }
    
    bindEvents() {
        if (this.countSelect) {
            this.addEventListener(this.countSelect, 'change', () => this.handleCountChange());
        }
    }
    
    renderTabs() {
        if (!this.tabsContainer || !this.container || !this.template) {
            console.error(`❌ ${this.pageName} container elements not found`);
            return;
        }
        
        // Clear containers
        this.container.innerHTML = '';
        this.tabsContainer.innerHTML = '';
        this.cards = [];
        
        // Determine total cards
        let total = 1;
        if (this.currentData.length > 0) {
            total = Math.max(this.currentData.length, 1);
        } else if (this.countSelect) {
            total = Math.max(1, parseInt(this.countSelect.value || '1', 10));
        }
        
        // Update count selector
        if (this.countSelect) {
            this.countSelect.value = total;
        }
        
        // Add cards
        for (let i = 0; i < total; i++) {
            this.addCard(i, this.currentData[i] || null);
        }
        
        this.updateTabStatus();
    }
    
    addCard(index, row = null) {
        if (!this.container || !this.template || !this.tabsContainer) {
            console.error(`❌ Required elements not found in addCard for ${this.pageName}`);
            return null;
        }
        
        console.log(`Adding ${this.pageName} card ${index}`);
        
        // Clone template content
        const templateContent = this.template.content;
        const card = templateContent.cloneNode(true);
        
        // Find the card element - adjust selector based on your template
        let cardElement = null;
        if (this.pageName === 'education') {
            cardElement = card.querySelector('.education-card') || card.firstElementChild;
        } else if (this.pageName === 'employment') {
            cardElement = card.querySelector('.employment-card') || card.firstElementChild;
        } else if (this.pageName === 'identification') {
            cardElement = card.querySelector('.identification-card') || card.firstElementChild;
        }
        
        if (!cardElement) {
            console.error(`❌ Card element not found in ${this.pageName} template`);
            return null;
        }
        
        // Set card attributes
        cardElement.dataset.cardIndex = index;
        cardElement.id = `${this.pageName}-card-${index}`;
        cardElement.style.display = 'none'; // Hide initially

        // Update visible card number badge if present
        const numEl = cardElement.querySelector('.education-num, .document-num, .employer-num');
        if (numEl) {
            numEl.textContent = String(index + 1);
        }
        
        // Populate with data
        this.populateCard(cardElement, row || {}, index);
        
        // Add to container
        this.container.appendChild(cardElement);
        this.cards[index] = cardElement;
        
        // Create tab
        this.createTab(index, row);
        
        return cardElement;
    }
    
    createTab(index, row = null) {
        if (!this.tabsContainer) return;
        
        const tab = document.createElement("div");
        tab.className = `${this.pageName}-tab tab-item`;
        tab.innerHTML = `${this.getTabLabel(index)} <span class="tab-dot">●</span>`;
        tab.dataset.index = index;
        tab.onclick = () => this.showTab(index);
        this.tabsContainer.appendChild(tab);
        
        // Make first tab active by default
        if (index === 0) {
            tab.classList.add('active');
        }
    }
    
    getTabLabel(index) {
        // Default implementation, override in child classes
        return `${this.pageName.charAt(0).toUpperCase() + this.pageName.slice(1)} ${index + 1}`;
    }
    
    populateCard(card, data, index) {
        // To be implemented by child classes
        console.log(`Populate card should be implemented by ${this.pageName}Manager`);
    }
    
    showTab(index) {
        if (index < 0 || index >= this.cards.length) return;
        
        // Hide all cards
        this.cards.forEach(card => {
            if (card) card.style.display = 'none';
        });
        
        // Show selected card
        if (this.cards[index]) {
            this.cards[index].style.display = 'block';
        }
        
        // Update tab active state
        if (this.tabsContainer) {
            const tabs = this.tabsContainer.querySelectorAll(`.${this.pageName}-tab`);
            tabs.forEach(tab => tab.classList.remove('active'));
            
            const activeTab = this.tabsContainer.querySelector(`.${this.pageName}-tab[data-index="${index}"]`);
            if (activeTab) {
                activeTab.classList.add('active');
            }
        }
        
        this.currentTab = index;
    }
    
    handleCountChange() {
        if (!this.countSelect) return;
        
        const newCount = parseInt(this.countSelect.value) || 1;
        const currentCount = this.cards.length;
        
        if (newCount > currentCount) {
            // Add new cards
            for (let i = currentCount; i < newCount; i++) {
                this.addCard(i, null);
            }
        } else if (newCount < currentCount) {
            // Remove cards from end
            for (let i = currentCount - 1; i >= newCount; i--) {
                this.removeCard(i);
                this.removeTab(i);
            }
        }
        
        this.showTab(Math.min(this.currentTab, newCount - 1));
        this.updateTabStatus();
    }
    
    removeCard(index) {
        if (this.cards[index]) {
            this.cards[index].remove();
            delete this.cards[index];
        }
    }
    
    removeTab(index) {
        if (!this.tabsContainer) return;
        
        const tab = this.tabsContainer.querySelector(`.${this.pageName}-tab[data-index="${index}"]`);
        if (tab) tab.remove();
    }
    
    updateTabStatus() {
        if (!this.tabsContainer) return;
        
        const tabs = this.tabsContainer.querySelectorAll(`.${this.pageName}-tab`);
        
        tabs.forEach(tab => {
            const index = parseInt(tab.dataset.index);
            const card = this.cards[index];
            const hasData = card ? this.cardHasData(card) : false;
            const dot = tab.querySelector('.tab-dot');
            
            if (dot) {
                dot.style.color = hasData ? '#16a34a' : '#9ca3af';
            }
        });
    }
    
    cardHasData(card) {
        // Default implementation, override in child classes
        const inputs = card.querySelectorAll('input:not([type="hidden"]):not([type="file"]), select, textarea');
        for (const input of inputs) {
            if (input.value && input.value.trim() !== '' && !input.disabled) {
                return true;
            }
        }
        return false;
    }
    
    buildFormData(isFinalSubmit = false) {
        const formData = new FormData();
        
        for (let i = 0; i < this.cards.length; i++) {
            const card = this.cards[i];
            if (!card) continue;
            
            const cardData = this.getFormDataForCard(card, i);
            
            for (const [key, value] of Object.entries(cardData)) {
                if (value instanceof File) {
                    formData.append(key, value);
                } else {
                    formData.append(key, value || '');
                }
            }
        }
        
        formData.append('draft', isFinalSubmit ? '0' : '1');
        formData.append('total_cards', this.cards.length);
        
        return formData;
    }
    
    getFormDataForCard(card, index) {
        // To be implemented by child classes
        return {};
    }
    
    validateForm(isFinalSubmit = false) {
        let isValid = true;
        const errors = [];
        
        for (let i = 0; i < this.cards.length; i++) {
            const card = this.cards[i];
            if (!card) continue;
            
            const cardErrors = this.validateCard(card, i, isFinalSubmit);
            if (cardErrors.length > 0) {
                isValid = false;
                errors.push(`${this.getTabLabel(i)}: ${cardErrors.join(', ')}`);
            }
        }
        
        if (errors.length > 0) {
            console.warn('Validation errors:', errors);
            if (window.Router && typeof window.Router.showNotification === 'function') {
                window.Router.showNotification('Please fix the highlighted errors before proceeding.', 'warning');
            } else if (typeof window.showAlert === 'function') {
                window.showAlert({ type: 'warning', message: 'Please fix the highlighted errors before proceeding.' });
            }
        }
        
        return isValid;
    }
    
    validateCard(card, index, isFinalSubmit) {
        // To be implemented by child classes
        return [];
    }
    
/* ================= PREVIEW FUNCTIONALITY ================= */

/**
 * Render a file preview with a clean filename link
 */
renderPreview(card, selector, file, label, folder = '') {
    const el = card.querySelector(selector);
    if (!el || !file) return null;

    const base = window.APP_BASE_URL || '';
    const filePath = `${base}/uploads/${folder ? folder + '/' : ''}${file}`;

    return this.renderPreviewHtml(el, file, filePath);
}

/**
 * Render a local (just-selected) file preview
 */
renderLocalPreview(card, selector, file) {
    const el = card.querySelector(selector);
    if (!el || !file) return null;

    const url = URL.createObjectURL(file);
    // Track object URLs so we can revoke later
    const prevUrl = el.getAttribute('data-preview-url');
    if (prevUrl) {
        try { URL.revokeObjectURL(prevUrl); } catch (_e) {}
    }
    el.setAttribute('data-preview-url', url);

    return this.renderPreviewHtml(el, file.name, url);
}

    renderPreviewHtml(el, fileName, filePath) {
        if (!el) return null;

    // Clear existing content
    el.innerHTML = '';

    const extension = String(fileName || '').split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension);
    const isPDF = extension === 'pdf';

    const previewHTML = `
        <div class="file-preview-pill">
            ${isImage ? 
                '<i class="fas fa-image text-primary me-2"></i>' : 
                isPDF ? 
                '<i class="fas fa-file-pdf text-danger me-2"></i>' : 
                '<i class="fas fa-file text-secondary me-2"></i>'
            }
            <button type="button"
                    class="file-preview-link preview-btn"
                    data-url="${filePath}"
                    data-name="${fileName}"
                    data-type="${isImage ? 'image' : isPDF ? 'pdf' : 'other'}">
                ${fileName}
            </button>
        </div>
    `;

    el.innerHTML = previewHTML;
    return el;
}

/**
 * Setup event listeners for preview buttons
 * NOTE: This is now handled globally in index.php
 */
setupPreviewEvents() {
    console.log('🔍 Preview events are handled globally in index.php');
    // No action needed - global handler in index.php handles everything
}

/**
 * Clear a preview
 */
clearPreview(card, selector) {
    const el = card.querySelector(selector);
    if (el) {
        const prevUrl = el.getAttribute('data-preview-url');
        if (prevUrl) {
            try { URL.revokeObjectURL(prevUrl); } catch (_e) {}
            el.removeAttribute('data-preview-url');
        }
        el.innerHTML = '';
    }
}
    
    /* ================= HELPER METHODS ================= */
    
    addEventListener(element, type, handler) {
        if (!element) return null;
        
        element.addEventListener(type, handler);
        this.eventListeners.push({ element, type, handler });
        return handler;
    }

    /* ================= FILE UPLOAD UI HELPERS ================= */

    getUploadBoxFromInput(input) {
        if (!input) return null;
        const control = input.closest('.form-control');
        if (!control) return null;
        return control.querySelector('[data-file-upload]');
    }

    clearUploadBox(box) {
        if (!box) return;
        const prevUrl = box.getAttribute('data-object-url');
        if (prevUrl) {
            try { URL.revokeObjectURL(prevUrl); } catch (_e) {}
            box.removeAttribute('data-object-url');
        }
        const nameEl = box.querySelector('[data-file-name]');
        const errorEl = box.querySelector('[data-file-error]');

        if (nameEl) {
            nameEl.textContent = 'No file chosen';
            nameEl.classList.remove('preview-btn');
            nameEl.removeAttribute('data-url');
            nameEl.removeAttribute('data-name');
            nameEl.removeAttribute('data-type');
            nameEl.setAttribute('disabled', 'disabled');
        }
        if (errorEl) errorEl.textContent = '';
    }

    setUploadBox(box, fileName, previewUrl, isLocal = false) {
        if (!box) return;
        const nameEl = box.querySelector('[data-file-name]');
        const errorEl = box.querySelector('[data-file-error]');

        if (errorEl) errorEl.textContent = '';
        if (nameEl) {
            const extension = String(fileName || '').split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension);
            const isPDF = extension === 'pdf';
            const iconHtml = isImage
                ? '<i class="fas fa-image"></i>'
                : isPDF
                    ? '<i class="fas fa-file-pdf"></i>'
                    : '<i class="fas fa-file"></i>';

            nameEl.innerHTML = fileName
                ? `${iconHtml}<span>${fileName}</span>`
                : 'No file chosen';
            nameEl.classList.toggle('preview-btn', !!fileName);
            if (fileName) {
                nameEl.removeAttribute('disabled');
                nameEl.setAttribute('data-url', previewUrl);
                nameEl.setAttribute('data-name', fileName);
                nameEl.setAttribute('data-type', isImage ? 'image' : isPDF ? 'pdf' : 'other');
            } else {
                nameEl.setAttribute('disabled', 'disabled');
                nameEl.removeAttribute('data-url');
                nameEl.removeAttribute('data-name');
                nameEl.removeAttribute('data-type');
            }
        }

        if (isLocal) {
            const prevUrl = box.getAttribute('data-object-url');
            if (prevUrl && prevUrl !== previewUrl) {
                try { URL.revokeObjectURL(prevUrl); } catch (_e) {}
            }
            box.setAttribute('data-object-url', previewUrl);
        }
    }

    validateUploadFile(file, allowedExt, maxBytes) {
        if (!file) return { ok: true };
        const name = String(file.name || '').toLowerCase();
        const ext = name.includes('.') ? name.split('.').pop() : '';
        if (!allowedExt.includes(ext)) {
            return { ok: false, message: 'Invalid file type. Only PDF, JPG, JPEG, PNG allowed.' };
        }
        if (file.size > maxBytes) {
            return { ok: false, message: 'File too large. Maximum 10MB allowed.' };
        }
        return { ok: true };
    }

    findInput(card, name) {
        // Try exact match first
        let element = card.querySelector(`[name="${name}"]`);
        if (element) return element;
        
        // Try array notation
        element = card.querySelector(`[name="${name}[]"]`);
        if (element) return element;
        
        // Try without index for first card
        if (!name.includes('[')) {
            element = card.querySelector(`[name="${name}"]`);
        }
        
        return element;
    }
    
    findOrCreateInput(card, name, type = 'hidden') {
        let element = this.findInput(card, name);
        if (!element) {
            element = document.createElement('input');
            element.type = type;
            element.name = name;
            card.appendChild(element);
        }
        return element;
    }
    
    cleanup() {
        // Remove all event listeners
        this.eventListeners.forEach(({ element, type, handler }) => {
            if (element && element.removeEventListener) {
                element.removeEventListener(type, handler);
            }
        });
        this.eventListeners = [];
        
        // Clear references
        this.cards = [];
        this.currentData = [];
    }
    
    showNotification(message, isError = false) {
        // Use Router's alert system if available
        if (window.Router && typeof window.Router.showAlert === 'function') {
            window.Router.showAlert(isError ? 'error' : 'success', message);
            return;
        }
        
        // Fallback to simple alert
        let notification = document.getElementById(`${this.pageName}-notification`);
        
        if (!notification) {
            notification = document.createElement('div');
            notification.id = `${this.pageName}-notification`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${isError ? '#dc2626' : '#10b981'};
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 9999;
                font-weight: 500;
                opacity: 0;
                transform: translateY(-20px);
                transition: opacity 0.3s, transform 0.3s;
            `;
            document.body.appendChild(notification);
        } else {
            notification.style.background = isError ? '#dc2626' : '#10b981';
        }
        
        notification.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px;">
                ${isError ? 
                    '<svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>' :
                    '<svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>'
                }
                <span>${message}</span>
            </div>
        `;
        
        setTimeout(() => {
            notification.style.opacity = '1';
            notification.style.transform = 'translateY(0)';
        }, 10);
        
        setTimeout(() => {
            notification.style.opacity = '0';
            notification.style.transform = 'translateY(-20px)';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }, 3000);
    }
}

if (typeof window !== 'undefined') {
    window.TabManager = TabManager;
}
console.log('✅ TabManager.js loaded');
