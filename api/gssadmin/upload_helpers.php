<?php

require_once __DIR__ . '/../../config/env.php';

function upload_customer_logo(string $customerName): array {
    $maxMb = 2;
    $maxBytes = $maxMb * 1024 * 1024;
    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($_FILES['customer_logo']) || !is_array($_FILES['customer_logo'])) {
        return ['ok' => true, 'path' => ''];
    }

    $f = $_FILES['customer_logo'];
    if (!isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => ''];
    }

    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Logo upload failed. Please try again.'];
    }

    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'message' => 'Logo file is empty.'];
    }
    if ($size > $maxBytes) {
        return ['ok' => false, 'message' => 'Logo too large. Max size is ' . $maxMb . ' MB.'];
    }

    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid uploaded logo file.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    if (!isset($allowedMime[$mime])) {
        return ['ok' => false, 'message' => 'Invalid logo file type. Allowed: JPG, PNG, WEBP. Max size: ' . $maxMb . ' MB.'];
    }

    $ext = $allowedMime[$mime];
    $base = preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower(trim($customerName))) ?: 'client';
    $fileName = $base . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    $projectRoot = app_base_path();
    if ($projectRoot === '') {
        $projectRoot = (string)(realpath(__DIR__ . '/../../') ?: '');
    }
    if ($projectRoot === '') {
        return ['ok' => false, 'message' => 'Upload directory not found.'];
    }

    $uploadsDir = app_path('/uploads/customer_logos');
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0775, true);
    }
    if (is_dir($uploadsDir) && !is_writable($uploadsDir)) {
        @chmod($uploadsDir, 0775);
    }
    if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
        return ['ok' => false, 'message' => 'Upload directory is not writable: ' . $uploadsDir];
    }

    $dest = $uploadsDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => 'Failed to save logo. Please try again.'];
    }

    $publicPath = app_url('/uploads/customer_logos/' . $fileName);
    return ['ok' => true, 'path' => $publicPath];
}

function upload_sow_pdf(string $customerName): array {
    $maxMb = 10;
    $maxBytes = $maxMb * 1024 * 1024;

    if (!isset($_FILES['sow_pdf']) || !is_array($_FILES['sow_pdf'])) {
        return ['ok' => true, 'path' => ''];
    }

    $f = $_FILES['sow_pdf'];
    if (!isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => ''];
    }

    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'SOW upload failed. Please try again.'];
    }

    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'message' => 'SOW file is empty.'];
    }
    if ($size > $maxBytes) {
        return ['ok' => false, 'message' => 'SOW too large. Max size is ' . $maxMb . ' MB.'];
    }

    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid uploaded SOW file.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    if ($mime !== 'application/pdf') {
        return ['ok' => false, 'message' => 'Invalid SOW file type. Allowed: PDF only. Max size: ' . $maxMb . ' MB.'];
    }

    $base = preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower(trim($customerName))) ?: 'client';
    $fileName = 'sow_' . $base . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';

    $projectRoot = app_base_path();
    if ($projectRoot === '') {
        $projectRoot = (string)(realpath(__DIR__ . '/../../') ?: '');
    }
    if ($projectRoot === '') {
        return ['ok' => false, 'message' => 'Upload directory not found.'];
    }

    $uploadsDir = app_path('/uploads/sow_pdfs');
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0775, true);
    }
    if (is_dir($uploadsDir) && !is_writable($uploadsDir)) {
        @chmod($uploadsDir, 0775);
    }
    if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
        return ['ok' => false, 'message' => 'Upload directory is not writable: ' . $uploadsDir];
    }

    $dest = $uploadsDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => 'Failed to save SOW. Please try again.'];
    }

    $publicPath = app_url('/uploads/sow_pdfs/' . $fileName);
    return ['ok' => true, 'path' => $publicPath];
}
