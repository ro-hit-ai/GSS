<?php
session_start();
header("Content-Type: application/json");

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

class ValidationException extends Exception {}

function get_reference_columns(PDO $pdo): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM Vati_Payfiller_Candidate_Reference_details');
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    foreach ($rows as $row) {
        $field = isset($row['Field']) ? (string)$row['Field'] : '';
        if ($field !== '') {
            $cache[$field] = true;
        }
    }
    return $cache;
}

function build_reference_section_data(string $prefix): array {
    return [
        'name' => trim($_POST[$prefix . '_name'] ?? ''),
        'designation' => trim($_POST[$prefix . '_designation'] ?? ''),
        'company' => trim($_POST[$prefix . '_company'] ?? ''),
        'mobile' => trim($_POST[$prefix . '_mobile'] ?? ''),
        'email' => trim($_POST[$prefix . '_email'] ?? ''),
        'relationship' => trim($_POST[$prefix . '_relationship'] ?? ''),
        'years_known' => trim($_POST[$prefix . '_years_known'] ?? '')
    ];
}

function validate_reference_section(array $data, string $label, bool $isDraft): void {
    $filledCount = 0;
    foreach ($data as $value) {
        if ($value !== '') {
            $filledCount++;
        }
    }

    if ($isDraft && $filledCount === 0) {
        return;
    }

    if ($isDraft && $filledCount > 0 && $filledCount < count($data)) {
        throw new ValidationException("Fill all fields or leave all empty for {$label}");
    }

    foreach ($data as $field => $value) {
        if ($value === '') {
            throw new ValidationException($label . ': ' . ucwords(str_replace('_', ' ', $field)) . ' is required');
        }
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new ValidationException($label . ': Invalid email format');
    }

    if (!preg_match('/^[0-9]{10}$/', $data['mobile'])) {
        throw new ValidationException($label . ': Mobile number must be exactly 10 digits');
    }

    if (!ctype_digit($data['years_known']) || (int)$data['years_known'] <= 0) {
        throw new ValidationException($label . ': Years known must be a positive number');
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit;
    }

    $application_id = $_SESSION['application_id'] ?? null;
    if (!$application_id) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
        exit;
    }

    $pdo = getDB();
    $pdo->beginTransaction();

    $isDraft = isset($_POST['draft']) && $_POST['draft'] === '1';
    $hasEducationReference = isset($_POST['has_education_reference']) && $_POST['has_education_reference'] === '1';
    $hasEmploymentReference = isset($_POST['has_employment_reference']) && $_POST['has_employment_reference'] === '1';

    $educationData = build_reference_section_data('education_reference');
    $employmentData = build_reference_section_data('employment_reference');

    if (!$hasEducationReference && !$hasEmploymentReference) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => $isDraft ? 'Draft saved (empty)' : 'No reference details required',
            'is_draft' => $isDraft,
            'data' => []
        ]);
        exit;
    }

    if ($hasEducationReference) {
        validate_reference_section($educationData, 'Education Reference', $isDraft);
    }
    if ($hasEmploymentReference) {
        validate_reference_section($employmentData, 'Employment Reference', $isDraft);
    }

    $hasAnyData = false;
    foreach ([$educationData, $employmentData] as $sectionData) {
        foreach ($sectionData as $value) {
            if ($value !== '') {
                $hasAnyData = true;
                break 2;
            }
        }
    }
    if ($isDraft && !$hasAnyData) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Draft saved (empty)',
            'is_draft' => true
        ]);
        exit;
    }

    $columns = get_reference_columns($pdo);
    $supportsDualReference = isset($columns['education_reference_name']) && isset($columns['employment_reference_name']);

    $primaryData = $hasEmploymentReference ? $employmentData : $educationData;
    $primaryData = [
        'reference_name' => $primaryData['name'],
        'reference_designation' => $primaryData['designation'],
        'reference_company' => $primaryData['company'],
        'reference_mobile' => $primaryData['mobile'],
        'reference_email' => $primaryData['email'],
        'relationship' => $primaryData['relationship'],
        'years_known' => $primaryData['years_known']
    ];

    $payload = $primaryData;
    if ($supportsDualReference) {
        $payload = array_merge($payload, [
            'education_reference_name' => $educationData['name'],
            'education_reference_designation' => $educationData['designation'],
            'education_reference_company' => $educationData['company'],
            'education_reference_mobile' => $educationData['mobile'],
            'education_reference_email' => $educationData['email'],
            'education_reference_relationship' => $educationData['relationship'],
            'education_reference_years_known' => $educationData['years_known'],
            'employment_reference_name' => $employmentData['name'],
            'employment_reference_designation' => $employmentData['designation'],
            'employment_reference_company' => $employmentData['company'],
            'employment_reference_mobile' => $employmentData['mobile'],
            'employment_reference_email' => $employmentData['email'],
            'employment_reference_relationship' => $employmentData['relationship'],
            'employment_reference_years_known' => $employmentData['years_known']
        ]);
    }

    $check = $pdo->prepare('SELECT id FROM Vati_Payfiller_Candidate_Reference_details WHERE application_id = ? LIMIT 1');
    $check->execute([$application_id]);
    $exists = (int)($check->fetchColumn() ?: 0);

    $fieldsForSave = array_keys($payload);

    if ($exists > 0) {
        $setSql = [];
        $params = [];
        foreach ($fieldsForSave as $field) {
            $setSql[] = $field . ' = ?';
            $params[] = $payload[$field];
        }
        $params[] = $application_id;

        $upd = $pdo->prepare(
            'UPDATE Vati_Payfiller_Candidate_Reference_details '
            . 'SET ' . implode(', ', $setSql) . ', updated_at = NOW() '
            . 'WHERE application_id = ?'
        );
        $upd->execute($params);
    } else {
        $insertFields = array_merge(['application_id'], $fieldsForSave, ['created_at', 'updated_at']);
        $placeholders = array_fill(0, count($insertFields), '?');
        $params = [$application_id];
        foreach ($fieldsForSave as $field) {
            $params[] = $payload[$field];
        }
        $params[] = date('Y-m-d H:i:s');
        $params[] = date('Y-m-d H:i:s');

        $ins = $pdo->prepare(
            'INSERT INTO Vati_Payfiller_Candidate_Reference_details '
            . '(' . implode(', ', $insertFields) . ') '
            . 'VALUES (' . implode(', ', $placeholders) . ')'
        );
        $ins->execute($params);
    }


    $fetchStmt = $pdo->prepare("
        SELECT *
        FROM Vati_Payfiller_Candidate_Reference_details
        WHERE application_id = ?
    ");
    $fetchStmt->execute([$application_id]);
    $savedData = $fetchStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $fetchStmt->closeCursor();

    $pdo->commit();


    echo json_encode([
        'success'  => true,
        'message'  => $isDraft ? 'Draft saved successfully' : 'Reference details saved successfully',
        'is_draft' => $isDraft,
        'data'     => $savedData
    ]);

} catch (ValidationException $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("store_reference.php ERROR: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}
