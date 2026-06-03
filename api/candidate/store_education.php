<?php
header("Content-Type: application/json");
session_start();

require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../shared/candidate_correction_service.php";
require_once __DIR__ . "/../../services/candidate/EducationService.php";

class ValidationException extends Exception {}

function candidate_upload_dir(string $relative): string {
    $dir = rtrim(app_path($relative), '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function candidate_handle_upload(array $file, string $relativeDir, string $prefix, array $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'], int $maxSize = 5242880): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed.');
    }
    $originalName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Invalid file type uploaded.');
    }
    if ((int)($file['size'] ?? 0) > $maxSize) {
        throw new RuntimeException('Uploaded file exceeds the allowed size.');
    }
    $dir = candidate_upload_dir($relativeDir);
    $storedName = $prefix . '_' . time() . '_' . uniqid('', true) . '.' . $ext;
    $target = $dir . $storedName;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
        throw new RuntimeException('Failed to store uploaded file.');
    }
    return ['file_name' => $storedName, 'original_name' => $originalName];
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

function candidate_replace_education_documents(PDO $pdo, string $applicationId, int $educationIndex, array $documents): void {
    $existingDocuments = EducationService::replaceDocuments($pdo, $applicationId, $educationIndex, $documents);
    $kept = [];
    foreach ($documents as $doc) {
        $fileName = trim((string)($doc['file_name'] ?? ''));
        if ($fileName !== '') {
            $kept[$fileName] = true;
        }
    }

    foreach ($existingDocuments as $doc) {
        $oldFile = trim((string)($doc['file_name'] ?? ''));
        if ($oldFile !== '' && !isset($kept[$oldFile])) {
            candidate_delete_uploaded_file('/uploads/education/', $oldFile);
        }
    }
}

function normalizeQualificationRule($qualification) {
    $normalized = trim((string)$qualification);

    $rules = [
        '10th' => ['require_marksheet' => true, 'require_degree' => false],
        '12th' => ['require_marksheet' => true, 'require_degree' => false],
        'SSLC' => ['require_marksheet' => true, 'require_degree' => false],
        'PUC' => ['require_marksheet' => true, 'require_degree' => false],
        'Diploma' => ['require_marksheet' => true, 'require_degree' => true],
        'UG' => ['require_marksheet' => true, 'require_degree' => true],
        'PG' => ['require_marksheet' => true, 'require_degree' => true],
        'PhD' => ['require_marksheet' => false, 'require_degree' => true],
        'International' => ['require_marksheet' => true, 'require_degree' => true],
    ];

    return $rules[$normalized] ?? ['require_marksheet' => false, 'require_degree' => false];
}

function validateSingle($data) {
    $allowedQualifications = ['10th', '12th', 'SSLC', 'PUC', 'Diploma', 'UG', 'PG', 'PhD', 'International'];
    $required = ['qualification', 'college_name', 'university_board', 'roll_number', 'college_address', 'year_from', 'year_to'];
    $fieldLabels = [
        'college_address' => 'Place of the Institution',
    ];
    foreach ($required as $field) {
        if (empty(trim($data[$field] ?? ''))) {
            $label = $fieldLabels[$field] ?? ucwords(str_replace('_', ' ', $field));
            throw new ValidationException($label . ' is required');
        }
    }

    if (!in_array($data['qualification'], $allowedQualifications, true)) {
        throw new ValidationException('Invalid qualification selected');
    }

    $education_index = (int)($data['education_index'] ?? 0);
    if ($education_index <= 0) {
        throw new ValidationException('Invalid education order');
    }

    if (!preg_match('/^\d{4}-\d{2}$/', $data['year_from'])) {
        throw new ValidationException('Invalid from year');
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $data['year_to'])) {
        throw new ValidationException('Invalid to year');
    }

    $from = (int)substr($data['year_from'], 0, 4);
    $to = (int)substr($data['year_to'], 0, 4);
    $currentYear = (int)date('Y');
    $currentMonth = date('Y-m');
    if ($to < $from) {
        throw new ValidationException('To year cannot be before from year');
    }
    if ($from > $currentYear || $to > $currentYear || $data['year_from'] > $currentMonth || $data['year_to'] > $currentMonth) {
        throw new ValidationException('Education dates cannot be in the future');
    }

    $passingYearRaw = trim((string)($data['year_of_passing'] ?? ''));
    if ($passingYearRaw !== '') {
        if (!preg_match('/\b(19|20)\d{2}\b/', $passingYearRaw, $match)) {
            throw new ValidationException('Invalid year of passing');
        }
        $passingYear = (int)$match[0];
        if ($passingYear > $currentYear) {
            throw new ValidationException('Year of passing cannot be in the future');
        }
        if ($passingYear !== $to) {
            throw new ValidationException('Year of passing must match To Year');
        }
    }
}

