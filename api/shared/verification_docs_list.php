<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login(null);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
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

    $applicationId = get_str('application_id');
    $docType = get_str('doc_type');

    if ($applicationId === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id is required']);
        exit;
    }

    $pdo = getDB();

    enforce_client_admin_application_scope($pdo, $applicationId);

    $sql = 'SELECT d.id, d.application_id, d.doc_type, d.file_path, d.original_name, d.mime_type, d.uploaded_by_user_id, d.uploaded_by_role, d.created_at, '
        . "TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS uploaded_by_name, "
        . 'u.username AS uploaded_by_username '
        . 'FROM Vati_Payfiller_Verification_Documents d '
        . 'LEFT JOIN Vati_Payfiller_Users u ON u.user_id = d.uploaded_by_user_id '
        . 'WHERE d.application_id = ?';
    $params = [$applicationId];

    if ($docType !== '') {
        $sql .= ' AND d.doc_type = ?';
        $params[] = $docType;
    }

    $sql .= ' ORDER BY d.created_at DESC, d.id DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
