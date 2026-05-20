<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/queue_visibility.php';

auth_require_login('verifier');

auth_session_start();
$userId = (int)($_SESSION['auth_user_id'] ?? 0);

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

function get_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $clientId = get_int('client_id', 0);
    $groupKey = strtoupper(get_str('group', ''));
    $search = get_str('search', '');

    if ($groupKey !== '' && !wf_is_valid_verifier_group($groupKey)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Invalid group']);
        exit;
    }

    if ($groupKey !== '') {
        $allowedSet = verifier_allowed_sections_set_from_session();
        if (!verifier_can_group_by_sections($allowedSet, $groupKey)) {
            http_response_code(403);
            echo json_encode(['status' => 0, 'message' => 'Access denied']);
            exit;
        }
    }

    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListMine(?, ?, ?, ?)');
    $stmt->execute([$userId, $clientId > 0 ? $clientId : null, $groupKey !== '' ? $groupKey : null, $search !== '' ? $search : null]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
