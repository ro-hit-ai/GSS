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

<style>
    .bgv-fixed-form {
        height: auto;
        max-height: none;
        min-height: 0;
        display: block;
        overflow: visible;
        border-radius: 16px;
    }

    .bgv-fixed-form .form-header {
        position: sticky;
        top: 0;
        background: #0b1220;
        color: #ffffff;
        padding: 10px 14px;
        border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 10px;
        z-index: 10;
        margin: 0 0 12px;
        flex-shrink: 0;
    }

    .bgv-fixed-form .form-header i {
        color: #93c5fd;
    }

    .bgv-fixed-form .form-content {
        padding: 20px 24px;
    }

    .bgv-fixed-form .form-footer {
        position: relative;
        bottom: auto;
        background: #ffffff;
        padding: 16px 24px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .bgv-fixed-form .education-toolbar {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 12px 16px;
        background: #f8fafc;
        border-radius: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
        flex-shrink: 0;
    }

    .bgv-fixed-form .tabs-container {
        flex: 1;
        overflow-x: auto;
        min-width: 0;
        scrollbar-width: thin;
    }

    .bgv-fixed-form .education-tab {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 6px 14px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        white-space: nowrap;
        margin-right: 8px;
    }

    .bgv-fixed-form .education-tab.active {
        background: #3b82f6;
        color: #ffffff;
        border-color: #3b82f6;
    }

    .bgv-fixed-form .tab-dot {
        font-size: 8px;
        margin-left: 4px;
    }

    .bgv-fixed-form .compact-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        margin-bottom: 16px;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }

    .bgv-fixed-form .compact-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .bgv-fixed-form .compact-header {
        background: #f8fafc;
        padding: 10px 12px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .bgv-fixed-form .compact-header h6 {
        font-size: 13px;
        font-weight: 600;
        color: #1e293b;
        margin: 0;
    }

    .bgv-fixed-form .compact-body {
        padding: 12px;
    }

    .bgv-fixed-form .compact-body .form-row-3 {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin-bottom: 12px;
    }

    .bgv-fixed-form .compact-body .form-row-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 12px;
    }

    .bgv-fixed-form .compact-body .form-row-4 {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-bottom: 12px;
    }

    .bgv-fixed-form .compact-control {
        padding: 6px 10px !important;
    }

    .bgv-fixed-form .compact-input,
    .bgv-fixed-form .compact-select {
        height: 32px !important;
        font-size: 13px !important;
    }

    .bgv-fixed-form .compact-textarea {
        min-height: 50px !important;
        font-size: 13px !important;
    }

    @media (max-width: 992px) {
        .bgv-fixed-form .compact-body .form-row-3 {
            grid-template-columns: repeat(2, 1fr);
        }

        .bgv-fixed-form .compact-body .form-row-4 {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .bgv-fixed-form .compact-body .form-row-3,
        .bgv-fixed-form .compact-body .form-row-2 {
            grid-template-columns: 1fr;
        }

        .bgv-fixed-form .compact-body .form-row-4 {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="candidate-form compact-form cr-fixed-form bgv-fixed-form">

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
            <button type="button"
                    class="btn-secondary save-draft-btn"
                    data-page="education">
                Save Draft
            </button>

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
                        <input type="text" name="qualification[]" class="compact-input">
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
            <div class="form-row-4 compact-row mb-2">
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

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">College Website</label>
                        <input type="text" name="college_website[]" class="compact-input" placeholder="https://example.com">
                    </div>
                </div>
            </div>

            <!-- ROW 3 -->
            <div class="form-row-3 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">College Address *</label>
                        <input type="text" name="college_address[]" class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Marksheet</label>
                        <div class="file-upload-box" data-file-upload>
                            <div class="file-upload-row">
                                <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                                <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
                            </div>
                            <div class="file-upload-error" data-file-error></div>
                        </div>
                        <input type="file"
                               name="marksheet_file[]"
                               class="compact-file d-none"
                               accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                               data-file-input>
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Degree Certificate</label>
                        <div class="file-upload-box" data-file-upload>
                            <div class="file-upload-row">
                                <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                                <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
                            </div>
                            <div class="file-upload-error" data-file-error></div>
                        </div>
                        <input type="file"
                               name="degree_file[]"
                               class="compact-file d-none"
                               accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                               data-file-input>
                    </div>
                </div>
            </div>

            <!-- ROW 4: DOCUMENTS -->
            <!-- <div class="form-row-2 compact-row mb-2">
               

               
            </div> -->

            <!-- ROW 5: CHECKBOX -->
            <div class="form-row-1 compact-row mb-2">
                <div class="form-field">
                    <div class="form-check normal-checkbox compact-checkbox">
                        <input type="checkbox"
                               name="insufficient_education_docs[]"
                               value="1">
                        <label class="compact-checkbox-label">
                            Insufficient Education Documents
                        </label>
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>
