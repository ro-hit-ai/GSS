<?php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/candidate/EducationService.php';
require_once __DIR__ . '/../../services/candidate/EmploymentService.php';
require_once __DIR__ . '/../../services/candidate/SidebarService.php';
require_once __DIR__ . '/../shared/reference_component_compat.php';

function sidebar_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function sidebar_blank($value): bool {
    return trim((string)($value ?? '')) === '';
}

function sidebar_has_doc($value): bool {
    $value = trim((string)($value ?? ''));
    return $value !== '' && strtoupper($value) !== 'INSUFFICIENT_DOCUMENTS';
}

function sidebar_fetch_one(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function sidebar_fetch_all(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sidebar_call_one(PDO $pdo, string $proc, string $applicationId): array {
    try {
        $stmt = $pdo->prepare("CALL {$proc}(?)");
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        while ($stmt->nextRowset()) {
        }
        $stmt->closeCursor();
        return $row;
    } catch (Throwable $e) {
        return [];
    }
}

function sidebar_call_all(PDO $pdo, string $proc, string $applicationId): array {
    try {
        $stmt = $pdo->prepare("CALL {$proc}(?)");
        $stmt->execute([$applicationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        while ($stmt->nextRowset()) {
        }
        $stmt->closeCursor();
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function sidebar_try_fetch_all(PDO $pdo, string $sql, array $params = []): array {
    try {
        return sidebar_fetch_all($pdo, $sql, $params);
    } catch (Throwable $e) {
        return [];
    }
}

function sidebar_try_fetch_one(PDO $pdo, string $sql, array $params = []): array {
    try {
        return sidebar_fetch_one($pdo, $sql, $params);
    } catch (Throwable $e) {
        return [];
    }
}

function sidebar_required_score(array $row, array $fields): array {
    $total = count($fields);
    $filled = 0;
    $missing = [];
    foreach ($fields as $field => $label) {
        if (!sidebar_blank($row[$field] ?? '')) {
            $filled++;
        } else {
            $missing[] = $label;
        }
    }
    return [$filled, $total, $missing];
}

function sidebar_section(
    string $key,
    string $label,
    int $formFilled,
    int $formTotal,
    int $docFilled,
    int $docTotal,
    array $issues,
    bool $hasData,
    array $correction = []
): array {
    $validationErrors = count(array_filter($issues, fn($issue) => in_array($issue['type'] ?? '', ['invalid_format', 'invalid_date', 'date_overlap', 'future_date'], true)));
    $formScore = $formTotal > 0 ? ($formFilled / $formTotal) : 1;
    $docScore = $docTotal > 0 ? ($docFilled / $docTotal) : 1;
    $validationScore = $validationErrors > 0 ? 0 : 1;
    $correctionScore = empty($correction) ? 1 : 0;
    $score = (int)round(($formScore * 40) + ($docScore * 35) + ($validationScore * 15) + ($correctionScore * 10));
    $score = max(0, min(100, $score));

    $status = 'not_started';
    $message = 'Pending';

    if (!empty($correction)) {
        $status = (($correction['status'] ?? '') === 'submitted') ? 'submitted' : 'correction_required';
        $message = (($correction['status'] ?? '') === 'submitted') ? 'Submitted for review' : 'Correction required';
    } elseif ($score >= 100 && empty($issues)) {
        $status = 'completed';
        $message = 'Completed';
    } elseif (!empty($issues)) {
        $status = 'needs_attention';
        $message = (string)($issues[0]['message'] ?? 'Needs attention');
    } elseif ($hasData) {
        $status = 'in_progress';
        $message = 'In progress';
    }

    return [
        'key' => $key,
        'label' => $label,
        'status' => $status,
        'message' => $message,
        'score' => $score,
        'issues' => $issues,
    ];
}

function sidebar_issue(string $type, string $message, string $field = ''): array {
    return ['type' => $type, 'message' => $message, 'field' => $field];
}

function sidebar_touched_sections(): array {
    $raw = trim((string)($_GET['touched'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $key) {
        $key = strtolower(trim($key));
        if ($key !== '') {
            $out[$key] = true;
        }
    }
    return $out;
}

function sidebar_hide_untouched_warnings(array $sections, array $touched): array {
    foreach ($sections as &$section) {
        $key = strtolower((string)($section['key'] ?? ''));
        $status = (string)($section['status'] ?? '');
        if (isset($touched[$key]) || in_array($status, ['correction_required', 'submitted', 'waiting_mobile_upload', 'rejected'], true)) {
            continue;
        }
        if ($status === 'needs_attention') {
            $section['issues'] = [];
            $section['status'] = ((int)($section['score'] ?? 0) > 0) ? 'in_progress' : 'not_started';
            $section['message'] = $section['status'] === 'in_progress' ? 'In progress' : 'Pending';
        }
    }
    unset($section);
    return $sections;
}

$applicationId = (string)($_SESSION['application_id'] ?? ($_GET['application_id'] ?? ''));
if (empty($_SESSION['logged_in']) || $applicationId === '') {
    sidebar_json(['success' => false, 'message' => 'Unauthorized'], 403);
}

try {
    $pdo = getDB();

    $activeCorrections = [];
    $rows = sidebar_try_fetch_all($pdo, "
            SELECT allowed_components_json, status
            FROM Vati_Payfiller_Candidate_Correction_Sessions
            WHERE application_id = ?
              AND status IN ('active', 'submitted')
            ORDER BY correction_session_id DESC
    ", [$applicationId]);
    foreach ($rows as $row) {
        $components = json_decode((string)($row['allowed_components_json'] ?? '[]'), true);
        if (!is_array($components)) {
            $components = [];
        }
        foreach ($components as $component) {
            $activeCorrections[(string)$component] = ['status' => (string)($row['status'] ?? 'active')];
        }
    }
    $activeCorrections = reference_compat_effective_component_map($activeCorrections);

    $requiredComponents = [];
    $caseComponentRows = sidebar_try_fetch_all($pdo, "
            SELECT LOWER(TRIM(component_key)) AS component_key
            FROM Vati_Payfiller_Case_Components
            WHERE application_id = ?
              AND is_required = 1
    ", [$applicationId]);
    foreach ($caseComponentRows as $row) {
        $componentKey = strtolower(trim((string)($row['component_key'] ?? '')));
        if ($componentKey !== '') {
            $requiredComponents[$componentKey] = true;
        }
    }
    $requiredComponents = array_fill_keys(reference_compat_effective_keys(array_keys($requiredComponents)), true);
    $hasRequiredComponentScope = !empty($requiredComponents);

    $sections = [];

    $basic = sidebar_fetch_one($pdo, "SELECT * FROM Vati_Payfiller_Candidate_Basic_details WHERE application_id = ? LIMIT 1", [$applicationId]);
    [$filled, $total, $missing] = sidebar_required_score($basic, [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'gender' => 'Gender',
        'dob' => 'Date of birth',
        'father_name' => "Father's name",
        'mobile' => 'Mobile number',
        'email' => 'Email',
        'country' => 'Country',
        'state' => 'State',
        'district' => 'District',
        'city_village' => 'City/Village',
        'pincode' => 'Pincode',
    ]);
    $basicIssues = [];
    foreach (array_slice($missing, 0, 1) as $label) {
        $basicIssues[] = sidebar_issue('missing_required_field', "{$label} missing");
    }
    if (!sidebar_blank($basic['email'] ?? '') && !filter_var((string)$basic['email'], FILTER_VALIDATE_EMAIL)) {
        array_unshift($basicIssues, sidebar_issue('invalid_format', 'Invalid email address', 'email'));
    }
    if (!sidebar_blank($basic['mobile'] ?? '') && strlen(preg_replace('/\D+/', '', (string)$basic['mobile'])) < 7) {
        array_unshift($basicIssues, sidebar_issue('invalid_format', 'Invalid mobile number', 'mobile'));
    }
    $photoUploaded = sidebar_has_doc($basic['photo_path'] ?? '');
    $resumeUploaded = sidebar_has_doc($basic['resume_file'] ?? '');
    if (!$photoUploaded) {
        $waitingPhoto = sidebar_try_fetch_one($pdo, "
                SELECT status
                FROM Vati_Payfiller_Candidate_Mobile_Photo_Sessions
                WHERE application_id = ?
                  AND status = 'pending'
                  AND expires_at > NOW()
                ORDER BY session_id DESC
                LIMIT 1
        ", [$applicationId]);
        $basicIssues[] = sidebar_issue($waitingPhoto ? 'waiting_mobile_upload' : 'document_missing', $waitingPhoto ? 'Waiting for mobile upload' : 'Profile photo pending', 'photo_path');
    }
    $basicSection = sidebar_section('basic-details', 'Basic Details', $filled, $total, (int)$photoUploaded, 1, $basicIssues, !empty($basic), $activeCorrections['basic'] ?? []);
    if (!$photoUploaded && !empty($waitingPhoto) && empty($activeCorrections['basic'])) {
        $basicSection['status'] = 'waiting_mobile_upload';
        $basicSection['message'] = 'Waiting for mobile upload';
    }
    if ($photoUploaded && empty($basicIssues)) {
        $basicSection['message'] = $resumeUploaded ? 'Photo + resume uploaded' : 'Photo uploaded';
    }
    $sections[] = $basicSection;

    $identification = sidebar_fetch_all($pdo, "
        SELECT documentId_type, id_number, name, upload_document
        FROM Vati_Payfiller_Candidate_Identification_details
        WHERE application_id = ?
        ORDER BY document_index ASC, proof_group ASC
    ", [$applicationId]);
    $idIssues = [];
    $idFormFilled = 0;
    $idFormTotal = max(3, count($identification) * 3);
    $idDocFilled = 0;
    $idDocTotal = max(1, count($identification));
    foreach ($identification as $row) {
        foreach (['documentId_type', 'id_number', 'name'] as $field) {
            if (!sidebar_blank($row[$field] ?? '')) {
                $idFormFilled++;
            }
        }
        if (sidebar_has_doc($row['upload_document'] ?? '')) {
            $idDocFilled++;
        }
    }
    if (!$identification) {
        $idIssues[] = sidebar_issue('missing_required_field', 'ID proof pending', 'identification');
    } elseif ($idDocFilled < $idDocTotal) {
        $idIssues[] = sidebar_issue('mandatory_document_missing', 'ID document upload pending', 'upload_document');
    }
    $sections[] = sidebar_section('identification', 'Identification', $idFormFilled, $idFormTotal, $idDocFilled, $idDocTotal, $idIssues, !empty($identification), $activeCorrections['identification'] ?? ($activeCorrections['id'] ?? []));

    $contact = sidebar_fetch_one($pdo, "SELECT * FROM Vati_Payfiller_Candidate_Contact_details WHERE application_id = ? LIMIT 1", [$applicationId]);
    $currentFields = [
        'address1' => 'Address line 1',
        'city' => 'City',
        'state' => 'State',
        'country' => 'Country',
        'postal_code' => 'Pin code',
    ];
    $permanentFields = [
        'permanent_address1' => 'Address line 1',
        'permanent_city' => 'City',
        'permanent_state' => 'State',
        'permanent_country' => 'Country',
        'permanent_postal_code' => 'Pin code',
    ];
    [$currentFilled, $currentTotal, $currentMissing] = sidebar_required_score($contact, $currentFields);
    [$permanentFilled, $permanentTotal, $permanentMissing] = sidebar_required_score($contact, $permanentFields);
    $currentDocFilled = (int)sidebar_has_doc($contact['current_proof_file'] ?? $contact['proof_file'] ?? '');
    $permanentDocFilled = (int)sidebar_has_doc($contact['permanent_proof_file'] ?? '');
    $currentBlockComplete = $currentFilled === $currentTotal && $currentDocFilled > 0;
    $permanentBlockComplete = $permanentFilled === $permanentTotal && $permanentDocFilled > 0;
    $contactIssues = [];
    $filled = max($currentFilled, $permanentFilled);
    $total = max($currentTotal, $permanentTotal);
    $contactDocFilled = max($currentDocFilled, $permanentDocFilled);

    if ($contact && !$currentBlockComplete && !$permanentBlockComplete) {
        $bestMissing = $permanentFilled > $currentFilled ? $permanentMissing : $currentMissing;
        foreach (array_slice($bestMissing, 0, 1) as $label) {
            $contactIssues[] = sidebar_issue('missing_required_field', "{$label} missing");
        }
        if ($currentDocFilled < 1 && $permanentDocFilled < 1) {
            $contactIssues[] = sidebar_issue('mandatory_document_missing', 'Address proof upload pending', 'address_proof');
        }
    }
    $sections[] = sidebar_section('contact', 'Address', $filled, $total, $contactDocFilled, 1, $contactIssues, !empty($contact), $activeCorrections['contact'] ?? []);

    $ecourt = sidebar_call_one($pdo, 'SP_Vati_Payfiller_get_ecourt_details', $applicationId);
    [$filled, $total, $missing] = sidebar_required_score($ecourt, [
        'applicant_legal_name' => 'Legal name',
        'father_name' => "Father's name",
        'current_address' => 'Current address',
        'permanent_address' => 'Permanent address',
        'period_from_date' => 'Period from',
        'period_to_date' => 'Period to',
        'dob' => 'Date of birth',
    ]);
    $ecourtIssues = [];
    foreach (array_slice($missing, 0, 1) as $label) {
        $ecourtIssues[] = sidebar_issue('missing_required_field', "{$label} missing");
    }
    if (!sidebar_blank($ecourt['period_from_date'] ?? '') && !sidebar_blank($ecourt['period_to_date'] ?? '') && strtotime((string)$ecourt['period_from_date']) > strtotime((string)$ecourt['period_to_date'])) {
        array_unshift($ecourtIssues, sidebar_issue('invalid_date', 'E-Court dates invalid', 'period_to_date'));
    }
    $ecourtDoc = sidebar_has_doc($ecourt['evidence_document'] ?? '');
    if ($ecourt && !$ecourtDoc) {
        $ecourtIssues[] = sidebar_issue('mandatory_document_missing', 'E-Court evidence pending', 'evidence_document');
    }
    $sections[] = sidebar_section('ecourt', 'E-Court', $filled, $total, (int)$ecourtDoc, 1, $ecourtIssues, !empty($ecourt), $activeCorrections['ecourt'] ?? []);

    $education = sidebar_call_all($pdo, 'SP_Vati_Payfiller_get_education_details', $applicationId);
    if ($education) {
        $educationExtras = sidebar_try_fetch_all($pdo, "
            SELECT education_index, education_gap_reason, education_gap_explanation, education_order_explanation
            FROM Vati_Payfiller_Candidate_Education_details
            WHERE application_id = ?
        ", [$applicationId]);
        $educationExtrasByIndex = [];
        foreach ($educationExtras as $extra) {
            $educationExtrasByIndex[(int)($extra['education_index'] ?? 0)] = $extra;
        }
        foreach ($education as &$educationRow) {
            $educationIndex = (int)($educationRow['education_index'] ?? 0);
            if ($educationIndex > 0 && isset($educationExtrasByIndex[$educationIndex])) {
                $educationRow = array_merge($educationRow, $educationExtrasByIndex[$educationIndex]);
            }
        }
        unset($educationRow);
    }
    $educationDocs = EducationService::fetchDocuments($pdo, $applicationId);
    $eduIssues = [];
    $eduFormFilled = 0;
    $eduFormTotal = max(4, count($education) * 4);
    foreach ($education as $row) {
        foreach (['qualification', 'college_name', 'university_board', 'year_from'] as $field) {
            if (!sidebar_blank($row[$field] ?? '')) {
                $eduFormFilled++;
            }
        }
        $yearTo = (string)($row['year_to'] ?? $row['year_of_passing'] ?? '');
        if ($yearTo !== '' && is_numeric($yearTo) && (int)$yearTo > (int)date('Y') + 1) {
            $eduIssues[] = sidebar_issue('future_date', 'Future education year found', 'year_to');
        }
    }
    $eduDocFilled = 0;
    foreach ($education as $row) {
        $eduDocFilled += (int)sidebar_has_doc($row['marksheet_file'] ?? '');
        $eduDocFilled += (int)sidebar_has_doc($row['degree_file'] ?? '');
    }
    $eduDocFilled += count(array_filter($educationDocs, fn($row) => sidebar_has_doc($row['file_name'] ?? '')));
    $eduDocTotal = 0;
    if (!$education) {
        $eduIssues[] = sidebar_issue('missing_required_field', 'Education details pending', 'education');
    } else {
        foreach ($education as $row) {
            if (strtoupper(trim((string)($row['insufficient_documents'] ?? ''))) === 'INSUFFICIENT_DOCUMENTS') {
                $eduIssues[] = sidebar_issue('mandatory_document_missing', 'Education documents required', 'education_documents');
                $eduDocTotal = max(1, $eduDocTotal);
                break;
            }
        }
    }
    $eduSection = sidebar_section('education', 'Education', $eduFormFilled, $eduFormTotal, min($eduDocFilled, $eduDocTotal), $eduDocTotal, $eduIssues, !empty($education), $activeCorrections['education'] ?? []);
    if (!empty($education) && $eduDocTotal > 0 && $eduDocFilled < $eduDocTotal) {
        $eduSection['message'] = "{$eduDocFilled} of {$eduDocTotal} documents uploaded";
    }
    $sections[] = $eduSection;

    $employment = sidebar_call_all($pdo, 'SP_Vati_Payfiller_get_employment_details', $applicationId);
    if ($employment) {
        $extras = EmploymentService::fetchExtras($pdo, $applicationId);
        $extrasByIndex = [];
        foreach ($extras as $extra) {
            $extrasByIndex[(int)($extra['employment_index'] ?? 0)] = $extra;
        }
        foreach ($employment as &$employmentRow) {
            $employmentIndex = (int)($employmentRow['employment_index'] ?? 0);
            if ($employmentIndex > 0 && isset($extrasByIndex[$employmentIndex])) {
                $employmentRow = array_merge($employmentRow, $extrasByIndex[$employmentIndex]);
            }
        }
        unset($employmentRow);
    }
    $empIssues = [];
    $empFormFilled = 0;
    $empFormTotal = max(6, count($employment) * 6);
    $empDocFilled = 0;
    $empDocTotal = max(1, count($employment));
    $periods = [];
    foreach ($employment as $index => $row) {
        $isFresher = (string)($row['is_fresher'] ?? '') === '1' || strtolower((string)($row['is_fresher'] ?? '')) === 'yes';
        if ($isFresher) {
            $empFormFilled = $empFormTotal;
            $empDocFilled = $empDocTotal;
            continue;
        }
        foreach (['employer_name', 'job_title', 'employee_id', 'job_location', 'joining_date', 'reason_leaving'] as $field) {
            if (!sidebar_blank($row[$field] ?? '')) {
                $empFormFilled++;
            }
        }
        $employmentStatus = trim((string)($row['employment_status'] ?? ''));
        if (strtolower((string)($row['currently_employed'] ?? '')) === 'yes') {
            $employmentStatus = 'currently_employed';
        } elseif ($employmentStatus === '') {
            $employmentStatus = strtolower((string)($row['currently_employed'] ?? '')) === 'yes'
                ? 'currently_employed'
                : 'resigned';
        }
        if ($employmentStatus !== '') {
            $empFormFilled++;
        }
        $currentLike = in_array($employmentStatus, ['currently_employed', 'serving_notice'], true);
        if ($currentLike && !sidebar_blank($row['tentative_relieving_date'] ?? '') && strtotime((string)$row['tentative_relieving_date']) <= strtotime('today')) {
            $empIssues[] = sidebar_issue('invalid_date', 'Tentative relieving date invalid', 'tentative_relieving_date');
        }
        if (sidebar_has_doc($row['employment_doc'] ?? '')) {
            $empDocFilled++;
        }
        if (!sidebar_blank($row['joining_date'] ?? '') && strtotime((string)$row['joining_date']) > time()) {
            $empIssues[] = sidebar_issue('future_date', 'Future employment date found', 'joining_date');
        }
        if (!$currentLike && !sidebar_blank($row['joining_date'] ?? '') && !sidebar_blank($row['relieving_date'] ?? '')) {
            $start = strtotime((string)$row['joining_date']);
            $end = strtotime((string)$row['relieving_date']);
            if ($start && $end && $start > $end) {
                $empIssues[] = sidebar_issue('invalid_date', 'Employment dates invalid', 'relieving_date');
            }
            if ($start && $end) {
                $periods[] = [
                    'start' => $start,
                    'end' => $end,
                    'index' => $index + 1,
                    'row' => $row,
                ];
            }
        }
    }
    if (!$employment) {
        $empIssues[] = sidebar_issue('missing_required_field', 'Employment details pending', 'employment');
    } elseif ($empDocFilled < $empDocTotal) {
        array_unshift($empIssues, sidebar_issue('mandatory_document_missing', 'Employment proof missing', 'employment_doc'));
    }
    $sections[] = sidebar_section('employment', 'Employment', $empFormFilled, $empFormTotal, $empDocFilled, $empDocTotal, $empIssues, !empty($employment), $activeCorrections['employment'] ?? []);

    $referenceRequired = !$hasRequiredComponentScope
        || isset($requiredComponents['reference'])
        || isset($requiredComponents['education_reference'])
        || isset($requiredComponents['employment_reference']);

    if (!$referenceRequired) {
        $sections[] = sidebar_section('reference', 'Reference', 1, 1, 0, 0, [], true, $activeCorrections['reference'] ?? []);
    } else {
        $reference = sidebar_fetch_one($pdo, "SELECT * FROM Vati_Payfiller_Candidate_Reference_details WHERE application_id = ? ORDER BY updated_at DESC, created_at DESC LIMIT 1", [$applicationId]);
        $referenceFields = [];
        if (!$hasRequiredComponentScope || isset($requiredComponents['reference']) || isset($requiredComponents['education_reference'])) {
            $referenceFields['education_reference_name'] = 'Education reference';
        }
        if (!$hasRequiredComponentScope || isset($requiredComponents['reference']) || isset($requiredComponents['employment_reference'])) {
            $referenceFields['employment_reference_name'] = 'Employment reference';
        }
        [$filled, $total] = sidebar_required_score($reference, $referenceFields ?: [
            'education_reference_name' => 'Education reference',
            'employment_reference_name' => 'Employment reference',
        ]);
        $referenceIssues = [];
        if ($reference && $filled > 0 && $filled < $total) {
            $referenceIssues[] = sidebar_issue('missing_required_field', 'Reference details incomplete', 'reference');
        }
        $sections[] = sidebar_section('reference', 'Reference', $filled, $total, 0, 0, $referenceIssues, !empty($reference), $activeCorrections['reference'] ?? []);
    }

    $social = sidebar_call_one($pdo, 'SP_Vati_Payfiller_get_social_media_details', $applicationId);
    $sections[] = sidebar_section('social', 'Social Media', !empty($social) ? 1 : 0, 1, 0, 0, [], !empty($social), $activeCorrections['social'] ?? []);

    $sections = SidebarService::sortSections($sections);
    $sections = sidebar_hide_untouched_warnings($sections, sidebar_touched_sections());

    $blockingIssues = [];
    foreach ($sections as $section) {
        foreach ($section['issues'] as $issue) {
            $blockingIssues[] = ['section' => $section['label'], 'section_key' => $section['key']] + $issue;
        }
        if (in_array($section['status'], ['correction_required', 'waiting_mobile_upload'], true)) {
            $blockingIssues[] = [
                'section' => $section['label'],
                'section_key' => $section['key'],
                'type' => $section['status'],
                'message' => $section['message'],
                'field' => '',
            ];
        }
    }
    usort($blockingIssues, function ($a, $b) {
        $pa = SidebarService::issuePriority((string)($a['type'] ?? ''));
        $pb = SidebarService::issuePriority((string)($b['type'] ?? ''));
        return $pa <=> $pb;
    });

    $completion = (int)round(array_sum(array_column($sections, 'score')) / max(1, count($sections)));
    $issuesRemaining = count($blockingIssues);
    $nextAction = $issuesRemaining > 0
        ? (($blockingIssues[0]['message'] ?? 'Complete pending item'))
        : 'Ready for submission';

    sidebar_json([
        'success' => true,
        'completion' => $completion,
        'ready_for_submission' => $issuesRemaining === 0,
        'issues_remaining' => $issuesRemaining,
        'next_required_action' => $nextAction,
        'sections' => $sections,
        'blocking_issues' => $blockingIssues,
    ]);
} catch (Throwable $e) {
    sidebar_json(['success' => false, 'message' => $e->getMessage()], 500);
}
