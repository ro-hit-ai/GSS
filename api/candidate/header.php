<!-- Main Header -->
<header class="bg-white border-bottom shadow-sm py-7">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-10">
                <div class="d-flex align-items-center">
                    <!-- Logo Area -->
                    <div class="d-flex align-items-center">
                        <div class="bg-danger rounded-circle d-flex align-items-center justify-content-center me-3" 
                             style="width: 50px; height: 50px;">
                            <i class="fas fa-shield-alt fa-lg text-white"></i>
                        </div>
                        <div>
                            <h4 class="text-danger mb-0 fw-bold">VATI GSS</h4>
                            <small class="text-muted">Background Verification System</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="d-flex align-items-center justify-content-end gap-3">
                    <!-- User Info -->
                    <div class="text-end d-none d-md-block">
                        <div class="text-muted small">Welcome,</div>
                        <strong class="text-dark"><?php echo htmlspecialchars($userName); ?></strong>
                    </div>
                    
                    <!-- Logout Button -->
                    <div class="dropdown">
                        <button class="btn btn-outline-danger btn-sm dropdown-toggle" type="button" 
                                data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user me-1"></i> Account
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i>Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="#" onclick="logout()">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<script>
function logout() {
    if (confirm('Are you sure you want to logout?')) {
        window.location.href = 'logout.php';
    }
}
</script>