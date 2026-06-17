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

    $clientId = isset($data['client_id']) ? (int)$data['client_id'] : 0;
    $roleName = isset($data['role_name']) ? trim((string)$data['role_name']) : '';

    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id is required']);
        exit;
    }

    if ($roleName === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'role_name is required']);
        exit;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_AddJobRole(?, ?)');
    $stmt->execute([$clientId, $roleName]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    $jobRoleId = isset($row['job_role_id']) ? (int)$row['job_role_id'] : 0;
    if ($jobRoleId <= 0) {
        throw new RuntimeException('Failed to add job role');
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'job_role_id' => $jobRoleId,
            'client_id' => $clientId,
            'role_name' => $roleName
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
