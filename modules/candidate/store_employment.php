<?php
session_start();
header("Content-Type: application/json");

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

class ValidationException extends Exception {}

function validateSingleEmployment(array $data, bool $isFresher, int $index, bool $isDraft): void
{
    if ($isFresher && $index !== 1) {
        return;
    }

    if ($isDraft) {
        foreach ($data as $v) {
            if (trim((string)$v) !== '') {
                goto CONTINUE_VALIDATION;
            }
        }
        return;
    }

    CONTINUE_VALIDATION:

    $required = [
        'employer_name'    => 'Employer name',
        'job_title'        => 'Job title',
        'employee_id'      => 'Employee ID',
        'joining_date'     => 'Joining date',
        'employer_address' => 'Employer address'
    ];

    foreach ($required as $key => $label) {
        if (empty(trim($data[$key] ?? ''))) {
            throw new ValidationException("$label is required for employment record $index");
        }
    }

    if (!empty($data['joining_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['joining_date'])) {
        throw new ValidationException("Invalid joining date format for employment record $index");
    }
    
    if (!empty($data['relieving_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['relieving_date'])) {
        throw new ValidationException("Invalid relieving date format for employment record $index");
    }
}

function handleFileUpload(array $file, string $applicationId, int $index): string
{
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new ValidationException("File upload failed (error {$file['error']})");
    }

    $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        throw new ValidationException("Invalid file type for employment document");
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        throw new ValidationException("Employment document exceeds 10MB");
    }

    $dir = __DIR__ . '/../../uploads/employment/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = "emp_{$applicationId}_{$index}_" . time() . "_" . uniqid() . ".$ext";
    $path = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new ValidationException("Failed to save uploaded employment document");
    }

    return $filename;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }

    $applicationId = getApplicationId();
    if (!$applicationId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Application ID not found']);
        exit;
    }

    $pdo = getDB();
    $pdo->beginTransaction();
    $isFresher = 'no';
    $currentlyEmployed = 'no';
    $contactEmployer = 'no';
    
    if (isset($_POST['is_fresher'][0])) {
        $isFresher = $_POST['is_fresher'][0] === 'yes' ? 'yes' : 'no';
        $currentlyEmployed = $_POST['currently_employed'][0] === 'yes' ? 'yes' : 'no';
        $contactEmployer = $_POST['contact_employer'][0] === 'yes' ? 'yes' : 'no';
    }

    $isDraft = ($_POST['draft'] ?? '0') === '1';
    $insufficientDocs = $_POST['insufficient_employment_docs'] ?? [];
    if (!is_array($insufficientDocs)) {
        $insufficientDocs = [$insufficientDocs];
    }
    $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_save_employment_details(
        :application_id,
        :employment_index,
        :is_fresher,
        :currently_employed,
        :contact_employer,
        :employer_name,
        :job_title,
        :employee_id,
        :joining_date,
        :relieving_date,
        :reason_leaving,
        :employer_address,
        :hr_manager_name,
        :hr_manager_phone,
        :hr_manager_email,
        :manager_name,
        :manager_phone,
        :manager_email,
        :employment_doc,
        :insufficient_documents
    )");

    $processed = [];

    $employerNames = $_POST['employer_name'] ?? [];
    $jobTitles = $_POST['job_title'] ?? [];
    $employeeIds = $_POST['employee_id'] ?? [];
    $joiningDates = $_POST['joining_date'] ?? [];
    $relievingDates = $_POST['relieving_date'] ?? [];
    $addresses = $_POST['employer_address'] ?? [];
    $reasons = $_POST['reason_leaving'] ?? [];
    $hrNames = $_POST['hr_manager_name'] ?? [];
    $hrPhones = $_POST['hr_manager_phone'] ?? [];
    $hrEmails = $_POST['hr_manager_email'] ?? [];
    $mgrNames = $_POST['manager_name'] ?? [];
    $mgrPhones = $_POST['manager_phone'] ?? [];
    $mgrEmails = $_POST['manager_email'] ?? [];

    $count = count($employerNames);

    for ($i = 0; $i < $count; $i++) {
        $index = $i + 1;

        if ($isFresher === 'yes' && $i !== 0) {
            continue;
        }

        $data = [
            'employer_name'    => trim($employerNames[$i] ?? ''),
            'job_title'        => trim($jobTitles[$i] ?? ''),
            'employee_id'      => trim($employeeIds[$i] ?? ''),
            'joining_date'     => $joiningDates[$i] ?? '',
            'relieving_date'   => $relievingDates[$i] ?? null,
            'reason_leaving'   => trim($reasons[$i] ?? ''),
            'employer_address' => trim($addresses[$i] ?? ''),
            'hr_manager_name'  => trim($hrNames[$i] ?? ''),
            'hr_manager_phone' => trim($hrPhones[$i] ?? ''),
            'hr_manager_email' => trim($hrEmails[$i] ?? ''),
            'manager_name'     => trim($mgrNames[$i] ?? ''),
            'manager_phone'    => trim($mgrPhones[$i] ?? ''),
            'manager_email'    => trim($mgrEmails[$i] ?? ''),
        ];

        validateSingleEmployment($data, $isFresher === 'yes', $index, $isDraft);
        $isInsufficient = isset($insufficientDocs[$i]) && 
                         ($insufficientDocs[$i] === 'on' || 
                          $insufficientDocs[$i] === '1' || 
                          $insufficientDocs[$i] === 'true');

        $doc = '';
        if ($isInsufficient) {
            $doc = 'INSUFFICIENT_DOCUMENTS';
        } else {
            if (!empty($_FILES['employment_doc']['name'][$i] ?? '')) {
                $doc = handleFileUpload([
                    'name'     => $_FILES['employment_doc']['name'][$i],
                    'type'     => $_FILES['employment_doc']['type'][$i],
                    'tmp_name' => $_FILES['employment_doc']['tmp_name'][$i],
                    'error'    => $_FILES['employment_doc']['error'][$i],
                    'size'     => $_FILES['employment_doc']['size'][$i],
                ], $applicationId, $index);
            } elseif (!empty($_POST['old_employment_doc'][$i] ?? '')) {
                $oldDoc = $_POST['old_employment_doc'][$i];
                if ($oldDoc !== 'INSUFFICIENT_DOCUMENTS') {
                    $doc = $oldDoc;
                }
            }
        }

        if (!$isDraft) {
            if (!$isInsufficient && empty($doc)) {
                throw new ValidationException("Employment document is required for employment record $index");
            }
        }

        $relieving = ($currentlyEmployed === 'yes' && $index === 1) 
            ? null 
            : ($data['relieving_date'] ?: null);

        $stmt->execute([
            ':application_id'     => $applicationId,
            ':employment_index'   => $index,
            ':is_fresher'         => $index === 1 ? $isFresher : 'no',
            ':currently_employed' => $index === 1 ? $currentlyEmployed : 'no',
            ':contact_employer'   => $index === 1 ? $contactEmployer : 'no',
            ':employer_name'      => $data['employer_name'],
            ':job_title'          => $data['job_title'],
            ':employee_id'        => $data['employee_id'],
            ':joining_date'       => $data['joining_date'],
            ':relieving_date'     => $relieving,
            ':reason_leaving'     => $data['reason_leaving'] ?: null,
            ':employer_address'   => $data['employer_address'],
            ':hr_manager_name'    => $data['hr_manager_name'] ?: null,
            ':hr_manager_phone'   => $data['hr_manager_phone'] ?: null,
            ':hr_manager_email'   => $data['hr_manager_email'] ?: null,
            ':manager_name'       => $data['manager_name'] ?: null,
            ':manager_phone'      => $data['manager_phone'] ?: null,
            ':manager_email'      => $data['manager_email'] ?: null,
            ':employment_doc'     => $doc ?: null,
            ':insufficient_documents' => $isInsufficient ? 1 : 0  
        ]);

        $processed[] = $index;
    }

    if (!$isDraft && !empty($processed)) {
        $placeholders = implode(',', array_fill(0, count($processed), '?'));
        $deleteStmt = $pdo->prepare(
            "DELETE FROM Vati_Payfiller_Candidate_Employment_details
             WHERE application_id = ?
             AND employment_index NOT IN ($placeholders)"
        );
        $params = array_merge([$applicationId], $processed);
        $deleteStmt->execute($params);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $isDraft ? 'Draft saved successfully' : 'Employment details saved successfully'
    ]);

} catch (ValidationException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("store_employment.php ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
