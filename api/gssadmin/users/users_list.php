<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/auth.php';

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

    $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
    $search = trim((string)($_GET['search'] ?? ''));
    $group = strtolower(trim((string)($_GET['group'] ?? '')));

    $pdo = getDB();

    // When client_id is 0/missing => return all users across all clients.
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetUsers(?, ?)');
    $stmt->execute([$clientId > 0 ? $clientId : null, $search !== '' ? $search : null]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($stmt->nextRowset()) {
    }

    // Never show candidate accounts in Users list
    $filteredBase = [];
    foreach ($rows as $r) {
        $role = strtolower(trim((string)($r['role'] ?? '')));
        if ($role === 'candidate') continue;
        $filteredBase[] = $r;
    }
    $rows = $filteredBase;

    $staffRoles = [
        'gss_admin' => true,
        'verifier' => true,
        'db_verifier' => true,
        'qa' => true,
        'team_lead' => true,
    ];
    if ($group === 'staff' || $group === 'client') {
        $filtered = [];
        foreach ($rows as $r) {
            $role = strtolower(trim((string)($r['role'] ?? '')));
            $isStaff = isset($staffRoles[$role]);
            if ($group === 'staff' && !$isStaff) continue;
            if ($group === 'client' && $isStaff) continue;
            $filtered[] = $r;
        }
        $rows = $filtered;
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => $rows
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
