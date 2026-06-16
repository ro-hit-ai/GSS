<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $data = read_json_body();
    if (!$data) {
        $data = $_POST;
    }

    $holidayId = isset($data['holiday_id']) ? (int)$data['holiday_id'] : 0;
    if ($holidayId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'holiday_id is required']);
        exit;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_DeactivateHoliday(?)');
    $stmt->execute([$holidayId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    $rows = isset($row['rows_affected']) ? (int)$row['rows_affected'] : 0;
    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => ['rows_affected' => $rows]]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
