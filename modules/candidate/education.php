<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../api/shared/candidate_correction_service.php';
require_once __DIR__ . '/../../services/candidate/EducationService.php';

ccs_guard_candidate_page('education');

$application_id = getApplicationId();
ensureApplicationExists($application_id);

$pdo = getDB();

function candidate_fetch_education_documents(PDO $pdo, string $applicationId): array {
    $rows = EducationService::fetchDocuments($pdo, $applicationId);
    $out = [];
    foreach ($rows as $row) {
        $idx = (int)($row['education_index'] ?? 0);
        if ($idx <= 0) continue;
        $out[$idx][] = $row;
    }
    return $out;
}

/* Fetch education details */
$stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_education_details(?)");
$stmt->execute([$application_id]);
$dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

/* Normalize rows */
$rows = [];
foreach ($dbRows as $row) {
    $idx = ((int)$row['education_index']) - 1;
    if ($idx >= 0) $rows[$idx] = $row;
}
$rows = array_values($rows);
$educationMetaMap = [];
try {
    $metaStmt = $pdo->prepare("
        SELECT education_index, ca_membership_number, year_of_passing,
               education_gap_reason, education_gap_explanation, education_order_explanation,
               institution_id, institution_display_name, manual_institution_name, institution_match_status
        FROM Vati_Payfiller_Candidate_Education_details
        WHERE application_id = ?
    ");
    $metaStmt->execute([$application_id]);
    foreach (($metaStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $metaRow) {
        $educationMetaMap[(int)($metaRow['education_index'] ?? 0)] = $metaRow;
    }
} catch (Throwable $e) {
    $educationMetaMap = [];
}
$educationDocs = candidate_fetch_education_documents($pdo, $application_id);
foreach ($rows as $i => $row) {
    $idx = (int)($row['education_index'] ?? ($i + 1));
    if (isset($educationMetaMap[$idx])) {
        $rows[$i]['ca_membership_number'] = $educationMetaMap[$idx]['ca_membership_number'] ?? ($rows[$i]['ca_membership_number'] ?? '');
        $rows[$i]['year_of_passing'] = $educationMetaMap[$idx]['year_of_passing'] ?? ($rows[$i]['year_of_passing'] ?? '');
        $rows[$i]['education_gap_reason'] = $educationMetaMap[$idx]['education_gap_reason'] ?? ($rows[$i]['education_gap_reason'] ?? '');
        $rows[$i]['education_gap_explanation'] = $educationMetaMap[$idx]['education_gap_explanation'] ?? ($rows[$i]['education_gap_explanation'] ?? '');
        $rows[$i]['education_order_explanation'] = $educationMetaMap[$idx]['education_order_explanation'] ?? ($rows[$i]['education_order_explanation'] ?? '');
        $rows[$i]['institution_id'] = $educationMetaMap[$idx]['institution_id'] ?? ($rows[$i]['institution_id'] ?? '');
        $rows[$i]['institution_display_name'] = $educationMetaMap[$idx]['institution_display_name'] ?? ($rows[$i]['institution_display_name'] ?? '');
        $rows[$i]['manual_institution_name'] = $educationMetaMap[$idx]['manual_institution_name'] ?? ($rows[$i]['manual_institution_name'] ?? '');
        $rows[$i]['institution_match_status'] = $educationMetaMap[$idx]['institution_match_status'] ?? ($rows[$i]['institution_match_status'] ?? '');
    }
    $docs = array_values($educationDocs[$idx] ?? []);
    $rows[$i]['marksheet_documents'] = array_values(array_filter($docs, static function ($doc) {
        return strtolower((string)($doc['document_slot'] ?? '')) === 'marksheet';
    }));
    $rows[$i]['supporting_documents'] = array_values(array_filter($docs, static function ($doc) {
        return strtolower((string)($doc['document_slot'] ?? '')) !== 'marksheet';
    }));
}

$defaultCount = max(1, count($rows));
$maxCount = 8;
?>

<div class="candidate-form compact-form cr-fixed-form bgv-fixed-form create-like-spacing">

    <!-- HEADER -->
    <div class="form-header">
        <i class="fas fa-graduation-cap"></i> Education Details
    </div>

    <p class="text-muted mb-3">
        List your academic qualifications in highest-to-lowest order. Keep recent/higher education first, then older school records.
    </p>

    <!-- COUNT + TABS (COMPACT BAR) -->
    <div class="compact-card education-toolbar mb-3">
        <div class="education-count">
            <div class="form-control double-border compact-control">
                <label class="compact-label">
                    Number of Qualifications <span class="required">*</span>
                </label>
                <select id="educationCount" class="compact-select">
                    <?php for ($i = 1; $i <= $maxCount; $i++): ?>
                        <option value="<?= $i ?>" <?= $i === $defaultCount ? 'selected' : '' ?>>
                            <?= $i ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>

        <div class="tabs-container compact-tabs">
            <div class="education-tabs" id="educationTabs"></div>
        </div>
    </div>

    <!-- FORM -->
    <form id="educationForm" enctype="multipart/form-data" data-server-visible-count="<?= (int)$defaultCount ?>">
        <input type="hidden" name="visibleEducationCount" id="visibleEducationCount" value="<?= (int)$defaultCount ?>">
        <div id="educationContainer"></div>

    </form>

    <!-- DATA -->
    <div id="educationData"
         data-rows='<?= htmlspecialchars(json_encode($rows), ENT_QUOTES) ?>'
         data-default-count="<?= $defaultCount ?>"
         style="display:none"></div>

    <div class="form-footer compact-footer">
        <button type="button"
                class="btn-outline prev-btn"
                data-form="educationForm">
            <i class="fas fa-arrow-left me-2"></i> Previous
        </button>

        <div class="footer-actions-right">
            <!-- <button type="button"
                    class="btn-secondary save-draft-btn"
                    data-page="education">
                Save Draft
            </button> -->

            <button type="button"
                    class="btn-primary external-submit-btn"
                    data-form="educationForm">
                Next <i class="fas fa-arrow-right ms-2"></i>
            </button>
        </div>
    </div>
</div>

<!-- ================= TEMPLATE ================= -->
<template id="educationTemplate">
    <div class="compact-card education-card mb-3">

        <input type="hidden" name="id[]">
        <input type="hidden" name="education_index[]">
        <input type="hidden" name="education_state[]" value="ACTIVE">
        <input type="hidden" name="old_marksheet_file[]">
        <input type="hidden" name="old_degree_file[]">
        <input type="hidden" data-marksheet-json>
        <input type="hidden" data-supporting-json>

        <div class="education-card-header compact-header">
            <h6>Education <span class="education-num">1</span></h6>
        </div>

        <div class="education-card-body compact-body">

            <!-- ROW 1 -->
            <div class="form-row-3 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Qualification *</label>
                        <select name="qualification[]" class="compact-select edu-qualification-select">
                            <option value="">Select qualification</option>
                            <option value="10th">10th</option>
                            <option value="12th">12th</option>
                            <option value="Diploma">Diploma</option>
                            <option value="International">International</option>
                            <option value="PG">PG</option>
                            <option value="PhD">PhD</option>
                            <option value="PUC">PUC</option>
                            <option value="SSLC">SSLC</option>
                            <option value="UG">UG</option>
                        </select>
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">University / Board *</label>
                        <select name="university_board[]" class="compact-select edu-univ-board-select" data-university-board-select>
                            <option value="">Select or type...</option>
                        </select>
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">College / Institution *</label>
                        <div class="institution-select-shell">
                            <input type="text" name="college_name[]" class="compact-input institution-search-input" autocomplete="off" placeholder="Search institution" data-institution-search>
                            <button type="button" class="institution-select-trigger" data-institution-trigger aria-label="Search institution">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <input type="hidden" name="institution_id[]" data-institution-id>
                        <input type="hidden" name="institution_display_name[]" data-institution-display-name>
                        <input type="hidden" name="manual_institution_name[]" data-manual-institution-name>
                        <input type="hidden" name="institution_match_status[]" data-institution-match-status value="manual_pending">
                        <div class="institution-search-panel" data-institution-panel></div>
                    </div>
                </div>
            </div>
            <!-- ROW 2 -->
            <div class="form-row-4 compact-row mb-2 education-meta-row">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Roll Number *</label>
                        <input type="text" name="roll_number[]" class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">From Year *</label>
                        <input type="month" name="year_from[]" class="compact-input" max="<?= htmlspecialchars(date('Y-m')) ?>">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">To Year *</label>
                        <input type="month" name="year_to[]" class="compact-input" max="<?= htmlspecialchars(date('Y-m')) ?>">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Year of Passing</label>
                        <input type="text" name="year_of_passing[]" class="compact-input" placeholder="2023">
                    </div>
                </div>
            </div>

            <!-- ROW 3: Address + Website + CA Membership -->
            <div class="form-row-3 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Place of the Institution *</label>
                        <input type="text" name="college_address[]" class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">College Website</label>
                        <input type="text" name="college_website[]" class="compact-input" placeholder="https://example.com">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">CA Membership No</label>
                        <input type="text" name="ca_membership_number[]" class="compact-input">
                    </div>
                </div>
            </div>

            <!-- ROW 4: DOCUMENTS -->
            <div class="form-row-2 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label marksheet-label">Marksheet</label>
                        <div class="file-upload-box" data-file-upload>
                            <div class="file-upload-row">
                                <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                                <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
                                <button type="button" class="file-upload-remove" data-file-remove aria-label="Remove marksheet" style="display:none;">
                                    <i class="fas fa-times"></i>
                                </button>
                                <div class="compact-hint marksheet-doc-list"></div>
                            </div>
                            <div class="file-upload-error" data-file-error></div>
                        </div>
                        <small class="text-muted compact-hint document-instruction marksheet-instruction"></small>
                        <input type="file"
                               name="marksheet_file[]"
                               class="compact-file d-none"
                               accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                               data-file-input>
                        <input type="file"
                               name="marksheet_documents_0[]"
                               class="compact-file d-none marksheet-documents-input"
                               accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                               data-file-input
                               multiple>
                    </div>
                </div>

                <div class="form-field degree-upload-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label degree-label">Degree Certificate</label>
                        <div class="file-upload-box" data-file-upload>
                            <div class="file-upload-row">
                                <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                                <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
                                <button type="button" class="file-upload-remove" data-file-remove aria-label="Remove degree certificate" style="display:none;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="file-upload-error" data-file-error></div>
                        </div>
                        <small class="text-muted compact-hint document-instruction degree-instruction"></small>
                        <input type="file"
                               name="degree_file[]"
                               class="compact-file d-none"
                               accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                               data-file-input>
                    </div>
                </div>
            </div>

            <!-- ROW 5: CONTINUATION -->
            <div class="form-row-1 compact-row mb-2 no-further-education-row">
                <div class="form-field">
                    <div class="form-check normal-checkbox compact-checkbox">
                        <input type="checkbox"
                               class="form-check-input no-further-education-checkbox"
                               value="1">
                        <label class="form-check-label compact-checkbox-label">
                            I have further educations
                        </label>
                    </div>
                </div>
            </div>

            <!-- ROW 6: INSUFFICIENT DOCUMENTS -->
            <!-- <div class="form-row-1 compact-row mb-2">
                <div class="form-field">
                    <div class="form-check normal-checkbox compact-checkbox">
                        <input type="checkbox"
                               class="form-check-input"
                               name="insufficient_education_docs[]"
                               value="1">
                        <label class="form-check-label compact-checkbox-label">
                            Insufficient Education Documents
                        </label>
                    </div>
                </div>
            </div> -->

        </div>
    </div>
</template>
