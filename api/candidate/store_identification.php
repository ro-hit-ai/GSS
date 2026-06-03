<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../shared/candidate_correction_service.php';
require_once __DIR__ . '/../../services/candidate/IdentificationService.php';

header("Content-Type: application/json");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!defined('APP_BASE_PATH')) {
    $basePath = realpath(__DIR__ . '/../..');
    define('APP_BASE_PATH', $basePath);
}

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

function normalizeArray($value): array {
    if (is_array($value)) return $value;
    if ($value === null || $value === '') return [];
    return [$value];
}

function normalizeFileMatrix(string $key): array {
    if (!isset($_FILES[$key])) {
        return [];
    }
    if (is_array($_FILES[$key]['name'])) {
        $out = [];
        $count = count($_FILES[$key]['name']);
        for ($i = 0; $i < $count; $i++) {
            $out[$i] = [
                'name' => $_FILES[$key]['name'][$i] ?? '',
                'type' => $_FILES[$key]['type'][$i] ?? '',
                'tmp_name' => $_FILES[$key]['tmp_name'][$i] ?? '',
                'error' => $_FILES[$key]['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES[$key]['size'][$i] ?? 0,
            ];
        }
        return $out;
    }
    return [[
        'name' => $_FILES[$key]['name'] ?? '',
        'type' => $_FILES[$key]['type'] ?? '',
        'tmp_name' => $_FILES[$key]['tmp_name'] ?? '',
        'error' => $_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE,
        'size' => $_FILES[$key]['size'] ?? 0,
    ]];
}

try {
    $application_id = $_SESSION['application_id'] ?? null;
    if (!$application_id) {
        throw new ValidationException("Session expired. Please log in again.");
    }

    $isDraft = isset($_POST['draft']) && $_POST['draft'] === '1';

    $documentIndexes = normalizeArray($_POST['document_index'] ?? null);
    $recordIds       = normalizeArray($_POST['id'] ?? null);
    $oldDocuments    = normalizeArray($_POST['old_upload_document'] ?? null);
    $primaryTypes    = normalizeArray($_POST['primary_document_type'] ?? null);
    $primaryNames    = normalizeArray($_POST['primary_name'] ?? null);
    $primaryIds      = normalizeArray($_POST['primary_id_number'] ?? null);
    $primaryFrom     = normalizeArray($_POST['primary_issue_date'] ?? null);
    $primaryTo       = normalizeArray($_POST['primary_expiry_date'] ?? null);
    $primaryOldFiles = normalizeArray($_POST['old_primary_upload_document'] ?? null);
    $secondaryTypes    = normalizeArray($_POST['secondary_document_type'] ?? null);
    $secondaryNames    = normalizeArray($_POST['secondary_name'] ?? null);
    $secondaryIds      = normalizeArray($_POST['secondary_id_number'] ?? null);
    $secondaryFrom     = normalizeArray($_POST['secondary_issue_date'] ?? null);
    $secondaryTo       = normalizeArray($_POST['secondary_expiry_date'] ?? null);
    $secondaryOldFiles = normalizeArray($_POST['old_secondary_upload_document'] ?? null);

    $country = trim($_POST['identification_country'] ?? 'India');

    $count = count($documentIndexes);

    if ($count === 0) {
        if ($isDraft) {
            echo json_encode([
                'success' => true,
                'message' => 'Draft saved (no identification data)',
                'is_draft' => true
            ]);
            exit;
        }
        throw new ValidationException("No identification records submitted.");
    }

    $pdo = getDB();
    $pdo->beginTransaction();

    $primaryFiles = normalizeFileMatrix('primary_upload_document');
    $secondaryFiles = normalizeFileMatrix('secondary_upload_document');

    $processedIndices = [];

    for ($i = 0; $i < $count; $i++) {
        $documentIndex = (int)($documentIndexes[$i] ?? ($i + 1));
        $recordId      = trim($recordIds[$i] ?? '');
        $oldDocument   = trim($oldDocuments[$i] ?? '');
        $groupRows = [];
        foreach ([
            'primary' => [
                'type' => trim($primaryTypes[$i] ?? ''),
                'name' => trim($primaryNames[$i] ?? ''),
                'id_number' => trim($primaryIds[$i] ?? ''),
                'issue_date' => !empty($primaryFrom[$i]) ? $primaryFrom[$i] : null,
                'expiry_date' => !empty($primaryTo[$i]) ? $primaryTo[$i] : null,
                'old_file' => trim($primaryOldFiles[$i] ?? ''),
                'file' => $primaryFiles[$i] ?? null,
            ],
            'secondary' => [
                'type' => trim($secondaryTypes[$i] ?? ''),
                'name' => trim($secondaryNames[$i] ?? ''),
                'id_number' => trim($secondaryIds[$i] ?? ''),
                'issue_date' => !empty($secondaryFrom[$i]) ? $secondaryFrom[$i] : null,
                'expiry_date' => !empty($secondaryTo[$i]) ? $secondaryTo[$i] : null,
                'old_file' => trim($secondaryOldFiles[$i] ?? ''),
                'file' => $secondaryFiles[$i] ?? null,
            ],
        ] as $groupKey => $row) {
            $selectedType = $row['type'];
            $hasAnyData = $selectedType !== '' || $row['name'] !== '' || $row['id_number'] !== '' || !empty($row['old_file']) || (!empty($row['file']) && ($row['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);
            if (!$hasAnyData) {
                continue;
            }

            if (!$isDraft) {
                if ($selectedType === '') {
                    throw new ValidationException("Select ID proof for ID {$documentIndex}");
                }
                if ($row['name'] === '') {
                    throw new ValidationException("Name is required for {$selectedType} in ID {$documentIndex}");
                }
                if ($row['id_number'] === '') {
                    throw new ValidationException("ID number is required for {$selectedType} in ID {$documentIndex}");
                }
                $usesDates = in_array(strtolower($selectedType), ['passport', 'driving licence', 'driving license', 'driver license', 'driver licence'], true);
                if ($usesDates) {
                    if (empty($row['issue_date'])) {
                        throw new ValidationException("From Date is required for {$selectedType} in ID {$documentIndex}");
                    }
                    if (empty($row['expiry_date'])) {
                        throw new ValidationException("To Date is required for {$selectedType} in ID {$documentIndex}");
                    }
                    if ($row['issue_date'] && $row['expiry_date'] && strtotime($row['expiry_date']) <= strtotime($row['issue_date'])) {
                        throw new ValidationException("To Date must be after From Date for {$selectedType} in ID {$documentIndex}");
                    }
                }
                $hasNewFile = !empty($row['file']) && ($row['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($row['file']['name'] ?? '') !== '';
                if (!$hasNewFile && $row['old_file'] === '') {
                    throw new ValidationException("Upload document is required for {$selectedType} in ID {$documentIndex}");
                }
            }

            $uploadDocument = $row['old_file'];
            if (!empty($row['file']) && ($row['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($row['file']['name'] ?? '') !== '') {
                $upload = candidate_handle_upload($row['file'], '/uploads/identification/', "doc_{$application_id}_{$documentIndex}_{$groupKey}", ['pdf', 'jpg', 'jpeg', 'png'], 5 * 1024 * 1024);
                $uploadDocument = $upload['file_name'];
            }

            $groupRows[] = [
                'proof_group' => $groupKey,
                'document_type' => $selectedType,
                'id_number' => $row['id_number'],
                'name' => $row['name'],
                'issue_date' => $row['issue_date'],
                'expiry_date' => $row['expiry_date'],
                'upload_document' => $uploadDocument,
                'country' => $country,
            ];
        }

        if ($isDraft && empty($groupRows) && $oldDocument === '') {
            continue;
        }

        $existingRows = IdentificationService::replaceRows($pdo, (string)$application_id, $documentIndex, $groupRows);
        $keptFiles = [];
        foreach ($groupRows as $row) {
            $group = trim((string)($row['proof_group'] ?? ''));
            $file = trim((string)($row['upload_document'] ?? ''));
            if ($group !== '' && $file !== '') {
                $keptFiles[$group] = $file;
            }
        }
        foreach ($existingRows as $existing) {
            $group = trim((string)($existing['proof_group'] ?? ''));
            $oldFile = trim((string)($existing['upload_document'] ?? ''));
            if ($oldFile !== '' && (!isset($keptFiles[$group]) || $keptFiles[$group] !== $oldFile)) {
                candidate_delete_uploaded_file('/uploads/identification/', $oldFile);
            }
        }

        $processedIndices[] = $documentIndex;
    }

    if (!$isDraft && !empty($processedIndices)) {
        $removedRows = IdentificationService::cleanupRows($pdo, (string)$application_id, $processedIndices);
        foreach ($removedRows as $row) {
            candidate_delete_uploaded_file('/uploads/identification/', (string)($row['upload_document'] ?? ''));
        }
    }

    ccs_progress_component_after_candidate_save($pdo, (string)$application_id, 'id', $isDraft);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $isDraft
            ? 'Draft saved successfully'
            : 'Identification details saved successfully',
        'is_draft' => $isDraft,
        'processed_count' => count($processedIndices)
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
    http_response_code(500);
    error_log("Identification save error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false, 
        'message' => 'Server error: ' . ($_ENV['APP_DEBUG'] ?? false ? $e->getMessage() : 'Please try again later')
    ]);
}
