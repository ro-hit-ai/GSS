<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/integration.php';

integration_bootstrap_json_api();
auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

integration_resolve_actor(true);

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

function get_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

function session_role_norm(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $role = !empty($_SESSION['auth_moduleAccess']) ? strtolower(trim((string)$_SESSION['auth_moduleAccess'])) : '';
    if ($role === 'customer_admin') $role = 'client_admin';
    return $role;
}

function session_client_id(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
}

function enforce_client_admin_application_scope(PDO $pdo, string $applicationId): void {
    $role = session_role_norm();
    if ($role !== 'client_admin') return;

    $cid = session_client_id();
    if ($cid <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $st = $pdo->prepare('SELECT client_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
    $st->execute([$applicationId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $appClientId = $row && isset($row['client_id']) ? (int)$row['client_id'] : 0;
    if ($appClientId !== $cid) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Forbidden']);
        exit;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $applicationId = integration_normalize_application_id(get_str('application_id', ''));
    if ($applicationId === '') {
        integration_json_error(400, 'application_id is required', [], 'integration_failures');
    }

    $limit = get_int('limit', 200);
    if ($limit <= 0) $limit = 200;
    if ($limit > 500) $limit = 500;

    $pdo = getDB();

    enforce_client_admin_application_scope($pdo, $applicationId);

    $sql = 'SELECT t.timeline_id, t.application_id, t.actor_user_id, t.actor_role, t.event_type, t.section_key, t.message, t.meta_json, t.created_at, '
        . 'u.username, u.first_name, u.last_name '
        . 'FROM Vati_Payfiller_Case_Timeline t '
        . 'LEFT JOIN Vati_Payfiller_Users u ON u.user_id = t.actor_user_id '
        . 'WHERE t.application_id = ? '
        . 'ORDER BY t.created_at DESC, t.timeline_id DESC '
        . 'LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$applicationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $events = [];
    foreach ($rows as $row) {
        $actorName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        $events[] = [
            'timelineId' => isset($row['timeline_id']) ? (int)$row['timeline_id'] : null,
            'applicationId' => integration_normalize_application_id((string)($row['application_id'] ?? $applicationId)),
            'eventType' => integration_nullable_string($row['event_type'] ?? null),
            'eventTimestamp' => integration_iso_datetime($row['created_at'] ?? null),
            'sectionKey' => integration_nullable_string($row['section_key'] ?? null),
            'componentKey' => integration_nullable_string($row['section_key'] ?? null),
            'message' => integration_nullable_string($row['message'] ?? null),
            'metadata' => json_decode((string)($row['meta_json'] ?? ''), true) ?: null,
            'actor' => [
                'userId' => isset($row['actor_user_id']) && (int)$row['actor_user_id'] > 0 ? (int)$row['actor_user_id'] : null,
                'role' => integration_nullable_string($row['actor_role'] ?? null),
                'username' => integration_nullable_string($row['username'] ?? null),
                'name' => integration_nullable_string($actorName),
            ],
        ];
    }

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $events]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
