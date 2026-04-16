<?php
header("Content-Type: application/json");
session_start();

require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../config/db.php";

error_reporting(E_ALL);
ini_set('display_errors', '0');

function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

function sendSuccess($message) {
    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
    exit;
}

function ensure_upload_dir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0775);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        sendError('Upload directory is not writable: ' . $dir, 500);
    }
}

function parse_mobile_parts(?string $countryCode, ?string $mobile): array {
    $cc = trim((string)($countryCode ?? ''));
    $m = trim((string)($mobile ?? ''));

    // If UI sends combined mobile like "+91 9876543210" (candidate router does this), split it.
    if ($cc === '' && $m !== '' && preg_match('/^(\+\d{1,4})\s*(.+)$/', $m, $mm)) {
        $cc = trim((string)$mm[1]);
        $m = trim((string)$mm[2]);
    }

    if ($cc === '') $cc = '+91';

    // If mobile begins with country code again, strip it.
    if ($m !== '' && strpos($m, $cc) === 0) {
        $m = trim((string)substr($m, strlen($cc)));
    }

    return [$cc, $m];
}

function validate_mobile_by_country(string $countryCode, string $mobileNumber): ?string {
    $cc = trim($countryCode);
    $mn = trim($mobileNumber);

    if ($mn === '') return 'Please enter mobile number';

    if (!preg_match('/^\d+$/', $mn)) {
        return 'Mobile number must contain digits only';
    }

    $expectedLenMap = [
        '+91' => 10,
        '+1'  => 10,
        '+44' => 10,
        '+61' => 9,
        '+81' => 10,
        '+86' => 11,
    ];

    if (isset($expectedLenMap[$cc])) {
        $expected = (int)$expectedLenMap[$cc];
        if (strlen($mn) !== $expected) {
            return 'Mobile number must be ' . $expected . ' digits for ' . $cc;
        }
        return null;
    }

    // Generic fallback for unknown codes
    $len = strlen($mn);
    if ($len < 6 || $len > 15) {
        return 'Mobile number must be between 6 and 15 digits';
    }

    return null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Invalid request method');
    }

    // Log received data for debugging
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));

    $application_id = getApplicationId();
    if (!$application_id) {
        sendError('Application ID not found');
    }

    $pdo = getDB();

    // Immutable prefill from case (client/bulk created). Candidate cannot change these.
    $casePrefill = [];
    try {
        $prefillStmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetCaseCandidatePrefill(?)');
        $prefillStmt->execute([$application_id]);
        $casePrefill = $prefillStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        while ($prefillStmt->nextRowset()) {
        }
    } catch (Throwable $e) {
        $casePrefill = [];
    }

    $prefillFirstName = trim((string)($casePrefill['candidate_first_name'] ?? ''));
    $prefillMiddleName = trim((string)($casePrefill['candidate_middle_name'] ?? ''));
    $prefillLastName = trim((string)($casePrefill['candidate_last_name'] ?? ''));
    $prefillEmail = trim((string)($casePrefill['candidate_email'] ?? ''));
    $prefillMobileRaw = trim((string)($casePrefill['candidate_mobile'] ?? ''));

    // If case prefill exists, force name/email identity fields from case BEFORE validation.
    $lockNameEmail = ($prefillFirstName !== '' || $prefillLastName !== '' || $prefillEmail !== '');
    if ($lockNameEmail) {
        if ($prefillFirstName !== '') $_POST['first_name'] = $prefillFirstName;
        if ($prefillMiddleName !== '') $_POST['middle_name'] = $prefillMiddleName;
        if ($prefillLastName !== '') $_POST['last_name'] = $prefillLastName;
        if ($prefillEmail !== '') $_POST['email'] = $prefillEmail;
    }

    // Basic validation for required fields (only for final submit)
    $save_draft = $_POST['save_draft'] ?? '1';
    
    if ($save_draft === '0') { // Final submission
        $required_fields = [
            'first_name', 'last_name', 'gender', 'dob',
            'father_name', 'mobile', 'email',
            'country', 'state', 'city_village', 'district', 'pincode'
        ];
        
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                sendError("Please fill in all required fields: " . ucfirst(str_replace('_', ' ', $field)));
            }
        }
        
        // Email validation
        if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            sendError("Please enter a valid email address");
        }
    }

    // Prepare data for database
    [$cc, $mn] = parse_mobile_parts($_POST['mobile_country_code'] ?? null, $_POST['mobile'] ?? null);

    // If JS sends combined mobile in $_POST['mobile'], mobile_country_code may be missing. validate based on parsed parts.
    if ($save_draft === '0') {
        $mobileErr = validate_mobile_by_country($cc, $mn);
        if ($mobileErr) {
            sendError($mobileErr);
        }

        $pin = trim((string)($_POST['pincode'] ?? ''));
        if ($pin !== '' && !preg_match('/^\d{6}$/', $pin)) {
            sendError('Pincode must be exactly 6 digits');
        }
    }

    $mobile = $cc . ' ' . $mn;

    // Handle photo upload
    $photoPath = $_POST['existing_photo'] ?? null;
    
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photo = $_FILES['photo'];
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png'];
        if (!in_array($photo['type'], $allowedTypes)) {
            sendError('Only JPG and PNG images are allowed');
        }
        
        // Validate file size (5MB)
        if ($photo['size'] > 5 * 1024 * 1024) {
            sendError('Profile photo must be less than 5MB');
        }
        
        // Generate unique filename
        $ext = pathinfo($photo['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . $application_id . '_' . time() . '.' . $ext;
        $uploadDir = app_path('/uploads/candidate_photos');
        ensure_upload_dir($uploadDir);
        
        $uploadPath = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        
        // Move uploaded file
        if (move_uploaded_file($photo['tmp_name'], $uploadPath)) {
            $photoPath = '/uploads/candidate_photos/' . $filename;
            
            // Delete old photo if exists and if it's not the same
            if (!empty($_POST['existing_photo']) && $_POST['existing_photo'] !== $photoPath) {
                $existing = (string)$_POST['existing_photo'];
                if (strpos($existing, '/uploads/candidate_photos/') === 0) {
                    $oldPhotoPath = app_path($existing);
                    if (file_exists($oldPhotoPath) && is_file($oldPhotoPath)) {
                        @unlink($oldPhotoPath);
                    }
                }
            }
        } else {
            sendError('Failed to upload profile photo');
        }
    }

    // Prepare parameters for stored procedure
    $params = [
        ':first_name'     => trim($_POST['first_name'] ?? ''),
        ':middle_name'    => trim($_POST['middle_name'] ?? ''),
        ':last_name'      => trim($_POST['last_name'] ?? ''),
        ':gender'         => trim($_POST['gender'] ?? ''),
        ':dob'            => trim($_POST['dob'] ?? '') ?: null,
        ':blood_group'    => trim($_POST['blood_group'] ?? ''),
        ':father_name'    => trim($_POST['father_name'] ?? ''),
        ':mother_name'    => trim($_POST['mother_name'] ?? ''),
        ':mobile'         => $mobile,
        ':landline'       => trim($_POST['landline'] ?? ''),
        ':email'          => trim($_POST['email'] ?? ''),
        ':marital_status' => trim($_POST['marital_status'] ?? ''),
        ':spouse_name'    => trim($_POST['spouse_name'] ?? ''),
        ':other_name'     => trim($_POST['other_name'] ?? ''),
        ':country'        => trim($_POST['country'] ?? ''),
        ':state'          => trim($_POST['state'] ?? ''),
        ':city_village'   => trim($_POST['city_village'] ?? ''),
        ':district'       => trim($_POST['district'] ?? ''),
        ':pincode'        => trim($_POST['pincode'] ?? ''),
        ':nationality'    => trim($_POST['nationality'] ?? ''),
        ':application_id' => $application_id,
        ':photo_path'     => $photoPath
    ];

    error_log("Stored procedure params: " . print_r($params, true));

    // Call the stored procedure
    $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_save_basic_details(
        :first_name, :middle_name, :last_name, :gender, :dob, :blood_group,
        :father_name, :mother_name, :mobile, :landline, :email,
        :marital_status, :spouse_name, :other_name,
        :country, :state, :city_village, :district, :pincode, :nationality,
        :application_id, :photo_path
    )");

    $result = $stmt->execute($params);
    
    if (!$result) {
        $errorInfo = $stmt->errorInfo();
        sendError('Database error: ' . ($errorInfo[2] ?? 'Unknown error'));
    }

    // Mark as completed if final submission
    if ($save_draft === '0') {
        markSectionAsCompleted($application_id, 'basic_details');
    }

    sendSuccess($save_draft === '0' ? 'Basic details submitted successfully!' : 'Draft saved successfully!');

} catch (Exception $e) {
    error_log("Exception in store_basic-details.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendError('Server error: ' . $e->getMessage(), 500);
}

function markSectionAsCompleted($application_id, $section) {
    try {
        $pdo = getDB();
        
        // Check if section tracking exists
        $checkStmt = $pdo->prepare(
            "SELECT id FROM Vati_Payfiller_Section_Status WHERE application_id = ? AND section_name = ?"
        );
        $checkStmt->execute([$application_id, $section]);
        
        if ($checkStmt->fetchColumn()) {
            // Update
            $stmt = $pdo->prepare(
                "UPDATE Vati_Payfiller_Section_Status SET 
                 is_completed = 1, 
                 completed_at = NOW(),
                 updated_at = NOW()
                 WHERE application_id = ? AND section_name = ?"
            );
            $stmt->execute([$application_id, $section]);
        } else {
            // Insert
            $stmt = $pdo->prepare(
                "INSERT INTO Vati_Payfiller_Section_Status 
                 (application_id, section_name, is_completed, completed_at, created_at)
                 VALUES (?, ?, 1, NOW(), NOW())"
            );
            $stmt->execute([$application_id, $section]);
        }
    } catch (Exception $e) {
        error_log("Error marking section as completed: " . $e->getMessage());
        // Don't fail the whole request if this fails
    }
}
