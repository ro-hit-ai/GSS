<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = getApplicationId();
ensureApplicationExists($application_id);

$pdo = getDB();

// Call the stored procedure to get data
$stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_basic_details(?)");
$stmt->execute([$application_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stmt->closeCursor(); // Important for stored procedures

// Prefill immutable fields from case (created by client/bulk upload)
$casePrefill = [];
try {
    $prefillStmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetCaseCandidatePrefill(?)');
    $prefillStmt->execute([$application_id]);
    $casePrefill = $prefillStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($prefillStmt->nextRowset()) {
    }
} catch (Throwable $e) {
    $casePrefill = [];
}

$prefillFirstName = trim((string)($casePrefill['candidate_first_name'] ?? ''));
$prefillMiddleName = trim((string)($casePrefill['candidate_middle_name'] ?? ''));
$prefillLastName = trim((string)($casePrefill['candidate_last_name'] ?? ''));
$prefillEmail = trim((string)($casePrefill['candidate_email'] ?? ''));
$prefillMobileRaw = trim((string)($casePrefill['candidate_mobile'] ?? ''));

if (($row['first_name'] ?? '') === '' && $prefillFirstName !== '') $row['first_name'] = $prefillFirstName;
if (($row['middle_name'] ?? '') === '' && $prefillMiddleName !== '') $row['middle_name'] = $prefillMiddleName;
if (($row['last_name'] ?? '') === '' && $prefillLastName !== '') $row['last_name'] = $prefillLastName;
if (($row['email'] ?? '') === '' && $prefillEmail !== '') $row['email'] = $prefillEmail;
if (($row['mobile'] ?? '') === '' && $prefillMobileRaw !== '') $row['mobile'] = $prefillMobileRaw;

/* Mobile split */
$mobileCode = '+91';
$mobileNumber = '';
if (!empty($row['mobile'])) {
    $p = preg_split('/\s+/', trim($row['mobile']), 2);
    $mobileCode = $p[0] ?? '+91';
    $mobileNumber = $p[1] ?? '';
}

$lockNameEmail = ($prefillFirstName !== '' || $prefillLastName !== '' || $prefillEmail !== '');
$lockMobile = false;

$citizenshipOptions = ['Indian','American','British','Australian','Canadian','German','French','Chinese','Japanese','Other'];
$countryOptions = ['India','United States','United Kingdom','Australia','Canada','Germany','France','China','Japan','Other'];
$stateOptions = [
    'Andaman and Nicobar Islands',
    'Andhra Pradesh',
    'Arunachal Pradesh',
    'Assam',
    'Bihar',
    'Chandigarh',
    'Chhattisgarh',
    'Dadra and Nagar Haveli and Daman and Diu',
    'Delhi',
    'Goa',
    'Gujarat',
    'Haryana',
    'Himachal Pradesh',
    'Jammu and Kashmir',
    'Jharkhand',
    'Karnataka',
    'Kerala',
    'Ladakh',
    'Lakshadweep',
    'Madhya Pradesh',
    'Maharashtra',
    'Manipur',
    'Meghalaya',
    'Mizoram',
    'Nagaland',
    'Odisha',
    'Puducherry',
    'Punjab',
    'Rajasthan',
    'Sikkim',
    'Tamil Nadu',
    'Telangana',
    'Tripura',
    'Uttar Pradesh',
    'Uttarakhand',
    'West Bengal'
];

$adultDobMax = date('Y-m-d', strtotime('-18 years'));
$countryVal = trim((string)($row['country'] ?? ''));
$stateVal = trim((string)($row['state'] ?? ''));
$photoPathRaw = trim((string)($row['photo_path'] ?? ''));
$photoPathForView = $photoPathRaw;
if ($photoPathRaw !== '' && strpos($photoPathRaw, '/uploads/') === 0) {
    $photoPathForView = app_url($photoPathRaw);
}
?>


<div class="candidate-form compact-form">

    <!-- HEADER -->
    <div class="form-header">
        <i class="fas fa-user"></i> Basic Details
    </div>

    <p class="text-muted mb-3">
        Please provide your personal information accurately.
    </p>

    <form id="basic-detailsForm" enctype="multipart/form-data">

        <!-- ================= TOP GRID (FORM + PHOTO) ================= -->
        <div class="basic-top-grid">

            <!-- LEFT : FORM -->
            <div class="basic-form-area">
                <div class="form-grid compact-grid">

                    <!-- First Name -->
                    <div class="form-field">
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">First Name <span class="required">*</span></label>
                            <input type="text" name="first_name" required class="compact-input"<?php echo $lockNameEmail ? ' readonly' : ''; ?>
                                   value="<?= htmlspecialchars($row['first_name'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Middle Name -->
                    <div class="form-field">
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">Middle Name</label>
                            <input type="text" name="middle_name" class="compact-input"<?php echo $lockNameEmail ? ' readonly' : ''; ?>
                                   value="<?= htmlspecialchars($row['middle_name'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Last Name -->
                    <div class="form-field">
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">Last Name <span class="required">*</span></label>
                            <input type="text" name="last_name" required class="compact-input"<?php echo $lockNameEmail ? ' readonly' : ''; ?>
                                   value="<?= htmlspecialchars($row['last_name'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Gender -->
                    <div class="form-field">
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">Gender <span class="required">*</span></label>
                            <select name="gender" required class="compact-select">
                                <option value="">Select</option>
                                <?php foreach (['male','female','other'] as $g): ?>
                                    <option value="<?= $g ?>" <?= ($row['gender'] ?? '') === $g ? 'selected' : '' ?>>
                                        <?= ucfirst($g) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Date of Birth -->
                    <div class="form-field">
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">Date of Birth <span class="required">*</span></label>
                            <input type="date" name="dob" required class="compact-input" max="<?= htmlspecialchars($adultDobMax) ?>"
                                   value="<?= htmlspecialchars($row['dob'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Blood Group -->
                    <div class="form-field">
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">Blood Group <span class="required">*</span></label>
                            <select name="blood_group" required class="compact-select">
                                <option value="">Select</option>
                                <?php foreach(['O+','O-','A+','A-','B+','B-','AB+','AB-'] as $bg): ?>
                                    <option value="<?= $bg ?>" <?= ($row['blood_group'] ?? '') === $bg ? 'selected' : '' ?>>
                                        <?= $bg ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Father's Name -->
                    <div class="form-field">
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">Father's Name <span class="required">*</span></label>
                            <input type="text" name="father_name" required class="compact-input"
                                   value="<?= htmlspecialchars($row['father_name'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Mother's Name -->
                    <div class="form-field">
                        <div class="form-control double-border compact-control">
                            <label class="compact-label">Mother's Name</label>
                            <input type="text" name="mother_name" class="compact-input"
                                   value="<?= htmlspecialchars($row['mother_name'] ?? '') ?>">
                        </div>
                    </div>

                </div>
            </div>

            <!-- RIGHT : PHOTO -->
            <div class="basic-photo-area">
                <div class="form-control double-border compact-control" style="min-height: 55px; padding: 6px 8px;">
                    <label class="compact-label">Profile Photo</label>
                    
                    <div class="photo-wrapper compact-photo" id="photoUploadTrigger" 
                         style="height: 70px; margin-top: 2px;">

                        <?php if (!empty($photoPathRaw)): ?>
                            <div class="photo-preview" style="height: 100%;">
                                <img src="<?= htmlspecialchars($photoPathForView) ?>" alt="Profile Photo" style="height: 100%; object-fit: contain;">
                                <button type="button" class="photo-remove-btn compact-btn" style="top: 4px; right: 4px;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="photo-upload-box" style="padding: 4px;">
                                <div class="upload-icon"><i class="fas fa-camera" style="font-size: 12px;"></i></div>
                                <div class="upload-text compact-label" style="font-size: 11px; margin-top: 2px;">Upload Photo</div>
                                <div class="upload-hint compact-hint" style="font-size: 9px; margin-top: 1px;">JPG/JPEG only (5MB max)</div>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

                <input type="file"
                       name="photo"
                       id="photoInput"
                       accept=".jpg,.jpeg,image/jpeg"
                       class="d-none">
            </div>

        </div>

        <!-- ================= MIDDLE SECTION ================= -->
        <div class="form-grid compact-grid mt-3">

            <!-- Marital Status -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Marital Status <span class="required">*</span></label>
                    <select name="marital_status" required class="compact-select" id="maritalStatus">
                        <option value="">Select</option>
                        <?php foreach (['single','married','divorced','widowed'] as $m): ?>
                            <option value="<?= $m ?>" <?= ($row['marital_status'] ?? '') === $m ? 'selected' : '' ?>>
                                <?= ucfirst($m) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Spouse Name (Always shown, placed beside Marital Status) -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Spouse Name</label>
                    <input type="text" name="spouse_name" class="compact-input" 
                           placeholder="Enter name or N/A"
                           value="<?= htmlspecialchars($row['spouse_name'] ?? '') ?>">
                </div>
            </div>

            <!-- Mobile Number -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Mobile <span class="required">*</span></label>
                    <div class="phone-input-container compact-phone">
                        <select name="mobile_country_code" class="compact-select">
                            <?php foreach (['+91','+1','+44','+61','+81','+86'] as $c): ?>
                                <option value="<?= $c ?>" <?= $mobileCode === $c ? 'selected' : '' ?>>
                                    <?= $c ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="tel" name="mobile" required class="compact-input"
                               value="<?= htmlspecialchars($mobileNumber) ?>">
                    </div>
                </div>
            </div>

            <!-- Email Address -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Email <span class="required">*</span></label>
                    <input type="email" name="email" required class="compact-input"<?php echo $lockNameEmail ? ' readonly' : ''; ?>
                           value="<?= htmlspecialchars($row['email'] ?? '') ?>">
                </div>
            </div>

            <!-- Landline -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Landline</label>
                    <input type="tel" name="landline" class="compact-input"
                           value="<?= htmlspecialchars($row['landline'] ?? '') ?>">
                </div>
            </div>

            <!-- Country -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Country <span class="required">*</span></label>
                    <select name="country" required class="compact-select">
                        <option value="">Select</option>
                        <?php if ($countryVal !== '' && !in_array($countryVal, $countryOptions, true)): ?>
                            <option value="<?= htmlspecialchars($countryVal) ?>" selected><?= htmlspecialchars($countryVal) ?></option>
                        <?php endif; ?>
                        <?php foreach ($countryOptions as $c): ?>
                            <option value="<?= $c ?>" <?= $countryVal !== '' && strcasecmp($countryVal, $c) === 0 ? 'selected' : '' ?>>
                                <?= $c ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- State -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">State <span class="required">*</span></label>
                    <select name="state" required class="compact-select">
                        <option value="">Select</option>
                        <?php if ($stateVal !== '' && !in_array($stateVal, $stateOptions, true)): ?>
                            <option value="<?= htmlspecialchars($stateVal) ?>" selected><?= htmlspecialchars($stateVal) ?></option>
                        <?php endif; ?>
                        <?php foreach ($stateOptions as $s): ?>
                            <option value="<?= $s ?>" <?= $stateVal !== '' && strcasecmp($stateVal, $s) === 0 ? 'selected' : '' ?>>
                                <?= $s ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- City/Village -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">City/Village <span class="required">*</span></label>
                    <input type="text" name="city_village" required class="compact-input"
                           value="<?= htmlspecialchars($row['city_village'] ?? '') ?>">
                </div>
            </div>

            <!-- District -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">District <span class="required">*</span></label>
                    <input type="text" name="district" required class="compact-input"
                           value="<?= htmlspecialchars($row['district'] ?? '') ?>">
                </div>
            </div>

            <!-- Pincode -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Pincode <span class="required">*</span></label>
                    <input type="text" name="pincode" required class="compact-input" inputmode="numeric" maxlength="6" pattern="\d{6}"
                           value="<?= htmlspecialchars($row['pincode'] ?? '') ?>">
                </div>
            </div>

            <!-- Citizenship -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Citizenship <span class="required">*</span></label>
                    <select name="nationality" required class="compact-select">
                        <option value="">Select</option>
                        <?php foreach ($citizenshipOptions as $c): ?>
                            <option value="<?= $c ?>" <?= ($row['nationality'] ?? '') === $c ? 'selected' : '' ?>>
                                <?= $c ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Other Name -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Alias or Other Name</label>
                    <input type="text" name="other_name" class="compact-input"
                           value="<?= htmlspecialchars($row['other_name'] ?? '') ?>">
                </div>
            </div>

        </div>

        <!-- Hidden Fields -->
        <input type="hidden" name="application_id" value="<?= $application_id ?>">
        <?php if (!empty($row['photo_path'])): ?>
            <input type="hidden" name="existing_photo" value="<?= htmlspecialchars($row['photo_path']) ?>">
        <?php endif; ?>

        <!-- FORM FOOTER WITH BUTTONS -->
        <div class="form-footer compact-footer">
            <div></div> <!-- Left spacer -->
            <div class="footer-actions-right">
                <button type="button" class="btn-secondary save-draft-btn" data-page="basic-details">
                    Save Draft
                </button>
                <button type="button" class="btn-primary external-submit-btn" data-form="basic-detailsForm">
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>

    </form>
</div>

<script>
    window.APP_BASE_URL = window.APP_BASE_URL || "<?= defined('APP_BASE_URL') ? htmlspecialchars(APP_BASE_URL) : '' ?>";
    
    // JavaScript to auto-fill "N/A" when marital status changes to non-married
    document.addEventListener('DOMContentLoaded', function() {
        const maritalStatusSelect = document.getElementById('maritalStatus');
        const spouseNameInput = document.querySelector('input[name="spouse_name"]');
        const dobInput = document.querySelector('input[name="dob"]');
        const pincodeInput = document.querySelector('input[name="pincode"]');
        const photoInput = document.getElementById('photoInput');
        
        if (maritalStatusSelect && spouseNameInput) {
            maritalStatusSelect.addEventListener('change', function() {
                if (this.value !== 'married') {
                    spouseNameInput.value = 'N/A';
                } else if (spouseNameInput.value === 'N/A') {
                    spouseNameInput.value = '';
                }
            });
            
            // Initialize on page load
            if (maritalStatusSelect.value !== 'married' && !spouseNameInput.value) {
                spouseNameInput.value = 'N/A';
            }
        }

        if (dobInput) {
            try {
                dobInput.max = dobInput.max || "<?= htmlspecialchars($adultDobMax) ?>";
            } catch (e) {
            }
        }

        if (pincodeInput) {
            pincodeInput.addEventListener('input', function () {
                var v = String(pincodeInput.value || '').replace(/\D/g, '').slice(0, 6);
                if (pincodeInput.value !== v) pincodeInput.value = v;
            });
        }

        if (photoInput) {
            photoInput.addEventListener('change', function () {
                var file = photoInput.files && photoInput.files[0] ? photoInput.files[0] : null;
                if (!file) return;
                var name = String(file.name || '').toLowerCase();
                var type = String(file.type || '').toLowerCase();
                var isJpeg = type === 'image/jpeg' || name.endsWith('.jpg') || name.endsWith('.jpeg');
                if (!isJpeg) {
                    alert('Only JPG/JPEG images are allowed for Profile Photo.');
                    photoInput.value = '';
                }
            });
        }
    });
</script>
