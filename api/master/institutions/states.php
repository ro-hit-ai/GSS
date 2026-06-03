<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../services/master/InstitutionService.php';

try {
    echo json_encode([
        'success' => true,
        'data' => InstitutionService::provider(getDB())->states(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Institution states unavailable']);
}
