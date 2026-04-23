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

function normalizeStoredFilePath($path) {
    $raw = trim((string)$path);
    if ($raw === '' || strtoupper($raw) === 'INSUFFICIENT_DOCUMENTS') {
        return '';
    }
    return str_replace('\\', '/', $raw);
}

function inferUploadFolder($sourceField, $component) {
    $field = strtolower(trim((string)$sourceField));
    $comp = strtolower(trim((string)$component));

    if ($field === 'upload_document' || $comp === 'id' || $comp === 'identification') return '/uploads/identification/';
    if ($field === 'proof_file' || $comp === 'contact' || $comp === 'address') return '/uploads/address/';
    if ($field === 'marksheet_file' || $field === 'degree_file' || $comp === 'education') return '/uploads/education/';
    if ($field === 'employment_doc' || $comp === 'employment') return '/uploads/employment/';
    if ($field === 'photo_path' || $comp === 'basic') return '/uploads/candidate_photos/';
    if ($field === 'evidence_document' || $comp === 'ecourt') return '/uploads/ecourt/';
    if ($field === 'authorization_file' || $comp === 'reports' || $comp === 'authorization') return '/uploads/verification/';
    return '/uploads/';
}

function buildDocumentUrl($path, $sourceField = '', $component = '') {
    $file = normalizeStoredFilePath($path);
    if ($file === '') return '';
    if (preg_match('~^https?://~i', $file)) return $file;

    if (strpos($file, '/uploads/') === 0) {
        return app_url($file);
    }

    if (strpos($file, 'uploads/') === 0) {
        return app_url('/' . ltrim($file, '/'));
    }

    if (strpos($file, '/') !== false) {
        return app_url('/' . ltrim($file, '/'));
    }

    return app_url(inferUploadFolder($sourceField, $component) . rawurlencode(basename($file)));
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

// Social media details
try {
    $stmt = $db->prepare("CALL SP_Vati_Payfiller_get_social_media_details(?)");
    if ($stmt->execute([$applicationId])) {
        $data['social'] = safeFetch($stmt);
    }
    $stmt->closeCursor();
} catch (Exception $e) {
    error_log("Social details error: " . $e->getMessage());
    $data['social'] = null;
}

// E-court details
try {
    $stmt = $db->prepare("CALL SP_Vati_Payfiller_get_ecourt_details(?)");
    if ($stmt->execute([$applicationId])) {
        $data['ecourt'] = safeFetch($stmt);
    }
    $stmt->closeCursor();
} catch (Exception $e) {
    error_log("E-court details error: " . $e->getMessage());
    $data['ecourt'] = null;
}

// Authorization details
try {
    $stmt = $db->prepare("SELECT file_name, digital_signature, uploaded_at FROM Vati_Payfiller_Candidate_Authorization_documents WHERE application_id = ? ORDER BY uploaded_at DESC LIMIT 1");
    if ($stmt->execute([$applicationId])) {
        $data['authorization'] = safeFetch($stmt);
    }
    $stmt->closeCursor();
} catch (Exception $e) {
    error_log("Authorization details error: " . $e->getMessage());
    $data['authorization'] = null;
}

// Uploaded verification documents
try {
    $stmt = $db->prepare("SELECT doc_type, file_path, original_name, mime_type, uploaded_by_role, created_at FROM Vati_Payfiller_Verification_Documents WHERE application_id = ? ORDER BY created_at ASC, id ASC");
    if ($stmt->execute([$applicationId])) {
        $data['uploaded_docs'] = safeFetchAll($stmt);
    }
    $stmt->closeCursor();
} catch (Exception $e) {
    error_log("Uploaded docs error: " . $e->getMessage());
    $data['uploaded_docs'] = [];
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
$social = $data['social'] ?? null;
$ecourt = $data['ecourt'] ?? null;
$authorization = $data['authorization'] ?? null;
$uploadedDocs = is_array($data['uploaded_docs'] ?? null) ? $data['uploaded_docs'] : [];

$evidenceDocs = [];

$addEvidence = function ($component, $label, $path, $sourceField = '', $uploadedAt = '', $uploadedBy = '', $mimeType = '') use (&$evidenceDocs) {
    $file = normalizeStoredFilePath($path);
    if ($file === '') return;
    $url = buildDocumentUrl($file, $sourceField, $component);
    if ($url === '') return;

    $evidenceDocs[] = [
        'component' => (string)$component,
        'label' => trim((string)$label) !== '' ? (string)$label : 'Document',
        'file_name' => basename($file),
        'file_path' => $file,
        'url' => $url,
        'uploaded_at' => trim((string)$uploadedAt),
        'uploaded_by' => trim((string)$uploadedBy),
        'mime_type' => trim((string)$mimeType)
    ];
};

if (is_array($contact)) {
    $addEvidence('contact', 'Address Proof', $contact['proof_file'] ?? '', 'proof_file', $contact['created_at'] ?? '', 'Candidate');
}

if (is_array($identifications)) {
    foreach ($identifications as $id) {
        if (!is_array($id)) continue;
        $label = trim((string)($id['document_type'] ?? 'Identification Document'));
        $addEvidence('id', $label, $id['upload_document'] ?? '', 'upload_document', $id['created_at'] ?? '', 'Candidate');
    }
}

if (is_array($educations)) {
    foreach ($educations as $edu) {
        if (!is_array($edu)) continue;
        $qualification = trim((string)($edu['qualification'] ?? 'Education'));
        $addEvidence('education', $qualification . ' Marksheet', $edu['marksheet_file'] ?? '', 'marksheet_file', $edu['created_at'] ?? '', 'Candidate');
        $addEvidence('education', $qualification . ' Degree', $edu['degree_file'] ?? '', 'degree_file', $edu['created_at'] ?? '', 'Candidate');
    }
}

if (is_array($employments)) {
    foreach ($employments as $emp) {
        if (!is_array($emp)) continue;
        $company = trim((string)($emp['company_name'] ?? 'Employment'));
        $addEvidence('employment', $company . ' Employment Proof', $emp['employment_doc'] ?? '', 'employment_doc', $emp['created_at'] ?? '', 'Candidate');
    }
}

if (is_array($ecourt)) {
    $addEvidence('ecourt', 'E-Court Evidence', $ecourt['evidence_document'] ?? '', 'evidence_document', $ecourt['created_at'] ?? '', 'Candidate');
}

if (is_array($authorization)) {
    $addEvidence('reports', 'Authorization Document', $authorization['file_name'] ?? '', 'authorization_file', $authorization['uploaded_at'] ?? '', 'Candidate');
}

foreach ($uploadedDocs as $doc) {
    if (!is_array($doc)) continue;
    $comp = trim((string)($doc['doc_type'] ?? 'reports'));
    $label = trim((string)($doc['original_name'] ?? $doc['file_path'] ?? 'Verification Document'));
    $addEvidence(
        $comp !== '' ? $comp : 'reports',
        $label,
        $doc['file_path'] ?? '',
        $doc['source_field'] ?? '',
        $doc['created_at'] ?? '',
        $doc['uploaded_by_role'] ?? '',
        $doc['mime_type'] ?? ''
    );
}

$uniqueEvidence = [];
$seenEvidence = [];
foreach ($evidenceDocs as $doc) {
    $key = strtolower(trim((string)($doc['file_path'] ?? ''))) . '|' . strtolower(trim((string)($doc['label'] ?? '')));
    if (isset($seenEvidence[$key])) continue;
    $seenEvidence[$key] = true;
    $uniqueEvidence[] = $doc;
}
$evidenceDocs = $uniqueEvidence;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Background Verification Application - <?php echo htmlspecialchars($applicationId); ?></title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
<style>
    /* ========== CSS VARIABLES ========== */
    :root {
        --primary-color: #2c3e50;
        --primary-light: #34495e;
        --secondary-color: #3498db;
        --accent-color: #e74c3c;
        --accent-light: #ffebee;
        --light-gray: #f8f9fa;
        --medium-gray: #ecf0f1;
        --dark-gray: #2c3e50;
        --text-color: #333;
        --text-light: #7f8c8d;
        --border-color: #dce0e5;
        --success-color: #27ae60;
        --warning-color: #f39c12;
        --error-color: #e74c3c;
        --shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
        --shadow-light: 0 1px 5px rgba(0, 0, 0, 0.05);
        --border-radius: 6px;
        --border-radius-sm: 4px;
    }
    
    /* ========== RESET & BASE ========== */
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }
    
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif;
        line-height: 1.6;
        color: var(--text-color);
        background-color: white;
        font-size: 14px;
        padding: 20px;
    }
    
    /* ========== LAYOUT ========== */
    .application-container {
        max-width: 1100px;
        margin: 0 auto;
        background: white;
    }
    
    /* ========== HEADER ========== */
    .header {
        border-bottom: 2px solid var(--medium-gray);
        padding-bottom: 25px;
        margin-bottom: 35px;
    }
    
    .company-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 25px;
    }
    
    .company-logo {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .logo-placeholder {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 18px;
    }
    
    .company-info h1 {
        font-size: 22px;
        color: var(--primary-color);
        font-weight: 600;
        margin-bottom: 4px;
        letter-spacing: -0.3px;
    }
    
    .company-info .tagline {
        color: var(--text-light);
        font-size: 13px;
        font-weight: 400;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 5px 12px;
        background: linear-gradient(135deg, var(--secondary-color), #2980b9);
        color: white;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .application-meta {
        background: var(--light-gray);
        border-radius: var(--border-radius);
        padding: 18px;
        border-left: 3px solid var(--secondary-color);
    }
    
    .meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
    }
    
    .meta-item {
        display: flex;
        flex-direction: column;
    }
    
    .meta-label {
        font-size: 10px;
        color: var(--text-light);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
        margin-bottom: 3px;
    }
    
    .meta-value {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-color);
    }
    
    /* ========== ACTION BUTTONS SECTION ========== */
    .action-buttons {
        background: var(--light-gray);
        border-radius: var(--border-radius);
        padding: 20px;
        margin: 30px 0;
        border: 1px solid var(--border-color);
        text-align: center;
    }
    
    .action-buttons h3 {
        color: var(--primary-color);
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 15px;
    }
    
    .action-btn-group {
        display: flex;
        justify-content: center;
        gap: 15px;
        flex-wrap: wrap;
    }
    
    .action-btn {
        padding: 10px 20px;
        border: none;
        border-radius: var(--border-radius);
        font-weight: 500;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    
    .action-btn.print {
        background: var(--primary-color);
        color: white;
    }
    
    .action-btn.print:hover {
        background: var(--primary-light);
        transform: translateY(-1px);
        box-shadow: 0 3px 10px rgba(44, 62, 80, 0.2);
    }
    
    .action-btn.download {
        background: var(--secondary-color);
        color: white;
    }
    
    .action-btn.download:hover {
        background: #2980b9;
        transform: translateY(-1px);
        box-shadow: 0 3px 10px rgba(52, 152, 219, 0.2);
    }
    
    /* ========== SECTIONS ========== */
    .section {
        margin-bottom: 40px;
    }
    
    .section-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--medium-gray);
    }
    
    .section-icon {
        width: 36px;
        height: 36px;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
    }
    
    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: var(--primary-color);
        flex: 1;
    }
    
    .section-number {
        background: var(--medium-gray);
        color: var(--primary-color);
        width: 26px;
        height: 26px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 12px;
    }
    
    /* ========== DATA GRID ========== */
    .data-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 16px;
        margin-bottom: 25px;
    }
    
    .data-item {
        display: flex;
        flex-direction: column;
    }
    
    .data-label {
        font-size: 11px;
        color: var(--text-light);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .data-value {
        font-size: 14px;
        padding: 12px;
        background: var(--light-gray);
        border-radius: var(--border-radius-sm);
        border-left: 2px solid var(--secondary-color);
        min-height: 44px;
        display: flex;
        align-items: center;
    }
    
    .data-value.empty {
        color: var(--text-light);
        font-style: italic;
    }
    
    /* ========== PHOTO ========== */
    .photo-section {
        display: flex;
        justify-content: center;
        margin: 30px 0;
    }
    
    .photo-container {
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 15px;
        background: white;
        max-width: 180px;
    }
    
    .photo-container img {
        width: 130px;
        height: 160px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid var(--border-color);
    }
    
    .photo-label {
        text-align: center;
        margin-top: 10px;
        font-size: 11px;
        color: var(--text-light);
    }
    
    /* ========== TABLES ========== */
    .data-table-container {
        overflow-x: auto;
        margin: 20px 0;
        border-radius: var(--border-radius);
        border: 1px solid var(--border-color);
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 600px;
    }
    
    .data-table thead {
        background: var(--primary-color);
    }
    
    .data-table th {
        color: white;
        font-weight: 600;
        text-align: left;
        padding: 14px 16px;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-right: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .data-table tbody tr {
        border-bottom: 1px solid var(--border-color);
        transition: background-color 0.2s;
    }
    
    .data-table tbody tr:hover {
        background-color: var(--light-gray);
    }
    
    .data-table tbody tr:last-child {
        border-bottom: none;
    }
    
    .data-table td {
        padding: 14px 16px;
        font-size: 13px;
    }
    
    /* ========== CARDS ========== */
    .card {
        background: white;
        border-radius: var(--border-radius);
        border: 1px solid var(--border-color);
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: var(--shadow-light);
    }
    
    .card-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid var(--medium-gray);
    }
    
    .card-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--primary-color);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .card-badge {
        background: var(--secondary-color);
        color: white;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    /* ========== EDUCATION SPECIFIC ========== */
    .education-table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
    }
    
    .education-table th {
        background: var(--primary-color);
        color: white;
        font-weight: 600;
        text-align: left;
        padding: 12px 15px;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .education-table td {
        padding: 12px 15px;
        font-size: 13px;
        border-bottom: 1px solid var(--border-color);
    }
    
    .education-table tr:last-child td {
        border-bottom: none;
    }
    
    .address-box {
        background: var(--light-gray);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 15px;
        margin: 15px 0;
        border-left: 3px solid var(--secondary-color);
    }
    
    .address-label {
        font-size: 12px;
        color: var(--primary-color);
        font-weight: 600;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .college-website {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--secondary-color);
        text-decoration: none;
        font-weight: 500;
        padding: 6px 12px;
        background: #e8f4fd;
        border-radius: 4px;
        border: 1px solid #bbdefb;
        font-size: 13px;
    }
    
    /* ========== TAGS & BADGES ========== */
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        background: var(--light-gray);
        color: var(--text-color);
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        gap: 4px;
        border: 1px solid var(--border-color);
    }
    
    .badge.success {
        background: #e6f4ea;
        color: var(--success-color);
        border-color: #c6e6d2;
    }
    
    /* ========== FOOTER ========== */
    .footer {
        margin-top: 50px;
        padding-top: 25px;
        border-top: 1px solid var(--medium-gray);
        text-align: center;
        color: var(--text-light);
        font-size: 12px;
    }
    
    .footer-links {
        display: flex;
        justify-content: center;
        gap: 25px;
        margin-bottom: 15px;
        flex-wrap: wrap;
    }
    
    /* ========== PRINT STYLES ========== */
    @media print {
        @page {
            size: A4;
            margin: 15mm;
        }
        
        body {
            font-size: 11pt;
            padding: 0 !important;
            background: white !important;
        }
        
        .application-container {
            max-width: 100% !important;
            box-shadow: none !important;
        }
        
        .no-print, .action-buttons {
            display: none !important;
        }
        
        .card {
            break-inside: avoid;
            box-shadow: none !important;
            border: 1px solid #ddd !important;
        }
        
        .data-value {
            background: white !important;
            border: 1px solid #eee !important;
        }
    }
    
    /* ========== MOBILE RESPONSIVE ========== */
    @media (max-width: 768px) {
        body {
            padding: 15px;
            font-size: 13px;
        }
        
        .company-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        
        .company-info h1 {
            font-size: 20px;
        }
        
        .meta-grid {
            grid-template-columns: 1fr;
        }
        
        .data-grid {
            grid-template-columns: 1fr;
            gap: 12px;
        }
        
        .section-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .education-table {
            display: block;
            overflow-x: auto;
        }
        
        .education-table thead {
            display: none;
        }
        
        .education-table tbody,
        .education-table tr,
        .education-table td {
            display: block;
            width: 100%;
        }
        
        .education-table tr {
            margin-bottom: 15px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
        }
        
        .education-table td {
            padding: 10px 12px;
            border: none;
            border-bottom: 1px solid var(--border-color);
            position: relative;
            padding-left: 45%;
        }
        
        .education-table td:last-child {
            border-bottom: none;
        }
        
        .education-table td::before {
            content: attr(data-label);
            position: absolute;
            left: 12px;
            top: 10px;
            width: 40%;
            font-weight: 600;
            font-size: 11px;
            color: var(--primary-color);
        }
        
        .action-btn-group {
            flex-direction: column;
            align-items: center;
        }
        
        .action-btn {
            width: 100%;
            max-width: 300px;
            justify-content: center;
        }
        
        .photo-container {
            max-width: 160px;
            padding: 12px;
        }
        
        .photo-container img {
            width: 120px;
            height: 150px;
        }
        
        .footer-links {
            flex-direction: column;
            gap: 10px;
        }
    }
    
    @media (max-width: 480px) {
        body {
            padding: 10px;
            font-size: 12px;
        }
        
        .company-info h1 {
            font-size: 18px;
        }
        
        .section-title {
            font-size: 16px;
        }
        
        .data-value {
            font-size: 13px;
            padding: 10px;
        }
        
        .address-box {
            padding: 12px;
        }
        
        .footer {
            padding: 15px;
        }
    }
    
    /* ========== UTILITY CLASSES ========== */
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .mb-1 { margin-bottom: 8px; }
    .mb-2 { margin-bottom: 16px; }
    .mb-3 { margin-bottom: 24px; }
    .mt-1 { margin-top: 8px; }
    .mt-2 { margin-top: 16px; }
    .mt-3 { margin-top: 24px; }
    .d-flex { display: flex; }
    .align-center { align-items: center; }
    .justify-between { justify-content: space-between; }
    .w-100 { width: 100%; }
