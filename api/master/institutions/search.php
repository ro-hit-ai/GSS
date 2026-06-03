<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../services/master/InstitutionService.php';

try {
    $pdo = getDB();
    $provider = InstitutionService::provider($pdo);
    $result = $provider->search([
        'q' => $_GET['q'] ?? '',
        'type' => $_GET['type'] ?? '',
        'state' => $_GET['state'] ?? '',
        'city' => $_GET['city'] ?? '',
        'country' => $_GET['country'] ?? '',
        'page' => $_GET['page'] ?? 1,
        'limit' => $_GET['limit'] ?? 20,
    ]);

    echo json_encode([
        'success' => true,
        'data' => $result['data'],
        'pagination' => [
            'page' => $result['page'],
            'limit' => $result['limit'],
            'has_more' => $result['has_more'],
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Institution search unavailable']);
}
