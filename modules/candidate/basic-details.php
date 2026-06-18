<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../api/shared/corrections/candidate_correction_service.php';

ccs_guard_candidate_page('basic-details');

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

try {
    $fullNameStmt = $pdo->prepare('SELECT full_name FROM Vati_Payfiller_Candidate_Basic_details WHERE application_id = ? LIMIT 1');
    $fullNameStmt->execute([$application_id]);
    $fullNameRow = $fullNameStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (array_key_exists('full_name', $fullNameRow)) {
        $row['full_name'] = $fullNameRow['full_name'];
    }
} catch (Throwable $e) {
}

$mobileCode = '+91';
$mobileNumber = '';
if (!empty($row['mobile'])) {
    $rawMobile = trim((string)$row['mobile']);
    if (preg_match('/^(\+\d{1,4})\s+(.+)$/', $rawMobile, $m)) {
        $mobileCode = $m[1];
        $mobileNumber = trim($m[2]);
    } else {
        $knownCodes = ['+91', '+44', '+86', '+81', '+61', '+1'];
        foreach ($knownCodes as $code) {
            if (strpos($rawMobile, $code) === 0) {
                $mobileCode = $code;
                $mobileNumber = trim(substr($rawMobile, strlen($code)));
                break;
            }
        }
        if ($mobileNumber === '') {
            $mobileNumber = preg_replace('/^\+\d{1,4}/', '', $rawMobile);
        }
    }
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
$resumeFile = trim((string)($row['resume_file'] ?? ''));
$resumeOriginalName = trim((string)($row['resume_original_name'] ?? ''));
$resumeUrl = $resumeFile !== '' ? app_url('/uploads/resume/' . ltrim($resumeFile, '/')) : '';
$applicantFullName = trim(implode(' ', array_filter([
    trim((string)($row['first_name'] ?? '')),
    trim((string)($row['middle_name'] ?? '')),
    trim((string)($row['last_name'] ?? '')),
])));
$displayFullName = trim((string)($row['full_name'] ?? ''));
if ($displayFullName === '') {
    $displayFullName = $applicantFullName;
}
?>


<div class="candidate-form compact-form create-like-spacing">

    <!-- HEADER -->
    <div class="form-header">
        <i class="fas fa-user"></i> Basic Details
    </div>

    <p class="text-muted mb-3">
        Please provide your personal information accurately.
    </p>

    <form id="basic-detailsForm" enctype="multipart/form-data" novalidate>
        <div class="basic-compact-grid">
            <div class="form-field basic-grid-span-1 basic-field-first">
                <div class="form-control compact-control">
                    <label class="compact-label">First Name <span class="required">*</span></label>
                    <input type="text" name="first_name" required class="compact-input"<?php echo $lockNameEmail ? ' readonly' : ''; ?>
                           value="<?= htmlspecialchars($row['first_name'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field basic-grid-span-1 basic-field-middle">
                <div class="form-control compact-control">
                    <label class="compact-label">Middle Name</label>
                    <input type="text" name="middle_name" class="compact-input"<?php echo $lockNameEmail ? ' readonly' : ''; ?>
                           value="<?= htmlspecialchars($row['middle_name'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field basic-grid-span-1 basic-field-last">
                <div class="form-control compact-control">
                    <label class="compact-label">Last Name <span class="required">*</span></label>
                    <input type="text" name="last_name" required class="compact-input"<?php echo $lockNameEmail ? ' readonly' : ''; ?>
                           value="<?= htmlspecialchars($row['last_name'] ?? '') ?>">
                </div>
            </div>

            <div class="basic-photo-area basic-grid-span-1 basic-photo-align-top basic-field-photo">
                <div class="basic-photo-card">
                    <label class="compact-label">Profile Photo <span class="required">*</span></label>

                    <div class="photo-wrapper compact-photo" id="photoUploadTrigger">
                        <?php if (!empty($photoPathRaw)): ?>
                            <div class="photo-preview has-image">
                                <img src="<?= htmlspecialchars($photoPathForView) ?>" alt="Profile Photo">
                                <button type="button" class="photo-remove-btn compact-btn" aria-label="Remove profile photo">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="photo-upload-box">
                                <div class="upload-icon"><i class="fas fa-camera"></i></div>
                                <div class="upload-text">Upload Photo</div>
                                <div class="upload-hint compact-hint">JPG/JPEG only (5MB max)</div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mobile-photo-actions">
                        <button type="button" class="mobile-photo-btn" id="mobilePhotoCaptureBtn">
                            <i class="fas fa-qrcode"></i> Capture via Mobile
                        </button>
                        <button type="button" class="mobile-photo-link" id="desktopPhotoUploadBtn">
                            Upload from desktop
                        </button>
                    </div>
                </div>

                <input type="file"
                       name="photo"
                       id="photoInput"
                       accept=".jpg,.jpeg,image/jpeg"
                       class="d-none">
            </div>

            <div class="form-field basic-grid-span-2 basic-field-fullname">
                <div class="form-control compact-control">
                    <label class="compact-label">Full Name</label>
                    <input type="text"
                           id="applicantFullNameDisplay"
                           name="full_name"
                           class="compact-input"
                           value="<?= htmlspecialchars($displayFullName) ?>"
                           data-auto-full-name="<?= htmlspecialchars($applicantFullName) ?>"
                           placeholder="Will auto-build from name fields">
                </div>
            </div>

            <div class="form-field basic-grid-span-1 basic-field-gender">
                <div class="form-control compact-control">
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

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
                    <label class="compact-label">Date of Birth <span class="required">*</span></label>
                    <input type="date" name="dob" required class="compact-input" max="<?= htmlspecialchars($adultDobMax) ?>"
                           value="<?= htmlspecialchars($row['dob'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
                    <label class="compact-label">Father's Name <span class="required">*</span></label>
                    <input type="text" name="father_name" required class="compact-input"
                           value="<?= htmlspecialchars($row['father_name'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
                    <label class="compact-label">Mother's Name</label>
                    <input type="text" name="mother_name" class="compact-input"
                           value="<?= htmlspecialchars($row['mother_name'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
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

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
                    <label class="compact-label">Alternate Mobile</label>
                    <input type="tel" name="landline" class="compact-input"
                           value="<?= htmlspecialchars($row['landline'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
                    <label class="compact-label">Email <span class="required">*</span></label>
                    <input type="email" name="email" required class="compact-input"<?php echo $lockNameEmail ? ' readonly' : ''; ?>
                           value="<?= htmlspecialchars($row['email'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
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

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
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

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
                    <label class="compact-label">District <span class="required">*</span></label>
                    <input type="text" name="district" required class="compact-input"
                           value="<?= htmlspecialchars($row['district'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
                    <label class="compact-label">City/Village <span class="required">*</span></label>
                    <input type="text" name="city_village" required class="compact-input"
                           value="<?= htmlspecialchars($row['city_village'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
                    <label class="compact-label">Pincode <span class="required">*</span></label>
                    <input type="text" name="pincode" required class="compact-input" inputmode="numeric" maxlength="6" pattern="\d{6}"
                           value="<?= htmlspecialchars($row['pincode'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field basic-grid-span-1">
                <div class="form-control compact-control">
                    <label class="compact-label">Are you known by any Other Name</label>
                    <input type="text" name="other_name" class="compact-input"
                           value="<?= htmlspecialchars($row['other_name'] ?? '') ?>">
                </div>
            </div>

            <div class="basic-resume-inline basic-grid-span-4">
                <label class="compact-label">Resume Upload</label>
                <div class="file-upload-box basic-resume-inline-box" data-file-upload>
                    <div class="file-upload-row">
                        <button type="button" class="file-upload-btn" data-file-choose data-file-target="resumeInput">Choose File</button>
                        <button type="button"
                                class="file-upload-name<?php echo $resumeOriginalName !== '' ? ' preview-btn' : ''; ?>"
                                data-file-name
                                <?php echo $resumeOriginalName !== '' ? '' : 'disabled'; ?>
                                <?php if ($resumeOriginalName !== '' && $resumeUrl !== ''): ?>
                                    data-url="<?= htmlspecialchars($resumeUrl) ?>"
                                    data-name="<?= htmlspecialchars($resumeOriginalName) ?>"
                                    data-type="pdf"
                                <?php endif; ?>
                        ><?= $resumeOriginalName !== '' ? htmlspecialchars($resumeOriginalName) : 'No file chosen' ?></button>
                        <button type="button" class="file-upload-remove" data-file-remove aria-label="Remove resume" style="<?= $resumeOriginalName !== '' ? '' : 'display:none;' ?>">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="file-upload-error" data-file-error></div>
                </div>
            </div>
        </div>

        <input type="hidden" name="existing_resume" value="<?= htmlspecialchars($resumeFile) ?>">
        <input type="hidden" name="existing_resume_name" value="<?= htmlspecialchars($resumeOriginalName) ?>">
        <input type="file"
               name="resume"
               id="resumeInput"
               accept=".pdf,application/pdf"
               class="d-none">

        <!-- Hidden Fields -->
        <input type="hidden" name="application_id" value="<?= $application_id ?>">
        <?php if (!empty($row['photo_path'])): ?>
            <input type="hidden" name="existing_photo" value="<?= htmlspecialchars($row['photo_path']) ?>">
        <?php endif; ?>

        <!-- FORM FOOTER WITH BUTTONS -->
        <div class="form-footer compact-footer">
            <div></div> <!-- Left spacer -->
            <div class="footer-actions-right">
                <!-- <button type="button" class="btn-secondary save-draft-btn" data-page="basic-details">
                    Save Draft
                </button> -->
                <button type="button" class="btn-primary external-submit-btn" data-form="basic-detailsForm">
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>

    </form>
</div>

<div class="mobile-photo-modal" id="mobilePhotoModal" aria-hidden="true">
    <div class="mobile-photo-dialog" role="dialog" aria-modal="true" aria-labelledby="mobilePhotoTitle">
        <button type="button" class="mobile-photo-close" id="mobilePhotoCloseBtn" aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
        <div class="mobile-photo-head">
            <div class="mobile-photo-icon"><i class="fas fa-mobile-alt"></i></div>
            <div>
                <h3 id="mobilePhotoTitle">Scan QR to Capture Photo</h3>
                <p>Use your mobile camera for better quality verification photo capture.</p>
            </div>
        </div>
        <div class="mobile-photo-qr-wrap">
            <div class="mobile-photo-qr" id="mobilePhotoQrBox">
                <span>Preparing secure QR...</span>
            </div>
        </div>
        <a class="mobile-photo-url" id="mobilePhotoUrl" href="#" target="_blank" rel="noopener" style="display:none;"></a>
        <div class="mobile-photo-steps">
            <div><b>1.</b> Scan the QR using your mobile camera.</div>
            <div><b>2.</b> Capture or retake your profile photo.</div>
            <div><b>3.</b> Upload once; this desktop page updates automatically.</div>
        </div>
        <div class="mobile-photo-status" id="mobilePhotoStatus">Secure upload session expires in 10 minutes.</div>
    </div>
</div>

<style>
    .mobile-photo-actions{display:flex;gap:5px;flex-wrap:nowrap;justify-content:center;margin-top:6px}
    .mobile-photo-btn,.mobile-photo-link{border-radius:8px;min-height:26px;padding:4px 7px;font-size:10px;font-weight:800;cursor:pointer;white-space:nowrap}
    .mobile-photo-btn{border:1px solid #bfdbfe;background:#eff6ff;color:#075c9f}
    .mobile-photo-link{border:1px solid #d8e4f0;background:#fff;color:#475569}
    .mobile-photo-modal{position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.42);display:none;align-items:center;justify-content:center;padding:18px}
    .mobile-photo-modal.is-open{display:flex}
    .mobile-photo-dialog{position:relative;width:min(430px,100%);background:#fff;border:1px solid #dbeafe;border-radius:18px;box-shadow:0 24px 80px rgba(15,23,42,.24);padding:18px}
    .mobile-photo-close{position:absolute;top:12px;right:12px;width:30px;height:30px;border-radius:50%;border:1px solid #d6e2ef;background:#fff;color:#334155;display:grid;place-items:center;cursor:pointer}
    .mobile-photo-head{display:flex;gap:12px;align-items:flex-start;margin-right:26px}
    .mobile-photo-icon{width:42px;height:42px;border-radius:13px;background:#e8f3ff;color:#0f74d1;display:grid;place-items:center;font-size:18px}
    .mobile-photo-head h3{margin:0 0 4px;font-size:18px;color:#0f172a}
    .mobile-photo-head p{margin:0;color:#64748b;font-size:13px;line-height:1.4}
    .mobile-photo-qr-wrap{display:flex;justify-content:center;padding:16px 0 12px}
    .mobile-photo-qr{width:220px;height:220px;border:1px solid #dbeafe;border-radius:16px;background:#f8fbff;display:grid;place-items:center;overflow:hidden;color:#64748b;font-size:12px;text-align:center}
    .mobile-photo-qr img{width:188px;height:188px;display:block}
    .mobile-photo-url{display:block;margin:-4px 0 10px;text-align:center;color:#075c9f;font-size:11px;font-weight:800;word-break:break-all;text-decoration:underline}
    .mobile-photo-steps{display:grid;gap:6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;font-size:12px;color:#334155}
    .mobile-photo-status{margin-top:10px;color:#075c9f;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:8px 10px;font-size:12px;font-weight:800;text-align:center}
    .mobile-photo-status.is-success{color:#047857;background:#ecfdf3;border-color:#a7f3d0}
    .mobile-photo-status.is-error{color:#991b1b;background:#fef2f2;border-color:#fecaca}
</style>

<script>
    window.APP_BASE_URL = window.APP_BASE_URL || "<?= defined('APP_BASE_URL') ? htmlspecialchars(APP_BASE_URL) : '' ?>";
    
    document.addEventListener('DOMContentLoaded', function() {
        const dobInput = document.querySelector('input[name="dob"]');
        const pincodeInput = document.querySelector('input[name="pincode"]');
        const photoInput = document.getElementById('photoInput');

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
