<?php
session_start();
header("Content-Type: application/json");

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../shared/candidate_correction_service.php';
require_once __DIR__ . '/../../services/candidate/ReferenceService.php';

class ValidationException extends Exception {}

function reference_post_array(string $key): array
{
    $value = $_POST[$key] ?? [];
    return is_array($value) ? array_values($value) : [$value];
}

function build_reference_rows(string $prefix, int $visibleCount): array
{
    $names = reference_post_array($prefix . '_name');
    $designations = reference_post_array($prefix . '_designation');
    $companies = reference_post_array($prefix . '_company');
    $relationships = reference_post_array($prefix . '_relationship');
    $yearsKnown = reference_post_array($prefix . '_years_known');
    $mobiles = reference_post_array($prefix . '_mobile');
    $emails = reference_post_array($prefix . '_email');

    $rows = [];
    for ($i = 0; $i < max(1, $visibleCount); $i++) {
        $rows[] = [
            'name' => trim((string)($names[$i] ?? '')),
            'designation' => trim((string)($designations[$i] ?? '')),
            'company' => trim((string)($companies[$i] ?? '')),
            'relationship' => trim((string)($relationships[$i] ?? '')),
            'years_known' => trim((string)($yearsKnown[$i] ?? '')),
            'mobile' => trim((string)($mobiles[$i] ?? '')),
            'email' => trim((string)($emails[$i] ?? '')),
        ];
    }

    return $rows;
}

function reference_row_is_empty(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string)$value) !== '') {
            return false;
        }
    }
    return true;
}

function validate_reference_rows(array $rows, string $label, bool $isDraft): array
{
    $validRows = [];
    foreach ($rows as $index => $row) {
        $rowNumber = $index + 1;
        $filledCount = 0;
        foreach ($row as $value) {
            if ($value !== '') {
                $filledCount++;
            }
        }

        if ($isDraft && $filledCount === 0) {
            continue;
        }

        if ($isDraft && $filledCount > 0 && $filledCount < count($row)) {
            throw new ValidationException("Fill all fields or leave all empty for {$label} {$rowNumber}");
        }

        foreach ($row as $field => $value) {
            if ($value === '') {
                throw new ValidationException($label . " {$rowNumber}: " . ucwords(str_replace('_', ' ', $field)) . ' is required');
            }
        }

        if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException($label . " {$rowNumber}: Invalid email format");
        }

        if (!preg_match('/^[0-9]{10}$/', $row['mobile'])) {
            throw new ValidationException($label . " {$rowNumber}: Mobile number must be exactly 10 digits");
        }

        if (!ctype_digit($row['years_known']) || (int)$row['years_known'] <= 0) {
            throw new ValidationException($label . " {$rowNumber}: Years known must be a positive number");
        }

        $validRows[] = $row;
    }

    return $validRows;
}

function reference_progress_component_keys(PDO $pdo, string $applicationId): array
{
    $sessionAllowedRaw = (string)($_SESSION['candidate_correction_allowed_components'] ?? '');
    if ($sessionAllowedRaw !== '') {
        $sessionAllowed = json_decode($sessionAllowedRaw, true);
        if (is_array($sessionAllowed)) {
            $sessionKeys = [];
            foreach ($sessionAllowed as $componentKey) {
                $key = ccs_component_norm((string)$componentKey);
                if ($key === 'education_reference' || $key === 'employment_reference' || $key === 'reference') {
                    $sessionKeys[$key] = true;
                }
            }
            $out = [];
            if (isset($sessionKeys['education_reference'])) $out[] = 'education_reference';
            if (isset($sessionKeys['employment_reference'])) $out[] = 'employment_reference';
            if (!$out && isset($sessionKeys['reference'])) $out[] = 'reference';
            if ($out) return $out;
        }
    }

    $keys = [];
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT LOWER(TRIM(component_key)) AS component_key
               FROM Vati_Payfiller_Case_Components
              WHERE application_id = ?
                AND COALESCE(is_required, 1) = 1
                AND LOWER(TRIM(component_key)) IN ('reference','education_reference','employment_reference')"
        );
        $st->execute([$applicationId]);
        foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $componentKey) {
            $key = strtolower(trim((string)$componentKey));
            if ($key !== '') $keys[$key] = true;
        }
    } catch (Throwable $e) {
    }

    $out = [];
    if (isset($keys['education_reference'])) $out[] = 'education_reference';
    if (isset($keys['employment_reference'])) $out[] = 'employment_reference';
    if (!$out && isset($keys['reference'])) $out[] = 'reference';
    return $out ?: ['reference'];
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
    $educationVisibleCount = max(1, (int)($_POST['education_reference_visible_count'] ?? 1));
    $employmentVisibleCount = max(1, (int)($_POST['employment_reference_visible_count'] ?? 1));

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

    $educationRows = $hasEducationReference
        ? validate_reference_rows(build_reference_rows('education_reference', $educationVisibleCount), 'Education Reference', $isDraft)
        : [];
    $employmentRows = $hasEmploymentReference
        ? validate_reference_rows(build_reference_rows('employment_reference', $employmentVisibleCount), 'Employment Reference', $isDraft)
        : [];

    if (!$isDraft) {
        if ($hasEducationReference && !$educationRows) {
            throw new ValidationException('At least one Education Reference is required');
        }
        if ($hasEmploymentReference && !$employmentRows) {
            throw new ValidationException('At least one Employment Reference is required');
        }
    }

    if ($isDraft && !$educationRows && !$employmentRows) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Draft saved (empty)',
            'is_draft' => true
        ]);
        exit;
    }

    $savedData = ReferenceService::replaceGrouped($pdo, (string)$application_id, $educationRows, $employmentRows);

    foreach (reference_progress_component_keys($pdo, (string)$application_id) as $componentKey) {
        ccs_progress_component_after_candidate_save($pdo, (string)$application_id, $componentKey, $isDraft);
    }
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
