<?php
session_start();

require_once __DIR__ . '/mobile_photo_common.php';

try {
    if (empty($_SESSION['logged_in']) || empty($_SESSION['application_id'])) {
        mobile_photo_json(['status' => 0, 'message' => 'Unauthorized'], 401);
    }

    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        mobile_photo_json(['status' => 0, 'message' => 'token is required'], 400);
    }

    $pdo = getDB();
    $row = mobile_photo_fetch_session($pdo, $token);
    if (!$row || (string)$row['application_id'] !== (string)$_SESSION['application_id']) {
        mobile_photo_json(['status' => 0, 'message' => 'Session not found'], 404);
    }

    if (strtolower((string)$row['status']) === 'pending' && !mobile_photo_session_is_valid($row)) {
        MobilePhotoService::markExpired($pdo, (int)$row['session_id']);
        $row['status'] = 'expired';
    }

    $photoPath = trim((string)($row['photo_path'] ?? ''));
    mobile_photo_json([
        'status' => 1,
        'data' => [
            'upload_status' => (string)$row['status'],
            'photo_path' => $photoPath,
            'photo_url' => $photoPath !== '' ? app_url($photoPath) : '',
        ],
    ]);
} catch (Throwable $e) {
    mobile_photo_json(['status' => 0, 'message' => 'Unable to check mobile photo status'], 500);
}
