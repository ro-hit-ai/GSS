<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../services/master/InstitutionService.php';

try {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Institution id is required']);
        exit;
    }

    $provider = InstitutionService::provider(getDB());
    $row = $provider->findById($id);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Institution not found']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $row]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Institution detail unavailable']);
}
