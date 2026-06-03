<?php
session_start();
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = getApplicationId();
ensureApplicationExists($application_id);
$correctionMode = !empty($_SESSION['candidate_correction_mode']);
$correctionContext = null;
if ($correctionMode && !empty($_SESSION['candidate_correction_session_id'])) {
    try {
        $pdo = getDB();
        $st = $pdo->prepare('SELECT requested_by_name, requested_role, correction_reason, expires_at, allowed_components_json FROM Vati_Payfiller_Candidate_Correction_Sessions WHERE correction_session_id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['candidate_correction_session_id']]);
        $cc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($cc) {
            $components = json_decode((string)($cc['allowed_components_json'] ?? '[]'), true);
            if (!is_array($components)) $components = [];
            $correctionContext = [
                'requested_by_name' => (string)($cc['requested_by_name'] ?? ''),
                'requested_role' => (string)($cc['requested_role'] ?? ''),
                'correction_reason' => (string)($cc['correction_reason'] ?? ''),
                'expires_at' => (string)($cc['expires_at'] ?? ''),
                'components' => $components
            ];
        }
    } catch (Throwable $e) {}
}
?>

<form id="review-confirmationForm" class="candidate-form">
    <?php if ($correctionMode && is_array($correctionContext)): ?>
    <div class="mb-4 p-3 border rounded" style="background:#fffbeb; border-color:#fde68a !important;">
        <div style="font-weight:700; color:#92400e; margin-bottom:6px;">Correction Request</div>
        <div style="font-size:13px; color:#7c2d12;">Requested By: <?= htmlspecialchars(trim((string)$correctionContext['requested_by_name']) !== '' ? (string)$correctionContext['requested_by_name'] : (string)$correctionContext['requested_role']) ?></div>
        <div style="font-size:13px; color:#7c2d12;">Role: <?= htmlspecialchars((string)$correctionContext['requested_role']) ?></div>
        <div style="font-size:13px; color:#7c2d12;">Components: <?= htmlspecialchars(implode(', ', array_map('strval', (array)$correctionContext['components']))) ?></div>
        <div style="font-size:13px; color:#7c2d12;">Reason: <?= htmlspecialchars((string)$correctionContext['correction_reason']) ?></div>
        <div style="font-size:13px; color:#7c2d12;">Deadline: <?= htmlspecialchars((string)$correctionContext['expires_at']) ?></div>
    </div>
    <?php endif; ?>

    <!-- TITLE -->
    <h2 class="section-title">
        <i class="fas fa-handshake me-2"></i>Welcome to Your Application
    </h2>

    <p class="text-muted mb-4">
        Thank you for choosing GSS. Before you begin filling in your details,
        please review and accept the full Release and Authorization Form below.
    </p>

    <div class="mb-4 p-4 bg-light border rounded">
        <strong class="d-block mb-2">Your Application ID:</strong>
        <span class="fs-5 fw-bold text-primary">
            <?= htmlspecialchars($application_id) ?>
        </span>
        <small class="text-muted d-block mt-2">
            Please save this ID for future reference.
        </small>
    </div>

    <!-- FULL RELEASE AND AUTHORIZATION FORM -->
    <div class="terms-box mb-5 p-4 border rounded bg-white" style="max-height: 400px; overflow-y: auto;">
        <h5 class="fw-bold mb-3 text-primary">
            <i class="fas fa-file-contract me-2"></i>Release and Authorization Form
        </h5>

        <p>
            The purpose of this form is to notify you that a consumer report will be prepared on you in the course of
            consideration of employment with <strong>GSS</strong>.
        </p>

        <p>
            Past employers, education institutions, law enforcement agencies and other entities, require the following
            information for positive identification when checking public records. This information is confidential and will not be
            used for any other purposes.
        </p>

        <p>
            In connection with this request, I <span id="dynamicName"></span> authorize past employers,
            educational institutions, law enforcement agencies, city, state, county and federal courts and military services to
            release information about my background including but not limited to information about my employment, education,
            credit history, driving records, criminal records, general public records to the person or company with which this
            form has been filed or their assigned agents thereof. My signature, below releases the aforesaid parties or the
            company or the individuals releasing the information from any liability whatsoever in collecting and disseminating
            the information obtained. Further, in accordance with the host nation laws regarding the release of information, the
            Data Protection Privacy Act, the European Privacy Act and others, I authorize the transmittal and release of information to the above agencies and my employer in any country.
        </p>

        <!-- Optional: Add a summary list for quick scan -->
        <details class="mt-3">
            <summary class="fw-bold text-secondary mb-2">Quick Summary of Authorizations</summary>
            <ul class="small">
                <li>Employment verification</li>
                <li>Education verification</li>
                <li>Identity & address checks</li>
                <li>Criminal checks (as permitted)</li>
                <li>Credit history & driving records</li>
                <li>Reference checks</li>
                <li>Release from liability for all parties</li>
            </ul>
        </details>

        <p class="fw-bold text-danger mt-3">
            I confirm all information provided is accurate and truthful. False information may result in rejection or termination.
        </p>
    </div>

    <!-- AGREEMENT - Updated checkbox label -->
    <div class="form-field mb-4">
        <div class="form-check normal-checkbox">
            <input
                class="form-check-input"
                type="checkbox"
                id="agreeCheck"
                name="agree_check"
                value="1"
                required
            >
            <label class="form-check-label" for="agreeCheck">
                I have read, understood, and agree to the full Release and Authorization Form above <span class="text-danger">*</span>
            </label>
        </div>
    </div>

    <!-- SIGNATURE -->
    <div class="form-field mb-5">
        <label class="form-label fw-bold">
            Digital Signature <span class="text-danger">*</span>
        </label>
        <input
            type="text"
            id="digitalSignature"
            name="digital_signature"
            class="form-control form-control-lg"
            placeholder="Type your full legal name"
            required
        >
        <small class="text-muted">
            Typing your name acts as your legal digital signature and will be inserted into the form above.
        </small>
    </div>

    <input type="hidden" name="application_id"
           value="<?= htmlspecialchars($application_id) ?>">

    <!-- START APPLICATION BUTTON -->
    <div class="text-end mt-5">
        <button
            type="button"
            class="btn btn-primary btn-lg external-submit-btn px-5"
            data-form="review-confirmationForm"
            id="submitFinalBtn"
            disabled
        >
            <i class="fas fa-arrow-right me-2"></i>Start Application
        </button>
    </div>
</form>

<!-- JavaScript for Dynamic Name Insertion and Button Enablement -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const signatureInput = document.getElementById('digitalSignature');
    const dynamicName = document.getElementById('dynamicName');
    const agreeCheck = document.getElementById('agreeCheck');
    const submitBtn = document.getElementById('submitFinalBtn');

    function updateNameAndButton() {
        if (signatureInput.value.trim()) {
            dynamicName.textContent = signatureInput.value.trim();
        } else {
            dynamicName.textContent = '[Your Full Name Here]';
        }

        submitBtn.disabled = !(agreeCheck.checked && signatureInput.value.trim());
    }

    signatureInput.addEventListener('input', updateNameAndButton);
    agreeCheck.addEventListener('change', updateNameAndButton);

    // Initial check
    updateNameAndButton();
});
</script>
