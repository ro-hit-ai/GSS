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
    $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

    if ($clientId > 0) {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetVerificationProfilesByClient(?)');
        $stmt->execute([$clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        while ($stmt->nextRowset()) {
        }
    } else {
        $stmt2 = $pdo->prepare('CALL SP_Vati_Payfiller_GetAllVerificationProfiles()');
        $stmt2->execute();
        $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        while ($stmt2->nextRowset()) {
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'profile_id' => isset($r['profile_id']) ? (int)$r['profile_id'] : 0,
            'client_id' => isset($r['client_id']) ? (int)$r['client_id'] : 0,
            'customer_name' => (string)($r['customer_name'] ?? ''),
            'profile_name' => (string)($r['profile_name'] ?? ''),
            'description' => (string)($r['description'] ?? ''),
            'location' => (string)($r['location'] ?? ''),
            'is_active' => isset($r['is_active']) ? (int)$r['is_active'] : 0,
            'internal_tat_days' => isset($r['internal_tat_days']) ? (int)$r['internal_tat_days'] : null,
            'external_tat_days' => isset($r['external_tat_days']) ? (int)$r['external_tat_days'] : null,
            'created_at' => (string)($r['created_at'] ?? ''),
            'updated_at' => (string)($r['updated_at'] ?? ''),
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
