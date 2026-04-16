<?php 
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

$fatalError = '';

$envOk = @include_once __DIR__ . '/../../config/env.php';
$dbOk = @include_once __DIR__ . '/../../config/db.php';

if (!$envOk) {
    $fatalError = $fatalError !== '' ? $fatalError : 'Failed to load env.php';
}
if (!$dbOk) {
    $fatalError = $fatalError !== '' ? $fatalError : 'Failed to load db.php';
}

$application_id = '';
$pdo = null;
$ecourt_row = [];
$contact_row = [];

try {
    if ($fatalError !== '') {
        throw new RuntimeException($fatalError);
    }

    $application_id = getApplicationId();
    $pdo = getDB();

    // Get E-Court details
    $ecourt_row = [];
    try {
        $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_ecourt_details(?)");
        $stmt->execute([$application_id]);
        $ecourt_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $ecourt_row = [];
    } finally {
        try {
            if (isset($stmt) && $stmt) {
                while ($stmt->nextRowset()) {
                }
                $stmt->closeCursor();
            }
        } catch (Throwable $_e) {
        }
    }

    // Get Contact details for addresses
    $contact_row = [];
    try {
        $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_contact_details(?)");
        $stmt->execute([$application_id]);
        $contact_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $contact_row = [];
    } finally {
        try {
            if (isset($stmt) && $stmt) {
                while ($stmt->nextRowset()) {
                }
                $stmt->closeCursor();
            }
        } catch (Throwable $_e) {
        }
    }

} catch (Throwable $e) {
    $fatalError = $e->getMessage();
    if (session_status() !== PHP_SESSION_NONE && !empty($_SESSION['application_id'])) {
        $application_id = (string)$_SESSION['application_id'];
    }
    $ecourt_row = [];
    $contact_row = [];
}

// Format addresses from contact details
function formatAddress($address1, $address2, $city, $state, $country, $postal_code) {
    $parts = [];
    if (!empty($address1)) $parts[] = $address1;
    if (!empty($address2)) $parts[] = $address2;
    if (!empty($city)) $parts[] = $city;
    if (!empty($state)) $parts[] = $state;
    if (!empty($postal_code)) $parts[] = $postal_code;
    if (!empty($country) && $country !== 'India') $parts[] = $country;
    
    return implode(', ', $parts);
}

// Get current address from contact
$current_address_from_contact = formatAddress(
    $contact_row['address1'] ?? '',
    $contact_row['address2'] ?? '',
    $contact_row['city'] ?? '',
    $contact_row['state'] ?? '',
    $contact_row['country'] ?? '',
    $contact_row['postal_code'] ?? ''
);

// Get permanent address from contact
$same_as_current = $contact_row['same_as_current'] ?? 0;
if ($same_as_current) {
    $permanent_address_from_contact = $current_address_from_contact;
} else {
    $permanent_address_from_contact = formatAddress(
        $contact_row['permanent_address1'] ?? '',
        $contact_row['permanent_address2'] ?? '',
        $contact_row['permanent_city'] ?? '',
        $contact_row['permanent_state'] ?? '',
        $contact_row['permanent_country'] ?? '',
        $contact_row['permanent_postal_code'] ?? ''
    );
}

// Use e-court data if exists, otherwise use contact data
$current_address = !empty($ecourt_row['current_address']) 
    ? $ecourt_row['current_address'] 
    : $current_address_from_contact;

$permanent_address = !empty($ecourt_row['permanent_address']) 
    ? $ecourt_row['permanent_address'] 
    : $permanent_address_from_contact;

$adultDobMax = date('Y-m-d', strtotime('-18 years'));
?>

