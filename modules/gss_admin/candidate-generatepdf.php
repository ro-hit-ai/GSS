<?php
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('gss_admin');

$applicationId = trim((string)($_GET['application_id'] ?? ''));
$clientId = (int)($_GET['client_id'] ?? 0);

if ($applicationId === '') {
    http_response_code(400);
    echo 'application_id is required';
    exit;
}

$target = 'candidate_report.php?print=1&application_id=' . rawurlencode($applicationId);
if ($clientId > 0) {
    $target .= '&client_id=' . rawurlencode((string)$clientId);
}

header('Location: ' . $target);
exit;
