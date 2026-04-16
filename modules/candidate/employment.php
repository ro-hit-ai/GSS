<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = getApplicationId();
ensureApplicationExists($application_id);

$pdo = getDB();

// Use stored procedure
$stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_employment_details(?)");
$stmt->execute([$application_id]);
$dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Close the cursor
$stmt->closeCursor();

/* Normalize rows by index */
$rows = [];
foreach ($dbRows as $row) {
    $idx = ((int)$row['employment_index']) - 1;
    if ($idx >= 0) {
        $rows[$idx] = $row;
    }
}
$rows = array_values($rows);

/* Fresher detection from database */
$isFresher = (!empty($rows[0]['is_fresher']) && $rows[0]['is_fresher'] === 'yes');
$defaultCount = $isFresher ? 1 : max(1, count($rows));
$maxCount = 5;
?>

<div class="candidate-form compact-form cr-fixed-form bgv-fixed-form create-like-spacing employment-create-compact">

    <!-- HEADER -->
    <div class="form-header">
        <i class="fas fa-briefcase"></i> Employment Details
    </div>

    <p class="text-muted mb-3">
        Please list your recent employers starting with the most recent one.
    </p>

    <div id="employmentFresherMessage" class="alert alert-info mb-3" style="display: none;">
        Fresher selected. No employment details required
    </div>

    <!-- COUNT + TABS (COMPACT BAR) -->
        <div class="compact-card employment-toolbar mb-3">
        <div class="employment-count">
            <div class="form-control double-border compact-control">
                <label class="compact-label">Number of Employments <span class="required">*</span></label>
                <select id="employmentCount" class="compact-select" <?= $isFresher ? 'disabled' : '' ?>>
                    <?php for ($i = 1; $i <= $maxCount; $i++): ?>
                        <option value="<?= $i ?>" <?= $i === $defaultCount ? 'selected' : '' ?>>
                            <?= $i ?> employment<?= $i > 1 ? 's' : '' ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>

        <div class="tabs-container compact-tabs">
            <div class="employment-tabs" id="employmentTabs"></div>
        </div>

        <!-- <div class="employment-hint-badge">
            <i class="fas fa-info-circle"></i>
            If you are a fresher, select <strong>Yes</strong> and fill only the first employer.
        </div> -->
        </div>

    <!-- Form -->
        <form id="employmentForm" enctype="multipart/form-data">
            <div id="employmentContainer"></div>
            <input type="hidden" name="application_id" value="<?= $application_id ?>">
        </form>

        <!-- Hidden data -->
        <div id="employmentData"
             data-rows='<?= htmlspecialchars(json_encode($rows), ENT_QUOTES, "UTF-8") ?>'
             data-is-fresher="<?= $isFresher ? 'true' : 'false' ?>"
             data-default-count="<?= $defaultCount ?>"
             style="display:none"></div>

    <div class="form-footer compact-footer">
        <button type="button" class="btn btn-outline prev-btn">
            <i class="fas fa-arrow-left me-2"></i> Previous
        </button>
        
        <div class="footer-actions-right">
            <!-- <button type="button" class="btn btn-secondary save-draft-btn" data-page="employment">
                Save Draft
            </button> -->
            
            <button type="button" class="btn btn-primary external-submit-btn" data-form="employmentForm">
                Next <i class="fas fa-arrow-right ms-2"></i>
            </button>
        </div>
    </div>

</div>

