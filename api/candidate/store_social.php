<?php
header("Content-Type: application/json");
session_start();

require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../shared/case_management/case_component_binding.php";
require_once __DIR__ . "/../shared/candidate_correction_service.php";

/* ================= MAIN ================= */
try {
    // Check session
    if (empty($_SESSION['application_id'])) {
        throw new Exception("Session expired. Please login again.");
    }

    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $application_id = $_SESSION['application_id'];
    ccs_guard_correction_component_or_json('socialmedia');
    $post = array_map('trim', $_POST);

    /* ================= VALIDATION ================= */
    $errors = [];
    
    // Check required fields if not draft
    $is_draft = !empty($post['save_draft']) ? 1 : 0;
    
    if (!$is_draft) {
        // Required fields
        if (empty($post['linkedin_url'])) {
            $errors[] = "LinkedIn profile is required";
        }
        
        if (empty($post['facebook_url'])) {
            $errors[] = "Facebook profile is required";
        }
        
        if (empty($post['consent_bgv'])) {
            $errors[] = "You must consent to social media verification";
        }
        
        // URL validation
        $urls = [
            'LinkedIn' => $post['linkedin_url'] ?? '',
            'Facebook' => $post['facebook_url'] ?? '',
            'Twitter' => $post['twitter_url'] ?? '',
            'Instagram' => $post['instagram_url'] ?? '',
            'Other' => $post['other_url'] ?? ''
        ];
        
        foreach ($urls as $platform => $url) {
            if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
                $errors[] = "Invalid URL format for $platform";
            }
        }
    }
    
    if (!empty($errors)) {
        throw new Exception(implode(", ", $errors));
    }

    /* ================= PREPARE DATA ================= */
    $data = [
        ':application_id' => $application_id,
        ':content'        => $post['content'] ?? '',
        ':linkedin_url'   => $post['linkedin_url'] ?? '',
        ':facebook_url'   => $post['facebook_url'] ?? '',
        ':instagram_url'  => $post['instagram_url'] ?? '',
        ':twitter_url'    => $post['twitter_url'] ?? '',
        ':other_url'      => $post['other_url'] ?? '',
        ':consent_bgv'    => !empty($post['consent_bgv']) ? 1 : 0,
        ':posted_by'      => 'Candidate',
        ':is_draft'       => $is_draft
    ];

    /* ================= SAVE TO DATABASE ================= */
    $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_save_social_media_details(
        :application_id,
        :content,
        :linkedin_url,
        :facebook_url,
        :instagram_url,
        :twitter_url,
        :other_url,
        :consent_bgv,
        :posted_by,
        :is_draft
    )");

    $stmt->execute($data);
    $stmt->closeCursor();

    // Ensure workflow rows always exist for socialmedia component.
    ensure_component_workflow_rows($pdo, (string)$application_id, 'socialmedia');
    ccs_progress_component_after_candidate_save($pdo, (string)$application_id, 'socialmedia', (bool)$is_draft);

    /* ================= SUCCESS RESPONSE ================= */
    $response = [
        'success' => true,
        'draft'   => (bool)$is_draft,
        'message' => $is_draft 
            ? 'Social media information saved as draft' 
            : 'Social media information submitted successfully'
    ];

    echo json_encode($response);

} catch (Exception $e) {
    /* ================= ERROR RESPONSE ================= */
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
