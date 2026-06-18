<?php
session_start();
header("Content-Type: application/json");

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../shared/corrections/candidate_correction_service.php';
require_once __DIR__ . '/../../services/candidate/EmploymentService.php';

class ValidationException extends Exception {}

function normalizeInputDate(?string $raw): ?string
{
    $v = trim((string)$raw);
    if ($v === '') return null;

    // Already ISO
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
        return $v;
    }

    // dd/mm/yyyy or dd-mm-yyyy
    if (preg_match('/^(\d{2})[\/-](\d{2})[\/-](\d{4})$/', $v, $m) === 1) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        return $v;
    }

    // dd/mm/yy or dd-mm-yy -> map to 20yy
    if (preg_match('/^(\d{2})[\/-](\d{2})[\/-](\d{2})$/', $v, $m) === 1) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $yy = (int)$m[3];
        $y = 2000 + $yy;
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        return $v;
    }

    return $v;
}

function validateSingleEmployment(array $data, bool $isFresher, int $index, bool $isDraft): void
{
    if ($isFresher && $index !== 1) {
        return;
    }

    if ($isDraft) {
        foreach ($data as $value) {
            if (trim((string)$value) !== '') {
                goto CONTINUE_VALIDATION;
            }
        }
        return;
    }

    CONTINUE_VALIDATION:

    $required = [
        'employer_name'  => 'Employer name',
        'job_title'      => 'Job title',
        'employee_id'    => 'Employee ID',
        'joining_date'   => 'Joining date',
        'reason_leaving' => 'Reason for leaving'
    ];

    foreach ($required as $key => $label) {
        if (empty(trim($data[$key] ?? ''))) {
            throw new ValidationException("$label is required for employment record $index");
        }
    }

    if (!empty($data['joining_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['joining_date'])) {
        throw new ValidationException("Invalid joining date format for employment record $index");
    }
    if (!empty($data['joining_date'])) {
        $joiningTs = strtotime((string)$data['joining_date']);
        if (!$joiningTs || $joiningTs > strtotime('today')) {
            throw new ValidationException("Joining date cannot be in the future for employment record $index");
        }
    }

    $status = (string)($data['employment_status'] ?? '');
    $currentLike = in_array($status, ['currently_employed', 'serving_notice'], true);

    if (!$currentLike && !empty($data['relieving_date'])) {
        $isIsoDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['relieving_date']) === 1;
        if (!$isIsoDate) {
            throw new ValidationException("Invalid relieving date format for employment record $index");
        }
        $relievingTs = strtotime((string)$data['relieving_date']);
        if (!$relievingTs || $relievingTs > strtotime('today')) {
            throw new ValidationException("End date cannot be in the future for employment record $index");
        }
    }

    if ($currentLike && !empty($data['tentative_relieving_date'])) {
        $tentative = strtotime((string)$data['tentative_relieving_date']);
        if (!$tentative || $tentative <= strtotime('today')) {
            throw new ValidationException("Tentative relieving date must be a future date for employment record $index");
        }
    }

}

function getAllowedEmploymentDocTypes(string $employmentStatus): array
{
    return match ($employmentStatus) {
        'currently_employed' => ['offer_letter', 'payslips', 'appointment_letter'],
        'serving_notice' => ['resignation_acceptance', 'latest_payslip', 'payslips'],
        'resigned' => ['relieving_letter', 'experience_letter', 'service_letter', 'appointment_letter'],
        default => ['experience_letter', 'service_letter', 'appointment_letter', 'contract_letter'],
    };
}

function validateEmploymentTimeline(array $rows): void
{
    if (count($rows) < 2) {
        return;
    }

    for ($i = 0; $i < count($rows) - 1; $i++) {
        $newer = $rows[$i];
        $older = $rows[$i + 1];

        if (empty($newer['joining_date']) || empty($older['relieving_date'])) {
            continue;
        }

        $newerStart = strtotime((string)$newer['joining_date']);
        $olderEnd = strtotime((string)$older['relieving_date']);
        if (!$newerStart || !$olderEnd) {
            continue;
        }

        if ($olderEnd > $newerStart) {
            throw new ValidationException(
                "Employment records must be in newest-to-oldest order. Employer {$older['index']} overlaps with Employer {$newer['index']} or is out of order."
            );
        }

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

    if (!in_array($ext, $allowed, true)) {
        throw new ValidationException('Invalid file type for employment document');
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new ValidationException('Employment document exceeds 5MB');
    }

    $dir = __DIR__ . '/../../uploads/employment/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = "emp_{$applicationId}_{$index}_" . time() . '_' . uniqid() . ".{$ext}";
    $path = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new ValidationException('Failed to save uploaded employment document');
    }

    return $filename;
}

function isEmploymentRowEmpty(array $data, ?array $file): bool
{
    foreach ($data as $value) {
        if (trim((string)$value) !== '') {
            return false;
        }
    }

    if ($file && !empty($file['name'])) {
        return false;
    }

    return true;
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
    ccs_guard_correction_component_or_json('employment');

    $pdo = getDB();
    $pdo->beginTransaction();

    $isFresher = 'no';
    $currentlyEmployed = 'no';
    $contactEmployer = 'no';

    if (isset($_POST['is_fresher'][0])) {
        $isFresher = ($_POST['is_fresher'][0] ?? 'no') === 'yes' ? 'yes' : 'no';
        $currentlyEmployed = ($_POST['currently_employed'][0] ?? 'no') === 'yes' ? 'yes' : 'no';
        $contactEmployer = ($_POST['contact_employer'][0] ?? 'no') === 'yes' ? 'yes' : 'no';
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
        :job_location,
        :reason_leaving,
        :hr_manager_name,
        :hr_manager_phone,
        :hr_manager_email,
        :manager_name,
        :manager_phone,
        :manager_email,
        :employment_doc,
        :employment_doc_type,
        :insufficient_documents
    )");

    $processed = [];
    $timelineRows = [];

    $employerNames = $_POST['employer_name'] ?? [];
    $jobTitles = $_POST['job_title'] ?? [];
    $employeeIds = $_POST['employee_id'] ?? [];
    $joiningDates = $_POST['joining_date'] ?? [];
    $relievingDates = $_POST['relieving_date'] ?? [];
    $jobLocations = $_POST['job_location'] ?? [];
    $employmentStatuses = $_POST['employment_status'] ?? [];
    $tentativeRelievingDates = $_POST['tentative_relieving_date'] ?? [];
    $tentativeRelievingNotes = $_POST['tentative_relieving_note'] ?? [];
    $gapReasons = $_POST['gap_reason'] ?? [];
    $gapExplanations = $_POST['gap_explanation'] ?? [];
    $overlapExplanations = $_POST['overlap_explanation'] ?? [];
    $documentTypes = $_POST['employment_doc_type'] ?? [];
    $reasons = $_POST['reason_leaving'] ?? [];
    $hrNames = $_POST['hr_manager_name'] ?? [];
    $hrPhones = $_POST['hr_manager_phone'] ?? [];
    $hrEmails = $_POST['hr_manager_email'] ?? [];
    $mgrNames = $_POST['manager_name'] ?? [];
    $mgrPhones = $_POST['manager_phone'] ?? [];
    $mgrEmails = $_POST['manager_email'] ?? [];
    $uploadNames = $_FILES['employment_doc']['name'] ?? [];

    $count = max(
        count($employerNames),
        count($jobTitles),
        count($employeeIds),
        count($joiningDates),
        count($relievingDates),
        count($jobLocations),
        count($employmentStatuses),
        count($documentTypes),
        count($reasons),
        count($hrNames),
        count($mgrNames),
        count($uploadNames)
    );

    $visibleEmploymentCount = (int)($_POST['visibleEmploymentCount'] ?? $count);
    if ($visibleEmploymentCount > 0) {
        $count = min($count, $visibleEmploymentCount);
    }

    error_log('[Employment date debug] received ' . json_encode([
        'application_id' => $applicationId,
        'visibleEmploymentCount' => $visibleEmploymentCount,
        'joining_date' => $joiningDates,
        'relieving_date' => $relievingDates,
        'employment_status' => $employmentStatuses,
    ]));

    for ($i = 0; $i < $count; $i++) {
        $index = $i + 1;

        if ($isFresher === 'yes' && $i !== 0) {
            continue;
        }

        $data = [
            'employer_name'      => trim($employerNames[$i] ?? ''),
            'job_title'          => trim($jobTitles[$i] ?? ''),
            'employee_id'        => trim($employeeIds[$i] ?? ''),
            'joining_date'       => normalizeInputDate($joiningDates[$i] ?? ''),
            'relieving_date'     => normalizeInputDate($relievingDates[$i] ?? null),
            'job_location'       => trim($jobLocations[$i] ?? ''),
            'employment_status'  => trim($employmentStatuses[$i] ?? ''),
            'tentative_relieving_date' => normalizeInputDate($tentativeRelievingDates[$i] ?? null),
            'tentative_relieving_note' => trim($tentativeRelievingNotes[$i] ?? ''),
            'gap_reason'         => trim($gapReasons[$i] ?? ''),
            'gap_explanation'    => trim($gapExplanations[$i] ?? ''),
            'overlap_explanation'=> trim($overlapExplanations[$i] ?? ''),
            'employment_doc_type'=> trim($documentTypes[$i] ?? ''),
            'reason_leaving'     => trim($reasons[$i] ?? ''),
            'hr_manager_name'    => trim($hrNames[$i] ?? ''),
            'hr_manager_phone'   => trim($hrPhones[$i] ?? ''),
            'hr_manager_email'   => trim($hrEmails[$i] ?? ''),
            'manager_name'       => trim($mgrNames[$i] ?? ''),
            'manager_phone'      => trim($mgrPhones[$i] ?? ''),
            'manager_email'      => trim($mgrEmails[$i] ?? ''),
        ];

        $fileMeta = null;
        if (!empty($_FILES['employment_doc']['name'][$i] ?? '')) {
            $fileMeta = [
                'name'     => $_FILES['employment_doc']['name'][$i],
                'type'     => $_FILES['employment_doc']['type'][$i],
                'tmp_name' => $_FILES['employment_doc']['tmp_name'][$i],
                'error'    => $_FILES['employment_doc']['error'][$i],
                'size'     => $_FILES['employment_doc']['size'][$i],
            ];
        }

        if (isEmploymentRowEmpty($data, $fileMeta)) {
            continue;
        }

        $employmentStatus = ($index === 1 && $currentlyEmployed === 'yes')
            ? 'currently_employed'
            : ($data['employment_status'] ?: 'resigned');
        $data['employment_status'] = $employmentStatus;
        validateSingleEmployment($data, $isFresher === 'yes', $index, $isDraft);

        $isCurrentlyEmployedForRow = in_array($employmentStatus, ['currently_employed', 'serving_notice'], true);
        if (!$isDraft && !$isCurrentlyEmployedForRow && empty($data['relieving_date'])) {
            throw new ValidationException("Relieving date is required for employment record $index");
        }

        $isInsufficient = isset($insufficientDocs[$i]) && in_array((string)$insufficientDocs[$i], ['on', '1', 'true'], true);

        if (!$isDraft && !$isInsufficient) {
            $allowedDocTypes = getAllowedEmploymentDocTypes($employmentStatus);
            $selectedDocType = $data['employment_doc_type'];

            if ($selectedDocType === '') {
                throw new ValidationException("Employment document type is required for employment record $index");
            }

            if (!in_array($selectedDocType, $allowedDocTypes, true)) {
                throw new ValidationException("Invalid employment document type for employment record $index");
            }
        }

        $doc = '';
        if ($isInsufficient) {
            $doc = 'INSUFFICIENT_DOCUMENTS';
        } else {
            if ($fileMeta) {
                $doc = handleFileUpload($fileMeta, $applicationId, $index);
            } elseif (!empty($_POST['old_employment_doc'][$i] ?? '')) {
                $oldDoc = $_POST['old_employment_doc'][$i];
                if ($oldDoc !== 'INSUFFICIENT_DOCUMENTS') {
                    $doc = $oldDoc;
                }
            }
        }

        if (!$isDraft && !$isInsufficient && empty($doc)) {
            throw new ValidationException("Employment document is required for employment record $index");
        }

        $relieving = $isCurrentlyEmployedForRow ? null : ($data['relieving_date'] ?: null);
        $tentativeRelieving = $isCurrentlyEmployedForRow ? ($data['tentative_relieving_date'] ?: null) : null;

        error_log('[Employment date debug] db-write ' . json_encode([
            'application_id' => $applicationId,
            'index' => $index,
            'joining_date' => $data['joining_date'],
            'relieving_date' => $relieving,
            'employment_status' => $employmentStatus,
            'currently_employed_for_row' => $isCurrentlyEmployedForRow,
        ]));

        $stmt->execute([
            ':application_id'         => $applicationId,
            ':employment_index'       => $index,
            ':is_fresher'             => $index === 1 ? $isFresher : 'no',
            ':currently_employed'     => $index === 1 ? $currentlyEmployed : 'no',
            ':contact_employer'       => $index === 1 ? $contactEmployer : 'no',
            ':employer_name'          => $data['employer_name'],
            ':job_title'              => $data['job_title'],
            ':employee_id'            => $data['employee_id'],
            ':joining_date'           => $data['joining_date'],
            ':relieving_date'         => $relieving,
            ':job_location'           => $data['job_location'] ?: null,
            ':reason_leaving'         => $data['reason_leaving'] ?: null,
            ':hr_manager_name'        => $data['hr_manager_name'] ?: null,
            ':hr_manager_phone'       => $data['hr_manager_phone'] ?: null,
            ':hr_manager_email'       => $data['hr_manager_email'] ?: null,
            ':manager_name'           => $data['manager_name'] ?: null,
            ':manager_phone'          => $data['manager_phone'] ?: null,
            ':manager_email'          => $data['manager_email'] ?: null,
            ':employment_doc'         => $doc ?: null,
            ':employment_doc_type'    => ($isInsufficient ? null : ($data['employment_doc_type'] ?: null)),
            ':insufficient_documents' => $isInsufficient ? 1 : 0
        ]);
        $stmt->closeCursor();

        EmploymentService::saveExtra($pdo, (string)$applicationId, $index, [
            'employment_status' => $employmentStatus,
            'tentative_relieving_date' => $tentativeRelieving,
            'tentative_relieving_note' => $data['tentative_relieving_note'] ?: null,
            'gap_reason' => $data['gap_reason'] ?: null,
            'gap_explanation' => $data['gap_explanation'] ?: null,
            'overlap_explanation' => $data['overlap_explanation'] ?: null,
        ]);

        $processed[] = $index;
        $timelineRows[] = [
            'index' => $index,
            'joining_date' => $data['joining_date'],
            'relieving_date' => $relieving,
            'gap_reason' => $data['gap_reason'],
            'overlap_explanation' => $data['overlap_explanation'],
        ];
    }

    if (!$isDraft) {
        validateEmploymentTimeline($timelineRows);
    }

    if (!$isDraft && !empty($processed)) {
        EmploymentService::cleanupRows($pdo, (string)$applicationId, $processed);
    }

    ccs_progress_component_after_candidate_save($pdo, (string)$applicationId, 'employment', $isDraft);
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
    error_log('store_employment.php ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
