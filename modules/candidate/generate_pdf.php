<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session FIRST
session_start();

// Check if embedded mode (for modal)
$isEmbedded = isset($_GET['embedded']) && $_GET['embedded'] == '1';

// For non-embedded mode, check authentication
if (!$isEmbedded) {
    if (!isset($_SESSION['candidate_id']) && !isset($_GET['bypass'])) {
        http_response_code(401);
        die('Unauthorized access. Please login again.');
    }
}

// Get application ID
$applicationId = $_SESSION['application_id'] ?? $_GET['application_id'] ?? null;
if (!$applicationId || $applicationId == 'N/A') {
    die('Application ID not found or invalid.');
}

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

// Initialize database
$db = getDB();

// ============================================
// FETCH ALL DATA FROM DATABASE
// ============================================

// Helper functions
function safeFetch($stmt) {
    try {
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    } catch (Exception $e) {
        error_log("Fetch error: " . $e->getMessage());
        return null;
    }
}

function safeFetchAll($stmt) {
    try {
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($result) ? $result : [];
    } catch (Exception $e) {
        error_log("FetchAll error: " . $e->getMessage());
        return [];
    }
}

// Fetch all data
$data = [];

// Basic details (from Vati_Payfiller_Candidate_Basic_details table)
try {
    $stmt = $db->prepare("SELECT * FROM Vati_Payfiller_Candidate_Basic_details WHERE application_id = ?");
    if ($stmt->execute([$applicationId])) {
        $data['basic'] = safeFetch($stmt);
    }
    $stmt->closeCursor();
} catch (Exception $e) {
    error_log("Basic details error: " . $e->getMessage());
    $data['basic'] = null;
}

// Contact details (from stored procedure)
try {
    $stmt = $db->prepare("CALL SP_Vati_Payfiller_get_contact_details(?)");
    if ($stmt->execute([$applicationId])) {
        $data['contact'] = safeFetch($stmt);
    }
    $stmt->closeCursor();
} catch (Exception $e) {
    error_log("Contact details error: " . $e->getMessage());
    $data['contact'] = null;
}

// Identification details (from stored procedure)
try {
    $stmt = $db->prepare("CALL SP_Vati_Payfiller_get_identification_details(?)");
    if ($stmt->execute([$applicationId])) {
        $data['identifications'] = safeFetchAll($stmt);
    }
    $stmt->closeCursor();
} catch (Exception $e) {
    error_log("Identification details error: " . $e->getMessage());
    $data['identifications'] = [];
}

// Education details (from stored procedure)
try {
    $stmt = $db->prepare("CALL SP_Vati_Payfiller_get_education_details(?)");
    if ($stmt->execute([$applicationId])) {
        $data['educations'] = safeFetchAll($stmt);
    }
    $stmt->closeCursor();
} catch (Exception $e) {
    error_log("Education details error: " . $e->getMessage());
    $data['educations'] = [];
}

// Employment details (from stored procedure)
try {
    $stmt = $db->prepare("CALL SP_Vati_Payfiller_get_employment_details(?)");
    if ($stmt->execute([$applicationId])) {
        $data['employments'] = safeFetchAll($stmt);
    }
    $stmt->closeCursor();
} catch (Exception $e) {
    error_log("Employment details error: " . $e->getMessage());
    $data['employments'] = [];
}

// Reference details (from Vati_Payfiller_Candidate_Reference_details table)
try {
    $stmt = $db->prepare("SELECT * FROM Vati_Payfiller_Candidate_Reference_details WHERE application_id = ?");
    if ($stmt->execute([$applicationId])) {
        $data['reference'] = safeFetch($stmt);
    }
    $stmt->closeCursor();
} catch (Exception $e) {
    error_log("Reference details error: " . $e->getMessage());
    $data['reference'] = null;
}

