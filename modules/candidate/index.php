<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['application_id'])) {
    header('Location: ' . app_url('/modules/candidate/login.php'));
    exit;
}

// Load application from session
$applicationId = (string)$_SESSION['application_id'];

// UI labels
$userName = $_SESSION['user_name'] ?? "Candidate";
$userEmail = $_SESSION['user_email'] ?? '';

$prefillFirstName = '';
$prefillLastName = '';
if (!empty($userName) && $userName !== 'Candidate') {
    $parts = preg_split('/\s+/', trim((string)$userName));
    if (is_array($parts) && count($parts) > 0) {
        $prefillFirstName = (string)($parts[0] ?? '');
        if (count($parts) > 1) {
            $prefillLastName = (string)end($parts);
        }
    }
}

if (($userEmail === '' || $userName === '' || $userName === 'Candidate') && function_exists('getDB')) {
    try {
        $pdo = getDB();

        $case = null;
        if (!empty($_SESSION['case_id'])) {
            $stmt = $pdo->prepare('SELECT candidate_first_name, candidate_last_name, candidate_email FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
            $stmt->execute([(int)$_SESSION['case_id']]);
            $case = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$case) {
            $stmt = $pdo->prepare('SELECT candidate_first_name, candidate_last_name, candidate_email FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
            $stmt->execute([$applicationId]);
            $case = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($case) {
            $dbName = trim((string)($case['candidate_first_name'] ?? '') . ' ' . (string)($case['candidate_last_name'] ?? ''));
            $dbEmail = (string)($case['candidate_email'] ?? '');

            if ($dbName !== '') {
                $_SESSION['user_name'] = $dbName;
                $userName = $dbName;
            }
            if ($dbEmail !== '') {
                $_SESSION['user_email'] = $dbEmail;
                $userEmail = $dbEmail;
            }
        }
    } catch (Throwable $e) {
        error_log("Database error in index.php: " . $e->getMessage());
        // keep silent for UI
    }
}

// Use the app_url() function from your env.php or define it if missing
if (!function_exists('app_url')) {
    function app_url($path = '') {
        // Use APP_BASE_URL from .env file
        $base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
        if (!$base) {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $parts = explode('/', trim($scriptName, '/'));
            $base = !empty($parts[0]) ? '/' . $parts[0] : '';
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('app_base_url')) {
    function app_base_url() {
        $base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
        if (!$base) {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $parts = explode('/', trim($scriptName, '/'));
            $base = !empty($parts[0]) ? '/' . $parts[0] : '';
        }
        return $base;
    }
}

// Get APP_BASE_URL for JavaScript
$jsAppBaseUrl = defined('APP_BASE_URL') ? APP_BASE_URL : '';
if (!$jsAppBaseUrl) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $parts = explode('/', trim($scriptName, '/'));
    $jsAppBaseUrl = !empty($parts[0]) ? '/' . $parts[0] : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ATTEST360 - Background Verification</title>

    <!-- Bootstrap + Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Core UI styles -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/css/candidate.css')); ?>">

    <!-- Set APP_BASE_URL early -->
    <script>
        window.APP_BASE_URL = "<?php echo htmlspecialchars($jsAppBaseUrl); ?>";
        console.log("🌐 APP_BASE_URL set to:", window.APP_BASE_URL);
        window.CANDIDATE_APP_ID = <?php echo json_encode((string)$applicationId); ?>;
    </script>

    <!-- Candidate UI CSS -->
</head>

<body class="candidate-page">

<script>
    window.CANDIDATE_PREFILL = <?php echo json_encode([
        'name' => $userName,
        'email' => $userEmail,
        'first_name' => $prefillFirstName,
        'last_name' => $prefillLastName,
    ]); ?>;

    (function () {
        try {
            if (window.localStorage && window.CANDIDATE_PREFILL) {
                var existing = null;
                try {
                    existing = JSON.parse(window.localStorage.getItem('candidate_prefill') || 'null');
                } catch (e) {
                    existing = null;
                }

                var merged = Object.assign({}, existing || {}, window.CANDIDATE_PREFILL || {});
                window.localStorage.setItem('candidate_prefill', JSON.stringify(merged));
            }
        } catch (e) {
            // ignore storage errors
        }
    })();
</script>

<header class="top-header">
    <div class="top-header-left">
        <div class="brand-mark"><img class="brand-logo" src="<?php echo htmlspecialchars(app_url('/assets/img/gss-logo.svg')); ?>" alt="GSS"></div>
        <div class="brand-text">
            <span class="brand-title">VATI GSS</span>
            <span class="brand-subtitle">Verification Platform</span>
        </div>
        <button type="button" class="btn-toggle-sidebar" id="sidebarToggle" aria-label="Toggle navigation">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="top-header-right">
        <button type="button" class="btn btn-sm btn-logout" onclick="window.location.href='<?php echo htmlspecialchars(app_url('/logout.php')); ?>'">Logout</button>
    </div>
</header>

<div class="app-shell">
    <?php include __DIR__ . '/../../api/candidate/aside.php'; ?>

    <div class="main" id="mainContent">
        <main class="page-area">
            <div class="candidate-wrapper">
                <div id="page-content">
                    <div class="text-center py-5">
                        <div class="spinner-border"></div>
                        <p class="text-muted mt-3">Loading application form...</p>
                    </div>
                </div>
            </div>
        </main>

        <footer class="app-footer">
            <span><?php echo date('Y'); ?> VATI GSS. All rights reserved.</span>
            <span>Environment: Staging</span>
        </footer>
    </div>
</div>

<!-- Bootstrap Bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- ============================================
     SPA APPLICATION SCRIPTS
============================================ -->

<!-- 1. CORE UTILITIES -->
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/forms.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/DraftManager.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/TabManager.js')); ?>"></script>

<!-- 2. PAGE MODULES -->
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/pages/BasicDetails.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/pages/Identification.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/pages/Contact.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/pages/Ecourt.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/pages/Social.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/pages/Education.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/pages/Employment.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/pages/Reference.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/pages/ReviewConfirmation.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/pages/Success.js')); ?>"></script>

<!-- 3. ROUTER (must load last) -->
<!--<script src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/router.js')); ?>"></script> -->

<script
    src="<?php echo htmlspecialchars(app_url('/js/modules/candidate/router.js')); ?>"
    data-app-base="<?php echo htmlspecialchars($jsAppBaseUrl); ?>">
</script>

<!-- ============================================
     GLOBAL PREVIEW MODAL - SIMPLIFIED VERSION
============================================ -->
<div class="modal fade" id="globalDocumentPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Document Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="globalPreviewContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading document...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a id="globalPreviewDownloadBtn" class="btn btn-primary" target="_blank" download>
                    <i class="fas fa-download me-1"></i> Download
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Global Preview Handler - FIXED & EXTENDED (SAFE)
(function () {
    console.log('🔧 Initializing global preview handler');

    // Event delegation for all preview buttons
    document.addEventListener('click', function (e) {
        const previewBtn = e.target.closest('.preview-btn');
        if (!previewBtn) return;

        e.preventDefault();
        e.stopPropagation();

        // Extract attributes
        const url =
            previewBtn.getAttribute('data-url') ||
            previewBtn.getAttribute('data-doc-url');

        const name =
            previewBtn.getAttribute('data-name') ||
            previewBtn.getAttribute('data-doc-name') ||
            'Document';

        let type =
            previewBtn.getAttribute('data-type') || 'other';

        if (!url) return;

        // Auto-detect file type if not provided
        if (type === 'other') {
            if (url.match(/\.pdf$/i)) type = 'pdf';
            else if (url.match(/\.(png|jpe?g|gif|webp)$/i)) type = 'image';
        }

        console.log('📄 Global preview:', name, url, type);

        // Prefer form-width preview if available
        if (typeof window.openFormPreview === 'function') {
            window.openFormPreview(url, name);
        } else {
            openGlobalPreviewModal(url, name, type);
        }
    });

    // Bootstrap modal preview (existing system)
    function openGlobalPreviewModal(url, name, type = 'other') {
        const modal = document.getElementById('globalDocumentPreviewModal');
        const content = document.getElementById('globalPreviewContent');
        const downloadBtn = document.getElementById('globalPreviewDownloadBtn');

        if (!modal || !content || !downloadBtn) {
            console.warn('⚠️ Preview modal missing, opening in new tab');
            window.open(url, '_blank');
            return;
        }

        const titleEl = modal.querySelector('.modal-title');
        if (titleEl) {
            const shortName =
                name.length > 30 ? name.slice(0, 27) + '…' : name;
            titleEl.textContent = `Preview: ${shortName}`;
            titleEl.title = name;
        }

        // Download button
        downloadBtn.href = url;
        downloadBtn.download = name;
        downloadBtn.innerHTML =
            '<i class="fas fa-download me-1"></i> Download';

        let html = '';

        if (type === 'image') {
            html = `
                <div class="text-center">
                    <img src="${url}"
                         alt="${name}"
                         class="img-fluid rounded"
                         style="max-height:320px;object-fit:contain"
                         onerror="this.onerror=null;this.src='data:image/svg+xml;utf8,<svg xmlns=\\'http://www.w3.org/2000/svg\\' width=\\'400\\' height=\\'300\\'><rect width=\\'100%\\' height=\\'100%\\' fill=\\'%23f8f9fa\\'/><text x=\\'50%\\' y=\\'50%\\' text-anchor=\\'middle\\' dy=\\'.3em\\' fill=\\'%236c757d\\'>Image not available</text></svg>';">
                    <p class="mt-2 text-muted">${name}</p>
                </div>
            `;
        } else if (type === 'pdf') {
            html = `
                <div style="height:320px;">
                    <iframe src="${url}"
                            width="100%"
                            height="320"
                            style="border:none;border-radius:4px;"
                            onerror="this.style.display='none';this.parentElement.innerHTML='<div class=&quot;alert alert-danger&quot;><i class=&quot;fas fa-exclamation-triangle me-2&quot;></i>Failed to load PDF. Please download the file.</div>'">
                    </iframe>
                    <p class="mt-2 text-muted">${name}</p>
                </div>
            `;
        } else {
            html = `
                <div class="text-center py-5">
                    <i class="fas fa-file fa-4x text-secondary mb-3"></i>
                    <h5>${name}</h5>
                    <p class="text-muted">Preview not available for this file type.</p>
                    <p>Please download the file to view it.</p>
                </div>
            `;
        }

        content.innerHTML = html;

        // Show Bootstrap modal
        try {
            if (window.bootstrap && bootstrap.Modal) {
                new bootstrap.Modal(modal).show();
                console.log('✅ Bootstrap preview modal shown');
            } else {
                console.warn('⚠️ Bootstrap unavailable, opening new tab');
                window.open(url, '_blank');
            }
        } catch (err) {
            console.error('❌ Preview error:', err);
            window.open(url, '_blank');
        }
    }

    // Backward-compatible aliases
    window.openDocumentPreview = openGlobalPreviewModal;
    window.openDocPreview = openGlobalPreviewModal;

    console.log('✅ Global preview handler ready');
})();
</script>

<script>
function openFormPreview(url, name='Document') {
    const overlay = document.getElementById('formPreviewOverlay');
    const modal = document.getElementById('formPreviewModal');
    const frame = document.getElementById('formPreviewFrame');
    const img = document.getElementById('formPreviewImage');
    const title = document.getElementById('formPreviewTitle');

    if (!overlay) return;

    const form = document.querySelector('.candidate-form');
    const baseWidth = form ? form.offsetWidth : window.innerWidth;

    modal.style.width = Math.min(baseWidth * 0.75, 1000) + 'px';
    modal.style.height = '80vh';

    title.textContent = name;

    frame.style.display = img.style.display = 'none';
    frame.src = img.src = '';

    if (/\.pdf$/i.test(url)) {
        frame.src = url;
        frame.style.display = 'block';
    } else {
        img.src = url;
        img.style.display = 'block';
    }

    overlay.hidden = false;
}

function closeFormPreview() {
    document.getElementById('formPreviewOverlay').hidden = true;
}
</script>

<!-- SIMPLE ROUTER PATCH -->
<script>
(function() {
    // Wait a bit for router to load
    setTimeout(function() {
        if (window.Router && window.Router.loadPageContent) {
            const originalLoad = Router.loadPageContent;
            Router.loadPageContent = async function(pageId) {
                const container = document.getElementById("page-content");
                if (!container) return;

                const url = `${window.APP_BASE_URL}/modules/candidate/${pageId}.php?t=${Date.now()}`;
                
                try {
                    const response = await fetch(url, { credentials: "include" });
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    
                    const html = await response.text();
                    container.innerHTML = html;
                    
                    // Initialize the page
                    if (Router.initializePage) {
                        await Router.initializePage(pageId);
                    }
                } catch (error) {
                    console.error("Load error:", error);
                    container.innerHTML = `
                        <div class="container py-4">
                            <div class="alert alert-warning">
                                <h5>${pageId.replace('-', ' ').toUpperCase()} Form</h5>
                                <p>Loading content... (Error: ${error.message})</p>
                            </div>
                        </div>
                    `;
                }
            };
            console.log("✅ Router patched successfully");
        }
    }, 500);
})();
</script>


<script>
document.addEventListener("DOMContentLoaded", function() {
    console.log("📐 Initializing sidebar...");
    
    const sidebar = document.getElementById("mainSidebar");
    const toggleBtn = document.getElementById("sidebarToggle");
    const overlay = document.getElementById("sidebarOverlay");
    
    // Toggle sidebar
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            
            if (window.innerWidth < 768) {
                // Mobile: toggle sidebar-open class
                sidebar.classList.toggle('sidebar-open');
                if (overlay) {
                    overlay.classList.toggle('show');
                }
            } else {
                // Desktop: toggle collapsed class
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            }
        });
    }
    
    // Close sidebar on overlay click (mobile)
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('sidebar-open');
            overlay.classList.remove('show');
        });
    }
    
    // Initialize desktop sidebar state
    if (sidebar && window.innerWidth >= 768) {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
        }
    }
    
    // ✅ FIXED: Sidebar navigation - PROPER EVENT HANDLER
    const sidebarNav = document.querySelector('.sidebar-nav');
    if (sidebarNav) {
        sidebarNav.addEventListener('click', function(e) {
            const item = e.target.closest('.sidebar-item');
            if (!item) return;
            
            const pageId = item.dataset.page;
            if (!pageId) return;
            
            e.preventDefault();
            e.stopPropagation();
            
            // Check if Router is loaded and accessible
            if (window.Router && window.Router.navigateTo) {
                // Use Router's navigation
                Router.navigateTo(pageId);
            } else {
                // Fallback: direct URL navigation
                const base = window.APP_BASE_URL || '';
                window.location.href = `${base}/modules/candidate/${pageId}.php`;
            }
        });
    }
    
    console.log("✅ Sidebar initialized");
});
</script>

