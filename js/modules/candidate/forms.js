class Forms {

    // Pages where Forms.js should do nothing at all
    static ignorePages = ["review-confirmation", "success", "identification", "education", "employment"];

    static initFormHandlers(page) {
        // Completely skip custom pages
        if (this.ignorePages.includes(page)) {
            console.log(`⏭️ Forms.js fully skipping ${page} (custom page)`);
            return;
        }

        console.log(`Forms: Initializing handlers for ${page}`);

        const setup = () => {
            const form = document.getElementById(`${page}Form`);
            if (!form) {
                console.warn(`Form ${page}Form not found`);
                return;
            }

            // Load any existing draft first
            this.loadDraft(form, page);

            // Then set up auto-save
            this.setupAutoSave(form, page);
        };

        // Small delay to ensure DOM is ready after router
        setTimeout(setup, 300);
    }

    // Auto-save on input (debounced)
    static setupAutoSave(form, page) {
        form.addEventListener('input', this.debounce((e) => {
            // Skip file inputs and photo (handled separately)
            if (e.target.type === 'file') return;
            if (page === 'basic-details' && e.target.name === 'photo') return;

            this.saveDraft(form, page);
        }, 1000));
    }

    // Save draft to localStorage
    static saveDraft(form, page) {
        try {
            const data = {};
            const inputs = form.querySelectorAll('input, select, textarea');

            inputs.forEach(input => {
                if (input.type === 'file' || input.name === '') return;

                if (input.type === 'checkbox' || input.type === 'radio') {
                    if (input.checked) {
                        data[input.name] = input.value;
                    }
                } else {
                    data[input.name] = input.value;
                }
            });

            localStorage.setItem(`draft-${page}`, JSON.stringify(data));
            localStorage.setItem(`draft-${page}-timestamp`, Date.now());

            console.log(`💾 Draft auto-saved for ${page}`);
        } catch (err) {
            console.error('Draft save error:', err);
        }
    }

    // Load draft from localStorage
    static loadDraft(form, page) {
        try {
            const draft = localStorage.getItem(`draft-${page}`);
            if (!draft) {
                console.log(`No draft found for ${page}`);
                return;
            }

            const data = JSON.parse(draft);
            let fieldCount = 0;

            Object.keys(data).forEach(key => {
                const inputs = form.querySelectorAll(`[name="${key}"]`);
                inputs.forEach(input => {
                    if (input.type === 'checkbox' || input.type === 'radio') {
                        if (input.value === data[key]) {
                            input.checked = true;
                            fieldCount++;
                        }
                    } else {
                        input.value = data[key];
                        fieldCount++;
                    }
                });
            });

            console.log(`📂 Loaded draft for ${page}: ${fieldCount} fields restored`);
        } catch (err) {
            console.error('Draft load error:', err);
        }
    }

    // Clear draft after successful submit
    static clearDraft(pageId) {
        try {
            localStorage.removeItem(`draft-${pageId}`);
            localStorage.removeItem(`draft-${pageId}-timestamp`);
            console.log(`🗑️ Draft cleared for ${pageId}`);
        } catch (err) {
            console.error('Error clearing draft:', err);
        }
    }

    // Optional: Mark as submitted
    static markAsSubmitted(page) {
        localStorage.setItem(`draft-${page}-submitted`, 'true');
        console.log(`✅ Marked ${page} as submitted`);
    }

    // Debounce utility
    static debounce(fn, delay) {
        let timeout;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => fn.apply(this, args), delay);
        };
    }
}

// Export globally
window.Forms = Forms;