<!-- ================= COMPACT TEMPLATE ================= -->
<template id="employmentTemplate">
    <div class="compact-card employment-card mb-3">
        <input type="hidden" name="id[]" value="">
        <input type="hidden" name="employment_index[]" value="">

        <!-- First employer questions (compact) -->
        <div class="first-employer-fields" style="display: none;">
            <div class="form-row-3 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Are you a fresher? <span class="required">*</span></label>
                        <div class="radio-options compact">
                            <label class="radio-option">
                                <input type="radio" name="is_fresher[0]" value="yes">
                                <span>Yes</span>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="is_fresher[0]" value="no" checked>
                                <span>No</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Currently employed? <span class="required">*</span></label>
                        <div class="radio-options compact">
                            <label class="radio-option">
                                <input type="radio" name="currently_employed[0]" value="yes">
                                <span>Yes</span>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="currently_employed[0]" value="no" checked>
                                <span>No</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-field contact-employer-field" style="display:none;">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Contact employer? <span class="required">*</span></label>
                        <div class="radio-options compact">
                            <label class="radio-option">
                                <input type="radio" name="contact_employer[0]" value="yes">
                                <span>Yes</span>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="contact_employer[0]" value="no" checked>
                                <span>No</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card Header -->
        <div class="employment-card-header compact-header">
            <h6 class="mb-0">Employment <span class="employer-num">1</span></h6>
            <span class="employment-badge compact-badge">
                <i class="fas fa-building"></i>
            </span>
        </div>

        <div class="employment-card-body compact-body">
            <!-- Row 1: Basic Info -->
            <div class="form-row-3 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Employer Name <span class="required">*</span></label>
                        <input type="text" name="employer_name[]" required class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Job Title <span class="required">*</span></label>
                        <input type="text" name="job_title[]" required class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Employee ID <span class="required">*</span></label>
                        <input type="text" name="employee_id[]" required class="compact-input">
                    </div>
                </div>
            </div>

            <!-- Row 2: Dates -->
            <div class="form-row-2 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Start Date <span class="required">*</span></label>
                        <input type="date" name="joining_date[]" required class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">End Date</label>
                        <input type="date" name="relieving_date[]" class="compact-input">
                    </div>
                </div>
            </div>

            <!-- Row 3: Employment Proof -->
            <div class="form-row-2 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Employment Document Type <span class="required">*</span></label>
                        <select name="employment_doc_type[]" class="compact-select">
                            <option value="">Select document type</option>
                        </select>
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Employment Proof <span class="required">*</span></label>
                        <div class="file-upload-box" data-file-upload>
                            <div class="file-upload-row">
                                <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                                <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
                            </div>
                            <div class="file-upload-error" data-file-error></div>
                        </div>
                        <input type="file"
                               name="employment_doc[]"
                               class="compact-file d-none"
                               accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                               data-file-input>
                    </div>
                </div>
            </div>

            <!-- Row 4: Reason -->
            <div class="form-row-1 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Reason for Leaving <span class="required">*</span></label>
                        <textarea name="reason_leaving[]" rows="1" required class="compact-textarea"></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Row 5: HR Details -->
            <div class="form-row-3 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">HR Name</label>
                        <input type="text" name="hr_manager_name[]" class="compact-input">
                    </div>
                </div>
                
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">HR Phone</label>
                        <input type="tel" name="hr_manager_phone[]" class="compact-input">
                    </div>
                </div>
                
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">HR Email</label>
                        <input type="email" name="hr_manager_email[]" class="compact-input">
                    </div>
                </div>
            </div>

            <!-- Row 6: Manager Details -->
            <div class="form-row-3 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Manager Name</label>
                        <input type="text" name="manager_name[]" class="compact-input">
                    </div>
                </div>
                
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Manager Phone</label>
                        <input type="tel" name="manager_phone[]" class="compact-input">
                    </div>
                </div>
                
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Manager Email</label>
                        <input type="email" name="manager_email[]" class="compact-input">
                    </div>
                </div>
            </div>

            <!-- Row 6: Employment Proof -->
            <!-- <div class="form-row-full compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Employment Proof <span class="required">*</span></label>
                        <input type="file" name="employment_doc[]" class="compact-file" accept=".pdf,.jpg,.jpeg,image/jpeg,application/pdf">
                        <p class="upload-hint compact-hint">
                            Offer/Salary/Relieving letter (Max 5MB)
                        </p>
                        <input type="hidden" name="old_employment_doc[]" value="">
                        <div class="employment-doc-preview mt-1"></div>
                    </div>
                </div>
            </div> -->

            <!-- <div class="form-row-1 compact-row mb-2">
                <div class="form-field">
                    <div class="form-check normal-checkbox compact-checkbox">
                        <input type="checkbox" 
                               name="insufficient_employment_docs[]" 
                               class="form-check-input insufficient-emp-checkbox" 
                               value="1">
                        <label class="form-check-label compact-checkbox-label">
                            Insufficient Employment Proof
                        </label>
                        <small class="text-muted compact-hint">
                            Check if document is unavailable
                        </small>
                    </div>
                </div>
                <div class="form-field"></div>
            </div> -->
        </div>
    </div>
</template>
