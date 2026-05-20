<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = getApplicationId();
ensureApplicationExists($application_id);
?>

<div class="candidate-form compact-form create-like-spacing review-page-shell">
    <div class="form-header">
        <i class="fas fa-clipboard-check"></i> Review Your Application
    </div>

    <p class="text-muted mb-3">
        Review all submitted details before final submission. You can go back and edit any section if needed.
    </p>

    <div id="candidateReviewRoot"
         class="candidate-review-root"
         data-application-id="<?= htmlspecialchars($application_id) ?>">
        <div id="candidateReviewMessage" class="alert alert-info">
            Loading your application preview...
        </div>
        <div id="candidateReviewContent" class="candidate-review-sections" style="display:none;"></div>
    </div>

    <div id="filePreviewModal" class="candidate-review-preview-modal" style="display:none;">
        <div class="candidate-review-preview-dialog">
            <span id="filePreviewCloseBtn" class="candidate-review-preview-close">&times;</span>
            <div id="previewContainer" class="candidate-review-preview-body"></div>
        </div>
    </div>

    <div class="form-footer compact-footer review-page-footer">
        <div class="review-note">
            Clicking Go Back & Edit will take you to Basic Details. You can move through the steps and correct anything before final submission.
        </div>

        <div class="footer-actions-right">
            <button type="button" class="btn btn-outline-primary" id="reviewDownloadReportBtn">
                Download Report
            </button>
            <button type="button" class="btn btn-outline-secondary" id="reviewGoBackBtn">
                Go Back & Edit
            </button>
            <button type="button" class="btn btn-primary" id="reviewFinalSubmitBtn">
                Final Submit
            </button>
        </div>
    </div>
</div>
