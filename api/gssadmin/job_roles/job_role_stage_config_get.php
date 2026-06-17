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

    $jobRoleId = isset($_GET['job_role_id']) ? (int)$_GET['job_role_id'] : 0;
    if ($jobRoleId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'job_role_id is required']);
        exit;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetJobRoleStageConfig(?)');
    $stmt->execute([$jobRoleId]);

    $stageRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Move to steps result set
    $stmt->nextRowset();
    $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Move to available types result set
    $stmt->nextRowset();
    $available = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Drain any remaining result sets
    while ($stmt->nextRowset()) {
    }

    $outStage = [
        'job_role_id' => $jobRoleId,
        'stage_key' => (string)($stageRow['stage_key'] ?? ''),
        'is_active' => isset($stageRow['is_active']) ? (int)$stageRow['is_active'] : 1,
    ];

    $outSteps = [];
    foreach ($steps as $s) {
        $outSteps[] = [
            'verification_type_id' => isset($s['verification_type_id']) ? (int)$s['verification_type_id'] : 0,
            'type_name' => (string)($s['type_name'] ?? ''),
            'type_category' => (string)($s['type_category'] ?? ''),
            'execution_group' => isset($s['execution_group']) ? (int)$s['execution_group'] : 1,
            'assigned_role' => (string)($s['assigned_role'] ?? ''),
            'is_active' => isset($s['is_active']) ? (int)$s['is_active'] : 1,
        ];
    }

    $outAvail = [];
    foreach ($available as $a) {
        $outAvail[] = [
            'verification_type_id' => isset($a['verification_type_id']) ? (int)$a['verification_type_id'] : 0,
            'type_name' => (string)($a['type_name'] ?? ''),
            'type_category' => (string)($a['type_category'] ?? ''),
            'sort_order' => isset($a['sort_order']) ? (int)$a['sort_order'] : 0,
        ];
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'stage' => $outStage,
            'steps' => $outSteps,
            'available_types' => $outAvail
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
