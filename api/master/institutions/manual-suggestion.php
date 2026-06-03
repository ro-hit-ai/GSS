<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../services/master/InstitutionService.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $name = trim((string)($payload['institution_name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Institution name is required']);
        exit;
    }

    $payload['application_id'] = $payload['application_id'] ?? ($_SESSION['application_id'] ?? null);
    $id = InstitutionService::provider(getDB())->createManualSuggestion($payload);

    echo json_encode([
        'success' => true,
        'message' => 'Manual institution suggestion captured',
        'data' => [
            'id' => $id,
            'match_status' => 'manual_pending',
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Manual institution capture unavailable']);
}
