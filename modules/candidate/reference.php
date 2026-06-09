<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../api/shared/candidate_correction_service.php';
require_once __DIR__ . '/../../services/candidate/ReferenceService.php';

ccs_guard_candidate_page('reference');

$application_id = getApplicationId();
ensureApplicationExists($application_id);

$pdo = getDB();
$referenceData = ReferenceService::fetchGrouped($pdo, (string)$application_id);

function renderReferenceCardTemplate(): void {
    ?>
    <template id="referenceCardTemplate">
        <div class="reference-section-card" data-reference-card style="display:none;">
            <div class="form-row-2 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Reference Name <span class="required">*</span></label>
                        <input type="text" data-field="name" class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Designation <span class="required">*</span></label>
                        <input type="text" data-field="designation" class="compact-input">
                    </div>
                </div>
            </div>

            <div class="form-row-2 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label" data-company-label>Institution / Company <span class="required">*</span></label>
                        <input type="text" data-field="company" class="compact-input">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Relationship <span class="required">*</span></label>
                        <input type="text" data-field="relationship" class="compact-input">
                    </div>
                </div>
            </div>

            <div class="form-row-3 compact-row mb-3">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Years Known <span class="required">*</span></label>
                        <input type="number" data-field="years_known" min="1" max="50" class="compact-input">
                    </div>
                </div>
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Mobile <span class="required">*</span></label>
                        <input type="tel" data-field="mobile" class="compact-input">
                    </div>
                </div>
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Email <span class="required">*</span></label>
                        <input type="email" data-field="email" class="compact-input">
                    </div>
                </div>
            </div>

            <div class="form-row-1 compact-row mb-2 reference-continuation-row">
                <div class="form-field">
                    <div class="form-check normal-checkbox compact-checkbox">
                        <input type="checkbox" class="form-check-input reference-continuation-checkbox" value="1">
                        <label class="form-check-label compact-checkbox-label" data-continuation-label></label>
                    </div>
                </div>
            </div>
        </div>
    </template>
    <?php
}
?>

<div class="candidate-form compact-form create-like-spacing reference-create-compact">
    <div class="form-header">
        <i class="fas fa-users"></i> Reference Details
    </div>

    <p class="text-muted mb-3">
        Provide the references required for your selected verification checks.
    </p>

    <div id="referenceData"
         data-reference='<?= htmlspecialchars(json_encode($referenceData), ENT_QUOTES, 'UTF-8') ?>'
         style="display:none"></div>

    <div id="referenceNoSectionMessage" class="alert alert-info mb-3" style="display:none;">
        No reference details required for your selected verification setup.
    </div>

    <form id="referenceForm" enctype="multipart/form-data">
        <input type="hidden" name="has_education_reference" value="0">
        <input type="hidden" name="has_employment_reference" value="0">
        <input type="hidden" name="education_reference_visible_count" value="1">
        <input type="hidden" name="employment_reference_visible_count" value="1">

        <div class="address-tabs mb-2" id="referenceTabsWrap" style="display:none;">
            <button type="button" class="address-tab-btn active" data-tab-target="education_reference_tab" id="educationReferenceTabBtn" style="display:none;">Education Reference</button>
            <button type="button" class="address-tab-btn" data-tab-target="employment_reference_tab" id="employmentReferenceTabBtn" style="display:none;">Employment Reference</button>
        </div>

        <div id="education_reference_tab" class="reference-tab-pane active" data-reference-section="education_reference">
            <div class="tabs-container compact-tabs mb-2">
                <div class="education-tabs reference-inner-tabs" data-reference-tabs="education"></div>
            </div>
            <div data-reference-list="education"></div>
        </div>

        <div id="employment_reference_tab" class="reference-tab-pane" data-reference-section="employment_reference" style="display:none;">
            <div class="tabs-container compact-tabs mb-2">
                <div class="employment-tabs reference-inner-tabs" data-reference-tabs="employment"></div>
            </div>
            <div data-reference-list="employment"></div>
        </div>

        <input type="hidden" name="application_id" value="<?= htmlspecialchars($application_id) ?>">

        <div class="form-footer compact-footer">
            <button type="button" class="btn-outline prev-btn">
                <i class="fas fa-arrow-left me-2"></i> Previous
            </button>

            <div class="footer-actions-right">
                <button type="button" class="btn-primary external-submit-btn" data-form="referenceForm">
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>
    </form>

    <?php renderReferenceCardTemplate(); ?>
</div>
