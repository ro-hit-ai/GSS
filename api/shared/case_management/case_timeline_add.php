<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/auth.php';

auth_require_login(null);

auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
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

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $applicationId = isset($input['application_id']) ? trim((string)$input['application_id']) : '';
    $eventType = isset($input['event_type']) ? strtolower(trim((string)$input['event_type'])) : 'comment';
    $sectionKey = isset($input['section_key']) ? trim((string)$input['section_key']) : '';
    $message = isset($input['message']) ? trim((string)$input['message']) : '';

    if ($applicationId === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id is required']);
        exit;
    }

    if ($message === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'message is required']);
        exit;
    }

    $allowedTypes = ['comment', 'update', 'action'];
    if (!in_array($eventType, $allowedTypes, true)) {
        $eventType = 'comment';
    }

    $userId = !empty($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
    $role = !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : '';

    $pdo = getDB();

    enforce_client_admin_application_scope($pdo, $applicationId);
    $stmt = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$applicationId, $userId > 0 ? $userId : null, $role !== '' ? $role : null, $eventType, $sectionKey !== '' ? $sectionKey : null, $message]);

    echo json_encode(['status' => 1, 'message' => 'ok']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
