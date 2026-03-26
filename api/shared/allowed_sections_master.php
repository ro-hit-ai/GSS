<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/auth.php';

if (!auth_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
    exit;
}

echo json_encode([
    'status' => 1,
    'message' => 'ok',
    'data' => [
        ['key' => 'basic', 'label' => 'Basic'],
        ['key' => 'id', 'label' => 'ID'],
        ['key' => 'contact', 'label' => 'Contact'],
        ['key' => 'education', 'label' => 'Education'],
        ['key' => 'employment', 'label' => 'Employment'],
        ['key' => 'socialmedia', 'label' => 'SocialMedia'],
        ['key' => 'ecourt', 'label' => 'ECourt'],
        ['key' => 'reference', 'label' => 'Reference'],
        ['key' => 'reports', 'label' => 'Authorization'],
        ['key' => 'timeline', 'label' => 'Timeline'],
    ]
]);
