<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

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

function normalizeArray($value): array {
    if (is_array($value)) return $value;
    if ($value === null || $value === '') return [];
    return [$value];
}

try {
    $application_id = $_SESSION['application_id'] ?? null;
    if (!$application_id) {
        throw new ValidationException("Session expired. Please log in again.");
    }

    $isDraft = isset($_POST['draft']) && $_POST['draft'] === '1';

    $documentIndexes = normalizeArray($_POST['document_index'] ?? null);
    $documentTypes   = normalizeArray($_POST['documentId_type'] ?? null);
    $idNumbers       = normalizeArray($_POST['id_number'] ?? null);
    $names           = normalizeArray($_POST['name'] ?? null);
    $recordIds       = normalizeArray($_POST['id'] ?? null);
    $oldDocuments    = normalizeArray($_POST['old_upload_document'] ?? null);
    $issueDates      = normalizeArray($_POST['issue_date'] ?? null);
    $expiryDates     = normalizeArray($_POST['expiry_date'] ?? null);
    
    $insufficientDocs = normalizeArray($_POST['insufficient_documents'] ?? null);

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

    $hasFiles = false;
    $fileArray = [];
    
    if (isset($_FILES['upload_document'])) {
        $hasFiles = true;
        if (is_array($_FILES['upload_document']['name'])) {
            $fileCount = count($_FILES['upload_document']['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                $fileArray[$i] = [
                    'name'     => $_FILES['upload_document']['name'][$i],
                    'type'     => $_FILES['upload_document']['type'][$i],
                    'tmp_name' => $_FILES['upload_document']['tmp_name'][$i],
                    'error'    => $_FILES['upload_document']['error'][$i],
                    'size'     => $_FILES['upload_document']['size'][$i]
                ];
            }
        } else {
            $fileArray[0] = [
                'name'     => $_FILES['upload_document']['name'],
                'type'     => $_FILES['upload_document']['type'],
                'tmp_name' => $_FILES['upload_document']['tmp_name'],
                'error'    => $_FILES['upload_document']['error'],
                'size'     => $_FILES['upload_document']['size']
            ];
        }
    }

    $pdo = getDB();
    $pdo->beginTransaction();

    $uploadDir = APP_BASE_PATH . "/uploads/identification/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $processedIndices = [];

    for ($i = 0; $i < $count; $i++) {
        $documentIndex = (int)($documentIndexes[$i] ?? ($i + 1));
        $documentType  = trim($documentTypes[$i] ?? '');
        $idNumber      = trim($idNumbers[$i] ?? '');
        $name          = trim($names[$i] ?? '');
        $recordId      = trim($recordIds[$i] ?? '');
        $oldDocument   = trim($oldDocuments[$i] ?? '');
        $issueDate     = !empty($issueDates[$i]) ? $issueDates[$i] : null;
        $expiryDate    = !empty($expiryDates[$i]) ? $expiryDates[$i] : null;
        $isInsufficient = isset($insufficientDocs[$i]) && 
                         ($insufficientDocs[$i] === 'on' || $insufficientDocs[$i] === '1' || $insufficientDocs[$i] === 'true');

        $uploadDocument = $oldDocument;

        if ($isDraft &&
            $documentType === '' &&
            $idNumber === '' &&
            $name === '' &&
            $oldDocument === '' &&
            !$isInsufficient
        ) {
            continue;
        }

        if (!$isDraft) {
            if (!$isInsufficient) {
                if ($documentType === '') {
                    throw new ValidationException("Document type required for Document {$documentIndex}");
                }
                if ($idNumber === '') {
                    throw new ValidationException("ID number required for Document {$documentIndex}");
                }
                if ($name === '') {
                    throw new ValidationException("Name required for Document {$documentIndex}");
                }
                
                $hasFile = isset($fileArray[$i]) && 
                          $fileArray[$i]['name'] !== '' && 
                          $fileArray[$i]['error'] === UPLOAD_ERR_OK;
                
                if (!$hasFile && empty($oldDocument)) {
                    throw new ValidationException("Document file is required for Document {$documentIndex}");
                }
            } else {
                $uploadDocument = 'INSUFFICIENT_DOCUMENTS';
            }
        }

        if (!$isInsufficient) {
            if (isset($fileArray[$i]) && 
                $fileArray[$i]['name'] !== '' && 
                $fileArray[$i]['error'] === UPLOAD_ERR_OK) {
                
                $fileName = $fileArray[$i]['name'];
                $fileSize = $fileArray[$i]['size'];
                $tmpName  = $fileArray[$i]['tmp_name'];

                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowed = ['pdf', 'jpg', 'jpeg', 'png'];

                if (!in_array($ext, $allowed)) {
                    throw new ValidationException("Invalid file type for Document {$documentIndex}. Allowed: PDF, JPG, JPEG, PNG");
                }

                if ($fileSize > 10 * 1024 * 1024) {
                    throw new ValidationException("File too large for Document {$documentIndex}. Maximum 10MB allowed");
                }

                $newFile = "doc_{$application_id}_{$documentIndex}_" . time() . "_" . uniqid() . "." . $ext;
                $dest = $uploadDir . $newFile;

                if (!move_uploaded_file($tmpName, $dest)) {
                    throw new ValidationException("Failed to save file for Document {$documentIndex}");
                }

                if ($oldDocument && 
                    $oldDocument !== 'INSUFFICIENT_DOCUMENTS' && 
                    file_exists($uploadDir . $oldDocument)) {
                    @unlink($uploadDir . $oldDocument);
                }

                $uploadDocument = $newFile;
            }
        }

        $stmt = $pdo->prepare("
            CALL SP_Vati_Payfiller_store_identification_details(
                :application_id,
                :document_index,
                :documentId_type,
                :id_number,
                :name,
                :upload_document,
                :country,
                :issue_date,
                :expiry_date
            )
        ");

        $uploadDocumentValue = ($uploadDocument === '' || $uploadDocument === 'INSUFFICIENT_DOCUMENTS') 
            ? $uploadDocument 
            : ($uploadDocument ?: null);

        $stmt->execute([
            ':application_id'   => $application_id,
            ':document_index'   => $documentIndex,
            ':documentId_type'  => $documentType ?: null,
            ':id_number'        => $idNumber ?: null,
            ':name'             => $name ?: null,
            ':upload_document'  => $uploadDocumentValue,
            ':country'          => $country ?: null,
            ':issue_date'       => $issueDate,
            ':expiry_date'      => $expiryDate
        ]);

        $processedIndices[] = $documentIndex;
    }

    if (!$isDraft && !empty($processedIndices)) {
        $placeholders = implode(',', array_fill(0, count($processedIndices), '?'));
        $delete = $pdo->prepare("
            DELETE FROM Vati_Payfiller_Candidate_Identification_details
            WHERE application_id = ?
            AND document_index NOT IN ($placeholders)
        ");
        $delete->execute(array_merge([$application_id], $processedIndices));
    }

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
