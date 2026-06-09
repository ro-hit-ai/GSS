<?php
header("Content-Type: application/json");
session_start();

require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../shared/candidate_correction_service.php";

class ValidationException extends Exception {}
class FileUploadException extends Exception {}


function validateRequired($value, $name) {
    if (trim($value) === '') {
        throw new ValidationException("$name is required.");
    }
}

function normalize_country_name($value): string {
    return strtolower(trim((string)$value));
}

function validate_postal_code(string $country, string $postalCode, string $label): void {
    $code = trim($postalCode);
    if ($code === '') return;

    $c = normalize_country_name($country);

    // India: 6-digit PIN only
    if ($c === 'india') {
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new ValidationException($label . ' pin code must be 6 digits.');
        }
        return;
    }

    if (strlen($code) < 3 || strlen($code) > 12) {
        throw new ValidationException($label . ' postal code must be between 3 and 12 characters.');
    }
    if (!preg_match('/^[A-Za-z0-9 \-]+$/', $code)) {
        throw new ValidationException($label . ' postal code contains invalid characters.');
    }
}

function handleFileUpload(array $file, string $application_id): string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new FileUploadException("File upload failed.");
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        throw new FileUploadException("Uploaded temp file is missing.");
    }

    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        throw new ValidationException("Invalid file type. Allowed: JPG, PNG, PDF");
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new ValidationException("File size exceeds 5MB.");
    }

    $dir = candidate_upload_dir('/uploads/address/');
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0775);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new FileUploadException("Upload directory is not writable.");
    }

    $filename = "address_{$application_id}_" . time() . "_" . uniqid() . "." . $ext;
    $target = $dir . $filename;

    if (!@move_uploaded_file($tmpName, $target)) {
        if (!@rename($tmpName, $target) && !@copy($tmpName, $target)) {
            throw new FileUploadException("Failed to save uploaded file.");
        }
        @unlink($tmpName);
    }

    return $filename;
}

