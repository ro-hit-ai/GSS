<?php

require_once __DIR__ . '/mobile_photo_common.php';

function mobile_photo_fail(string $message, int $code = 400): void
{
    mobile_photo_json(['status' => 0, 'message' => $message], $code);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        mobile_photo_fail('Method not allowed', 405);
    }

    $token = trim((string)($_POST['token'] ?? ''));
    if ($token === '') {
        mobile_photo_fail('Missing upload token');
    }

    if (empty($_FILES['photo']) || !is_array($_FILES['photo'])) {
        mobile_photo_fail('Please capture a photo');
    }

    $pdo = getDB();
    $row = mobile_photo_fetch_session($pdo, $token);
    if (!$row) {
        mobile_photo_fail('Invalid upload session', 404);
    }
    if (!mobile_photo_session_is_valid($row)) {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        mobile_photo_fail($status === 'uploaded' ? 'Photo already uploaded for this QR. Please generate a new QR to retake.' : 'Upload session expired. Please generate a new QR from desktop.', 409);
    }

    $file = $_FILES['photo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        mobile_photo_fail('Photo upload failed');
    }
    if ((int)($file['size'] ?? 0) > 6 * 1024 * 1024) {
        mobile_photo_fail('Photo must be under 6MB');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        mobile_photo_fail('Uploaded photo is missing');
    }

    $info = @getimagesize($tmp);
    $mime = strtolower((string)($info['mime'] ?? ''));
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        mobile_photo_fail('Only JPG, PNG, or WEBP photos are allowed');
    }
    if ((!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) && $mime !== 'image/jpeg') {
        mobile_photo_fail('Server image compression is unavailable. Please upload a JPG photo.');
    }

    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    if ($width < 240 || $height < 240) {
        mobile_photo_fail('Photo is too small for verification');
    }

    $applicationId = (string)$row['application_id'];
    $filename = 'profile_mobile_' . preg_replace('/[^A-Za-z0-9_-]/', '', $applicationId) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $target = mobile_photo_upload_dir() . $filename;

    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $raw = file_get_contents($tmp);
        $src = $raw !== false ? @imagecreatefromstring($raw) : false;
        if (!$src) {
            mobile_photo_fail('Invalid image file');
        }
        $maxSide = 1200;
        $scale = min(1, $maxSide / max($width, $height));
        $targetWidth = max(1, (int)round($width * $scale));
        $targetHeight = max(1, (int)round($height * $scale));
        $dst = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($src);
        if (!imagejpeg($dst, $target, 84)) {
            imagedestroy($dst);
            mobile_photo_fail('Unable to save captured photo', 500);
        }
        imagedestroy($dst);
    } else {
        if (!move_uploaded_file($tmp, $target)) {
            mobile_photo_fail('Unable to save captured photo', 500);
        }
    }

    $photoPath = '/uploads/candidate_photos/' . $filename;

    $pdo->beginTransaction();
    if (MobilePhotoService::complete($pdo, $token, $photoPath) <= 0) {
        $pdo->rollBack();
        @unlink($target);
        mobile_photo_fail('Upload session already used', 409);
    }

    MobilePhotoService::attachToBasic($pdo, $applicationId, $photoPath);
    $pdo->commit();

    mobile_photo_json([
        'status' => 1,
        'message' => 'Photo uploaded successfully',
        'data' => [
            'photo_path' => $photoPath,
            'photo_url' => app_url($photoPath),
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    mobile_photo_json(['status' => 0, 'message' => 'Unable to upload photo'], 500);
}
