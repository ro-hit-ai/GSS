<?php
header("Content-Type: application/json");
session_start();

require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../config/db.php";

class ValidationException extends Exception {}
class FileUploadException extends Exception {}

function validateEcourtData($data) {
    $errors = [];
    
    // Required fields
    $required = [
        'current_address' => 'Current Address',
        'permanent_address' => 'Permanent Address',
        'period_from_date' => 'Period From Date',
        'period_to_date' => 'Period To Date',
        'period_duration_years' => 'Duration (Years)',
        'dob' => 'Date of Birth'
    ];
    
    foreach ($required as $field => $label) {
        if (empty(trim($data[$field] ?? ''))) {
            $errors[] = "$label is required";
        }
    }
    
    // Date validation
    if (!empty($data['period_from_date']) && !empty($data['period_to_date'])) {
        $fromDate = new DateTime($data['period_from_date']);
        $toDate = new DateTime($data['period_to_date']);
        
        if ($fromDate > $toDate) {
            $errors[] = '"From Date" must be earlier than "To Date"';
        }
    }
    
    // Duration validation
    if (!empty($data['period_duration_years']) && !is_numeric($data['period_duration_years'])) {
        $errors[] = 'Duration must be a number';
    }
    
    if (!empty($errors)) {
        throw new ValidationException(implode('; ', $errors));
    }
}

function handleEvidenceUpload($file, $application_id) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server max upload size',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form max size',
            UPLOAD_ERR_PARTIAL => 'File upload incomplete',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
        ];
        $msg = $errors[$file['error']] ?? 'File upload failed';
        throw new FileUploadException($msg);
    }

    // Validate file type
    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        throw new ValidationException("Invalid file type. Allowed: JPG, JPEG, PNG, PDF");
    }

    // Validate file size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new ValidationException("File size exceeds 5MB limit");
    }

    // Create upload directory if it doesn't exist
    $dir = rtrim(app_path('/uploads/ecourt/'), '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new FileUploadException('Failed to create upload folder: ' . $dir);
        }
    }

    if (!is_writable($dir)) {
        throw new FileUploadException('Upload folder not writable: ' . $dir);
    }

    // Generate unique filename
    $filename = "ecourt_{$application_id}_" . time() . "_" . uniqid() . "." . $ext;
    $fullPath = $dir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new FileUploadException("Failed to save uploaded file");
    }

    return $filename;
}

