class ReviewConfirmation {
    
    // Simple notification helper
    static showNotification(message, type = "info") {
        if (typeof window.showAlert === "function") {
            window.showAlert({ type, message });
        } else {
            console.log(`[${type}] ${message}`);
        }
    }
    
    static init() {
        console.log("📝 ReviewConfirmation module initialized");
        this.setupValidation();
    }
    
    static setupValidation() {
        const agreeCheck = document.getElementById('agreeCheck');
        const signatureInput = document.getElementById('digitalSignature');
        const submitBtn = document.getElementById('submitFinalBtn');
        
        if (!agreeCheck || !signatureInput || !submitBtn) return;
        
        const validate = () => {
            const isValid = agreeCheck.checked && signatureInput.value.trim().length >= 3;
            submitBtn.disabled = !isValid;
            
            // Show hints
            if (!isValid) {
                if (!agreeCheck.checked && signatureInput.value.trim().length >= 3) {
                    this.showNotification("Check agreement checkbox", "info");
                } else if (agreeCheck.checked && signatureInput.value.trim().length < 3) {
                    this.showNotification("Enter your full name", "info");
                }
            }
        };
        
        // Event listeners
        agreeCheck.addEventListener('change', validate);
        signatureInput.addEventListener('input', validate);
        
        // Submit handler
        submitBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            
            if (submitBtn.disabled) {
                this.showNotification("Please complete all required fields", "warning");
                return;
            }
            
            this.showNotification("✅ Saving authorization...", "success");

            try {
                submitBtn.disabled = true;

                const fd = new FormData();
                fd.append('agree_check', agreeCheck.checked ? '1' : '');
                fd.append('digital_signature', String(signatureInput.value || '').trim());

                const res = await fetch(`${window.APP_BASE_URL}/api/candidate/store_authorization.php`, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });

                const data = await res.json().catch(() => null);
                if (!data || data.success !== true) {
                    const msg = data && data.message ? data.message : 'Failed to save authorization';
                    this.showNotification(msg, 'warning');
                    submitBtn.disabled = false;
                    return;
                }

                this.showNotification("✅ Authorization saved", "success");
            } catch (err) {
                this.showNotification(err && err.message ? err.message : 'Failed to save authorization', 'warning');
                submitBtn.disabled = false;
                return;
            }

            // Mark as completed
            if (window.Router && window.Router.markCompleted) {
                Router.markCompleted('review-confirmation');
            } else {
                localStorage.setItem('completed-review-confirmation', '1');
            }

            // Navigate
            if (window.Router && window.Router.navigateTo) {
                Router.navigateTo('basic-details');
            } else {
                window.location.href = '?page=basic-details';
            }
        });
        
        validate(); // Initial validation
    }
}

window.ReviewConfirmation = ReviewConfirmation;
