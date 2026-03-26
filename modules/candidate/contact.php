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
?>

<div class="candidate-form compact-form">

    <div class="form-header">
        <i class="fas fa-address-book"></i> Contact Details
    </div>
    <p class="text-muted mb-3">
        Please provide your current contact information.
    </p>

    <form id="contactForm" enctype="multipart/form-data">

        <!-- CONTACT INFO -->
        <div class="form-grid compact-grid">
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Mobile <span class="required">*</span></label>
                    <div class="compact-phone">
                        <select name="mobile_country_code" class="compact-select">
                            <?php foreach (['+91','+1','+44','+61','+81','+86'] as $c): ?>
                                <option value="<?= $c ?>" <?= ($row['mobile_country_code'] ?? '+91') === $c ? 'selected' : '' ?>>
                                    <?= $c ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="tel" name="mobile" required class="compact-input"
                               value="<?= htmlspecialchars($row['mobile'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Alternative Mobile</label>
                    <input type="tel" name="alternative_mobile" class="compact-input"
                           value="<?= htmlspecialchars($row['alternative_mobile'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Email <span class="required">*</span></label>
                    <input type="email" name="email" required class="compact-input"
                           value="<?= htmlspecialchars($row['email'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Alternative Email</label>
                    <input type="email" name="alternative_email" class="compact-input"
                           value="<?= htmlspecialchars($row['alternative_email'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- CURRENT ADDRESS -->
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

        <!-- SAME AS CURRENT -->
        <div class="form-row-full compact-row mt-3">
            <div class="form-check normal-checkbox compact-checkbox">
                <input type="checkbox" id="same_as_current" name="same_as_current" value="1"
                       <?= !empty($row['same_as_current']) ? 'checked' : '' ?>>
                <label for="same_as_current" class="compact-checkbox-label">
                    Permanent address is same as current address
                </label>
            </div>
        </div>

        <!-- PERMANENT ADDRESS -->
        <div class="form-grid compact-grid mt-3">
            <div class="form-field col-span-full">
                <div class="form-row-2 compact-row">
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Permanent Address Line 1 <span class="required">*</span></label>
                        <input type="text" name="permanent_address1" required class="compact-input"<?php echo !empty($row['same_as_current']) ? ' disabled' : ''; ?>
                               value="<?= htmlspecialchars($row['permanent_address1'] ?? '') ?>">
                    </div>
                    <div class="form-control double-border compact-control">
                        <label class="compact-label">Permanent Address Line 2</label>
                        <input type="text" name="permanent_address2" class="compact-input"<?php echo !empty($row['same_as_current']) ? ' disabled' : ''; ?>
                               value="<?= htmlspecialchars($row['permanent_address2'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">City <span class="required">*</span></label>
                    <input type="text" name="permanent_city" required class="compact-input"<?php echo !empty($row['same_as_current']) ? ' disabled' : ''; ?>
                           value="<?= htmlspecialchars($row['permanent_city'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">State <span class="required">*</span></label>
                    <input type="text" name="permanent_state" required class="compact-input"<?php echo !empty($row['same_as_current']) ? ' disabled' : ''; ?>
                           value="<?= htmlspecialchars($row['permanent_state'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Country <span class="required">*</span></label>
                    <select name="permanent_country" required class="compact-select"<?php echo !empty($row['same_as_current']) ? ' disabled' : ''; ?>>
                        <?php foreach ($countries as $c): ?>
                            <option value="<?= $c ?>" <?= ($row['permanent_country'] ?? 'India') === $c ? 'selected' : '' ?>>
                                <?= $c ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Pin Code <span class="required">*</span></label>
                    <input type="text" name="permanent_postal_code" required class="compact-input"<?php echo !empty($row['same_as_current']) ? ' disabled' : ''; ?>
                           value="<?= htmlspecialchars($row['permanent_postal_code'] ?? '') ?>">
                </div>
            </div>
        </div>

        <input type="hidden" name="application_id" value="<?= $application_id ?>">

        <div class="form-footer compact-footer">
            <button type="button" class="btn-outline prev-btn">
                <i class="fas fa-arrow-left me-2"></i> Previous
            </button>

            <div class="footer-actions-right">
                <button type="button" class="btn-secondary save-draft-btn" data-page="contact">
                    Save Draft
                </button>
                <button type="button" class="btn-primary external-submit-btn" data-form="contactForm">
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>

    </form>
</div>

