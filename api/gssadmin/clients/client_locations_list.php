<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id is required']);
        exit;
    }

    $pdo = getDB();

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetClientLocationsByClient(?)');
    $stmt->execute([$clientId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($stmt->nextRowset()) {
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'client_location_id' => isset($r['client_location_id']) ? (int)$r['client_location_id'] : 0,
            'client_id' => isset($r['client_id']) ? (int)$r['client_id'] : $clientId,
            'location_name' => (string)($r['location_name'] ?? ''),
            'is_active' => isset($r['is_active']) ? (int)$r['is_active'] : 1,
        ];
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => $out
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
