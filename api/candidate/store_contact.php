<?php
header("Content-Type: application/json");
session_start();

require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../config/db.php";

/* ================= EXCEPTIONS ================= */
class ValidationException extends Exception {}
class FileUploadException extends Exception {}

/* ================= HELPERS ================= */

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

    // Others: basic sanity check (3-12, alnum + space + hyphen)
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

    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        throw new ValidationException("Invalid file type. Allowed: JPG, PNG, PDF");
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new ValidationException("File size exceeds 5MB.");
    }

    $dir = rtrim(app_path('/uploads/address/'), '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = "address_{$application_id}_" . time() . "_" . uniqid() . "." . $ext;

    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        throw new FileUploadException("Failed to save uploaded file.");
    }

    return $filename;
}

/* ================= MAIN ================= */

try {
    if (empty($_SESSION['application_id'])) {
        throw new ValidationException("Session expired. Please login again.");
    }

    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    $application_id = $_SESSION['application_id'];
    $post = array_map('trim', $_POST);

    /* ================= FLAGS ================= */

    $same_as_current        = isset($post['same_as_current']) && (string)$post['same_as_current'] === '1' ? 1 : 0;
    $insufficient_documents = !empty($post['insufficient_address_proof']) ? 1 : 0;
    $is_draft               = !empty($post['save_draft']);
    $hasCurrentAddress      = isset($post['has_current_address']) && (string)$post['has_current_address'] === '1';
    $hasPermanentAddress    = isset($post['has_permanent_address']) && (string)$post['has_permanent_address'] === '1';

    // Backward compatibility: older forms may not send these flags.
    if (!$hasCurrentAddress && !$hasPermanentAddress) {
        $hasCurrentAddress = true;
        $hasPermanentAddress = true;
    }

    /* ================= CONTACT ================= */

    $mobile_country_code = '';
    $mobile              = '';
    $alternative_mobile  = '';
    $email               = '';
    $alternative_email   = '';

    /* ================= CURRENT ADDRESS ================= */

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

    /* ================= PERMANENT ADDRESS ================= */

    if ($hasCurrentAddress && !$hasPermanentAddress) {
        $same_as_current = 1;
        $p_address1    = $address1;
        $p_address2    = $address2;
        $p_city        = $city;
        $p_state       = $state;
        $p_country     = $country;
        $p_postal_code = $postal_code;
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
        $p_address1    = $address1;
        $p_address2    = $address2;
        $p_city        = $city;
        $p_state       = $state;
        $p_country     = $country;
        $p_postal_code = $postal_code;
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

    /* ================= PROOF ================= */

    $proof_type = $post['proof_type'] ?? '';
    $proof_file = null;

    if (!$insufficient_documents) {
        $proofField = null;
        if (!empty($_FILES['address_proof_file'])) {
            $proofField = 'address_proof_file';
        } elseif (!empty($_FILES['current_address_proof'])) {
            $proofField = 'current_address_proof';
        }

        if ($proofField && isset($_FILES[$proofField]['error']) && $_FILES[$proofField]['error'] !== UPLOAD_ERR_NO_FILE && $_FILES[$proofField]['error'] !== UPLOAD_ERR_OK) {
            throw new FileUploadException("File upload failed.");
        }

        if ($proofField && $_FILES[$proofField]['error'] === UPLOAD_ERR_OK) {
            $proof_file = handleFileUpload($_FILES[$proofField], $application_id);

        } else {
            // keep existing file (from form or DB)
            $proof_file = trim((string)($post['existing_current_address_proof'] ?? ''));
            if ($proof_file === '') {
                $stmt = $pdo->prepare(
                    "SELECT proof_file FROM Vati_Payfiller_Candidate_Contact_details WHERE application_id = ?"
                );
                $stmt->execute([$application_id]);
                $proof_file = (string)$stmt->fetchColumn();
                $stmt->closeCursor();
            }
        }
    }

    // Postal code format validation (final submit)
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

    // Current proof required for final submit when current address is enabled.
    if (!$is_draft && $hasCurrentAddress && !$insufficient_documents) {
        if (empty($proof_file)) {
            throw new ValidationException('Please upload current address proof.');
        }
    }

    /* ================= SAVE ================= */

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
        :application_id,
        :same_as_current,
        :insufficient_documents,

        :p_address1,
        :p_address2,
        :p_city,
        :p_state,
        :p_country,
        :p_postal_code
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
        ':application_id'          => $application_id,
        ':same_as_current'         => $same_as_current,
        ':insufficient_documents'  => $insufficient_documents,

        ':p_address1'              => $p_address1,
        ':p_address2'              => $p_address2,
        ':p_city'                  => $p_city,
        ':p_state'                 => $p_state,
        ':p_country'               => $p_country,
        ':p_postal_code'           => $p_postal_code
    ]);

    $stmt->closeCursor();
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
