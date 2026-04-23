class Success {
    static hasInitialized = false;
    static buttonsBound = false;
    
    static init() {
        // Prevent multiple initializations
        if (this.hasInitialized) {
            console.log("✅ Success already initialized");
            return;
        }
        
        console.log("🎉 Success module initialized");
        this.hasInitialized = true;
        
        // Display application ID
        const appId = this.displayApplicationId();
        
        // Set up buttons with single binding
        this.initButtons();
        
        // Add mobile optimizations
        this.addMobileStyles();
        
        // Try to initialize PDF Viewer if available
        this.tryInitPdfViewer();
    }
    
    static tryInitPdfViewer() {
        // Check if PdfViewer exists, if not load it
        if (typeof PdfViewer === 'undefined') {
            console.log("📄 PdfViewer not found, loading...");
            this.loadPdfViewer();
        } else if (!PdfViewer.modal) {
            // Initialize if not already
            PdfViewer.init();
        }
    }
    
    static loadPdfViewer() {
        // Check if already loading
        if (document.querySelector('script[src*="PdfViewer.js"]')) {
            console.log("📄 PdfViewer.js already loading");
            return;
        }
        
        const baseUrl = window.APP_BASE_URL || '/GSS2';
        const scriptUrl = `${baseUrl}/js/modules/candidate/pages/PdfViewer.js?v=${Date.now()}`;
        
        const script = document.createElement('script');
        script.src = scriptUrl;
        script.async = true;
        script.defer = true;
        
        script.onload = () => {
            console.log("✅ PdfViewer.js loaded successfully");
            if (typeof PdfViewer !== 'undefined') {
                PdfViewer.init();
            }
        };
        
        script.onerror = () => {
            console.error("❌ Failed to load PdfViewer.js");
            // Fallback to simple download/print
            this.setupFallbackButtons();
        };
        
        document.head.appendChild(script);
    }
    
    static initButtons() {
        // Prevent multiple button bindings
        if (this.buttonsBound) {
            console.log("✅ Buttons already bound");
            return;
        }
        
        console.log("🔗 Binding success page buttons");
        
        // Download button
        const downloadBtn = document.getElementById('downloadApplicationBtn');
        if (downloadBtn && !downloadBtn.dataset.bound) {
            downloadBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.openApplicationPDF('download');
            });
            downloadBtn.dataset.bound = 'true';
            console.log("✅ Download button bound");
        } else if (downloadBtn) {
            console.log("📌 Download button already bound");
        }
        
        // View button
        const viewBtn = document.getElementById('viewApplicationBtn');
        if (viewBtn && !viewBtn.dataset.bound) {
            viewBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.openApplicationPDF('view');
            });
            viewBtn.dataset.bound = 'true';
            console.log("✅ View button bound");
        }
        
        // Print button
        const printBtn = document.querySelector('.print-btn');
        if (printBtn && !printBtn.dataset.bound) {
            printBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.openApplicationPDF('print');
            });
            printBtn.dataset.bound = 'true';
            console.log("✅ Print button bound");
        }
        
        // Save button
        const saveBtn = document.getElementById('saveApplicationBtn');
        if (saveBtn && !saveBtn.dataset.bound) {
            saveBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.openApplicationPDF('view');
            });
            saveBtn.dataset.bound = 'true';
            console.log("✅ Save button bound");
        }
        
        this.buttonsBound = true;
    }
    
    static setupFallbackButtons() {
        console.log("🔄 Setting up fallback buttons");
        
        const buttons = [
            'downloadApplicationBtn',
            'viewApplicationBtn',
            'saveApplicationBtn'
        ];
        
        buttons.forEach(btnId => {
            const btn = document.getElementById(btnId);
            if (btn) {
                btn.setAttribute('data-fallback', 'true');
            }
        });
        
        const printBtn = document.querySelector('.print-btn');
        if (printBtn) {
            printBtn.setAttribute('data-fallback', 'true');
            if (!printBtn.dataset.bound) {
                printBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.printSuccessPage();
                });
                printBtn.dataset.bound = 'true';
            }
        }
    }
    
    static addMobileStyles() {
        // Only add once
        if (document.getElementById('success-mobile-styles')) return;
        
        const styles = `
            @media (max-width: 768px) {
                .success-wrapper {
                    margin: 20px auto;
                    padding: 0 15px;
                }
                
                .success-card {
                    padding: 25px 20px;
                    border-radius: 12px;
                }
                
                .success-icon-circle {
                    height: 80px;
                    width: 80px;
                    margin-bottom: 20px;
                }
                
                .success-icon-circle i {
                    font-size: 40px;
                }
                
                .success-title {
                    font-size: 24px;
                    line-height: 1.3;
                }
                
                .success-subtitle {
                    font-size: 16px;
                    line-height: 1.5;
                }
                
                .reference-box {
                    margin-top: 25px;
                    padding: 15px;
                }
                
                .action-buttons {
                    flex-direction: column;
                    gap: 10px;
                    margin: 20px 0;
                }
                
                .action-buttons .btn {
                    width: 100%;
                    padding: 12px;
                    font-size: 16px;
                }
                
                .logout-btn {
                    width: 100%;
                    margin-top: 25px;
                }
            }
            
            @media (max-width: 480px) {
                .success-title {
                    font-size: 22px;
                }
                
                .success-subtitle {
                    font-size: 15px;
                }
            }
        `;
        
        const styleSheet = document.createElement("style");
        styleSheet.id = 'success-mobile-styles';
        styleSheet.textContent = styles;
        document.head.appendChild(styleSheet);
    }
    
    static displayApplicationId() {
        const appIdElement = document.getElementById('applicationIdDisplay');
        if (!appIdElement) return null;
        
        let appId = localStorage.getItem('application_id') || 
                    sessionStorage.getItem('application_id') ||
                    document.querySelector('[data-application-id]')?.dataset?.applicationId ||
                    appIdElement.textContent.trim();
        
        if (appId && appId !== 'N/A') {
            appIdElement.textContent = appId;
            localStorage.setItem('application_id', appId);
            sessionStorage.setItem('application_id', appId);
            
            // Also set as data attribute on body for easy access
            document.body.dataset.applicationId = appId;
        }
        
        console.log(" Application ID:", appId);
        return appId;
    }
    
    static getApplicationId() {
        return (
            document.body.dataset.applicationId ||
            localStorage.getItem('application_id') ||
            sessionStorage.getItem('application_id') ||
            document.getElementById('applicationIdDisplay')?.textContent ||
            ''
        ).toString().trim();
    }
    
    static openApplicationPDF(action) {
        const appId = this.getApplicationId();
        
        if (!appId) {
            console.error("No application ID found");
            return;
        }
        
        const button = action === 'print'
            ? document.querySelector('.print-btn')
            : document.getElementById('downloadApplicationBtn');
        
        if (button) {
            this.setButtonLoading(button, action);
        }
        
        // Check if PdfViewer is available and modal is ready
        if (typeof PdfViewer !== 'undefined' && PdfViewer.show) {
            try {
                // Show modal first
                PdfViewer.show(appId);
                
                // If print was requested, trigger print after modal opens
                if (action === 'print') {
                    setTimeout(() => {
                        if (typeof PdfViewer.printIframe === 'function') {
                            // Wait for iframe to load
                            setTimeout(() => PdfViewer.printIframe(), 1000);
                        }
                    }, 500);
                } else if (action === 'download') {
                    setTimeout(() => {
                        if (typeof PdfViewer.downloadIframe === 'function') {
                            setTimeout(() => PdfViewer.downloadIframe(), 700);
                        }
                    }, 500);
                }
            } catch (error) {
                console.error("Error with PDF viewer:", error);
                this.fallbackOpenPDF(appId, action);
            }
        } else {
            // Fallback to direct download/print
            this.fallbackOpenPDF(appId, action);
        }
        
        // Reset button after delay
        if (button) {
            setTimeout(() => this.resetButton(button, action), 2000);
        }
    }
    
    static setButtonLoading(button, action) {
        const originalHtml = button.innerHTML;
        const originalText = button.textContent.trim();
        
        button.dataset.originalHtml = originalHtml;
        button.dataset.originalText = originalText;
        
        const loadingText = action === 'print' ? 
            '<i class="fas fa-spinner fa-spin me-2"></i>Preparing...' :
            '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';
        
        button.innerHTML = loadingText;
        button.disabled = true;
        button.classList.add('btn-loading');
    }
    
    static resetButton(button, action) {
        if (!button) return;
        
        const originalHtml = button.dataset.originalHtml || 
            (action === 'print' ? 
                '<i class="fas fa-print me-2"></i>Print Application' :
                '<i class="fas fa-download me-2"></i>Download Application');
        
        button.innerHTML = originalHtml;
        button.disabled = false;
        button.classList.remove('btn-loading');
    }
    
    static fallbackOpenPDF(appId, action) {
        const baseUrl = window.APP_BASE_URL || '/GSS2';
        
        if (action === 'print') {
            // Open in new tab with print parameter
            const printUrl = `${baseUrl}/api/candidate/generate_pdf.php?application_id=${appId}&bypass=1&print=1&autoclose=1`;
            const printWindow = window.open(printUrl, '_blank');
            
            // Auto-print after load
            if (printWindow) {
                printWindow.onload = function() {
                    printWindow.print();
                };
            }
        } else if (action === 'download') {
            const downloadUrl = `${baseUrl}/api/candidate/generate_pdf.php?application_id=${appId}&bypass=1&force_download=1`;
            window.open(downloadUrl, '_blank');
        } else {
            // Open in new tab for viewing
            const viewUrl = `${baseUrl}/api/candidate/generate_pdf.php?application_id=${appId}&bypass=1&embedded=1`;
            window.open(viewUrl, '_blank');
        }
    }
    
    static printSuccessPage() {
        console.log("🖨️ Printing success page...");
        
        const appId = this.getApplicationId();
        const printDate = new Date().toLocaleString();
        
        const printContent = `
            <div style="font-family: Arial, sans-serif; padding: 40px; max-width: 800px; margin: 0 auto;">
                <div style="text-align: center; margin-bottom: 40px;">
                    <div style="color: #1aae6f; font-size: 60px; margin-bottom: 20px;">✓</div>
                    <h1 style="color: #333; margin-bottom: 20px; font-size: 28px;">Application Submitted Successfully</h1>
                    <p style="color: #666; font-size: 18px; line-height: 1.6;">
                        Thank you for completing your background verification form.<br>
                        Our team will now begin the review and verification process.
                    </p>
                </div>
                
                <div style="border-left: 5px solid #1aae6f; padding: 25px; margin: 40px 0; background: #f8f9fa;">
                    <h3 style="color: #333; margin-bottom: 15px; font-size: 20px;">Your Reference Number</h3>
                    <h2 style="color: #1aae6f; margin: 20px 0; font-size: 32px; font-weight: bold;">${appId}</h2>
                    <p style="color: #666; font-size: 16px;">
                        Please keep this reference number for all future correspondence regarding your application.
                    </p>
                </div>
                
                <div style="margin-top: 60px; padding-top: 30px; border-top: 1px solid #ddd; color: #666; font-size: 14px;">
                    <p>Printed on: ${printDate}</p>
                    <p><em>This is a confirmation of your application submission.</em></p>
                </div>
            </div>
        `;
        
        const printWindow = window.open('', '_blank', 'width=900,height=600');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Application Confirmation - ${appId}</title>
                <style>
                    @media print {
                        @page { margin: 20mm; }
                        body { -webkit-print-color-adjust: exact; }
                    }
                    body { margin: 0; padding: 0; }
                </style>
            </head>
            <body>${printContent}</body>
            </html>
        `);
        printWindow.document.close();
        
        printWindow.onload = function() {
            printWindow.focus();
            printWindow.print();
        };
    }
    
    // Cleanup method
    static cleanup() {
        console.log("🧹 Success module cleanup called");
        
        // Reset initialization flags
        this.hasInitialized = false;
        this.buttonsBound = false;
        
        // Clean up PDF viewer if open
        if (typeof PdfViewer !== 'undefined' && PdfViewer.isOpen) {
            PdfViewer.close();
        }
        
        // Remove event listeners by resetting data-bound attribute
        const buttons = document.querySelectorAll('[data-bound]');
        buttons.forEach(btn => {
            btn.removeAttribute('data-bound');
        });
    }
}

// Expose globally
window.Success = Success;

// Auto-initialize if module loaded directly
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        console.log("📚 Success.js module loaded and registered globally");
    });
} else {
    console.log("📚 Success.js module loaded and registered globally");
}
