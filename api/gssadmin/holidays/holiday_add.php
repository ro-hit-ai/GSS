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

    $date = isset($data['holiday_date']) ? trim((string)$data['holiday_date']) : '';
    $name = isset($data['holiday_name']) ? trim((string)$data['holiday_name']) : '';

    if ($date === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'holiday_date is required']);
        exit;
    }
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'holiday_name is required']);
        exit;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_AddHoliday(?, ?)');
    $stmt->execute([$date, $name]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    $holidayId = isset($row['holiday_id']) ? (int)$row['holiday_id'] : 0;
    if ($holidayId <= 0) {
        throw new RuntimeException('Failed to add holiday');
    }

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => ['holiday_id' => $holidayId]]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