</style>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
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

        <!-- 7. Evidence Documents -->
        <div class="section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-file-signature"></i>
                </div>
                <h2 class="section-title">Evidence Documents</h2>
                <div class="section-number">7</div>
            </div>

            <div class="card">
                <?php if (!empty($evidenceDocs)): ?>
                    <div class="data-table-container">
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th style="width: 70px;">#</th>
                                <th>Component</th>
                                <th>Document</th>
                                <th>Uploaded By</th>
                                <th>Uploaded At</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($evidenceDocs as $idx => $doc): ?>
                                <tr>
                                    <td><?php echo (int)$idx + 1; ?></td>
                                    <td><?php echo htmlspecialchars(ucwords(str_replace(['_', '-'], ' ', (string)($doc['component'] ?? 'General')))); ?></td>
                                    <td><?php echo htmlspecialchars($doc['label'] ?? ($doc['file_name'] ?? 'Document')); ?></td>
                                    <td><?php echo htmlspecialchars($doc['uploaded_by'] !== '' ? $doc['uploaded_by'] : 'Candidate'); ?></td>
                                    <td><?php echo htmlspecialchars($doc['uploaded_at'] !== '' ? $doc['uploaded_at'] : '-'); ?></td>
                                    <td>
                                        <a href="<?php echo htmlspecialchars($doc['url']); ?>" target="_blank" rel="noopener">
                                            View / Download
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-file-excel fa-2x mb-2" style="color: var(--warning-color);"></i>
                        <p class="mb-0">No evidence documents found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
<!-- Action Buttons Section -->
<div class="action-buttons no-print">
    <h3><i class="fas fa-download me-2"></i>Export Options</h3>
    <div class="action-btn-group">
        <button type="button" class="action-btn print" onclick="window.print()">
            <i class="fas fa-print"></i> Print Document
        </button>
        <a class="action-btn download" href="<?php echo htmlspecialchars(app_url('/api/candidate/generate_pdf.php?application_id=' . rawurlencode((string)$applicationId) . '&bypass=1&force_download=1')); ?>">
            <i class="fas fa-download"></i> Download Report
        </a>
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
