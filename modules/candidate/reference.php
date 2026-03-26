<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = getApplicationId();
ensureApplicationExists($application_id);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM `Vati_Payfiller_Candidate_Reference_details` WHERE `application_id` = ?");
$stmt->execute([$application_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
?>

<div class="candidate-form compact-form">

    <!-- HEADER -->
    <div class="form-header">
        <i class="fas fa-users"></i> Reference Details
    </div>

    <p class="text-muted mb-3">
        Provide a professional reference.
    </p>

    <!-- Hidden data for JS -->
    <div id="referenceData" 
         data-reference='<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>'
         style="display:none"></div>

    <!-- FORM -->
    <form id="referenceForm" enctype="multipart/form-data">

        <!-- Row 1: Name, Designation -->
        <div class="form-row-2 compact-row mb-2">
            <!-- Reference Name -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Reference Name <span class="required">*</span></label>
                    <input type="text" name="reference_name" required class="compact-input"
                           value="<?= htmlspecialchars($row['reference_name'] ?? '') ?>">
                </div>
            </div>

            <!-- Designation -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Designation <span class="required">*</span></label>
                    <input type="text" name="reference_designation" required class="compact-input"
                           value="<?= htmlspecialchars($row['reference_designation'] ?? '') ?>">
                </div>
            </div>
        </div>

        
        <div class="form-row-2 compact-row mb-2">
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Company <span class="required">*</span></label>
                    <input type="text" name="reference_company" required class="compact-input"
                           value="<?= htmlspecialchars($row['reference_company'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Relationship <span class="required">*</span></label>
                    <input type="text" name="relationship" required class="compact-input"
                           value="<?= htmlspecialchars($row['relationship'] ?? '') ?>">
                </div>
            </div>

     </div>
        <!-- Row 2: Mobile, Email, Years Known -->
        <div class="form-row-3 compact-row mb-2">
             <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Years Known <span class="required">*</span></label>
                    <input type="number" name="years_known" min="1" max="50" required class="compact-input"
                           value="<?= htmlspecialchars($row['years_known'] ?? '') ?>">
                    <!-- <small class="compact-hint">Number of years you have known this reference</small> -->
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Mobile <span class="required">*</span></label>
                    <input type="tel" name="reference_mobile" required class="compact-input"
                           value="<?= htmlspecialchars($row['reference_mobile'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Email <span class="required">*</span></label>
                    <input type="email" name="reference_email" required class="compact-input"
                           value="<?= htmlspecialchars($row['reference_email'] ?? '') ?>">
                </div>
            </div>
        </div>


        <!-- Hidden fields -->
        <input type="hidden" name="application_id" value="<?= $application_id ?>">

        <!-- Form Footer -->
        <div class="form-footer compact-footer">
            <button type="button" class="btn-outline prev-btn">
                <i class="fas fa-arrow-left me-2"></i> Previous
            </button>
            
            <div class="footer-actions-right">
                <button type="button" class="btn-secondary save-draft-btn" data-page="reference">
                    Save Draft
                </button>
                
                <button type="button" class="btn-primary external-submit-btn" data-form="referenceForm">
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>

    </form>
</div>
