<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$application_id = getApplicationId();
$pdo = getDB();

/* ================= GET SOCIAL MEDIA DETAILS ================= */
$stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_social_media_details(?)");
$stmt->execute([$application_id]);
$social = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stmt->closeCursor();

$content            = $social['content'] ?? '';
$linkedin_url       = $social['linkedin_url'] ?? '';
$facebook_url       = $social['facebook_url'] ?? '';
$instagram_url      = $social['instagram_url'] ?? '';
$twitter_url        = $social['twitter_url'] ?? '';
$other_url          = $social['other_url'] ?? '';
$consent_bgv        = $social['consent_bgv'] ?? 0;
?>

<div class="candidate-form compact-form create-like-spacing">

    <!-- HEADER -->
    <div class="form-header">
        <i class="fas fa-share-alt"></i> Social Media Information
    </div>

    <p class="text-muted mb-3">
        Provide your social media profiles for verification purposes. LinkedIn and Facebook are required.
    </p>

    <form id="socialForm">

        <!-- REQUIRED PROFILES -->
        <div class="form-row-2 compact-row mb-2">
            <!-- LinkedIn (Required) -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label required">LinkedIn Profile</label>
                    <input type="url"
                           name="linkedin_url"
                           class="compact-input"
                           placeholder="https://linkedin.com/in/username"
                           value="<?= htmlspecialchars($linkedin_url) ?>"
                           required>
                </div>
            </div>

            <!-- Facebook (Required) -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label required">Facebook Profile</label>
                    <input type="url"
                           name="facebook_url"
                           class="compact-input"
                           placeholder="https://facebook.com/username"
                           value="<?= htmlspecialchars($facebook_url) ?>"
                           required>
                </div>
            </div>
        </div>

        <div class="form-row-2 compact-row mb-3">
            <!-- Twitter (Optional) -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Twitter Profile</label>
                    <input type="url"
                           name="twitter_url"
                           class="compact-input"
                           placeholder="https://twitter.com/username"
                           value="<?= htmlspecialchars($twitter_url) ?>">
                </div>
            </div>

            <!-- Instagram (Optional) -->
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Instagram Profile</label>
                    <input type="url"
                           name="instagram_url"
                           class="compact-input"
                           placeholder="https://instagram.com/username"
                           value="<?= htmlspecialchars($instagram_url) ?>">
                </div>
            </div>
        </div>

        <!-- OTHER + ADDITIONAL INFORMATION -->
        <div class="form-row-2 compact-row mb-3">
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Other Profile/Portfolio</label>
                   <textarea
                    name="other_url" 
                    rows="1"
                    class="compact-textarea"
                     placeholder="GitHub, Portfolio, Blog, etc.">
                     <?= htmlspecialchars($other_url) ?></textarea>
                </div>
            </div>
            <div class="form-field">
                <div class="form-control double-border compact-control">
                    <label class="compact-label">Additional Information</label>
                    <textarea
                        name="content"
                        rows="1"
                        class="compact-textarea"
                        placeholder="Any notes about your social media profiles..."
                    ><?= htmlspecialchars($content) ?></textarea>
                </div>
            </div>
        </div>

        <!-- CONSENT CHECKBOX -->
        <div class="form-field mb-4">
            <div class="form-check">
                <input class="form-check-input" 
                       type="checkbox" 
                       name="consent_bgv" 
                       value="1" 
                       id="consent_bgv"
                       required
                       <?= $consent_bgv ? 'checked' : '' ?>>
                <label class="form-check-label small" for="consent_bgv">
                    I consent to social media verification as part of the background check process.
                </label>
            </div>
        </div>

        <!-- FOOTER BUTTONS -->
        <div class="form-footer compact-footer">
            <button type="button" class="btn-outline prev-btn">
                <i class="fas fa-arrow-left me-2"></i> Previous
            </button>

            <div class="footer-actions-right">
                <!-- <button type="button"
                        class="btn-secondary save-draft-btn"
                        data-page="social">
                    Save Draft
                </button> -->

                <button type="button"
                        class="btn-primary external-submit-btn"
                        data-form="socialForm">
                    Next <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>

    </form>
</div>

<?php
$socialData = [
    'content'       => $content,
    'linkedin_url'  => $linkedin_url,
    'facebook_url'  => $facebook_url,
    'instagram_url' => $instagram_url,
    'twitter_url'   => $twitter_url,
    'other_url'     => $other_url,
    'consent_bgv'   => $consent_bgv,
];
?>

<div id="socialData"
     data-social='<?= htmlspecialchars(json_encode($socialData), ENT_QUOTES, 'UTF-8') ?>'
     style="display:none"></div>