<script>
window.Toast = {
    show(message, type = "info", timeout = 3500) {
        let root = document.getElementById("toast-root");
        if (!root) {
            root = document.createElement('div');
            root.id = 'toast-root';
            document.body.appendChild(root);
        }

        const toast = document.createElement("div");
        toast.className = `toast ${type}`;

        const icons = {
            success: "fa-check-circle",
            error: "fa-times-circle",
            info: "fa-info-circle",
            warn: "fa-exclamation-triangle"
        };

        toast.innerHTML = `
            <i class="fas ${icons[type] || icons.info}"></i>
            <div>${message}</div>
            <div class="toast-close">&times;</div>
        `;

        toast.querySelector(".toast-close").onclick = () => toast.remove();

        root.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, timeout);
    },

    success(msg) { this.show(msg, "success"); },
    error(msg)   { this.show(msg, "error"); },
    info(msg)    { this.show(msg, "info"); },
    warn(msg)    { this.show(msg, "warn"); }
};

window.showAlert = function ({ type = 'info', message = '' } = {}) {
    const t = (type || 'info').toLowerCase();
    const m = String(message || '').trim();
    if (!m) return;

    if (window.Toast && typeof window.Toast.show === 'function') {
        const mapped = (t === 'warning') ? 'warn' : t;
        window.Toast.show(m, mapped);
        return;
    }

    console[t === 'error' ? 'error' : 'log'](m);
};
</script>
<style>
    #formPreviewOverlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 20000;
    display: flex;
    align-items: center;
    justify-content: center;
}

#formPreviewModal {
    background: #fff;
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    max-height: 90vh;
}

.form-preview-body iframe,
.form-preview-body img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

</style>
<!-- ===== FORM-WIDTH (75%) PREVIEW OVERLAY ===== -->
<div id="formPreviewOverlay" hidden>
    <div id="formPreviewModal">
        <div class="form-preview-header">
            <span id="formPreviewTitle">Document Preview</span>
            <button type="button" onclick="closeFormPreview()">×</button>
        </div>
        <div class="form-preview-body">
            <iframe id="formPreviewFrame"></iframe>
            <img id="formPreviewImage" />
        </div>
    </div>
</div>

</body>
</html>

</html>

