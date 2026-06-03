class ModalManager {
    static init() {
        if (window._modalManagerInitialized) return;
        
        console.log('🔄 Initializing ModalManager');
        this.createModal();
        this.setupEventListeners();
        
        window._modalManagerInitialized = true;
    }
    
    static createModal() {
        const existingModal = document.getElementById('documentPreviewModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        const modalHTML = `
            <div class="modal fade" id="documentPreviewModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="previewModalTitle">Document Preview</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="previewModalBody">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2">Loading document...</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <a id="previewDownloadBtn" class="btn btn-primary" target="_blank" download>
                                <i class="fas fa-download me-1"></i> Download
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        console.log('✅ Preview modal created');
    }
    
    static setupEventListeners() {
        // Event delegation for all preview buttons
        document.addEventListener('click', (e) => {
            const previewBtn = e.target.closest('.preview-btn');
            if (previewBtn) {
                e.preventDefault();
                e.stopPropagation();
                
                const url = previewBtn.getAttribute('data-url') || 
                           previewBtn.getAttribute('data-doc-url');
                const name = previewBtn.getAttribute('data-name') || 
                            previewBtn.getAttribute('data-doc-name') || 'Document';
                const type = previewBtn.getAttribute('data-type') || 'other';
                
                if (url) {
                    console.log(`📄 Preview requested: ${name}`, url);
                    this.openPreviewModal(url, name, type);
                }
            }
        });
    }
    
    static openPreviewModal(url, name, type = 'other') {
        console.log(`🔄 Opening preview modal for: ${name} (${type})`);
        
        // Ensure modal exists
        if (!document.getElementById('documentPreviewModal')) {
            this.createModal();
        }
        
        const modal = document.getElementById('documentPreviewModal');
        const modalBody = document.getElementById('previewModalBody');
        const modalTitle = document.getElementById('previewModalTitle');
        const downloadBtn = document.getElementById('previewDownloadBtn');
        
        if (!modal || !modalBody || !modalTitle || !downloadBtn) {
            console.error('❌ Modal elements not found');
            // Fallback to new tab
            window.open(url, '_blank');
            return;
        }
        
        // Set modal title
        modalTitle.textContent = `Preview: ${name}`;
        
        // Set download button
        downloadBtn.href = url;
        downloadBtn.download = name;
        downloadBtn.textContent = 'Download';
        downloadBtn.innerHTML = '<i class="fas fa-download me-1"></i> Download';
        
        // Set content based on file type
        let contentHTML = '';
        
        if (type === 'image') {
            contentHTML = `
                <div class="text-center">
                    <img src="${url}" alt="${name}" class="img-fluid rounded" 
                         style="max-height: 70vh; object-fit: contain;"
                         onerror="this.onerror=null;this.src='data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"400\" height=\"300\"><rect width=\"100%\" height=\"100%\" fill=\"%23f8f9fa\"/><text x=\"50%\" y=\"50%\" text-anchor=\"middle\" dy=\".3em\" fill=\"%236c757d\">Image not available</text></svg>';">
                    <p class="mt-3 text-muted">${name}</p>
                </div>
            `;
        } else if (type === 'pdf') {
            contentHTML = `
                <div style="height: 70vh;">
                    <iframe src="${url}#view=fitH" 
                            width="100%" 
                            height="100%" 
                            frameborder="0"
                            style="border-radius: 4px;"
                            onerror="this.parentElement.innerHTML='<div class=\"alert alert-danger\"><i class=\"fas fa-exclamation-triangle me-2\"></i>Failed to load PDF. Please download the file.</div>'">
                    </iframe>
                    <p class="mt-2 text-muted">${name}</p>
                </div>
            `;
        } else {
            contentHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-file fa-4x text-secondary mb-3"></i>
                    <h5>${name}</h5>
                    <p class="text-muted">This file format cannot be previewed in the browser.</p>
                    <p>Please download the file to view its contents.</p>
                </div>
            `;
        }
        
        modalBody.innerHTML = contentHTML;
        
        // Show modal
        try {
            // Check if Bootstrap is available
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modalInstance = bootstrap.Modal.getOrCreateInstance(modal);
                modalInstance.show();
                console.log('✅ Modal shown using Bootstrap');
            } else {
                // Fallback: show modal manually
                console.log('⚠️ Bootstrap not available, showing modal manually');
                modal.style.display = 'block';
                modal.classList.add('show');
                modal.setAttribute('aria-modal', 'true');
                modal.setAttribute('aria-hidden', 'false');
                
                // Add backdrop
                const backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                document.body.appendChild(backdrop);
                
                // Add close handlers
                const closeModal = () => {
                    modal.style.display = 'none';
                    modal.classList.remove('show');
                    if (backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                };
                
                modal.querySelectorAll('[data-bs-dismiss="modal"], .btn-secondary').forEach(btn => {
                    btn.onclick = closeModal;
                });
                
                // Close when clicking backdrop
                modal.onclick = (e) => {
                    if (e.target === modal) closeModal();
                };
            }
        } catch (error) {
            console.error('❌ Error showing modal:', error);
            window.open(url, '_blank');
        }
    }
    
    static cleanup() {
        const modal = document.getElementById('documentPreviewModal');
        if (modal) {
            modal.remove();
        }
        window._modalManagerInitialized = false;
    }
}

// Auto-initialize on page load
if (typeof window !== 'undefined') {
    window.ModalManager = ModalManager;
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ModalManager.init());
    } else {
        ModalManager.init();
    }
}

console.log('✅ ModalManager.js loaded');
