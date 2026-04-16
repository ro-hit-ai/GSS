<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = getApplicationId();
ensureApplicationExists($application_id);

$pdo = getDB();

/* ================= COUNTRY FROM BASIC DETAILS ================= */
$stmt = $pdo->prepare("
    SELECT country 
    FROM Vati_Payfiller_Candidate_Basic_details 
    WHERE application_id = ?
");
$stmt->execute([$application_id]);
$basicDetails = $stmt->fetch(PDO::FETCH_ASSOC);
$candidateCountry = $basicDetails['country'] ?? 'India';

/* ================= FETCH IDENTIFICATION ================= */
$stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_identification_details(?)");
$stmt->execute([$application_id]);
$dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

/* Normalize rows by document_index */
$rows = [];
foreach ($dbRows as $row) {
    $idx = ((int)$row['document_index']) - 1;
    if ($idx >= 0) {
        $rows[$idx] = $row;
    }
}
$rows = array_values($rows);

/* ================= STATIC DATA ================= */
$documentTypes = [
    'India' => ['Aadhaar','PAN','Passport','Driving Licence','Voter ID','Other'],
    'USA'   => ['SSN','Passport','Driver License','State ID','Other'],
    'UK'    => ['Passport','Driving Licence','NIN','Other'],
    'Other' => ['Passport','National ID','Other']
];

$countries = ['India','USA','UK','Canada','Australia','Other'];
$count = max(1, count($rows));
$maxCount = 3;
?>

<div class="candidate-form compact-form cr-fixed-form bgv-fixed-form create-like-spacing">

    <!-- HEADER -->
    <div class="form-header">
        <i class="fas fa-id-card"></i> Identification Details
    </div>

    <p class="text-muted mb-3">
        Add your government-issued identification documents.
    </p>

    <!-- COUNTRY + COUNT + TABS (COMPACT BAR) -->
        <div class="compact-card identification-toolbar mb-3">
            <div class="tabs-container compact-tabs">
                <div class="identification-tabs" id="identificationTabs"></div>
            </div>
        </div>

    <!-- FORM -->
    <form id="identificationForm" enctype="multipart/form-data" novalidate>
        <input type="hidden"
               name="identification_country"
               id="identificationCountryField"
               value="<?= htmlspecialchars($candidateCountry) ?>">

            <div style="display:none;">
                <select id="identificationCountry">
                    <?php foreach ($countries as $c): ?>
                        <option value="<?= $c ?>" <?= $c === $candidateCountry ? 'selected' : '' ?>>
                            <?= $c ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="identificationCount">
                    <?php for ($i = 1; $i <= $maxCount; $i++): ?>
                        <option value="<?= $i ?>" <?= $i === $count ? 'selected' : '' ?>>
                            <?= $i ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- ⚠️ THIS CONTAINER IS CLEARED BY TABMANAGER -->
            <div id="identificationContainer"></div>

            <!-- DATA FOR JS -->
            <div id="identificationData"
                 data-rows='<?= htmlspecialchars(json_encode($rows), ENT_QUOTES) ?>'
                 data-country='<?= htmlspecialchars($candidateCountry, ENT_QUOTES) ?>'
                 data-document-types='<?= htmlspecialchars(json_encode($documentTypes), ENT_QUOTES) ?>'
                 data-countries='<?= htmlspecialchars(json_encode($countries), ENT_QUOTES) ?>'
                 data-count='<?= $count ?>'
                 style="display:none"></div>
    </form>

    <!-- FOOTER -->
    <div class="form-footer compact-footer">
        <button type="button" class="btn-outline prev-btn">
            <i class="fas fa-arrow-left me-2"></i> Previous
        </button>

        <div class="footer-actions-right">
            <!-- <button type="button"
                    class="btn-secondary save-draft-btn"
                    data-page="identification">
                Save Draft
            </button> -->

            <button type="button"
                    class="btn-primary external-submit-btn"
                    data-form="identificationForm">
                Next <i class="fas fa-arrow-right ms-2"></i>
            </button>
        </div>
    </div>
</div>

<!-- =========================================================
     TEMPLATE (⚠️ MUST BE OUTSIDE identificationContainer)
========================================================= -->
<template id="identificationTemplate">
    <div class="compact-card identification-card mb-3">

        <input type="hidden" name="id[]" value="">
        <input type="hidden" name="document_index[]" value="">
        <input type="hidden" name="old_upload_document[]" value="">

        <div class="identification-card-header compact-header">
            <h6 class="mb-0">Document <span class="document-num">1</span></h6>
        </div>

        <div class="identification-card-body compact-body">

            <!-- TYPE + NUMBER -->
            <div class="form-row-3 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Document Type *</label>
                        <select name="documentId_type[]"
                                class="compact-select document-type-select"></select>
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">ID Number *</label>
                        <input type="text" name="id_number[]" class="compact-input">
                        <!-- <small class="text-muted compact-hint id-number-hint"></small> -->
                    </div>
                </div>
                
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Name as per ID Proof *</label>
                        <input type="text" name="name[]" class="compact-input">
                    </div>
                </div>
            </div>


            <!-- DATES -->
            <div class="form-row-2 compact-row mb-2 identification-dates-row">
                <div class="form-field issue-date-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Issue Date</label>
                        <input type="date" name="issue_date[]" class="compact-input">
                    </div>      
                </div>

                <div class="form-field expiry-date-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Expiry Date</label>
                        <input type="date"  name="expiry_date[]"   class="compact-input expiry-date-input">
                        <small class="text-muted compact-hint expiry-date-hint"></small>
                    </div>
                </div>
            </div>

            <!-- FILE -->
            <div class="form-row-1 compact-row mb-2">
                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Upload Document *</label>
                        <div class="file-upload-box" data-file-upload>
                            <div class="file-upload-row">
                                <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                                <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
                            </div>
                            <div class="file-upload-error" data-file-error></div>
                        </div>
                        <input type="file"
                               name="upload_document[]"
                               accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                               class="compact-file d-none"
                               data-file-input>
                    </div>
                </div>
            </div>

            <!-- CHECKBOX -->
            <!-- <div class="form-row-1 compact-row mb-2">
                <div class="form-field">
                    <div class="form-check normal-checkbox compact-checkbox">
                        <input type="checkbox"
                               class="form-check-input"
                               name="insufficient_documents[]"
                               value="1">
                        <label class="form-check-label compact-checkbox-label">
                            Insufficient Documents
                        </label>
                    </div>
                </div>
            </div> -->

        </div>
    </div>
</template>
