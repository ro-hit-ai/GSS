<?php
// dashboard.php - Dashboard content for Candidate Portal
// Save in: C:\xampp\htdocs\GSS\modules\candidate\pages\dashboard.php

// REMOVE the AJAX check - Router fetches via simple fetch()
// REMOVE session_start() - Let portal.php handle sessions

// Dashboard content starts here - NO HTML, HEAD, or BODY tags
?>

<!-- DASHBOARD CONTENT -->
<div class="candidate-dashboard">
<div class="container-fluid">
    <!-- Welcome Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="mb-2 text-primary"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h4>
                            <p class="text-muted mb-0">
                                Complete your background verification application step by step.
                                Your progress is automatically saved.
                            </p>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="d-inline-block bg-primary text-white rounded-pill px-4 py-2">
                                <div class="small">Application ID</div>
                                <div class="fw-bold">APP-<?php echo date('YmdHis'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <h5 class="mb-3"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
            <div class="row g-3">
                <div class="col-md-3 col-6">
                    <div class="card h-100 border-0 shadow-sm hover-shadow text-center" 
                         onclick="window.Router.navigateTo('basic-details')"
                         style="cursor: pointer; transition: all 0.3s;">
                        <div class="card-body p-3">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-2" 
                                 style="width: 50px; height: 50px;">
                                <i class="fas fa-user fa-lg"></i>
                            </div>
                            <h6 class="mb-1">Basic Details</h6>
                            <small class="text-muted">Personal Information</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card h-100 border-0 shadow-sm hover-shadow text-center"
                         onclick="window.Router.navigateTo('identification')"
                         style="cursor: pointer; transition: all 0.3s;">
                        <div class="card-body p-3">
                            <div class="bg-warning bg-opacity-10 text-warning rounded-circle d-inline-flex align-items-center justify-content-center mb-2" 
                                 style="width: 50px; height: 50px;">
                                <i class="fas fa-id-card fa-lg"></i>
                            </div>
                            <h6 class="mb-1">Identification</h6>
                            <small class="text-muted">ID Documents</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card h-100 border-0 shadow-sm hover-shadow text-center"
                         onclick="window.Router.navigateTo('contact')"
                         style="cursor: pointer; transition: all 0.3s;">
                        <div class="card-body p-3">
                            <div class="bg-info bg-opacity-10 text-info rounded-circle d-inline-flex align-items-center justify-content-center mb-2" 
                                 style="width: 50px; height: 50px;">
                                <i class="fas fa-address-book fa-lg"></i>
                            </div>
                            <h6 class="mb-1">Contact Info</h6>
                            <small class="text-muted">Address & Contact</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card h-100 border-0 shadow-sm hover-shadow text-center"
                         onclick="window.Router.navigateTo('education')"
                         style="cursor: pointer; transition: all 0.3s;">
                        <div class="card-body p-3">
                            <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-2" 
                                 style="width: 50px; height: 50px;">
                                <i class="fas fa-graduation-cap fa-lg"></i>
                            </div>
                            <h6 class="mb-1">Education</h6>
                            <small class="text-muted">Academic Details</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Progress Overview -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h5 class="mb-3"><i class="fas fa-chart-line me-2"></i>Application Progress</h5>
                    <div class="mb-4">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Overall Completion</span>
                            <span id="overallProgress">0%</span>
                        </div>
                        <div class="progress" style="height: 12px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 id="overallProgressBar" 
                                 role="progressbar" 
                                 style="width: 0%">
                            </div>
                        </div>
                    </div>

                    <!-- Step Progress -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                                     style="width: 30px; height: 30px; font-size: 12px;">1</div>
                                <span>Basic Details</span>
                                <div class="ms-auto">
                                    <span class="badge bg-secondary" id="step1Status">Pending</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                                     style="width: 30px; height: 30px; font-size: 12px;">2</div>
                                <span>Identification</span>
                                <div class="ms-auto">
                                    <span class="badge bg-secondary" id="step2Status">Pending</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                                     style="width: 30px; height: 30px; font-size: 12px;">3</div>
                                <span>Contact Info</span>
                                <div class="ms-auto">
                                    <span class="badge bg-secondary" id="step3Status">Pending</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                                     style="width: 30px; height: 30px; font-size: 12px;">4</div>
                                <span>Education</span>
                                <div class="ms-auto">
                                    <span class="badge bg-secondary" id="step4Status">Pending</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                                     style="width: 30px; height: 30px; font-size: 12px;">5</div>
                                <span>Employment</span>
                                <div class="ms-auto">
                                    <span class="badge bg-secondary" id="step5Status">Pending</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                                     style="width: 30px; height: 30px; font-size: 12px;">6</div>
                                <span>References</span>
                                <div class="ms-auto">
                                    <span class="badge bg-secondary" id="step6Status">Pending</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <button class="btn btn-primary" onclick="window.Router.navigateTo('basic-details')">
                            <i class="fas fa-play me-2"></i>Start Application
                        </button>
                        <button class="btn btn-outline-secondary ms-2" onclick="checkProgress()">
                            <i class="fas fa-sync me-2"></i>Refresh Progress
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h5 class="mb-3"><i class="fas fa-chart-pie me-2"></i>Quick Stats</h5>
                    <div class="mb-3">
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-warning bg-opacity-10 rounded-circle p-2 me-3">
                                <i class="fas fa-clock text-warning"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="text-muted">Time Spent</small>
                                <div class="fw-bold" id="timeSpent">0 min</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-success bg-opacity-10 rounded-circle p-2 me-3">
                                <i class="fas fa-save text-success"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="text-muted">Last Saved</small>
                                <div class="fw-bold" id="lastSaved">Just now</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-primary bg-opacity-10 rounded-circle p-2 me-3">
                                <i class="fas fa-edit text-primary"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="text-muted">Edits Made</small>
                                <div class="fw-bold" id="editsCount">0</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
                            <div class="bg-info bg-opacity-10 rounded-circle p-2 me-3">
                                <i class="fas fa-check-circle text-info"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="text-muted">Sections Completed</small>
                                <div class="fw-bold" id="completedSections">0/6</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-3 border-top">
                        <div class="d-grid gap-2">
                            <button class="btn btn-outline-primary" onclick="window.Router.navigateTo('basic-details')">
                                <i class="fas fa-forward me-2"></i>Continue Application
                            </button>
                            <button class="btn btn-outline-success" onclick="saveAllProgress()">
                                <i class="fas fa-cloud-upload-alt me-2"></i>Save All Progress
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Activity -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="mb-3"><i class="fas fa-history me-2"></i>Recent Activity</h5>
                    <div class="list-group list-group-flush">
                        <div class="list-group-item border-0 px-0">
                            <div class="d-flex align-items-center">
                                <div class="bg-primary bg-opacity-10 rounded-circle p-2 me-3">
                                    <i class="fas fa-user-check text-primary"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="mb-1">Account Created</h6>
                                        <small class="text-muted">Just now</small>
                                    </div>
                                    <p class="mb-0 small text-muted">Your candidate portal account has been created</p>
                                </div>
                            </div>
                        </div>
                        <div class="list-group-item border-0 px-0">
                            <div class="d-flex align-items-center">
                                <div class="bg-info bg-opacity-10 rounded-circle p-2 me-3">
                                    <i class="fas fa-info-circle text-info"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="mb-1">Welcome</h6>
                                        <small class="text-muted">Just now</small>
                                    </div>
                                    <p class="mb-0 small text-muted">Welcome to ATTEST360 Background Verification Portal</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
</div>

<script>
// Dashboard-specific JavaScript
console.log('📊 Dashboard content loaded');

// Initialize dashboard
function initDashboard() {
    console.log('Initializing dashboard...');
    updateDashboardProgress();
    updateLastSavedTime();
    updateEditsCount();
    updateTimeSpent();
    updateCompletedSections();
    
    // Auto-refresh progress every 10 seconds
    setInterval(updateDashboardProgress, 10000);
}

function updateDashboardProgress() {
    const pages = ['basic-details', 'identification', 'contact', 'education', 'employment', 'reference'];
    let completed = 0;
    
    for (let i = 0; i < pages.length; i++) {
        const page = pages[i];
        if (localStorage.getItem(`draft-${page}`)) {
            completed++;
            
            // Update step status
            const stepElement = document.getElementById(`step${i + 1}Status`);
            if (stepElement) {
                stepElement.className = 'badge bg-success';
                stepElement.textContent = 'Completed';
            }
        } else {
            // Reset step status
            const stepElement = document.getElementById(`step${i + 1}Status`);
            if (stepElement) {
                stepElement.className = 'badge bg-secondary';
                stepElement.textContent = 'Pending';
            }
        }
    }
    
    const progress = Math.round((completed / pages.length) * 100);
    
    // Update overall progress
    const overallProgress = document.getElementById('overallProgress');
    const overallProgressBar = document.getElementById('overallProgressBar');
    
    if (overallProgress) {
        overallProgress.textContent = `${progress}%`;
    }
    
    if (overallProgressBar) {
        overallProgressBar.style.width = `${progress}%`;
        overallProgressBar.setAttribute('aria-valuenow', progress);
        
        // Update color based on progress
        if (progress < 30) {
            overallProgressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-danger';
        } else if (progress < 70) {
            overallProgressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-warning';
        } else {
            overallProgressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-success';
        }
    }
}

function updateLastSavedTime() {
    const lastSaved = localStorage.getItem('last-saved-time');
    const element = document.getElementById('lastSaved');
    
    if (element) {
        if (lastSaved) {
            const timeDiff = Date.now() - parseInt(lastSaved);
            const minutes = Math.floor(timeDiff / (1000 * 60));
            
            if (minutes < 1) {
                element.textContent = 'Just now';
            } else if (minutes < 60) {
                element.textContent = `${minutes} min ago`;
            } else {
                const hours = Math.floor(minutes / 60);
                element.textContent = `${hours} hour${hours > 1 ? 's' : ''} ago`;
            }
        } else {
            element.textContent = 'Never saved';
        }
    }
}

function updateEditsCount() {
    let totalEdits = 0;
    const pages = ['basic-details', 'identification', 'contact', 'education', 'employment', 'reference'];
    
    for (const page of pages) {
        const edits = localStorage.getItem(`edits-${page}`);
        if (edits) {
            totalEdits += parseInt(edits);
        }
    }
    
    const element = document.getElementById('editsCount');
    if (element) {
        element.textContent = totalEdits;
    }
}

function updateTimeSpent() {
    let totalTime = 0;
    const pages = ['basic-details', 'identification', 'contact', 'education', 'employment', 'reference'];
    
    for (const page of pages) {
        const time = localStorage.getItem(`time-${page}`);
        if (time) {
            totalTime += parseInt(time);
        }
    }
    
    const element = document.getElementById('timeSpent');
    if (element) {
        if (totalTime < 60) {
            element.textContent = `${totalTime} min`;
        } else {
            const hours = Math.floor(totalTime / 60);
            const minutes = totalTime % 60;
            element.textContent = `${hours}h ${minutes}m`;
        }
    }
}

function updateCompletedSections() {
    let completed = 0;
    const pages = ['basic-details', 'identification', 'contact', 'education', 'employment', 'reference'];
    
    for (const page of pages) {
        if (localStorage.getItem(`draft-${page}`)) {
            completed++;
        }
    }
    
    const element = document.getElementById('completedSections');
    if (element) {
        element.textContent = `${completed}/${pages.length}`;
    }
}

function checkProgress() {
    updateDashboardProgress();
    updateLastSavedTime();
    updateEditsCount();
    updateTimeSpent();
    updateCompletedSections();
    
    if (typeof window.App !== 'undefined' && window.App.showToast) {
        window.App.showToast('Progress updated successfully!', 'success');
    }
}

function saveAllProgress() {
    // Save timestamp
    localStorage.setItem('last-saved-time', Date.now().toString());
    
    // Update display
    updateLastSavedTime();
    
    // Show success message
    if (typeof window.App !== 'undefined' && window.App.showToast) {
        window.App.showToast('All progress saved successfully!', 'success');
    } else {
        alert('Progress saved successfully!');
    }
}

// Initialize dashboard when content loads
document.addEventListener('DOMContentLoaded', initDashboard);

// Also initialize if DOM is already loaded
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(initDashboard, 100);
}
</script>