function educationQualificationRank(string $qualification): int {
    $ranks = [
        'PhD' => 9,
        'PG' => 8,
        'UG' => 7,
        'International' => 7,
        'Diploma' => 6,
        'PUC' => 5,
        '12th' => 5,
        'SSLC' => 4,
        '10th' => 4,
    ];
    return $ranks[$qualification] ?? 0;
}

function educationMonthTimestamp(?string $month): ?int {
    $month = trim((string)$month);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return null;
    }
    $ts = strtotime($month . '-01 00:00:00');
    return $ts ?: null;
}

function validateEducationTimeline(array $rows): void {
    $rows = array_values($rows);

    for ($i = 0; $i < count($rows) - 1; $i++) {
        $higher = $rows[$i];
        $lower = $rows[$i + 1];
        $higherLabel = 'Education ' . ((int)($higher['display_index'] ?? ($i + 1)));
        $lowerLabel = 'Education ' . ((int)($lower['display_index'] ?? ($i + 2)));

        $higherRank = educationQualificationRank((string)($higher['qualification'] ?? ''));
        $lowerRank = educationQualificationRank((string)($lower['qualification'] ?? ''));
        $higherStart = educationMonthTimestamp($higher['year_from'] ?? '');
        $lowerEnd = educationMonthTimestamp($lower['year_to'] ?? '');

        if ($higherRank > 0 && $lowerRank > 0 && $higherRank < $lowerRank) {
            error_log("$higherLabel should be higher than $lowerLabel. Showing education order warning only.");
        }

        if ($higherStart && $lowerEnd && $lowerEnd > $higherStart) {
            error_log("$lowerLabel overlaps or ends after $higherLabel starts. Showing education date warning only.");
        }
    }
}

