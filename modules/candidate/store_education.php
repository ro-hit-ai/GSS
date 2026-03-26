<?php
header("Content-Type: application/json");
session_start();

require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../config/db.php";

class ValidationException extends Exception {}

function validateSingle($data) {
    $required = ['qualification', 'college_name', 'university_board', 'roll_number', 'college_address', 'year_from', 'year_to'];
    foreach ($required as $field) {
        if (empty(trim($data[$field] ?? ''))) {
            throw new ValidationException(ucwords(str_replace('_', ' ', $field)) . " is required");
        }
    }

    $education_index = (int)($data['education_index'] ?? 0);
    if ($education_index <= 0) {
        throw new ValidationException("Invalid education order");
    }


    if (!preg_match('/^\d{4}-\d{2}$/', $data['year_from'])) {
        throw new ValidationException("Invalid from year");
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $data['year_to'])) {
        throw new ValidationException("Invalid to year");
    }

    $from = (int)substr($data['year_from'], 0, 4);
    $to = (int)substr($data['year_to'], 0, 4);
    if ($to < $from) {
        throw new ValidationException("To year cannot be before from year");
    }
}

function handleFile($fileArray, $application_id, $index, $type) {
    if ($fileArray['error'][$index] === UPLOAD_ERR_NO_FILE) return null;

    if ($fileArray['error'][$index] !== UPLOAD_ERR_OK) {
        throw new ValidationException("File upload error for $type (card $index)");
    }

    $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    $ext = strtolower(pathinfo($fileArray['name'][$index], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        throw new ValidationException("Invalid file type for $type. Only PDF, JPG, JPEG, PNG allowed");
    }

    if ($fileArray['size'][$index] > 10 * 1024 * 1024) {
        throw new ValidationException("File too large (max 10MB) for $type");
    }

    $dir = __DIR__ . "/../../uploads/education/";
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = $application_id . "_" . time() . "_" . uniqid() . "." . $ext;
    $path = $dir . $filename;

    if (!move_uploaded_file($fileArray['tmp_name'][$index], $path)) {
        throw new ValidationException("Failed to save $type file");
    }

    return $filename;
}

function getEducationDetails($pdo, $application_id) {
    $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_education_details(?)");
    $stmt->execute([$application_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return $results;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error_log("ERROR: Invalid method - " . $_SERVER['REQUEST_METHOD']);
        throw new ValidationException("Invalid method. Expected POST");
    }

    $pdo = getDB();
    $pdo->beginTransaction();

    $application_id = $_SESSION['application_id'] ?? null;
    if (!$application_id) {
        throw new ValidationException("Session expired. Please log in again.");
    }

    error_log("Application ID: " . $application_id);

    $qualCount = count($_POST['qualification'] ?? []);
    if ($qualCount === 0) {
        throw new ValidationException("No education data received");
    }

    error_log("Processing $qualCount education records");

$stmt = $pdo->prepare("CALL SP_Vati_Payfiller_save_education_details(
    :application_id,
    :education_index,
    :qualification,
    :college_name,
    :university_board,
    :year_from,
    :year_to,
    :roll_number,
    :college_website,
    :college_address,
    :marksheet_file,
    :degree_file,
    :insufficient_documents  -- ADD THIS
)");


    for ($i = 0; $i < $qualCount; $i++) {
        $insufficientDocs = $_POST['insufficient_education_docs'] ?? [];
if (!is_array($insufficientDocs)) {
    $insufficientDocs = [$insufficientDocs];
}


$isInsufficient = isset($insufficientDocs[$i]) && 
                 ($insufficientDocs[$i] === 'on' || 
                  $insufficientDocs[$i] === '1' || 
                  $insufficientDocs[$i] === 'true');
        $data = [
            'education_index' => $_POST['education_index'][$i] ?? '',
            'qualification' => trim($_POST['qualification'][$i] ?? ''),
            'college_name' => trim($_POST['college_name'][$i] ?? ''),
            'university_board' => trim($_POST['university_board'][$i] ?? ''),
            'year_from' => $_POST['year_from'][$i] ?? '',
            'year_to' => $_POST['year_to'][$i] ?? '',
            'roll_number' => trim($_POST['roll_number'][$i] ?? ''),
            'college_website' => trim($_POST['college_website'][$i] ?? ''),
            'college_address' => trim($_POST['college_address'][$i] ?? ''),
        ];

        error_log("Processing education card $i: " . json_encode($data));

        if (
            empty($data['qualification']) &&
            empty($data['college_name']) &&
            empty($data['university_board']) &&
            empty($data['year_from']) &&
            empty($data['year_to']) &&
            empty($data['roll_number']) &&
            empty($data['college_address'])
        ) {
            error_log("Skipping empty card $i");
            continue;
        }

        validateSingle($data);


        $year_from = $data['year_from'] ? $data['year_from'] . '-01' : null;
        $year_to = $data['year_to'] ? $data['year_to'] . '-01' : null;

 
    $marksheet_file = null;
$degree_file = null;


$isInsufficient = isset($insufficientDocs[$i]) && 
                 ($insufficientDocs[$i] === 'on' || 
                  $insufficientDocs[$i] === '1' || 
                  $insufficientDocs[$i] === 'true');

$marksheet_file = null;
$degree_file = null;

$old_marksheet = $_POST['old_marksheet_file'][$i] ?? '';
$old_degree    = $_POST['old_degree_file'][$i] ?? '';

if ($isInsufficient) {

    // ❗ Only mark insufficient if NO old files exist
    if (
        empty($old_marksheet) &&
        empty($old_degree)
    ) {
        $marksheet_file = 'INSUFFICIENT_DOCUMENTS';
        $degree_file = 'INSUFFICIENT_DOCUMENTS';
        error_log("Education card $i marked as INSUFFICIENT_DOCUMENTS");
    } else {
        // Preserve existing documents
        $marksheet_file = $old_marksheet ?: null;
        $degree_file = $old_degree ?: null;
        error_log("Education card $i has existing documents, not marking insufficient");
    }

} else {
    // Normal upload / fallback logic
    if (!empty($_FILES['marksheet_file']['name'][$i])) {
        $marksheet_file = handleFile($_FILES['marksheet_file'], $application_id, $i, 'marksheet');
    } elseif (!empty($old_marksheet)) {
        $marksheet_file = $old_marksheet;
    }

    if (!empty($_FILES['degree_file']['name'][$i])) {
        $degree_file = handleFile($_FILES['degree_file'], $application_id, $i, 'degree');
    } elseif (!empty($old_degree)) {
        $degree_file = $old_degree;
    }
}

      
$stmt->execute([
    ':application_id'   => $application_id,
    ':education_index'  => (int)$data['education_index'],
    ':qualification'    => $data['qualification'],
    ':college_name'     => $data['college_name'] ?: null,
    ':university_board' => $data['university_board'] ?: null,
    ':year_from'        => $year_from,
    ':year_to'          => $year_to,
    ':roll_number'      => $data['roll_number'] ?: null,
    ':college_website'  => $data['college_website'] ?: null,
    ':college_address'  => $data['college_address'],
    ':marksheet_file'   => $marksheet_file,
    ':degree_file'      => $degree_file,
    ':insufficient_documents' => $isInsufficient ? 1 : 0 
]);

        do {
            if ($stmt->rowCount() > 0) {
                $stmt->fetchAll();
            }
        } while ($stmt->nextRowset());
        $stmt->closeCursor();
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Education details saved successfully',
        'data' => getEducationDetails($pdo, $application_id)
    ]);

} catch (ValidationException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Validation error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("store_education.php error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
