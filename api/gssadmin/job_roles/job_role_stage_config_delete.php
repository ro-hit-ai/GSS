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

function arr_str($v, string $default = ''): string {
    if ($v === null) return $default;
    return trim((string)$v);
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

    $jobRoleId = isset($data['job_role_id']) ? (int)$data['job_role_id'] : 0;
    $stageKey = arr_str($data['stage_key'] ?? '');

    if ($jobRoleId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'job_role_id is required']);
        exit;
    }

    $allowedStages = ['pre_interview', 'post_interview', 'employee_pool'];
    $baseStageKey = $stageKey;
    if ($stageKey !== '' && strpos($stageKey, '__') !== false) {
        $parts = explode('__', $stageKey, 2);
        $baseStageKey = arr_str($parts[0] ?? '');
    }
    if ($stageKey === '' || !in_array($baseStageKey, $allowedStages, true)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'stage_key must be pre_interview, post_interview or employee_pool']);
        exit;
    }

    $pdo = getDB();

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_DeleteJobRoleStageMapping(?, ?)');
    $stmt->execute([$jobRoleId, $stageKey]);
    while ($stmt->nextRowset()) {}

    echo json_encode([
        'status' => 1,
        'message' => 'Deleted',
        'data' => [
            'job_role_id' => $jobRoleId,
            'stage_key' => $stageKey,
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