function handleFile($fileArray, $application_id, $index, $type) {
    if (($fileArray['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;

    if (($fileArray['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new ValidationException("File upload error for $type (card $index)");
    }

    $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    $ext = strtolower(pathinfo($fileArray['name'][$index], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new ValidationException("Invalid file type for $type. Only PDF, JPG, JPEG, PNG allowed");
    }

    if (($fileArray['size'][$index] ?? 0) > 5 * 1024 * 1024) {
        throw new ValidationException("File too large (max 5MB) for $type");
    }

    $dir = __DIR__ . '/../../uploads/education/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = $application_id . '_' . time() . '_' . uniqid() . '.' . $ext;
    $path = $dir . $filename;

    if (!move_uploaded_file($fileArray['tmp_name'][$index], $path)) {
        throw new ValidationException("Failed to save $type file");
    }

    return $filename;
}

function getEducationDetails($pdo, $application_id) {
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_get_education_details(?)');
    $stmt->execute([$application_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return $results;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error_log('ERROR: Invalid method - ' . $_SERVER['REQUEST_METHOD']);
        throw new ValidationException('Invalid method. Expected POST');
    }

    $pdo = getDB();
    $pdo->beginTransaction();

    $application_id = $_SESSION['application_id'] ?? null;
    if (!$application_id) {
        throw new ValidationException('Session expired. Please log in again.');
    }

    $qualCount = count($_POST['qualification'] ?? []);
    if ($qualCount === 0) {
        throw new ValidationException('No education data received');
    }

    $visibleEducationCount = (int)($_POST['visibleEducationCount'] ?? $qualCount);
    if ($visibleEducationCount < 1) {
        $visibleEducationCount = 1;
    }
    $processCount = min($qualCount, $visibleEducationCount);

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
        :insufficient_documents
    )");

    $isDraft = ($_POST['draft'] ?? '0') === '1';
    $processed = [];
    $timelineRows = [];

    for ($i = 0; $i < $processCount; $i++) {
        $insufficientDocs = $_POST['insufficient_education_docs'] ?? [];
        if (!is_array($insufficientDocs)) {
            $insufficientDocs = [$insufficientDocs];
        }

        $data = [
            'education_index' => $_POST['education_index'][$i] ?? '',
            'qualification' => trim($_POST['qualification'][$i] ?? ''),
            'college_name' => trim($_POST['college_name'][$i] ?? ''),
            'university_board' => trim($_POST['university_board'][$i] ?? ''),
            'year_from' => $_POST['year_from'][$i] ?? '',
            'year_to' => $_POST['year_to'][$i] ?? '',
            'roll_number' => trim($_POST['roll_number'][$i] ?? ''),
            'college_website' => trim($_POST['college_website'][$i] ?? ''),
            'college_address' => trim($_POST['college_address'][$i] ?? ($_POST['place_of_institution'][$i] ?? '')),
            'ca_membership_number' => trim($_POST['ca_membership_number'][$i] ?? ($_POST['ca_membership_no'][$i] ?? '')),
            'year_of_passing' => trim($_POST['year_of_passing'][$i] ?? ''),
            'education_gap_reason' => trim($_POST['education_gap_reason'][$i] ?? ''),
            'education_gap_explanation' => trim($_POST['education_gap_explanation'][$i] ?? ''),
            'education_order_explanation' => trim($_POST['education_order_explanation'][$i] ?? ''),
            'institution_id' => trim($_POST['institution_id'][$i] ?? ''),
            'institution_display_name' => trim($_POST['institution_display_name'][$i] ?? ''),
            'manual_institution_name' => trim($_POST['manual_institution_name'][$i] ?? ''),
            'institution_match_status' => trim($_POST['institution_match_status'][$i] ?? ''),
            'display_index' => $i + 1,
        ];

        if ($data['institution_display_name'] === '') {
            $data['institution_display_name'] = $data['college_name'];
        }
        if ($data['institution_id'] === '' && $data['manual_institution_name'] === '' && $data['college_name'] !== '') {
            $data['manual_institution_name'] = $data['college_name'];
        }
        if ($data['institution_match_status'] === '') {
            $data['institution_match_status'] = $data['institution_id'] !== '' ? 'verified_master' : 'manual_pending';
        }

        if (
            empty($data['qualification']) &&
            empty($data['college_name']) &&
            empty($data['university_board']) &&
            empty($data['year_from']) &&
            empty($data['year_to']) &&
            empty($data['roll_number']) &&
            empty($data['college_address'])
        ) {
            continue;
        }

        validateSingle($data);
        if (!$isDraft) {
            validateEducationTimeline(array_merge($timelineRows, [$data]));
        }
        $timelineRows[] = $data;

        $qualificationRules = normalizeQualificationRule($data['qualification']);
        $year_from = $data['year_from'] ? $data['year_from'] . '-01' : null;
        $year_to = $data['year_to'] ? $data['year_to'] . '-01' : null;
        $isMultiMarksheetQualification = in_array($data['qualification'], ['UG', 'PG'], true);
        $isInsufficient = isset($insufficientDocs[$i]) && in_array((string)$insufficientDocs[$i], ['on', '1', 'true'], true);

        $marksheet_file = null;
        $degree_file = null;
        $old_marksheet = $_POST['old_marksheet_file'][$i] ?? '';
        $old_degree = $_POST['old_degree_file'][$i] ?? '';

        $marksheetDocs = [];
        $existingMarksheetDocs = json_decode((string)($_POST['old_marksheet_documents_json'][$i] ?? '[]'), true);
        if (!is_array($existingMarksheetDocs)) {
            $existingMarksheetDocs = [];
        }
        foreach ($existingMarksheetDocs as $doc) {
            if (!is_array($doc)) continue;
            $fileName = trim((string)($doc['file_name'] ?? ''));
            if ($fileName === '') continue;
            $marksheetDocs[] = [
                'document_slot' => 'marksheet',
                'file_name' => $fileName,
                'original_name' => (string)($doc['original_name'] ?? $fileName),
            ];
        }
        if ($isMultiMarksheetQualification && empty($marksheetDocs) && !empty($old_marksheet) && $old_marksheet !== 'INSUFFICIENT_DOCUMENTS') {
            $marksheetDocs[] = [
                'document_slot' => 'marksheet',
                'file_name' => $old_marksheet,
                'original_name' => $old_marksheet,
            ];
        }

        if ($isInsufficient) {
            if (empty($old_marksheet) && empty($old_degree) && empty($marksheetDocs)) {
                $marksheet_file = 'INSUFFICIENT_DOCUMENTS';
                $degree_file = 'INSUFFICIENT_DOCUMENTS';
            } else {
                $marksheet_file = $old_marksheet ?: null;
                $degree_file = $old_degree ?: null;
            }
        } else {
            if ($isMultiMarksheetQualification) {
                $marksheetDocsKey = 'marksheet_documents_' . $i;
                if (isset($_FILES[$marksheetDocsKey]) && is_array($_FILES[$marksheetDocsKey]['name'] ?? null)) {
                    $uploadCount = count($_FILES[$marksheetDocsKey]['name']);
                    for ($docIndex = 0; $docIndex < $uploadCount; $docIndex++) {
                        if (($_FILES[$marksheetDocsKey]['error'][$docIndex] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                            continue;
                        }
                        $upload = candidate_handle_upload([
                            'name' => $_FILES[$marksheetDocsKey]['name'][$docIndex],
                            'type' => $_FILES[$marksheetDocsKey]['type'][$docIndex],
                            'tmp_name' => $_FILES[$marksheetDocsKey]['tmp_name'][$docIndex],
                            'error' => $_FILES[$marksheetDocsKey]['error'][$docIndex],
                            'size' => $_FILES[$marksheetDocsKey]['size'][$docIndex],
                        ], '/uploads/education/', $application_id . '_edu_' . ((int)$data['education_index']) . '_marksheet');
                        $marksheetDocs[] = [
                            'document_slot' => 'marksheet',
                            'file_name' => $upload['file_name'],
                            'original_name' => $upload['original_name'],
                        ];
                    }
                }

                if ($qualificationRules['require_marksheet'] && empty($marksheetDocs)) {
                    throw new ValidationException('At least one marksheet is required for education card ' . ($i + 1));
                }

                $marksheet_file = !empty($marksheetDocs) ? (string)($marksheetDocs[0]['file_name'] ?? null) : null;
            } else {
                if (!empty($_FILES['marksheet_file']['name'][$i])) {
                    $marksheet_file = handleFile($_FILES['marksheet_file'], $application_id, $i, 'marksheet');
                } elseif (!empty($old_marksheet)) {
                    $marksheet_file = $old_marksheet;
                }

                if ($qualificationRules['require_marksheet'] && (empty($marksheet_file) || $marksheet_file === 'INSUFFICIENT_DOCUMENTS')) {
                    throw new ValidationException('Marksheet is required for education card ' . ($i + 1));
                }
            }

            if (!empty($_FILES['degree_file']['name'][$i])) {
                $degree_file = handleFile($_FILES['degree_file'], $application_id, $i, 'degree');
            } elseif (!empty($old_degree)) {
                $degree_file = $old_degree;
            }

            if ($qualificationRules['require_degree'] && (empty($degree_file) || $degree_file === 'INSUFFICIENT_DOCUMENTS')) {
                throw new ValidationException('Degree certificate is required for education card ' . ($i + 1));
            }
        }

        $stmt->execute([
            ':application_id' => $application_id,
            ':education_index' => (int)$data['education_index'],
            ':qualification' => $data['qualification'],
            ':college_name' => $data['college_name'] ?: null,
            ':university_board' => $data['university_board'] ?: null,
            ':year_from' => $year_from,
            ':year_to' => $year_to,
            ':roll_number' => $data['roll_number'] ?: null,
            ':college_website' => $data['college_website'] ?: null,
            ':college_address' => $data['college_address'],
            ':marksheet_file' => $marksheet_file,
            ':degree_file' => $degree_file,
            ':insufficient_documents' => $isInsufficient ? 1 : 0,
        ]);

        EducationService::saveMeta($pdo, (string)$application_id, (int)$data['education_index'], [
            'ca_membership_number' => $data['ca_membership_number'] ?: null,
            'year_of_passing' => $data['year_of_passing'] ?: null,
            'education_gap_reason' => $data['education_gap_reason'] ?: null,
            'education_gap_explanation' => $data['education_gap_explanation'] ?: null,
            'education_order_explanation' => $data['education_order_explanation'] ?: null,
            'institution_id' => $data['institution_id'] !== '' ? (int)$data['institution_id'] : null,
            'institution_display_name' => $data['institution_display_name'] ?: $data['college_name'],
            'manual_institution_name' => $data['manual_institution_name'] ?: null,
            'institution_match_status' => $data['institution_match_status'] ?: null,
        ]);

        $educationDocuments = [];
        if ($isMultiMarksheetQualification && !$isInsufficient) {
            foreach ($marksheetDocs as $doc) {
                $educationDocuments[] = $doc;
            }
        }

        $existingSupporting = json_decode((string)($_POST['old_supporting_documents_json'][$i] ?? '[]'), true);
        if (!is_array($existingSupporting)) {
            $existingSupporting = [];
        }
        foreach ($existingSupporting as $doc) {
            if (!is_array($doc)) continue;
            $fileName = trim((string)($doc['file_name'] ?? ''));
            if ($fileName === '') continue;
            $educationDocuments[] = [
                'document_slot' => 'supporting',
                'file_name' => $fileName,
                'original_name' => (string)($doc['original_name'] ?? $fileName),
            ];
        }

        $supportingKey = 'supporting_documents_' . $i;
        if (isset($_FILES[$supportingKey]) && is_array($_FILES[$supportingKey]['name'] ?? null)) {
            $uploadCount = count($_FILES[$supportingKey]['name']);
            for ($docIndex = 0; $docIndex < $uploadCount; $docIndex++) {
                if (($_FILES[$supportingKey]['error'][$docIndex] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $upload = candidate_handle_upload([
                    'name' => $_FILES[$supportingKey]['name'][$docIndex],
                    'type' => $_FILES[$supportingKey]['type'][$docIndex],
                    'tmp_name' => $_FILES[$supportingKey]['tmp_name'][$docIndex],
                    'error' => $_FILES[$supportingKey]['error'][$docIndex],
                    'size' => $_FILES[$supportingKey]['size'][$docIndex],
                ], '/uploads/education/', $application_id . '_edu_' . ((int)$data['education_index']) . '_doc');
                $educationDocuments[] = [
                    'document_slot' => 'supporting',
                    'file_name' => $upload['file_name'],
                    'original_name' => $upload['original_name'],
                ];
            }
        }

        candidate_replace_education_documents($pdo, (string)$application_id, (int)$data['education_index'], $educationDocuments);
        $processed[] = (int)$data['education_index'];

        do {
            if ($stmt->rowCount() > 0) {
                $stmt->fetchAll();
            }
        } while ($stmt->nextRowset());
        $stmt->closeCursor();
    }

    if (!$isDraft) {
        EducationService::cleanupRows($pdo, (string)$application_id, $processed);
    }

    ccs_progress_component_after_candidate_save($pdo, (string)$application_id, 'education', $isDraft);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Education details saved successfully',
        'data' => getEducationDetails($pdo, $application_id)
    ]);
} catch (ValidationException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Validation error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('store_education.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
