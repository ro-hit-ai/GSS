class PdfViewer {
    static modal = null;
    static iframe = null;
    static isOpen = false;
    static currentAppId = null;
    
    static init() {
        // Check if already initialized
        if (this.modal) return;
        
        // Create modal structure
        this.createModal();
        console.log("📄 PDF Viewer initialized");
    }
    
    static createModal() {
        // Check if modal already exists
        if (document.getElementById('pdfViewerModal')) return;
        
        // Create modal HTML with buttons at the bottom
        const modalHTML = `
            <div class="modal fade" id="pdfViewerModal" tabindex="-1" aria-labelledby="pdfViewerModalLabel" 
                 data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog modal-xl modal-fullscreen-md-down">
                    <div class="modal-content h-100">
                        <!-- Header with only title and close button -->
                        <div class="modal-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-file-pdf fa-lg me-3"></i>
                                <div>
                                    <h5 class="modal-title mb-0 fw-bold" id="pdfViewerModalLabel">
                                        Background Verification Application
                                    </h5>
                                    <small class="opacity-75" id="pdfAppIdLabel"></small>
                                </div>
                            </div>
                            <!-- Only close button in top-right -->
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" 
                                    aria-label="Close"></button>
                        </div>
                        
                        <!-- Modal body with iframe -->
                        <div class="modal-body p-0 position-relative" style="flex: 1; min-height: 0;">
                            <!-- Loading indicator -->
                            <div class="loading-overlay d-flex flex-column justify-content-center align-items-center" 
                                 id="pdfLoading">
                                <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;" 
                                     role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <h5 class="text-muted">Loading application form...</h5>
                                <p class="text-muted small mt-2">Please wait while we prepare your document</p>
                            </div>
                            
                            <!-- Error message -->
                            <div class="alert alert-danger d-none m-4" id="pdfError" role="alert">
                                <div class="d-flex">
                                    <i class="fas fa-exclamation-triangle fa-2x me-3 mt-1"></i>
                                    <div>
                                        <h5 class="alert-heading mb-2">Failed to Load Document</h5>
                                        <p class="mb-0" id="errorMessage">Failed to load application form.</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- PDF iframe -->
                            <iframe id="pdfViewerIframe" 
                                    frameborder="0" 
                                    class="w-100 h-100 d-none"
                                    title="Background Verification Application"
                                    sandbox="allow-scripts allow-same-origin allow-downloads allow-modals">
                            </iframe>
                        </div>
                        
                        <!-- Footer with action buttons -->
                    </div>
                </div>
            </div>
        `;
        
        // Add to body
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        // Get modal element and initialize Bootstrap modal
        const modalElement = document.getElementById('pdfViewerModal');
        this.modal = new bootstrap.Modal(modalElement, {
            backdrop: 'static',
            keyboard: false,
            focus: true
        });
        
        // Get iframe reference
        this.iframe = document.getElementById('pdfViewerIframe');
        
        // Add event listeners - check if they exist first
        const printBtn = document.getElementById('pdfPrintBtn');
        const downloadBtn = document.getElementById('pdfDownloadBtn');
        
        if (printBtn && !printBtn.dataset.hasListener) {
            printBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.printIframe();
            });
            printBtn.dataset.hasListener = 'true';
        }
        
        if (downloadBtn && !downloadBtn.dataset.hasListener) {
            downloadBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.downloadIframe();
            });
            downloadBtn.dataset.hasListener = 'true';
        }
        
        // Fix accessibility issues
        modalElement.addEventListener('show.bs.modal', () => {
            modalElement.removeAttribute('aria-hidden');
            modalElement.setAttribute('aria-modal', 'true');
            this.isOpen = true;
        });
        
        // Reset on hide
        modalElement.addEventListener('hidden.bs.modal', () => {
            if (this.iframe) {
                this.iframe.src = 'about:blank';
                this.iframe.classList.add('d-none');
            }
            this.currentAppId = null;
            this.isOpen = false;
            
            // Remove focus trap
            modalElement.removeAttribute('aria-hidden');
        });
        
        // Add custom styles
        this.addStyles();
    }
    
    static addStyles() {
        // Add styles only once
        if (document.getElementById('pdf-viewer-styles')) return;
        
        const styles = `
            /* Modal sizing */
            #pdfViewerModal .modal-xl {
                max-width: 95%;
                height: 90vh;
                margin: 5vh auto;
            }
            
            /* Modal content */
            #pdfViewerModal .modal-content {
                border: none;
                box-shadow: 0 5px 30px rgba(0, 0, 0, 0.15);
                border-radius: 10px;
                overflow: hidden;
            }
            
            /* Header styling */
            #pdfViewerModal .modal-header {
                border-bottom: 1px solid rgba(255, 255, 255, 0.15);
                background: linear-gradient(135deg, #2c3e50, #34495e);
            }
            
            /* Body styling */
            #pdfViewerModal .modal-body {
                background: #f8f9fa;
            }
            
            /* Footer styling */
            #pdfViewerModal .modal-footer {
                border-top: 1px solid #dee2e6;
                background: #f8f9fa;
            }
            
            /* Iframe styling */
            #pdfViewerModal iframe {
                border: none;
                background: white;
            }
            
            /* Loading overlay */
            #pdfViewerModal .loading-overlay {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(255, 255, 255, 0.95);
                z-index: 10;
                backdrop-filter: blur(2px);
            }
            
            /* Mobile optimizations */
            @media (max-width: 768px) {
                #pdfViewerModal .modal-xl {
                    max-width: 100%;
                    height: 100vh;
                    margin: 0;
                }
                
                #pdfViewerModal .modal-content {
                    border-radius: 0;
                }
                
                #pdfViewerModal .modal-header {
                    padding: 12px 15px;
                }
                
                #pdfViewerModal .modal-footer {
                    flex-direction: column;
                    gap: 15px;
                    padding: 15px;
                }
                
                #pdfViewerModal .modal-footer > div {
                    width: 100%;
                    justify-content: center;
                }
                
                #pdfViewerModal .modal-footer .d-flex {
                    width: 100%;
                    justify-content: center;
                    flex-wrap: wrap;
                    gap: 10px;
                }
                
                #pdfViewerModal #pdfPrintBtn,
                #pdfViewerModal #pdfDownloadBtn,
                #pdfViewerModal .btn-outline-secondary {
                    min-width: 120px;
                    flex: 1;
                }
            }
            
            /* Print optimization */
            @media print {
                #pdfViewerModal .modal-header,
                #pdfViewerModal .modal-footer,
                #pdfViewerModal .loading-overlay,
                #pdfViewerModal #pdfError {
                    display: none !important;
                }
                
                #pdfViewerModal .modal-content {
                    border: none !important;
                    box-shadow: none !important;
                    border-radius: 0 !important;
                }
                
                #pdfViewerModal .modal-body {
                    padding: 0 !important;
                    margin: 0 !important;
                }
                
                #pdfViewerModal iframe {
                    height: 100% !important;
                    min-height: auto !important;
                }
            }
        `;
        
        const styleSheet = document.createElement("style");
        styleSheet.id = 'pdf-viewer-styles';
        styleSheet.textContent = styles;
        document.head.appendChild(styleSheet);
    }
    
    static show(applicationId) {
        // Initialize if needed
        if (!this.modal) {
            this.init();
        }
        
        this.currentAppId = applicationId;
        
        const baseUrl = window.APP_BASE_URL || '/GSS2';
        const pdfUrl = `${baseUrl}/api/candidate/generate_pdf.php?application_id=${applicationId}&bypass=1&embedded=1&t=${Date.now()}`;
        
        console.log("Opening PDF viewer for:", applicationId);
        
        // Update app ID label
        const appIdLabel = document.getElementById('pdfAppIdLabel');
        if (appIdLabel) {
            appIdLabel.textContent = `Application ID: ${applicationId}`;
        }
        
        // Show loading
        const loadingEl = document.getElementById('pdfLoading');
        const errorEl = document.getElementById('pdfError');
        
        if (loadingEl) {
            loadingEl.classList.remove('d-none');
            loadingEl.style.display = 'flex';
        }
        if (errorEl) errorEl.classList.add('d-none');
        if (this.iframe) this.iframe.classList.add('d-none');
        
        // Load iframe
        if (this.iframe) {
            this.iframe.src = pdfUrl;
        }
        
        // Show modal
        if (this.modal) {
            this.modal.show();
            this.isOpen = true;
        }
        
        // Set up iframe event handlers
        if (this.iframe) {
            const onLoadHandler = () => {
                setTimeout(() => {
                    if (loadingEl) {
                        loadingEl.classList.add('d-none');
                        loadingEl.style.display = 'none';
                    }
                    if (this.iframe) this.iframe.classList.remove('d-none');
                    
                    // Check for errors in iframe content
                    try {
                        const iframeDoc = this.iframe.contentDocument || this.iframe.contentWindow.document;
                        const bodyText = iframeDoc.body.textContent || iframeDoc.body.innerHTML;
                        
                        if (bodyText.includes('error') || bodyText.includes('Error') || 
                            bodyText.includes('not found') || bodyText.includes('Unauthorized')) {
                            this.showError('Failed to load application form. The document may not be available.');
                        }
                    } catch (e) {
                        console.log("Iframe loaded successfully");
                    }
                }, 500);
            };
            
            const onErrorHandler = () => {
                this.showError('Failed to load application form. Please check your internet connection and try again.');
            };
            
            // Remove previous listeners
            this.iframe.onload = null;
            this.iframe.onerror = null;
            
            // Add new listeners
            this.iframe.onload = onLoadHandler;
            this.iframe.onerror = onErrorHandler;
        }
    }
    
    static showError(message) {
        const loadingEl = document.getElementById('pdfLoading');
        const errorEl = document.getElementById('pdfError');
        const errorMessage = document.getElementById('errorMessage');
        
        if (loadingEl) {
            loadingEl.classList.add('d-none');
            loadingEl.style.display = 'none';
        }
        if (this.iframe) this.iframe.classList.add('d-none');
        if (errorEl) errorEl.classList.remove('d-none');
        if (errorMessage) errorMessage.textContent = message;
    }
    
    static printIframe() {
        try {
            if (this.iframe && this.iframe.contentWindow) {
                const iframeWindow = this.iframe.contentWindow;
                iframeWindow.focus();
                
                if (iframeWindow && iframeWindow.print) {
                    iframeWindow.print();
                } else {
                    console.error("Iframe not ready for printing");
                    if (typeof window.showAlert === 'function') {
                        window.showAlert({ type: 'info', message: 'Document is still loading. Please try again in a moment.' });
                    }
                }
            }
        } catch (error) {
            console.error("Print error:", error);
            
            // Fallback method
            const baseUrl = window.APP_BASE_URL || '/GSS2';
            const printUrl = `${baseUrl}/api/candidate/generate_pdf.php?application_id=${this.currentAppId}&bypass=1&print=1&autoclose=1`;
            window.open(printUrl, '_blank');
        }
    }
    
    static downloadIframe() {
        try {
            const appId = String(this.currentAppId || '').trim();
            if (!appId) {
                console.error("No application ID for download");
                return;
            }

            const baseUrl = window.APP_BASE_URL || '/GSS2';
            const downloadUrl = `${baseUrl}/api/candidate/generate_pdf.php?application_id=${encodeURIComponent(appId)}&bypass=1&force_download=1`;
            window.open(downloadUrl, '_blank');
        } catch (error) {
            console.error("Download error:", error);
            
            // Fallback method
            const baseUrl = window.APP_BASE_URL || '/GSS2';
            const downloadUrl = `${baseUrl}/api/candidate/generate_pdf.php?application_id=${this.currentAppId}&bypass=1&force_download=1`;
            window.open(downloadUrl, '_blank');
        }
    }
    
    static close() {
        if (this.modal) {
            this.modal.hide();
            this.isOpen = false;
        }
    }
    
    // Cleanup method to prevent memory leaks
    static cleanup() {
        if (this.modal) {
            this.modal.hide();
            const modalElement = document.getElementById('pdfViewerModal');
            if (modalElement) {
                modalElement.remove();
            }
            this.modal = null;
            this.iframe = null;
            this.isOpen = false;
            this.currentAppId = null;
        }
    }
}

// Expose globally
window.PdfViewer = PdfViewer;
