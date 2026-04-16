<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = getApplicationId();
ensureApplicationExists($application_id);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM `Vati_Payfiller_Candidate_Reference_details` WHERE `application_id` = ?");
$stmt->execute([$application_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$educationReference = [
    'name' => $row['education_reference_name'] ?? '',
    'designation' => $row['education_reference_designation'] ?? '',
    'company' => $row['education_reference_company'] ?? '',
    'relationship' => $row['education_reference_relationship'] ?? '',
    'years_known' => $row['education_reference_years_known'] ?? '',
    'mobile' => $row['education_reference_mobile'] ?? '',
    'email' => $row['education_reference_email'] ?? '',
];

$employmentReference = [
    'name' => $row['employment_reference_name'] ?? ($row['reference_name'] ?? ''),
    'designation' => $row['employment_reference_designation'] ?? ($row['reference_designation'] ?? ''),
    'company' => $row['employment_reference_company'] ?? ($row['reference_company'] ?? ''),
    'relationship' => $row['employment_reference_relationship'] ?? ($row['relationship'] ?? ''),
    'years_known' => $row['employment_reference_years_known'] ?? ($row['years_known'] ?? ''),
    'mobile' => $row['employment_reference_mobile'] ?? ($row['reference_mobile'] ?? ''),
    'email' => $row['employment_reference_email'] ?? ($row['reference_email'] ?? ''),
];

function renderReferenceSectionTemplate(string $sectionKey, string $title, string $iconClass, array $data, string $companyLabel): void {
    ?>
    <div class="reference-section-card" data-reference-section="<?= htmlspecialchars($sectionKey) ?>" style="display:none;">
        <div class="form-header mb-2" style="font-size: 18px;">
            <i class="<?= htmlspecialchars($iconClass) ?>"></i> <?= htmlspecialchars($title) ?>
        </div>

        <div class="form-row-2 compact-row mb-2">
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Reference Name <span class="required">*</span></label>
                    <input type="text" name="<?= htmlspecialchars($sectionKey) ?>_name" class="compact-input"
                           value="<?= htmlspecialchars($data['name'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Designation <span class="required">*</span></label>
                    <input type="text" name="<?= htmlspecialchars($sectionKey) ?>_designation" class="compact-input"
                           value="<?= htmlspecialchars($data['designation'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="form-row-2 compact-row mb-2">
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label"><?= htmlspecialchars($companyLabel) ?> <span class="required">*</span></label>
                    <input type="text" name="<?= htmlspecialchars($sectionKey) ?>_company" class="compact-input"
                           value="<?= htmlspecialchars($data['company'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Relationship <span class="required">*</span></label>
                    <input type="text" name="<?= htmlspecialchars($sectionKey) ?>_relationship" class="compact-input"
                           value="<?= htmlspecialchars($data['relationship'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="form-row-3 compact-row mb-3">
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Years Known <span class="required">*</span></label>
                    <input type="number" name="<?= htmlspecialchars($sectionKey) ?>_years_known" min="1" max="50" class="compact-input"
                           value="<?= htmlspecialchars($data['years_known'] ?? '') ?>">
                </div>
            </div>
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Mobile <span class="required">*</span></label>
                    <input type="tel" name="<?= htmlspecialchars($sectionKey) ?>_mobile" class="compact-input"
                           value="<?= htmlspecialchars($data['mobile'] ?? '') ?>">
                </div>
            </div>
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Email <span class="required">*</span></label>
                    <input type="email" name="<?= htmlspecialchars($sectionKey) ?>_email" class="compact-input"
                           value="<?= htmlspecialchars($data['email'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>
    <?php
}
?>

<div class="candidate-form compact-form create-like-spacing reference-create-compact">

    <!-- HEADER -->
    <div class="form-header">
        <i class="fas fa-users"></i> Reference Details
    </div>

    <p class="text-muted mb-3">
        Provide the references required for your selected verification checks.
    </p>

    <!-- Hidden data for JS -->
    <div id="referenceData" 
         data-reference='<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>'
         style="display:none"></div>

    <div id="referenceNoSectionMessage" class="alert alert-info mb-3" style="display:none;">
        No reference details required for your selected verification setup.
    </div>

    <!-- FORM -->
    <form id="referenceForm" enctype="multipart/form-data">
        <input type="hidden" name="has_education_reference" value="0">
        <input type="hidden" name="has_employment_reference" value="0">

        <div class="address-tabs mb-3" id="referenceTabsWrap" style="display:none;">
            <button type="button" class="address-tab-btn active" data-tab-target="education_reference_tab" id="educationReferenceTabBtn" style="display:none;">Education Reference</button>
            <button type="button" class="address-tab-btn" data-tab-target="employment_reference_tab" id="employmentReferenceTabBtn" style="display:none;">Employment Reference</button>
        </div>

        <div id="education_reference_tab" class="reference-tab-pane active">
            <?php renderReferenceSectionTemplate('education_reference', 'Education Reference', 'fas fa-graduation-cap', $educationReference, 'Institution / Company'); ?>
        </div>

        <div id="employment_reference_tab" class="reference-tab-pane" style="display:none;">
            <?php renderReferenceSectionTemplate('employment_reference', 'Employment Reference', 'fas fa-briefcase', $employmentReference, 'Company'); ?>
        </div>


        <!-- Hidden fields -->
        <input type="hidden" name="application_id" value="<?= $application_id ?>">

        <!-- Form Footer -->
        <div class="form-footer compact-footer">
            <button type="button" class="btn-outline prev-btn">
                <i class="fas fa-arrow-left me-2"></i> Previous
            </button>
            
            <div class="footer-actions-right">
                <!-- <button type="button" class="btn-secondary save-draft-btn" data-page="reference">
                    Save Draft
                </button> -->
                
                <button type="button" class="btn-primary external-submit-btn" data-form="referenceForm">
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>

    </form>
</div>