<div class="candidate-form compact-form create-like-spacing">

    <?php if ($fatalError !== ''): ?>
        <div class="alert alert-danger" style="font-size:13px;">
            <?php echo htmlspecialchars($fatalError); ?>
        </div>
    <?php endif; ?>

    <!-- HEADER -->
    <div class="form-header">
        <i class="fas fa-gavel"></i> E-Court Check
    </div>

    <p class="text-muted mb-3">
        Verify court records and provide evidence documents. Addresses are pre-filled from your contact information.
    </p>

    <form id="ecourtForm" enctype="multipart/form-data">

        <!-- ADDRESS SECTION: Current + Permanent side by side -->
        <div class="form-row-2 compact-row mb-3">
            <!-- Current Address -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Current Address <span class="required">*</span></label>
                    <textarea name="current_address" rows="3" required class="compact-textarea"><?= htmlspecialchars($current_address) ?></textarea>
                    <?php if (empty($ecourt_row['current_address']) && !empty($current_address_from_contact)): ?>
                    <small class="compact-hint">Pre-filled from contact information</small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Permanent Address -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Permanent Address <span class="required">*</span></label>
                    <textarea name="permanent_address" rows="3" required class="compact-textarea"><?= htmlspecialchars($permanent_address) ?></textarea>
                    <?php if (empty($ecourt_row['permanent_address']) && !empty($permanent_address_from_contact)): ?>
                    <small class="compact-hint">
                        Pre-filled from contact information
                        <?php if ($same_as_current): ?>
                            <span class="text-info">(Same as current)</span>
                        <?php endif; ?>
                    </small>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- EVIDENCE DOCUMENT SECTION -->
        <div class="form-row-full compact-row mb-3">
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Evidence Document <span class="required">*</span></label>
                    <div class="file-upload-box" data-file-upload>
                        <div class="file-upload-row">
                            <button type="button" class="file-upload-btn" data-file-choose>Choose File</button>
                            <button type="button" class="file-upload-name" data-file-name disabled>No file chosen</button>
                        </div>
                        <div class="file-upload-error" data-file-error></div>
                    </div>
                    <input type="file"
                           name="evidence_document"
                           accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                           class="compact-file d-none"
                           data-file-input
                           required>
                </div>
            </div>
        </div>

        <!-- PERIOD & DATE INFORMATION -->
        <div class="form-grid-4 compact-row mb-3">
            <!-- From Date -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">From Date <span class="required">*</span></label>
                    <input type="date" name="period_from_date" required class="compact-input"
                           value="<?= htmlspecialchars($ecourt_row['period_from_date'] ?? '') ?>">
                </div>
            </div>

            <!-- To Date -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">To Date <span class="required">*</span></label>
                    <input type="date" name="period_to_date" required class="compact-input"
                           value="<?= htmlspecialchars($ecourt_row['period_to_date'] ?? '') ?>">
                </div>
            </div>

            <!-- Duration -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Duration (Years) <span class="required">*</span></label>
                    <input type="number" step="0.1" name="period_duration_years" required class="compact-input"
                           value="<?= htmlspecialchars($ecourt_row['period_duration_years'] ?? '') ?>">
                </div>
            </div>

            <!-- Date of Birth -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Date of Birth <span class="required">*</span></label>
                    <input type="date" name="dob" required class="compact-input" max="<?= htmlspecialchars($adultDobMax) ?>"
                           value="<?= htmlspecialchars($ecourt_row['dob'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- FORM FOOTER -->
        <div class="form-footer compact-footer">
            <button type="button" class="btn-outline prev-btn" data-form="ecourtForm">
                <i class="fas fa-arrow-left me-2"></i> Previous
            </button>
            
            <div class="footer-actions-right">
                <!-- <button type="button" class="btn-secondary save-draft-btn" data-page="ecourt">
                    Save Draft
                </button> -->
                
                <button type="button" class="btn-primary external-submit-btn" data-form="ecourtForm">
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>

    </form>
</div>

<?php
$ecourtData = [
    'current_address' => $current_address,
    'permanent_address' => $permanent_address,
    'evidence_document' => $ecourt_row['evidence_document'] ?? '',
    'period_from_date' => $ecourt_row['period_from_date'] ?? '',
    'period_to_date' => $ecourt_row['period_to_date'] ?? '',
    'period_duration_years' => $ecourt_row['period_duration_years'] ?? '',
    'dob' => $ecourt_row['dob'] ?? '',
    'on_hold' => 0,
    'not_applicable' => 0,
    'comments' => ''
];
?>

<div id="ecourtData"
     data-ecourt='<?= htmlspecialchars(json_encode($ecourtData), ENT_QUOTES, "UTF-8") ?>'
     data-dob-max="<?= htmlspecialchars($adultDobMax) ?>"
     style="display:none"></div>
