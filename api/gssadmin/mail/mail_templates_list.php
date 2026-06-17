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

    $type = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : '';
    $isActiveRaw = isset($_GET['is_active']) ? trim((string)$_GET['is_active']) : '';
    $isActive = null;
    if ($isActiveRaw !== '') {
        $isActive = ((int)$isActiveRaw) === 1 ? 1 : 0;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_MailTemplates_List(?, ?)');
    $stmt->execute([$type !== '' ? $type : null, $isActive]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($stmt->nextRowset()) {
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'template_id' => isset($r['template_id']) ? (int)$r['template_id'] : 0,
            'template_name' => (string)($r['template_name'] ?? ''),
            'template_type' => (string)($r['template_type'] ?? ''),
            'subject' => (string)($r['subject'] ?? ''),
            'body' => (string)($r['body'] ?? ''),
            'is_active' => isset($r['is_active']) ? (int)$r['is_active'] : 0,
            'created_at' => (string)($r['created_at'] ?? ''),
            'updated_at' => (string)($r['updated_at'] ?? ''),
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
