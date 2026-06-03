<?php
session_start();

require_once __DIR__ . '/mobile_photo_common.php';

try {
    if (empty($_SESSION['logged_in']) || empty($_SESSION['application_id'])) {
        mobile_photo_json(['status' => 0, 'message' => 'Unauthorized'], 401);
    }

    $applicationId = trim((string)$_SESSION['application_id']);
    if ($applicationId === '') {
        mobile_photo_json(['status' => 0, 'message' => 'Missing application context'], 400);
    }

    $pdo = getDB();
    $token = mobile_photo_new_token();
    $expiresAt = MobilePhotoService::createSession($pdo, $applicationId, $token);

    $uploadUrl = mobile_photo_build_url($token);
    mobile_photo_json([
        'status' => 1,
        'message' => 'Mobile photo session created',
        'data' => [
            'token' => $token,
            'expires_at' => $expiresAt,
            'upload_url' => $uploadUrl,
            'security_hint' => mobile_photo_url_security_hint($uploadUrl),
            'qr_data_uri' => mobile_photo_qr_data_uri($uploadUrl),
            'qr_url' => app_url('/api/candidate/mobile_photo_qr.php?token=' . urlencode($token)),
        ],
    ]);
} catch (Throwable $e) {
    mobile_photo_json(['status' => 0, 'message' => 'Unable to create mobile photo session'], 500);
}