function getExistingEcourtData($pdo, $application_id) {
    try {
        $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_ecourt_details(?)");
        $stmt->execute([$application_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    } catch (Exception $e) {
        // If stored procedure doesn't exist, return empty array
        return [];
    } finally {
        try {
            if (isset($stmt) && $stmt) {
                while ($stmt->nextRowset()) {
                }
                $stmt->closeCursor();
            }
        } catch (Throwable $_e) {
        }
    }
}

function cleanupUploadedFile($filename) {
    $dir = rtrim(app_path('/uploads/ecourt/'), '/\\') . DIRECTORY_SEPARATOR;
    $full = $dir . basename((string)$filename);
    if ($filename && file_exists($full)) {
        @unlink($full);
    }
}

try {
    // Check session
    if (!isset($_SESSION['application_id'])) {
        throw new ValidationException("Session expired. Please log in again.");
    }

    // Get database connection
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    $application_id = $_SESSION['application_id'];
    
    // Trim all POST data
    $post = array_map('trim', $_POST);
    
    // Check if it's a draft save
    $isDraft = isset($post['draft']) && $post['draft'] === '1';
    
    // Get verification action if present
    $verification_action = $post['action'] ?? null;
    $verification_notes = $post['verification_notes'] ?? '';
    
    // Get form data
    $current_address = $post['current_address'] ?? '';
    $permanent_address = $post['permanent_address'] ?? '';
    $period_from_date = $post['period_from_date'] ?? null;
    $period_to_date = $post['period_to_date'] ?? null;
    $period_duration_years = $post['period_duration_years'] ?? null;
    $dob = $post['dob'] ?? null;
    $on_hold = isset($post['on_hold']) ? 1 : 0;
    $not_applicable = isset($post['not_applicable']) ? 1 : 0;
    $comments = $post['comments'] ?? '';
    
    // Get existing e-court data for draft handling
    $existing = getExistingEcourtData($pdo, $application_id);
    
    // Handle addresses for drafts
    if ($isDraft) {
        // For drafts, if addresses are empty in form submission, keep existing ones
        if (empty(trim($current_address)) && !empty($existing['current_address'])) {
            $current_address = $existing['current_address'];
        }
        
        if (empty(trim($permanent_address)) && !empty($existing['permanent_address'])) {
            $permanent_address = $existing['permanent_address'];
        }
        
        // For other fields, if empty in form submission, keep existing values
        if (empty($period_from_date) && !empty($existing['period_from_date'])) {
            $period_from_date = $existing['period_from_date'];
        }
        
        if (empty($period_to_date) && !empty($existing['period_to_date'])) {
            $period_to_date = $existing['period_to_date'];
        }
        
        if (empty($period_duration_years) && !empty($existing['period_duration_years'])) {
            $period_duration_years = $existing['period_duration_years'];
        }
        
        if (empty($dob) && !empty($existing['dob'])) {
            $dob = $existing['dob'];
        }
        
        // For checkboxes in drafts, if not submitted, keep existing values
        if (!isset($post['on_hold']) && isset($existing['on_hold'])) {
            $on_hold = $existing['on_hold'];
        }
        
        if (!isset($post['not_applicable']) && isset($existing['not_applicable'])) {
            $not_applicable = $existing['not_applicable'];
        }
        
        if (empty($comments) && !empty($existing['comments'])) {
            $comments = $existing['comments'];
        }
    }
    
    // Validate data (skip validation for drafts, but still validate dates if present)
    if (!$isDraft) {
        validateEcourtData([
            'current_address' => $current_address,
            'permanent_address' => $permanent_address,
            'period_from_date' => $period_from_date,
            'period_to_date' => $period_to_date,
            'period_duration_years' => $period_duration_years,
            'dob' => $dob
        ]);
    } else {
        // For drafts, still validate dates if both are provided
        if (!empty($period_from_date) && !empty($period_to_date)) {
            $fromDate = new DateTime($period_from_date);
            $toDate = new DateTime($period_to_date);
            
            if ($fromDate > $toDate) {
                throw new ValidationException('"From Date" must be earlier than "To Date"');
            }
        }
    }
    
    // Handle file upload
    $evidence_document = null;
    $uploaded_file = null;
    
    // Check if "Not Applicable" is checked - if yes, no file is required
    if ($not_applicable) {
        $evidence_document = null;
    } else {
        // Check if new file is uploaded
        $new_upload = isset($_FILES['evidence_document']) && $_FILES['evidence_document']['error'] === UPLOAD_ERR_OK;
        
        if ($new_upload) {
            $uploaded_file = handleEvidenceUpload($_FILES['evidence_document'], $application_id);
            $evidence_document = $uploaded_file;
        } else {
            // Check for existing file - IMPORTANT: Keep existing file if no new upload
            if (!empty($existing['evidence_document'])) {
                $evidence_document = $existing['evidence_document'];
            }
        }
        
        // For final submission (not draft), validate file is present
        if (!$isDraft && !$evidence_document && !$not_applicable) {
            throw new ValidationException("Evidence document is required unless 'Not Applicable' is checked");
        }
    }
    
    // Call stored procedure
    $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_save_ecourt_details(
        :current_address,
        :permanent_address,
        :evidence_document,
        :period_from_date,
        :period_to_date,
        :period_duration_years,
        :dob,
        :on_hold,
        :not_applicable,
        :comments,
        :application_id,
        :verification_action,
        :verification_notes
    )");

    try {
        $stmt->execute([
            ':current_address' => $current_address,
            ':permanent_address' => $permanent_address,
            ':evidence_document' => $evidence_document,
            ':period_from_date' => $period_from_date,
            ':period_to_date' => $period_to_date,
            ':period_duration_years' => $period_duration_years,
            ':dob' => $dob,
            ':on_hold' => $on_hold,
            ':not_applicable' => $not_applicable,
            ':comments' => $comments,
            ':application_id' => $application_id,
            ':verification_action' => $verification_action,
            ':verification_notes' => $verification_notes
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
    } finally {
        try {
            if (isset($stmt) && $stmt) {
                while ($stmt->nextRowset()) {
                }
                $stmt->closeCursor();
            }
        } catch (Throwable $_e) {
        }
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => $isDraft ? 'Draft saved successfully' : 'E-Court details saved successfully',
        'action' => $result['action'] ?? 'saved',
        'verification_status' => $result['verification_status'] ?? 'pending',
        'is_draft' => $isDraft,
        'data' => [
            'current_address' => $current_address,
            'permanent_address' => $permanent_address,
            'period_from_date' => $period_from_date,
            'period_to_date' => $period_to_date,
            'dob' => $dob
        ]
    ]);

} catch (ValidationException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cleanupUploadedFile($uploaded_file ?? null);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (FileUploadException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cleanupUploadedFile($uploaded_file ?? null);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cleanupUploadedFile($uploaded_file ?? null);
    error_log("store_ecourt.php ERROR: " . $e->getMessage() . " on line " . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Server error: ' . $e->getMessage(),
        'error_type' => get_class($e),
        'error_line' => $e->getLine()
    ]);
}
?>
