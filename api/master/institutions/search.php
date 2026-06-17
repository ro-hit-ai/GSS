<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../services/master/InstitutionService.php';

try {
    $pdo = getDB();

    // List mode: return all distinct university + board values, alphabetical
    if (isset($_GET['list']) && $_GET['list'] === 'university_board') {
        $stmt = $pdo->query("
            SELECT DISTINCT university_name AS value
              FROM Vati_Payfiller_Institution_Master
             WHERE is_active = 1 AND university_name IS NOT NULL AND university_name <> ''
            UNION
            SELECT DISTINCT board_name AS value
              FROM Vati_Payfiller_Institution_Master
             WHERE is_active = 1 AND board_name IS NOT NULL AND board_name <> ''
            ORDER BY value ASC
        ");
        $values = array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN)));
        echo json_encode(['success' => true, 'data' => $values]);
        exit;
    }

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
