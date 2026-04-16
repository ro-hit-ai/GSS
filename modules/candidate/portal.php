<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ATTEST360 - Background Verification</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/candidate.css" rel="stylesheet">
</head>
<body class="candidate-portal">
    <div class="container-fluid">
        <div class="row flex-nowrap">
            <!-- Sidebar -->
            <div class="col-lg-3 col-xl-2 bg-light border-end sidebar-sticky">
                <div class="pt-3">
                    <div class="text-center mb-4">
                        <h4 class="text-danger">ATTEST360</h4>
                        <p class="text-muted small">Background Verification</p>
                    </div>
                    <div id="sidebar-container">
                        <!-- Sidebar Links with data-page (no onclick) -->
                        <div class="list-group list-group-flush">
                            <a href="#" data-page="basic-details" class="list-group-item list-group-item-action border-0 active">
                                <i class="fas fa-user me-2"></i>Basic Details
                            </a>
                            <a href="#" data-page="identification" class="list-group-item list-group-item-action border-0">
                                <i class="fas fa-id-card me-2"></i>Identification
                            </a>
                            <a href="#" data-page="contact" class="list-group-item list-group-item-action border-0">
                                <i class="fas fa-address-book me-2"></i>Contact Info
                            </a>
                            <a href="#" data-page="education" class="list-group-item list-group-item-action border-0">
                                <i class="fas fa-graduation-cap me-2"></i>Education
                            </a>
                            <a href="#" data-page="employment" class="list-group-item list-group-item-action border-0">
                                <i class="fas fa-briefcase me-2"></i>Employment
                            </a>
                            <a href="#" data-page="reference" class="list-group-item list-group-item-action border-0">
                                <i class="fas fa-users me-2"></i>Reference
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-9 col-xl-10">
                <div class="container-fluid py-4">
                    <!-- Header -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <nav aria-label="breadcrumb">
                                <ol class="breadcrumb">
                                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                                    <li class="breadcrumb-item active">Basic Details</li>
                                </ol>
                            </nav>
                        </div>
                    </div>

                    <!-- Page Content -->
                    <div class="row">
                        <div class="col-12">
                            <div id="page-content">
                                <div class="text-center py-5">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-3 text-muted">Loading Application...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JS -->
    <script src="assets/js/router.js"></script>
    <script src="assets/js/forms.js"></script>
    <script src="assets/js/app.js"></script>
    
    <script>
    // FIXED: Centralized Sidebar Clicks + Progress
    document.addEventListener('DOMContentLoaded', () => {
        // Sidebar clicks
        document.querySelectorAll('[data-page]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = link.getAttribute('data-page');
                Router.navigateTo(page);
                setActiveNav(page);
                updateProgress(progressMap[page] || 0);
                if (window.innerWidth <= 768 && sidebarManager) {
                    sidebarManager.collapse();
                }
            });
        });

        // Initial state
        setActiveNav('basic-details');
        updateProgress(15);
        Router.navigateTo('basic-details');
    });

    const progressMap = {
        'basic-details': 15, 'identification': 30, 'contact': 45,
        'education': 60, 'employment': 80, 'reference': 95
    };

    function setActiveNav(page) {
        document.querySelectorAll('.list-group-item').forEach(item => {
            item.classList.remove('active');
        });
        document.querySelector(`[data-page="${page}"]`)?.classList.add('active');
    }

    function updateProgress(percent) {
        const bar = document.getElementById('sidebarProgress');
        const text = document.getElementById('sidebarProgressText');
        if (bar) bar.style.width = percent + '%';
        if (text) text.textContent = percent + '% Complete';
    }
    </script>
</body>
</html>
