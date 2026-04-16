<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = getApplicationId();
ensureApplicationExists($application_id);

$pdo = getDB();

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

$defaultCount = max(1, count($rows));
$maxCount = 4;
?>

<div class="candidate-form compact-form cr-fixed-form bgv-fixed-form create-like-spacing">

    <!-- HEADER -->
    <div class="form-header">
        <i class="fas fa-graduation-cap"></i> Education Details
    </div>

    <p class="text-muted mb-3">
        List your academic qualifications (highest first).
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
    <form id="educationForm" enctype="multipart/form-data">
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

        <div class="education-card-header compact-header">
            <h6>Education <span class="education-num">1</span></h6>
        </div>

        <div class="education-card-body compact-body">

            <!-- ROW 1 -->
            <div class="form-row-3 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Qualification *</label>
                        <select name="qualification[]" class="compact-select">
                            <option value="">Select qualification</option>
                            <option value="10th">10th</option>
                            <option value="12th">12th</option>
                            <option value="Diploma">Diploma</option>
                            <option value="UG">UG</option>
                            <option value="PG">PG</option>
                        </select>
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">College / Institution *</label>
                        <input type="text" name="college_name[]" class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">University / Board *</label>
                        <input type="text" name="university_board[]" class="compact-input">
                    </div>
                </div>
            </div>

            <!-- ROW 2 -->
            <div class="form-row-3 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Roll Number *</label>
                        <input type="text" name="roll_number[]" class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">From Year *</label>
                        <input type="month" name="year_from[]" class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">To Year *</label>
                        <input type="month" name="year_to[]" class="compact-input">
                    </div>
                </div>
            </div>

            <!-- ROW 3: Address + Website -->
            <div class="form-row-3 compact-row mb-2">
                <div class="form-field col-span-2">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">College Address *</label>
                        <input type="text" name="college_address[]" class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">College Website</label>
                        <input type="text" name="college_website[]" class="compact-input" placeholder="https://example.com">
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
                            </div>
                            <div class="file-upload-error" data-file-error></div>
                        </div>
                        <small class="text-muted compact-hint document-instruction marksheet-instruction"></small>
                        <input type="file"
                               name="marksheet_file[]"
                               class="compact-file d-none"
                               accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                               data-file-input>
                    </div>
                </div>

                <div class="form-field degree-upload-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label degree-label">Degree Certificate</label>
                        <div class="file-upload-box" data-file-upload>
                            <div class="file-upload-row">
                                <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                                <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
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

            <!-- ROW 5: NO FURTHER EDUCATIONS -->
            <div class="form-row-1 compact-row mb-2 no-further-education-row">
                <div class="form-field">
                    <div class="form-check normal-checkbox compact-checkbox">
                        <input type="checkbox"
                               class="form-check-input no-further-education-checkbox"
                               value="1">
                        <label class="form-check-label compact-checkbox-label">
                            I don't have further educations
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
