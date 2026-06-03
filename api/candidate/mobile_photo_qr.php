<?php
session_start();

require_once __DIR__ . '/mobile_photo_common.php';
require_once __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';

try {
    if (empty($_SESSION['logged_in']) || empty($_SESSION['application_id'])) {
        http_response_code(401);
        exit;
    }

    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(400);
        exit;
    }

    $pdo = getDB();
    $row = mobile_photo_fetch_session($pdo, $token);
    if (!$row || (string)$row['application_id'] !== (string)$_SESSION['application_id'] || !mobile_photo_session_is_valid($row)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $barcode = new TCPDF2DBarcode(mobile_photo_build_url($token), 'QRCODE,H');
    echo $barcode->getBarcodeSVGcode(6, 6, '#0f172a');
} catch (Throwable $e) {
    http_response_code(500);
}
