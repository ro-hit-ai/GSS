<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/auth.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

auth_require_login('gss_admin');

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
    $rows = [];
    try {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetJobRolesByClient(?)');
        $stmt->execute([$clientId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        while ($stmt->nextRowset()) {
        }
    } catch (Throwable $e) {
        $stmt2 = $pdo->prepare('SELECT job_role_id, client_id, role_name, is_active FROM Vati_Payfiller_Job_Roles WHERE client_id = ? ORDER BY role_name ASC');
        $stmt2->execute([$clientId]);
        $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'job_role_id' => isset($r['job_role_id']) ? (int)$r['job_role_id'] : 0,
            'client_id' => isset($r['client_id']) ? (int)$r['client_id'] : $clientId,
            'role_name' => (string)($r['role_name'] ?? ''),
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