function candidate_upload_dir(string $relative): string {
    $dir = rtrim(app_path($relative), '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function candidate_delete_uploaded_file(string $relativeDir, string $fileName): void {
    $fileName = trim($fileName);
    if ($fileName === '' || strtoupper($fileName) === 'INSUFFICIENT_DOCUMENTS') {
        return;
    }
    $path = candidate_upload_dir($relativeDir) . basename($fileName);
    if (is_file($path)) {
        @unlink($path);
    }
}

function pickUploadedAddressProofField(array $files, array $fieldOrder): ?string {
    foreach ($fieldOrder as $field) {
        if (!isset($files[$field]) || !is_array($files[$field])) {
            continue;
        }
        $error = $files[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
        $tmpName = (string)($files[$field]['tmp_name'] ?? '');
        if ($error === UPLOAD_ERR_OK && $tmpName !== '' && is_file($tmpName)) {
            return $field;
        }
    }
    foreach ($fieldOrder as $field) {
        if (!isset($files[$field]) || !is_array($files[$field])) {
            continue;
        }
        $error = $files[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
        $name = (string)($files[$field]['name'] ?? '');
        if ($error === UPLOAD_ERR_OK && $name !== '') {
            error_log('store_contact.php upload field missing tmp file: ' . $field . ' name=' . $name . ' tmp=' . (string)($files[$field]['tmp_name'] ?? ''));
        }
    }
    return null;
}


try {
    if (empty($_SESSION['application_id'])) {
        throw new ValidationException("Session expired. Please login again.");
    }

    $application_id = $_SESSION['application_id'];
    ccs_guard_correction_component_or_json('contact');

    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    $post = array_map('trim', $_POST);


    $same_as_current        = isset($post['same_as_current']) && (string)$post['same_as_current'] === '1' ? 1 : 0;
    $insufficient_documents = !empty($post['insufficient_address_proof']) ? 1 : 0;
    $is_draft               = !empty($post['save_draft']);
    $hasCurrentAddress      = isset($post['has_current_address']) && (string)$post['has_current_address'] === '1';
    $hasPermanentAddress    = isset($post['has_permanent_address']) && (string)$post['has_permanent_address'] === '1';


    if (!$hasCurrentAddress && !$hasPermanentAddress) {
        $hasCurrentAddress = true;
        $hasPermanentAddress = true;
    }

    $mobile_country_code = '';
    $mobile              = '';
    $alternative_mobile  = '';
    $email               = '';
    $alternative_email   = '';


    $address1    = $post['current_address1'] ?? '';
    $address2    = $post['current_address2'] ?? '';
    $city        = $post['current_city'] ?? '';
    $state       = $post['current_state'] ?? '';
    $country     = $post['current_country'] ?? 'India';
    $postal_code = $post['current_postal_code'] ?? '';

    if (!$is_draft && $hasCurrentAddress) {
        validateRequired($address1, 'Current Address');
        validateRequired($city, 'City');
        validateRequired($state, 'State');
        validateRequired($country, 'Country');
        validateRequired($postal_code, 'Postal Code');
    }


    if ($hasCurrentAddress && !$hasPermanentAddress) {
        $same_as_current = 1;
        $p_address1    = '';
        $p_address2    = '';
        $p_city        = '';
        $p_state       = '';
        $p_country     = '';
        $p_postal_code = '';
    } elseif (!$hasCurrentAddress && $hasPermanentAddress) {
        $same_as_current = 0;
        $p_address1    = $post['permanent_address1'] ?? '';
        $p_address2    = $post['permanent_address2'] ?? '';
        $p_city        = $post['permanent_city'] ?? '';
        $p_state       = $post['permanent_state'] ?? '';
        $p_country     = $post['permanent_country'] ?? 'India';
        $p_postal_code = $post['permanent_postal_code'] ?? '';

        if (!$is_draft) {
            validateRequired($p_address1, 'Permanent Address');
            validateRequired($p_city, 'Permanent City');
            validateRequired($p_state, 'Permanent State');
            validateRequired($p_country, 'Permanent Country');
            validateRequired($p_postal_code, 'Permanent Postal Code');
        }
    } elseif ($same_as_current) {
        $p_address1    = '';
        $p_address2    = '';
        $p_city        = '';
        $p_state       = '';
        $p_country     = '';
        $p_postal_code = '';
    } else {
        $p_address1    = $post['permanent_address1'] ?? '';
        $p_address2    = $post['permanent_address2'] ?? '';
        $p_city        = $post['permanent_city'] ?? '';
        $p_state       = $post['permanent_state'] ?? '';
        $p_country     = $post['permanent_country'] ?? 'India';
        $p_postal_code = $post['permanent_postal_code'] ?? '';

        if (!$is_draft && $hasPermanentAddress) {
            validateRequired($p_address1, 'Permanent Address');
            validateRequired($p_city, 'Permanent City');
            validateRequired($p_state, 'Permanent State');
            validateRequired($p_country, 'Permanent Country');
            validateRequired($p_postal_code, 'Permanent Postal Code');
        }
    }

    // Safety: backend is source of truth; do not persist permanent address when same_as_current is set.
    if ($same_as_current) {
        $hasPermanentAddress = false;
    }

    /* ================= PROOF ================= */

    $contactRow = [];
    try {
        $contactStmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_contact_details(?)");
        $contactStmt->execute([$application_id]);
        $contactRow = $contactStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        while ($contactStmt->nextRowset()) {
        }
        $contactStmt->closeCursor();
    } catch (Throwable $e) {
        $contactRow = [];
    }
    $proof_type = $post['current_proof_type'] ?? ($post['proof_type'] ?? '');
    $proof_file = null;
    $currentProofOriginalName = trim((string)($contactRow['current_proof_original_name'] ?? ''));
    $permanentProofType = $post['permanent_proof_type'] ?? '';
    $permanentProofFile = trim((string)($post['existing_permanent_address_proof'] ?? ($contactRow['permanent_proof_file'] ?? '')));
    $permanentProofOriginalName = trim((string)($contactRow['permanent_proof_original_name'] ?? ''));

    if (!$insufficient_documents) {
        $proofField = pickUploadedAddressProofField($_FILES, [
            'current_address_proof',
        ]);

        if (!$proofField) {
            foreach (['current_address_proof'] as $candidateField) {
                if (isset($_FILES[$candidateField]['error']) && $_FILES[$candidateField]['error'] !== UPLOAD_ERR_NO_FILE && $_FILES[$candidateField]['error'] !== UPLOAD_ERR_OK) {
                    throw new FileUploadException("File upload failed.");
                }
            }
        }

        if ($proofField && isset($_FILES[$proofField]['error']) && $_FILES[$proofField]['error'] !== UPLOAD_ERR_NO_FILE && $_FILES[$proofField]['error'] !== UPLOAD_ERR_OK) {
            throw new FileUploadException("File upload failed.");
        }

        if ($proofField && $_FILES[$proofField]['error'] === UPLOAD_ERR_OK) {
            $proof_file = handleFileUpload($_FILES[$proofField], $application_id);
            $currentProofOriginalName = (string)($_FILES[$proofField]['name'] ?? $proof_file);

        } else {
            // keep existing file (from form or DB)
            $proof_file = trim((string)($post['existing_current_address_proof'] ?? ''));
            if ($proof_file === '') {
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(NULLIF(current_proof_file, ''), proof_file) FROM Vati_Payfiller_Candidate_Contact_details WHERE application_id = ?"
                );
                $stmt->execute([$application_id]);
                $proof_file = (string)$stmt->fetchColumn();
                $stmt->closeCursor();
            }
        }
    }

    if (isset($_FILES['permanent_address_proof']) && ($_FILES['permanent_address_proof']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $oldPermanent = $permanentProofFile;
        $permanentProofFile = handleFileUpload($_FILES['permanent_address_proof'], $application_id);
        $permanentProofOriginalName = (string)($_FILES['permanent_address_proof']['name'] ?? $permanentProofFile);
        if ($oldPermanent !== '' && $oldPermanent !== $permanentProofFile) {
            candidate_delete_uploaded_file('/uploads/address/', $oldPermanent);
        }
    }

    if (!$is_draft) {
        if (!$hasCurrentAddress && !$hasPermanentAddress) {
            throw new ValidationException('Please provide at least one address.');
        }

        if ($hasCurrentAddress) {
            validate_postal_code((string)$country, (string)$postal_code, 'Current');
        }
        if ($hasPermanentAddress && (!$hasCurrentAddress || !$same_as_current)) {
            validate_postal_code((string)$p_country, (string)$p_postal_code, 'Permanent');
        }
    }

    if (!$is_draft && $hasCurrentAddress && !$insufficient_documents) {
        if (empty($proof_file)) {
            throw new ValidationException('Please upload current address proof.');
        }
        if (trim($proof_type) === '') {
            throw new ValidationException('Please select current address proof type.');
        }
    }

    if (!$is_draft && $hasPermanentAddress && !$same_as_current) {
        if (trim($permanentProofType) === '') {
            throw new ValidationException('Please select permanent address proof type.');
        }
        if (trim($permanentProofFile) === '') {
            throw new ValidationException('Please upload permanent address proof.');
        }
    }

    if ($same_as_current) {
        if ($permanentProofType === '') {
            $permanentProofType = $proof_type;
        }
        if ($permanentProofFile === '') {
            $permanentProofFile = (string)$proof_file;
            $permanentProofOriginalName = $currentProofOriginalName;
        }
    }


    $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_save_contact_details(
        :mobile_country_code,
        :mobile,
        :alternative_mobile,
        :email,
        :alternative_email,

        :address1,
        :address2,
        :city,
        :state,
        :country,
        :postal_code,

        :proof_type,
        :proof_file,
        :current_proof_original_name,
        :application_id,
        :same_as_current,
        :insufficient_documents,

        :p_address1,
        :p_address2,
        :p_city,
        :p_state,
        :p_country,
        :p_postal_code,
        :permanent_proof_type,
        :permanent_proof_file,
        :permanent_proof_original_name
    )");

    $stmt->execute([
        ':mobile_country_code'     => $mobile_country_code,
        ':mobile'                  => $mobile,
        ':alternative_mobile'      => $alternative_mobile,
        ':email'                   => $email,
        ':alternative_email'       => $alternative_email,

        ':address1'                => $address1,
        ':address2'                => $address2,
        ':city'                    => $city,
        ':state'                   => $state,
        ':country'                 => $country,
        ':postal_code'             => $postal_code,

        ':proof_type'              => $proof_type,
        ':proof_file'              => $proof_file,
        ':current_proof_original_name' => $currentProofOriginalName !== '' ? $currentProofOriginalName : null,
        ':application_id'          => $application_id,
        ':same_as_current'         => $same_as_current,
        ':insufficient_documents'  => $insufficient_documents,

        ':p_address1'              => $p_address1,
        ':p_address2'              => $p_address2,
        ':p_city'                  => $p_city,
        ':p_state'                 => $p_state,
        ':p_country'               => $p_country,
        ':p_postal_code'           => $p_postal_code,
        ':permanent_proof_type'    => $permanentProofType !== '' ? $permanentProofType : null,
        ':permanent_proof_file'    => $permanentProofFile !== '' ? $permanentProofFile : null,
        ':permanent_proof_original_name' => $permanentProofOriginalName !== '' ? $permanentProofOriginalName : null
    ]);

    $stmt->closeCursor();
    ccs_progress_component_after_candidate_save($pdo, (string)$application_id, 'contact', $is_draft);
    $pdo->commit();

$response = [
    'success' => true,
    'draft'   => $is_draft,
];

if (!$is_draft) {
    $response['message'] = 'Contact details saved successfully';
}

echo json_encode($response);


} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("store_contact.php ERROR: {$e->getMessage()}");

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
