<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/auth.php';

auth_require_login(null);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function post_str(string $key, string $default = ''): string {
    return trim((string)($_POST[$key] ?? $default));
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $applicationId = post_str('application_id');
    $docType = post_str('doc_type');
    $role = post_str('role');

    if ($applicationId === '' || $docType === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id and doc_type are required']);
        exit;
    }

    if (empty($_FILES['files'])) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'No files uploaded']);
        exit;
    }

    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    $maxBytes = 10 * 1024 * 1024;

    $uploadDir = app_path('/uploads/verification/');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $pdo = getDB();

    enforce_client_admin_application_scope($pdo, $applicationId);

    $uploadedByUserId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : null;
    $uploadedByRole = $role !== '' ? $role : (isset($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : null);

    $files = $_FILES['files'];
    $count = is_array($files['name']) ? count($files['name']) : 0;
    $saved = [];

    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

        $origName = (string)($files['name'][$i] ?? '');
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        $size = (int)($files['size'][$i] ?? 0);
        $mime = (string)($files['type'][$i] ?? '');

        if ($size <= 0 || $size > $maxBytes) {
            continue;
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            continue;
        }

        $fname = 'ver_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $applicationId) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $uploadDir . $fname;

        if (!move_uploaded_file($tmp, $dest)) {
            continue;
        }

        $dbPath = '/uploads/verification/' . $fname;

        $stmt = $pdo->prepare('INSERT INTO Vati_Payfiller_Verification_Documents (application_id, doc_type, file_path, original_name, mime_type, uploaded_by_user_id, uploaded_by_role) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$applicationId, $docType, $dbPath, $origName, $mime, $uploadedByUserId, $uploadedByRole]);

        $saved[] = [
            'file_path' => $dbPath,
            'original_name' => $origName,
            'doc_type' => $docType
        ];
    }

    echo json_encode([
        'status' => 1,
        'message' => 'Uploaded',
        'data' => $saved
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
