<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../shared/verifier_routing.php';

auth_require_login('gss_admin');

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

    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'user_id is required']);
        exit;
    }

    $pdo = getDB();

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetUserById(?)');
    $stmt->execute([$userId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    while ($stmt->nextRowset()) {
    }

    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'User not found']);
        exit;
    }

    try {
        $locStmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetUserLocationsByUser(?)');
        $locStmt->execute([$userId]);
        $locRows = $locStmt->fetchAll(PDO::FETCH_ASSOC);
        while ($locStmt->nextRowset()) {
        }

        $names = [];
        foreach ($locRows as $lr) {
            $n = trim((string)($lr['location_name'] ?? ''));
            if ($n === '') continue;
            $names[] = $n;
        }
        if (!empty($names)) {
            $row['locations'] = $names;
        }
    } catch (Throwable $e) {
    }

    $row['routing_capabilities'] = verifier_routing_fetch_user_capabilities($pdo, $userId);

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => $row
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
