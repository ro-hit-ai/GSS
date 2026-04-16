<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = getApplicationId();
ensureApplicationExists($application_id);

$pdo = getDB();
$stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_contact_details(?)");
$stmt->execute([$application_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stmt->closeCursor();

$countries = ['India','United States','United Kingdom','Australia','Canada','Germany','France','China','Japan','Other'];
$hasPermanentData = !empty($row['permanent_address1']) || !empty($row['permanent_city']) || !empty($row['permanent_state']) || !empty($row['permanent_postal_code']);
$sameAsCurrent = !empty($row['same_as_current']) || !$hasPermanentData;
?>

<div class="candidate-form compact-form cr-fixed-form bgv-fixed-form create-like-spacing contact-create-compact">

    <div class="form-header">
        <i class="fas fa-address-book"></i> Address Details
    </div>
    <p class="text-muted mb-3">
        Please provide your current contact information.
    </p>

    <form id="contactForm" enctype="multipart/form-data">
        <input type="hidden" name="has_current_address" value="0">
        <input type="hidden" name="has_permanent_address" value="0">

        <div class="form-row-full compact-row mt-3 mb-3" id="sameAsCurrentWrap">
            <div class="form-check normal-checkbox compact-checkbox contact-address-switch" style="display:flex; gap:20px; flex-wrap:wrap;">
                <label class="compact-checkbox-label contact-switch-option" style="display:flex; align-items:center; gap:8px;">
                    <input type="radio" name="same_as_current" value="1" <?= $sameAsCurrent ? 'checked' : '' ?>>
                    <span>Permanent address same as current</span>
                </label>
                <label class="compact-checkbox-label contact-switch-option" style="display:flex; align-items:center; gap:8px;">
                    <input type="radio" name="same_as_current" value="0" <?= !$sameAsCurrent ? 'checked' : '' ?>>
                    <span>Permanent address is different</span>
                </label>
            </div>
        </div>

        <div class="address-tabs mb-3" id="addressTabsWrap">
            <button type="button" class="address-tab-btn active" data-tab-target="current_address_tab" id="currentAddressTabBtn">Current Address</button>
            <button type="button" class="address-tab-btn" data-tab-target="permanent_address_tab" id="permanentAddressTabBtn" style="display:none;">Permanent Address</button>
        </div>

        <div class="tab-pane active contact-address-pane" id="current_address_tab" data-address-section="current_address">
            <div class="form-grid compact-grid mt-3">
                <div class="form-field col-span-full">
                    <div class="form-row-2 compact-row">
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">Current Address Line 1 <span class="required">*</span></label>
                            <input type="text" name="current_address1" required class="compact-input"
                                   value="<?= htmlspecialchars($row['address1'] ?? '') ?>">
                        </div>
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">Current Address Line 2</label>
                            <input type="text" name="current_address2" class="compact-input"
                                   value="<?= htmlspecialchars($row['address2'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">City <span class="required">*</span></label>
                        <input type="text" name="current_city" required class="compact-input"
                               value="<?= htmlspecialchars($row['city'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">State <span class="required">*</span></label>
                        <input type="text" name="current_state" required class="compact-input"
                               value="<?= htmlspecialchars($row['state'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Country <span class="required">*</span></label>
                        <select name="current_country" required class="compact-select">
                            <?php foreach ($countries as $c): ?>
                                <option value="<?= $c ?>" <?= ($row['country'] ?? 'India') === $c ? 'selected' : '' ?>>
                                    <?= $c ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Pin Code <span class="required">*</span></label>
                        <input type="text" name="current_postal_code" required class="compact-input"
                               value="<?= htmlspecialchars($row['postal_code'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-field mt-3">
                <div class="form-control double-border compact-control contact-proof-control">
                    <label class="compact-label">Current Address Proof <span class="required">*</span></label>
                    <div class="file-upload-box" data-file-upload>
                        <div class="file-upload-row">
                            <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                            <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
                        </div>
                        <div class="file-upload-error" data-file-error></div>
                    </div>
                    <input type="file"
                           name="current_address_proof"
                           class="compact-file d-none contact-file-input"
                           accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                           data-file-input />
                </div>
            </div>
        </div>

        <div class="tab-pane contact-address-pane" id="permanent_address_tab" style="display:none;" data-address-section="permanent_address">
            <div class="form-grid compact-grid mt-3">
                <div class="form-field col-span-full">
                    <div class="form-row-2 compact-row">
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">Permanent Address Line 1 <span class="required">*</span></label>
                            <input type="text" name="permanent_address1" class="compact-input"
                                   value="<?= htmlspecialchars($row['permanent_address1'] ?? '') ?>">
                        </div>
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">Permanent Address Line 2</label>
                            <input type="text" name="permanent_address2" class="compact-input"
                                   value="<?= htmlspecialchars($row['permanent_address2'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">City <span class="required">*</span></label>
                        <input type="text" name="permanent_city" class="compact-input"
                               value="<?= htmlspecialchars($row['permanent_city'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">State <span class="required">*</span></label>
                        <input type="text" name="permanent_state" class="compact-input"
                               value="<?= htmlspecialchars($row['permanent_state'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Country <span class="required">*</span></label>
                        <select name="permanent_country" class="compact-select">
                            <?php foreach ($countries as $c): ?>
                                <option value="<?= $c ?>" <?= ($row['permanent_country'] ?? 'India') === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-field">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Pin Code <span class="required">*</span></label>
                        <input type="text" name="permanent_postal_code" class="compact-input"
                               value="<?= htmlspecialchars($row['permanent_postal_code'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-field mt-3">
                <div class="form-control double-border compact-control contact-proof-control">
                    <label class="compact-label">Permanent Address Identity Proof</label>
                    <div class="file-upload-box" data-file-upload>
                        <div class="file-upload-row">
                            <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                            <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
                        </div>
                        <div class="file-upload-error" data-file-error></div>
                    </div>
                    <input type="file"
                           name="permanent_address_proof"
                           class="compact-file d-none contact-file-input"
                           accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                           data-file-input />
                </div>
            </div>
        </div>

        <input type="hidden" name="application_id" value="<?= $application_id ?>">

        <div class="form-footer compact-footer">
            <button type="button" class="btn-outline prev-btn">
                <i class="fas fa-arrow-left me-2"></i> Previous
            </button>

            <div class="footer-actions-right">
                <!-- <button type="button" class="btn-secondary save-draft-btn" data-page="contact">
                    Save Draft
                </button> -->
                <button type="button" class="btn-primary external-submit-btn" data-form="contactForm">
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>

    </form>
</div>
