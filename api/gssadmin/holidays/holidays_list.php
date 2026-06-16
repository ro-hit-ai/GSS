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

    $pdo = getDB();
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_ListHolidays()');
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($stmt->nextRowset()) {
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'holiday_id' => isset($r['holiday_id']) ? (int)$r['holiday_id'] : 0,
            'holiday_date' => (string)($r['holiday_date'] ?? ''),
            'holiday_name' => (string)($r['holiday_name'] ?? ''),
            'is_active' => isset($r['is_active']) ? (int)$r['is_active'] : 1,
        ];
    }

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $out]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
