<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/integration.php';

auth_require_login();
auth_session_start();

function get_str(string $key, string $default = ''): string
{
    return trim((string)($_GET[$key] ?? $default));
}

function session_role_norm(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $role = !empty($_SESSION['auth_moduleAccess']) ? strtolower(trim((string)$_SESSION['auth_moduleAccess'])) : '';
    if ($role === 'customer_admin') {
        $role = 'client_admin';
    }
    return $role;
}

function session_client_id(): int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
}

function enforce_client_admin_application_scope(PDO $pdo, string $applicationId): void
{
    if (session_role_norm() !== 'client_admin') {
        return;
    }

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

function resolve_replies_table(PDO $pdo): string
{
    $table = 'GSS_Email_Replies';

    $st = $pdo->query("SHOW TABLES LIKE 'GSS_Email_Replies'");
    
    if ($st && $st->fetchColumn()) {
        return $table;
    }

    throw new RuntimeException('Replies table not found: GSS_Email_Replies');
}

function ensure_required_columns(PDO $pdo, string $table): void
{
    $st = $pdo->query('DESCRIBE `' . str_replace('`', '``', $table) . '`');
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $have = [];
    foreach ($rows as $row) {
        $name = isset($row['Field']) ? strtolower(trim((string)$row['Field'])) : '';
        if ($name !== '') {
            $have[$name] = true;
        }
    }

    foreach (['application_id', 'sender', 'message', 'created_at'] as $required) {
        if (!isset($have[$required])) {
            throw new RuntimeException("Column missing in {$table}: {$required}");
        }
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $applicationId = integration_normalize_application_id(get_str('application_id'));
    if ($applicationId === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id is required']);
        exit;
    }

    $pdo = getDB();
    enforce_client_admin_application_scope($pdo, $applicationId);
    $table = resolve_replies_table($pdo);
    ensure_required_columns($pdo, $table);

    $stmt = $pdo->prepare(
        'SELECT sender, message, created_at '
        . 'FROM `' . str_replace('`', '``', $table) . '` '
        . 'WHERE application_id = ? '
        . 'ORDER BY created_at DESC '
        . 'LIMIT 200'
    );
    $stmt->execute([$applicationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode(array_map(static function (array $row): array {
        return [
            'sender' => (string)($row['sender'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }, $rows));
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 0,
        'message' => 'Database error',
        'error' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 0,
        'message' => 'Server error',
        'error' => $e->getMessage()
    ]);
}
