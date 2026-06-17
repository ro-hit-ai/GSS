<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

auth_require_login('gss_admin');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $templateId = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
    if ($templateId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'template_id is required']);
        exit;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_MailTemplate_Get(?)');
    $stmt->execute([$templateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    while ($stmt->nextRowset()) {
    }

    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Template not found']);
        exit;
    }

    $out = [
        'template_id' => isset($row['template_id']) ? (int)$row['template_id'] : $templateId,
        'template_name' => (string)($row['template_name'] ?? ''),
        'template_type' => (string)($row['template_type'] ?? ''),
        'subject' => (string)($row['subject'] ?? ''),
        'body' => (string)($row['body'] ?? ''),
        'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : 0,
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $out]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
