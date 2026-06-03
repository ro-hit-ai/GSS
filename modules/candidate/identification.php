<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = getApplicationId();
ensureApplicationExists($application_id);

$pdo = getDB();

function candidate_fetch_identification_rows(PDO $pdo, string $applicationId): array {
    $stmt = $pdo->prepare("
        SELECT
            document_index,
            COALESCE(NULLIF(proof_group, ''), 'primary') AS proof_group,
            documentId_type AS document_type,
            id_number,
            name,
            issue_date,
            expiry_date,
            upload_document
        FROM Vati_Payfiller_Candidate_Identification_details
        WHERE application_id = ?
        ORDER BY document_index ASC, proof_group ASC, id ASC
    ");
    $stmt->execute([$applicationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $idx = (int)($row['document_index'] ?? 0);
        $group = trim((string)($row['proof_group'] ?? ''));
        if ($idx <= 0 || $group === '') continue;
        $out[$idx][$group] = $row;
    }
    return $out;
}

$stmt = $pdo->prepare("
    SELECT country 
    FROM Vati_Payfiller_Candidate_Basic_details 
    WHERE application_id = ?
");
$stmt->execute([$application_id]);
$basicDetails = $stmt->fetch(PDO::FETCH_ASSOC);
$candidateCountry = $basicDetails['country'] ?? 'India';

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

$identificationRowsByDocument = candidate_fetch_identification_rows($pdo, $application_id);
foreach ($identificationRowsByDocument as $documentIndex => $groupRows) {
    $idx = $documentIndex - 1;
    if (!isset($rows[$idx])) {
        $rows[$idx] = [
            'document_index' => $documentIndex,
            'documentId_type' => '',
            'id_number' => '',
            'name' => '',
            'upload_document' => '',
            'issue_date' => '',
            'expiry_date' => '',
        ];
    }
    $rows[$idx]['grouped_rows'] = $groupRows;
}
ksort($rows);
$rows = array_values($rows);

$documentTypes = [
    'India' => ['Aadhaar','PAN','Passport','Driving Licence','Voter ID','Other'],
    'USA'   => ['SSN','Passport','Driver License','State ID','Other'],
    'UK'    => ['Passport','Driving Licence','NIN','Other'],
    'Other' => ['Passport','National ID','Other']
];

$countries = ['India','USA','UK','Canada','Australia','Other'];
$count = max(1, count($rows));
$maxCount = 6;
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
        <input type="hidden" name="old_primary_upload_document[]" value="">
        <input type="hidden" name="old_secondary_upload_document[]" value="">

        <div class="identification-card-header compact-header">
            <h6 class="mb-0">ID <span class="document-num">1</span></h6>
        </div>

        <div class="identification-card-body compact-body">

            <div class="identification-proof-stack">
                <div class="identification-proof-block" data-proof-group="primary">
                    <div class="form-row-3 compact-row mb-2">
                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">Aadhaar / Driving Licence *</label>
                                <select class="compact-select grouped-doc-select primary-doc-select"
                                        name="primary_document_type[]"
                                        data-proof-group-select="primary">
                                    <option value="">Select ID proof</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">Name as per ID Proof *</label>
                                <input type="text" name="primary_name[]" class="compact-input">
                            </div>
                        </div>

                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">ID Number *</label>
                                <input type="text" name="primary_id_number[]" class="compact-input">
                            </div>
                        </div>
                    </div>

                    <div class="form-row-2 compact-row mb-2 proof-date-row" data-proof-date-row="primary" style="display:none;">
                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">From Date</label>
                                <input type="date" name="primary_issue_date[]" class="compact-input">
                            </div>
                        </div>

                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">To Date</label>
                                <input type="date" name="primary_expiry_date[]" class="compact-input">
                            </div>
                        </div>
                    </div>

                    <div class="form-row-1 compact-row mb-3">
                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">Upload Document *</label>
                                <div class="file-upload-box compact-inline-upload" data-file-upload data-doc-group="primary">
                                    <div class="file-upload-row">
                                        <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                                        <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
                                        <button type="button" class="file-upload-remove" data-file-remove aria-label="Remove uploaded document" style="display:none;">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <div class="file-upload-error" data-file-error></div>
                                </div>
                                <input type="file"
                                       name="primary_upload_document[]"
                                       accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                       class="compact-file d-none primary-doc-file"
                                       data-doc-file-input
                                       data-doc-group="primary">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="identification-proof-block" data-proof-group="secondary">
                    <div class="form-row-3 compact-row mb-2">
                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">Voter / PAN / Passport *</label>
                                <select class="compact-select grouped-doc-select secondary-doc-select"
                                        name="secondary_document_type[]"
                                        data-proof-group-select="secondary">
                                    <option value="">Select ID proof</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">Name as per ID Proof *</label>
                                <input type="text" name="secondary_name[]" class="compact-input">
                            </div>
                        </div>

                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">ID Number *</label>
                                <input type="text" name="secondary_id_number[]" class="compact-input">
                            </div>
                        </div>
                    </div>

                    <div class="form-row-2 compact-row mb-2 proof-date-row" data-proof-date-row="secondary" style="display:none;">
                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">From Date</label>
                                <input type="date" name="secondary_issue_date[]" class="compact-input">
                            </div>
                        </div>

                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">To Date</label>
                                <input type="date" name="secondary_expiry_date[]" class="compact-input">
                            </div>
                        </div>
                    </div>

                    <div class="form-row-1 compact-row mb-2">
                        <div class="form-field">
                            <div class="form-control double-border compact-control">
                                <label class="compact-label">Upload Document *</label>
                                <div class="file-upload-box compact-inline-upload" data-file-upload data-doc-group="secondary">
                                    <div class="file-upload-row">
                                        <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                                        <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
                                        <button type="button" class="file-upload-remove" data-file-remove aria-label="Remove uploaded document" style="display:none;">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <div class="file-upload-error" data-file-error></div>
                                </div>
                                <input type="file"
                                       name="secondary_upload_document[]"
                                       accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                       class="compact-file d-none secondary-doc-file"
                                       data-doc-file-input
                                       data-doc-group="secondary">
                            </div>
                        </div>
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
