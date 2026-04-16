<?php
session_start();
require_once __DIR__ . '/../../config/env.php';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();

    header('Location: ' . app_url('/modules/candidate/login.php'));
    exit;
}

$applicationId = $_SESSION['application_id'] ?? 'N/A';
?>

<div class="candidate-success">
<div class="success-wrapper">

    <div class="success-card">

        <!-- Big Success Icon -->
        <div class="success-icon-circle">
            <i class="fas fa-check-circle"></i>
        </div>

        <h1 class="success-title">Application Submitted Successfully</h1>

        <p class="success-subtitle">
            Thank you for completing your background verification.  
            Our team will now begin the review and verification process.
        </p>

        <!-- Reference Number box -->
        <div class="reference-box">
            <h5 class="mb-1"><i class="fas fa-file-alt me-2"></i>Your Reference Number</h5>
            <p class="mb-0 fw-bold" style="font-size:20px;" id="applicationIdDisplay">
                <?= htmlspecialchars($applicationId) ?>
            </p>
        </div>
<!-- <div class="action-buttons">
    <button id="downloadApplicationBtn" class="btn download-btn">
        <i class="fas fa-eye me-2"></i> View Application
    </button>
    
    <!-- <button class="btn print-btn">
        <i class="fas fa-print me-2"></i> Print Application
    </button> -->
<!-- </div> --> 

        <!-- Logout -->
        <a href="<?= htmlspecialchars(app_url('/modules/candidate/success.php?action=logout')) ?>" class="btn btn-outline-danger logout-btn">
            <i class="fas fa-sign-out-alt me-2"></i> Logout
        </a>

    </div>
</div>
 </div>
<!-- Update the action buttons section in success.php -->


<!-- Update the script section -->
<script>
// Set global base URL
window.APP_BASE_URL = <?= json_encode(app_base_url()) ?>;

// Load dependencies first, then Success.js
(function() {
    // Load Bootstrap Modal if not already loaded
    if (typeof bootstrap === 'undefined') {
        const bsScript = document.createElement('script');
        bsScript.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js';
        document.head.appendChild(bsScript);
    }
    
    // Load Success and PdfViewer modules
    function loadScript(src, callback) {
        const script = document.createElement('script');
        script.src = src;
        script.async = false;
        script.onload = callback;
        document.head.appendChild(script);
    }
    
    // Load modules in order
    loadScript(`${window.APP_BASE_URL}/js/modules/candidate/pages/PdfViewer.js?v=<?= time() ?>`, function() {
        loadScript(`${window.APP_BASE_URL}/js/modules/candidate/pages/Success.js?v=<?= time() ?>`, function() {
            // Initialize when both are loaded
            if (window.Success && typeof window.Success.init === 'function') {
                window.Success.init();
            }
        });
    });
})();

// Mobile-specific adjustments
if (window.innerWidth <= 768) {
    document.addEventListener('DOMContentLoaded', function() {
        const successCard = document.querySelector('.success-card');
        if (successCard) {
            successCard.style.margin = '10px';
        }
    });
}
</script>
