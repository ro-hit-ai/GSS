<?php
session_start();

$applicationId = trim((string)($_GET['application_id'] ?? ''));
if ($applicationId === '') {
    http_response_code(400);
    echo 'Invalid request';
    exit;
}

$role = strtolower(trim((string)($_SESSION['role'] ?? ($_SESSION['auth_moduleAccess'] ?? ''))));
$isCandidateSession = !empty($_SESSION['logged_in']) && !empty($_SESSION['application_id']);

if (!$isCandidateSession && empty($_SESSION['auth_user_id'])) {
    http_response_code(401);
    echo 'Unauthorized access';
    exit;
}

if ($isCandidateSession) {
    $sessionAppId = trim((string)($_SESSION['application_id'] ?? ''));
    if ($sessionAppId !== $applicationId) {
        http_response_code(401);
        echo 'Unauthorized access to application';
        exit;
    }
}

$_GET['application_id'] = $applicationId;
$_GET['force_download'] = '1';
require __DIR__ . '/../../candidate/generate_pdf.php';

