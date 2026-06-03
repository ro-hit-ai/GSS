<?php

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/candidate/MobilePhotoService.php';

function mobile_photo_new_token(): string
{
    return bin2hex(random_bytes(24));
}

function mobile_photo_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function mobile_photo_upload_dir(): string
{
    $dir = rtrim(app_path('/uploads/candidate_photos'), '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function mobile_photo_build_url(string $token): string
{
    $path = '/modules/candidate/mobile-photo-upload.php?token=' . urlencode($token);

    $configuredBase = trim((string)(env_get('MOBILE_PHOTO_BASE_URL', '') ?? ''));
    if ($configuredBase === '') {
        $configuredBase = trim((string)(env_get('CANDIDATE_PORTAL_BASE_URL', '') ?? ''));
    }
    if ($configuredBase !== '') {
        return rtrim($configuredBase, '/') . $path;
    }

    $url = app_url($path);
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $lanIp = gethostbyname(gethostname());
        if (filter_var($lanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $lanIp !== '127.0.0.1') {
            $scheme = (string)($parts['scheme'] ?? 'http');
            $port = isset($parts['port']) ? (':' . (int)$parts['port']) : '';
            $pathPart = (string)($parts['path'] ?? '');
            $query = isset($parts['query']) ? ('?' . (string)$parts['query']) : '';
            return $scheme . '://' . $lanIp . $port . $pathPart . $query;
        }
    }
    return $url;
}

function mobile_photo_url_security_hint(string $url): string
{
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($scheme === 'https') {
        return '';
    }
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return 'This QR uses localhost and will not work from a phone. Configure MOBILE_PHOTO_BASE_URL with an HTTPS tunnel URL.';
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return 'This QR uses local HTTP. Some mobile browsers show an HTTPS warning; use an HTTPS ngrok URL in MOBILE_PHOTO_BASE_URL for a smooth mobile capture.';
    }
    return 'This QR uses HTTP. Use HTTPS for the best mobile camera experience.';
}

function mobile_photo_qr_data_uri(string $content): string
{
    if (!class_exists('TCPDF2DBarcode')) {
        require_once __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
    }
    $barcode = new TCPDF2DBarcode($content, 'QRCODE,H');
    $svg = $barcode->getBarcodeSVGcode(6, 6, '#0f172a');
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function mobile_photo_fetch_session(PDO $pdo, string $token): ?array
{
    return MobilePhotoService::fetchByToken($pdo, $token);
}

function mobile_photo_session_is_valid(array $row): bool
{
    if (strtolower(trim((string)($row['status'] ?? ''))) !== 'pending') {
        return false;
    }
    try {
        $pdo = getDB();
        $st = $pdo->prepare('SELECT 1 FROM Vati_Payfiller_Candidate_Mobile_Photo_Sessions WHERE session_id = ? AND status = \'pending\' AND expires_at >= NOW() LIMIT 1');
        $st->execute([(int)($row['session_id'] ?? 0)]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
