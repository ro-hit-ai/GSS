<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_any_access(['qa', 'team_lead']);
auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function get_int(string $key, int $default = 0): int {
    $v = $_GET[$key] ?? $default;
    return (int)$v;
}

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $role = strtolower(get_str('role', ''));
    $clientId = get_int('client_id', 0);

    $roleSet = [];
    if ($role === 'verifier') {
        $roleSet = ['verifier', 'db_verifier'];
    } else {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'role is required (verifier)']);
        exit;
    }

    $pdo = getDB();

    $in = implode(',', array_fill(0, count($roleSet), '?'));
    $sql =
        "SELECT u.user_id, u.client_id, u.username, u.first_name, u.last_name, u.role\n" .
        "  FROM Vati_Payfiller_Users u\n" .
        " WHERE u.is_active = 1\n" .
        "   AND LOWER(TRIM(u.role)) IN ($in)\n" .
        "   AND (? = 0 OR u.client_id = ?)\n" .
        " ORDER BY u.first_name ASC, u.last_name ASC, u.username ASC";

    $params = array_merge($roleSet, [$clientId, $clientId]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $name = trim(((string)($r['first_name'] ?? '')) . ' ' . ((string)($r['last_name'] ?? '')));
        if ($name === '') {
            $name = (string)($r['username'] ?? ('User #' . (string)($r['user_id'] ?? '')));
        }
        $out[] = [
            'user_id' => (int)($r['user_id'] ?? 0),
            'client_id' => (int)($r['client_id'] ?? 0),
            'name' => $name,
            'role' => (string)($r['role'] ?? '')
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
