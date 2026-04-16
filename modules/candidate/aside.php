<?php
?>
<aside class="sidebar" id="mainSidebar">
    <nav class="sidebar-nav">

        <a href="#" data-page="basic-details" class="sidebar-item">
            <i class="fas fa-user"></i>
            <span class="sidebar-label">Basic Details</span>
            <span class="sidebar-status"></span>
        </a>

        <a href="#" data-page="identification" class="sidebar-item">
            <i class="fas fa-id-card"></i>
            <span class="sidebar-label">Identification</span>
            <span class="sidebar-status"></span>
        </a>

        <a href="#" data-page="contact" class="sidebar-item">
            <i class="fas fa-address-book"></i>
            <span class="sidebar-label">Address</span>
            <span class="sidebar-status"></span>
        </a>

                <a href="#" data-page="social" class="sidebar-item">
            <i class="fas fa-users"></i>
            <span class="sidebar-label">Social Media</span>
            <span class="sidebar-status"></span>
        </a>

         <a href="#" data-page="ecourt" class="sidebar-item">
            <i class="fas fa-gavel"></i>
            <span class="sidebar-label">E-Court</span>
            <span class="sidebar-status"></span>
        </a>


        <a href="#" data-page="education" class="sidebar-item">
            <i class="fas fa-graduation-cap"></i>
            <span class="sidebar-label">Education</span>
            <span class="sidebar-status"></span>
        </a>

        <a href="#" data-page="employment" class="sidebar-item">
            <i class="fas fa-briefcase"></i>
            <span class="sidebar-label">Employment</span>
            <span class="sidebar-status"></span>
        </a>

        <a href="#" data-page="reference" class="sidebar-item">
            <i class="fas fa-users"></i>
            <span class="sidebar-label">Reference</span>
            <span class="sidebar-status"></span>
</a>

    </nav>
</aside>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<script>
document.addEventListener("DOMContentLoaded", () => {

    const sidebar = document.getElementById("mainSidebar");
    const toggleBtn = document.getElementById("sidebarToggle");
    const overlay = document.getElementById("sidebarOverlay");

    /* ===============================
       SIDEBAR TOGGLE (MOBILE)
    =============================== */
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener("click", () => {
            sidebar.classList.toggle("open");
            overlay.classList.toggle("show");
        });
    }

    /* Close sidebar when overlay is clicked */
    overlay?.addEventListener("click", () => {
        sidebar.classList.remove("open");
        overlay.classList.remove("show");
    });

    document.querySelectorAll(".sidebar-item").forEach(item => {

        item.addEventListener("click", (e) => {
            e.preventDefault();

            const pageId = item.dataset.page;
            if (!pageId || !window.Router) return;

            if (!Router.isPageAccessible(pageId)) {
                if (typeof Router.showNotification === 'function') {
                    Router.showNotification('Please complete the current step before proceeding.', 'warning');
                    return;
                }
                if (window.Toast && typeof window.Toast.warn === 'function') {
                    window.Toast.warn('Please complete the current step before proceeding.');
                    return;
                }
                console.warn('Please complete the current step before proceeding.');
                return;
            }
            Router.navigateTo(pageId);

            if (window.innerWidth <= 768) {
                sidebar.classList.remove("open");
                overlay.classList.remove("show");
            }
        });

    });

});
</script>