// Clear output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set headers
if (!$isEmbedded) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    if (isset($_GET['force_download'])) {
        header('Content-Disposition: attachment; filename="Application_' . $applicationId . '.html"');
    }
} else {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Extract variables for cleaner template
$basic = $data['basic'];
$contact = $data['contact'];
$identifications = $data['identifications'];
$educations = $data['educations'];
$employments = $data['employments'];
$reference = $data['reference'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Background Verification Application - <?php echo htmlspecialchars($applicationId); ?></title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/css/candidate.css')); ?>">

    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="candidate-print">
    <!-- Watermark -->
    <div class="watermark">CONFIDENTIAL</div>
    
    <div class="application-container">
        <!-- Header Section -->
        <div class="header">
            <div class="company-header">
                <div class="company-logo">
                    <div class="logo-placeholder">
                        <i class="fas fa-shield-check"></i>
                    </div>
                    <div class="company-info">
                        <h1>Vati Background Verification</h1>
                        <p class="tagline">Trust & Transparency in Hiring</p>
                    </div>
                </div>
                <div class="status-badge">
                    <i class="fas fa-check-circle me-2"></i> Application Submitted
                </div>
            </div>
            
            <div class="application-meta">
                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Application ID</span>
                        <span class="meta-value"><?php echo htmlspecialchars($applicationId); ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Submission Date</span>
                        <span class="meta-value"><?php echo date('F j, Y'); ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Generated On</span>
                        <span class="meta-value"><?php echo date('M j, Y \a\t g:i A'); ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Document Version</span>
                        <span class="meta-value">1.0</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 1. Basic Details -->
        <div class="section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-user"></i>
                </div>
                <h2 class="section-title">Basic Details</h2>
                <div class="section-number">1</div>
            </div>
            
            <?php if ($basic && is_array($basic)): ?>
                <!-- Personal Photo -->
                <?php 
                if (!empty($basic['photo_path'])) {
                    $photoPath = __DIR__ . '/../../uploads/basic/' . $basic['photo_path'];
                    if (file_exists($photoPath)) {
                        $imageData = base64_encode(file_get_contents($photoPath));
                        echo '<div class="photo-section">';
                        echo '<div class="photo-container">';
                        echo '<img src="data:image/jpeg;base64,' . $imageData . '" alt="Candidate Photo">';
                        echo '<div class="photo-label">Profile Photo</div>';
                        echo '</div>';
                        echo '</div>';
                    }
                }
                ?>
                
                <!-- Personal Details Grid -->
                <div class="data-grid">
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-id-card"></i> First Name
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['first_name'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-id-card"></i> Middle Name
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['middle_name'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-id-card"></i> Last Name
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['last_name'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-birthday-cake"></i> Date of Birth
                        </div>
                        <div class="data-value">
                            <?php 
                            if (!empty($basic['dob']) && $basic['dob'] != '0000-00-00') {
                                echo date('F j, Y', strtotime($basic['dob']));
                            } else {
                                echo 'Not provided';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-venus-mars"></i> Gender
                        </div>
                        <div class="data-value">
                            <?php 
                            $gender = $basic['gender'] ?? '';
                            echo htmlspecialchars($gender ? ucfirst($gender) : 'Not provided'); 
                            ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-tint"></i> Blood Group
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['blood_group'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-user-tie"></i> Father's Name
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['father_name'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-user-tie"></i> Mother's Name
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['mother_name'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-heart"></i> Marital Status
                        </div>
                        <div class="data-value">
                            <?php 
                            $marital = $basic['marital_status'] ?? '';
                            echo htmlspecialchars($marital ? ucfirst($marital) : 'Not provided'); 
                            ?>
                        </div>
                    </div>
                    
                    <?php if (($basic['marital_status'] ?? '') === 'married'): ?>
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-user-friends"></i> Spouse Name
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['spouse_name'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($basic['other_name'])): ?>
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-user-tag"></i> Other Name (Alias)
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['other_name']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-mobile-alt"></i> Mobile Number
                        </div>
                        <div class="data-value">
                            <?php 
                            $mobile = $basic['mobile'] ?? '';
                            if (!empty($mobile)) {
                                $mobileParts = preg_split('/\s+/', trim($mobile), 2);
                                $mobileCode = $mobileParts[0] ?? '+91';
                                $mobileNumber = $mobileParts[1] ?? $mobile;
                                echo htmlspecialchars($mobileCode . ' ' . $mobileNumber);
                            } else {
                                echo 'Not provided';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-envelope"></i> Email Address
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['email'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-phone"></i> Landline
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['landline'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-globe"></i> Country
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['country'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-landmark"></i> State
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['state'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                    
                    <div class="data-item">
                        <div class="data-label">
                            <i class="fas fa-flag"></i> Nationality
                        </div>
                        <div class="data-value">
                            <?php echo htmlspecialchars($basic['nationality'] ?? 'Not provided'); ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2" style="color: var(--warning-color);"></i>
                        <p class="mb-0">Basic details not available</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 2. Contact Information -->
        <div class="section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-address-book"></i>
                </div>
                <h2 class="section-title">Contact Information</h2>
                <div class="section-number">2</div>
            </div>
            
            <?php if ($contact && is_array($contact)): ?>
                <!-- Current Address Card -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-home"></i> Current Address
                        </h3>
                        <span class="badge success">Active</span>
                    </div>
                    <div class="data-grid">
                        <div class="data-item col-span-full">
                            <div class="data-label">
                                <i class="fas fa-map-marker-alt"></i> Address Line 1
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['address1'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item col-span-full">
                            <div class="data-label">
                                <i class="fas fa-map-marker-alt"></i> Address Line 2
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['address2'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-city"></i> City
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['city'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-landmark"></i> State
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['state'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-globe"></i> Country
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['country'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-mail-bulk"></i> Postal Code
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['postal_code'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Permanent Address Card -->
                <?php if (!($contact['same_as_current'] ?? 0)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-building"></i> Permanent Address
                        </h3>
                        <span class="badge">Permanent</span>
                    </div>
                    <div class="data-grid">
                        <div class="data-item col-span-full">
                            <div class="data-label">
                                <i class="fas fa-map-marker-alt"></i> Address Line 1
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['permanent_address1'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item col-span-full">
                            <div class="data-label">
                                <i class="fas fa-map-marker-alt"></i> Address Line 2
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['permanent_address2'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-city"></i> City
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['permanent_city'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-landmark"></i> State
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['permanent_state'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-globe"></i> Country
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['permanent_country'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-mail-bulk"></i> Postal Code
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['permanent_postal_code'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-building"></i> Permanent Address
                        </h3>
                        <span class="badge success">Same as Current Address</span>
                    </div>
                    <div class="text-center py-4">
                        <i class="fas fa-check-circle fa-2x mb-2" style="color: var(--success-color);"></i>
                        <p class="mb-0">Permanent address is same as current address</p>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Address Proof -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-file-contract"></i> Address Proof
                        </h3>
                    </div>
                    <div class="data-grid">
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-file-alt"></i> Proof Type
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['proof_type'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($contact['proof_file'])): ?>
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-paperclip"></i> Proof Document
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($contact['proof_file']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2" style="color: var(--warning-color);"></i>
                        <p class="mb-0">Contact information not available</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 3. Identification Documents -->
        <div class="section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-id-card"></i>
                </div>
                <h2 class="section-title">Identification Details</h2>
                <div class="section-number">3</div>
            </div>
            
            <?php if (!empty($identifications) && is_array($identifications)): ?>
                <?php foreach ($identifications as $index => $id): ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-file-alt"></i> Document <?php echo $index + 1; ?>
                        </h3>
                        <span class="badge"><?php echo htmlspecialchars($id['document_type'] ?? 'Document'); ?></span>
                    </div>
                    
                    <div class="data-grid">
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-file-alt"></i> Document Type
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($id['document_type'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-hashtag"></i> ID Number
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($id['id_number'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-user"></i> Name on Document
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($id['name'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($id['issue_date']) && $id['issue_date'] != '0000-00-00'): ?>
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-calendar-plus"></i> Issue Date
                            </div>
                            <div class="data-value">
                                <?php echo date('F j, Y', strtotime($id['issue_date'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($id['expiry_date']) && $id['expiry_date'] != '0000-00-00'): ?>
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-calendar-minus"></i> Expiry Date
                            </div>
                            <div class="data-value">
                                <?php 
                                echo date('F j, Y', strtotime($id['expiry_date']));
                                $expiry = strtotime($id['expiry_date']);
                                if ($expiry < time()): ?>
                                    <span class="badge warning ms-2">
                                        <i class="fas fa-exclamation-triangle me-1"></i> Expired
                                    </span>
                                <?php else: ?>
                                    <span class="badge success ms-2">
                                        <i class="fas fa-check-circle me-1"></i> Valid
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($id['upload_document'])): ?>
                    <div class="mt-3">
                        <span class="badge success">
                            <i class="fas fa-paperclip me-1"></i> Document uploaded
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="card">
                    <div class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2" style="color: var(--warning-color);"></i>
                        <p class="mb-0">No identification documents found</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
<!-- 4. Education Details -->
<div class="section">
    <div class="section-header">
        <div class="section-icon">
            <i class="fas fa-graduation-cap"></i>
        </div>
        <h2 class="section-title">Education Details</h2>
        <div class="section-number">4</div>
    </div>
    
    <?php if (!empty($educations) && is_array($educations)): ?>
        <?php foreach ($educations as $index => $edu): ?>
        <div class="card">
            <div class="education-header">
                <div class="education-title">
                    <?php echo htmlspecialchars($edu['qualification'] ?? 'Qualification'); ?>
                </div>
                <div class="education-subtitle">
                    Education <?php echo $index + 1; ?>
                </div>
            </div>
            
            <div class="card-body">
                <!-- Table for education details -->
                <table class="education-table">
                    <thead>
                        <tr>
                            <th>Qualification</th>
                            <th>College / Institution</th>
                            <th>University / Board</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td data-label="Qualification">
                                <?php echo htmlspecialchars($edu['qualification'] ?? 'Not provided'); ?>
                            </td>
                            <td data-label="College / Institution">
                                <?php echo htmlspecialchars($edu['college_name'] ?? 'Not provided'); ?>
                            </td>
                            <td data-label="University / Board">
                                <?php echo htmlspecialchars($edu['university_board'] ?? 'Not provided'); ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Roll / Register Number</th>
                            <th>From Year</th>
                            <th>To Year</th>
                        </tr>
                        <tr>
                            <td data-label="Roll / Register Number">
                                <?php echo htmlspecialchars($edu['roll_number'] ?? 'Not provided'); ?>
                            </td>
                            <td data-label="From Year">
                                <?php 
                                if (!empty($edu['year_from'])) {
                                    echo date('F, Y', strtotime($edu['year_from'] . '-01'));
                                } else {
                                    echo 'Not provided';
                                }
                                ?>
                            </td>
                            <td data-label="To Year">
                                <?php 
                                if (!empty($edu['year_to'])) {
                                    echo date('F, Y', strtotime($edu['year_to'] . '-01'));
                                } else {
                                    echo 'Not provided';
                                }
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- College Address -->
                <div class="college-address">
                    <div class="address-label">
                        <i class="fas fa-map-marker-alt"></i> College Address
                    </div>
                    <div class="address-content">
                        <?php echo nl2br(htmlspecialchars($edu['college_address'] ?? 'Not provided')); ?>
                    </div>
                </div>
                
                <!-- College Website -->
                <?php if (!empty($edu['college_website'])): ?>
                <div class="mb-3">
                    <div class="address-label mb-2">
                        <i class="fas fa-globe"></i> College Website
                    </div>
                    <a href="<?php echo htmlspecialchars($edu['college_website']); ?>" 
                       class="college-website" target="_blank">
                        <i class="fas fa-external-link-alt"></i>
                        <?php echo htmlspecialchars($edu['college_website']); ?>
                    </a>
                </div>
                <?php endif; ?>
                
                <!-- Document Status -->
                <?php if (!empty($edu['marksheet_file']) || !empty($edu['degree_file'])): ?>
                <div class="mt-3">
                    <span class="badge success">
                        <i class="fas fa-check-circle me-1"></i> Supporting documents uploaded
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="card">
            <div class="text-center py-4">
                <i class="fas fa-exclamation-triangle fa-2x mb-2" style="color: var(--warning-color);"></i>
                <p class="mb-0">No education details found</p>
            </div>
        </div>
    <?php endif; ?>
</div>
        
        <!-- 5. Employment History -->
        <div class="section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-briefcase"></i>
                </div>
                <h2 class="section-title">Employment Details</h2>
                <div class="section-number">5</div>
            </div>
            
            <?php if (!empty($employments) && is_array($employments)): ?>
                <?php 
                $isFresher = false;
                if (!empty($employments[0]['is_fresher']) && $employments[0]['is_fresher'] === 'yes') {
                    $isFresher = true;
                }
                
                if ($isFresher): ?>
                    <div class="card">
                        <div class="text-center py-5">
                            <div class="mb-3">
                                <i class="fas fa-user-graduate fa-3x" style="color: var(--accent-color);"></i>
                            </div>
                            <h3 class="mb-2">Fresh Graduate</h3>
                            <p class="text-light mb-0">Candidate has no prior work experience</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($employments as $index => $emp): ?>
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-building"></i> <?php echo htmlspecialchars($emp['employer_name'] ?? 'Employer'); ?>
                            </h3>
                            <div class="d-flex align-center gap-2">
                                <span class="card-badge">Employer <?php echo $index + 1; ?></span>
                                <?php if (!empty($emp['currently_employed']) && $emp['currently_employed'] === 'yes'): ?>
                                    <span class="badge success">
                                        <i class="fas fa-briefcase me-1"></i> Current
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($index === 0): ?>
                        <div class="mb-3">
                            <div class="data-grid">
                                <div class="data-item">
                                    <div class="data-label">
                                        <i class="fas fa-user-graduate"></i> Fresher Status
                                    </div>
                                    <div class="data-value">
                                        <?php echo ucfirst($emp['is_fresher'] ?? 'no'); ?>
                                    </div>
                                </div>
                                
                                <div class="data-item">
                                    <div class="data-label">
                                        <i class="fas fa-briefcase"></i> Currently Employed
                                    </div>
                                    <div class="data-value">
                                        <?php echo ucfirst($emp['currently_employed'] ?? 'no'); ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($emp['currently_employed']) && $emp['currently_employed'] === 'yes'): ?>
                                <div class="data-item">
                                    <div class="data-label">
                                        <i class="fas fa-phone"></i> Contact Employer
                                    </div>
                                    <div class="data-value">
                                        <?php echo ucfirst($emp['contact_employer'] ?? 'no'); ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="data-grid">
                            <div class="data-item">
                                <div class="data-label">
                                    <i class="fas fa-building"></i> Employer Name
                                </div>
                                <div class="data-value">
                                    <?php echo htmlspecialchars($emp['employer_name'] ?? 'Not provided'); ?>
                                </div>
                            </div>
                            
                            <div class="data-item">
                                <div class="data-label">
                                    <i class="fas fa-user-tie"></i> Job Title
                                </div>
                                <div class="data-value">
                                    <?php echo htmlspecialchars($emp['job_title'] ?? 'Not provided'); ?>
                                </div>
                            </div>
                            
                            <div class="data-item">
                                <div class="data-label">
                                    <i class="fas fa-id-badge"></i> Employee ID
                                </div>
                                <div class="data-value">
                                    <?php echo htmlspecialchars($emp['employee_id'] ?? 'Not provided'); ?>
                                </div>
                            </div>
                            
                            <div class="data-item">
                                <div class="data-label">
                                    <i class="fas fa-calendar-plus"></i> Joining Date
                                </div>
                                <div class="data-value">
                                    <?php 
                                    if (!empty($emp['joining_date']) && $emp['joining_date'] != '0000-00-00') {
                                        echo date('F j, Y', strtotime($emp['joining_date']));
                                    } else {
                                        echo 'Not provided';
                                    }
                                    ?>
                                </div>
                            </div>
                            
                            <div class="data-item">
                                <div class="data-label">
                                    <i class="fas fa-calendar-minus"></i> Relieving Date
                                </div>
                                <div class="data-value">
                                    <?php 
                                    if (!empty($emp['relieving_date']) && $emp['relieving_date'] != '0000-00-00') {
                                        echo date('F j, Y', strtotime($emp['relieving_date']));
                                    } else {
                                        echo 'Not provided';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <div class="data-label mb-1">
                                <i class="fas fa-map-marked-alt"></i> Employer Address
                            </div>
                            <div class="data-value">
                                <?php echo nl2br(htmlspecialchars($emp['employer_address'] ?? 'Not provided')); ?>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <div class="data-label mb-1">
                                <i class="fas fa-sign-out-alt"></i> Reason for Leaving
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($emp['reason_leaving'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <!-- HR Details -->
                        <div class="mt-3">
                            <div class="data-label mb-2">
                                <i class="fas fa-users"></i> HR Details
                            </div>
                            <div class="data-grid">
                                <div class="data-item">
                                    <div class="data-label">HR Manager</div>
                                    <div class="data-value"><?php echo htmlspecialchars($emp['hr_manager_name'] ?? 'Not provided'); ?></div>
                                </div>
                                <div class="data-item">
                                    <div class="data-label">HR Phone</div>
                                    <div class="data-value"><?php echo htmlspecialchars($emp['hr_manager_phone'] ?? 'Not provided'); ?></div>
                                </div>
                                <div class="data-item">
                                    <div class="data-label">HR Email</div>
                                    <div class="data-value"><?php echo htmlspecialchars($emp['hr_manager_email'] ?? 'Not provided'); ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Reporting Manager -->
                        <div class="mt-3">
                            <div class="data-label mb-2">
                                <i class="fas fa-user-tie"></i> Reporting Manager
                            </div>
                            <div class="data-grid">
                                <div class="data-item">
                                    <div class="data-label">Manager Name</div>
                                    <div class="data-value"><?php echo htmlspecialchars($emp['manager_name'] ?? 'Not provided'); ?></div>
                                </div>
                                <div class="data-item">
                                    <div class="data-label">Manager Phone</div>
                                    <div class="data-value"><?php echo htmlspecialchars($emp['manager_phone'] ?? 'Not provided'); ?></div>
                                </div>
                                <div class="data-item">
                                    <div class="data-label">Manager Email</div>
                                    <div class="data-value"><?php echo htmlspecialchars($emp['manager_email'] ?? 'Not provided'); ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($emp['employment_doc'])): ?>
                        <div class="mt-3">
                            <span class="badge success">
                                <i class="fas fa-file-contract me-1"></i> Employment proof documents uploaded
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php else: ?>
                <div class="card">
                    <div class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2" style="color: var(--warning-color);"></i>
                        <p class="mb-0">No employment details found</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 6. Reference Details -->
        <div class="section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-user-friends"></i>
                </div>
                <h2 class="section-title">Reference Details</h2>
                <div class="section-number">6</div>
            </div>
            
            <?php if ($reference && is_array($reference)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-user"></i> Professional Reference
                        </h3>
                        <span class="badge">Reference</span>
                    </div>
                    
                    <div class="data-grid">
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-user-circle"></i> Reference Name
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($reference['reference_name'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-briefcase"></i> Designation
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($reference['reference_designation'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-building"></i> Company
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($reference['reference_company'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-mobile-alt"></i> Mobile
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($reference['reference_mobile'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-envelope"></i> Email
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($reference['reference_email'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-handshake"></i> Relationship
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($reference['relationship'] ?? 'Not provided'); ?>
                            </div>
                        </div>
                        
                        <div class="data-item">
                            <div class="data-label">
                                <i class="fas fa-calendar-alt"></i> Years Known
                            </div>
                            <div class="data-value">
                                <?php echo htmlspecialchars($reference['years_known'] ?? 'Not provided'); ?> years
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2" style="color: var(--warning-color);"></i>
                        <p class="mb-0">No reference details found</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
<!-- Action Buttons Section -->
<div class="action-buttons no-print">
    <h3><i class="fas fa-download me-2"></i>Export Options</h3>
    <div class="action-btn-group">
        <button type="button" class="action-btn print" onclick="window.print()">
            <i class="fas fa-print"></i> Print Document
        </button>
        <!-- <button type="button" class="action-btn download" onclick="downloadAsHTML()">
            <i class="fas fa-download"></i> Download as HTML
        </button> -->
    </div>
    <p class="text-muted mt-3 mb-0" style="font-size: 12px;">
        <i class="fas fa-info-circle me-1"></i>
        For best print results, use "Save as PDF" in your browser's print dialog
    </p>
</div>

<!-- Footer -->
<div class="footer">
    <div class="footer-links mb-2">
        <span><i class="fas fa-lock me-1"></i> Confidential</span>
        <span><i class="fas fa-calendar-alt me-1"></i> Generated: <?php echo date('M j, Y'); ?></span>
        <span><i class="fas fa-id-card me-1"></i> ID: <?php echo htmlspecialchars($applicationId); ?></span>
    </div>
    <p class="mb-0">
        &copy; <?php echo date('Y'); ?> Vati Background Verification System
    </p>
</div>
    </div>
    
    <script>
    function downloadAsHTML() {
        const htmlContent = document.documentElement.outerHTML;
        const blob = new Blob([htmlContent], { type: 'text/html' });
        const url = URL.createObjectURL(blob);
        
        const a = document.createElement('a');
        a.href = url;
        a.download = 'BGV_Application_<?php echo $applicationId; ?>_<?php echo date('Y-m-d'); ?>.html';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        <?php if ($isEmbedded): ?>
        if (window.parent && window.parent.PdfViewer) {
            window.parent.PdfViewer.close();
        }
        <?php endif; ?>
    }
    
    // Auto-resize iframe for embedded mode
    <?php if ($isEmbedded): ?>
    function resizeIframe() {
        const height = document.documentElement.scrollHeight;
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: 'iframeResize',
                height: height + 50,
                source: 'bgv_application'
            }, '*');
        }
    }
    
    window.addEventListener('load', function() {
        resizeIframe();
        setTimeout(resizeIframe, 500);
    });
    
    window.addEventListener('resize', resizeIframe);
    
    // Listen for print command from parent
    window.addEventListener('message', function(event) {
        if (event.data === 'printIframe') {
            setTimeout(() => window.print(), 500);
        }
    });
    <?php endif; ?>
    
    // Auto-print if requested
    if (window.location.search.includes('print=1')) {
        setTimeout(() => {
            window.print();
        }, 1000);
    }
    
    // Auto-close after printing
    window.onafterprint = function() {
        if (window.location.search.includes('autoclose=1')) {
            setTimeout(() => {
                window.close();
            }, 500);
        }
    };
    </script>
</body>
</html>
