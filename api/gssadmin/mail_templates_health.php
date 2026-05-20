<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../shared/template_governance.php';

auth_require_login('gss_admin');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }
    $pdo = getDB();
    $health = tmpl_health_check($pdo);
    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => $health,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}

