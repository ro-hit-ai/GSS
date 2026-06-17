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
    $levelKey = isset($_GET['level_key']) ? trim((string)$_GET['level_key']) : '';
    $jobRoleId = isset($_GET['job_role_id']) ? (int)$_GET['job_role_id'] : 0;

    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id is required']);
        exit;
    }
    if ($levelKey === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'level_key is required']);
        exit;
    }

    $pdo = getDB();

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetClientTypeTatCost(?, ?, ?)');
    $stmt->execute([$clientId, $levelKey, $jobRoleId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {}

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'client_id' => isset($r['client_id']) ? (int)$r['client_id'] : $clientId,
            'level_key' => (string)($r['level_key'] ?? $levelKey),
            'verification_type_id' => isset($r['verification_type_id']) ? (int)$r['verification_type_id'] : 0,
            'internal_tat_value' => array_key_exists('internal_tat_value', $r) ? $r['internal_tat_value'] : null,
            'internal_tat_unit' => (string)($r['internal_tat_unit'] ?? 'days'),
            'external_tat_value' => array_key_exists('external_tat_value', $r) ? $r['external_tat_value'] : null,
            'external_tat_unit' => (string)($r['external_tat_unit'] ?? 'days'),
            'cost_inr' => array_key_exists('cost_inr', $r) ? $r['cost_inr'] : null,
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
