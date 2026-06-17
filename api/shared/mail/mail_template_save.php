<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/template_governance.php';

auth_require_login('gss_admin');

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function arr_str($v, string $default = ''): string {
    if ($v === null) return $default;
    return trim((string)$v);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $data = read_json_body();
    if (!$data) {
        $data = $_POST;
    }

    $templateId = isset($data['template_id']) ? (int)$data['template_id'] : 0;
    $name = tmpl_normalize_key(arr_str($data['template_name'] ?? ''));
    $type = strtolower(arr_str($data['template_type'] ?? ''));
    $subject = arr_str($data['subject'] ?? '');
    $body = (string)($data['body'] ?? '');
    $isActive = isset($data['is_active']) ? (((int)$data['is_active']) === 1 ? 1 : 0) : 1;

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'template_name is required']);
        exit;
    }
    if (!tmpl_is_allowed_key($name)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'template_name is not in canonical template registry']);
        exit;
    }

    if ($type !== 'email' && $type !== 'physical' && $type !== 'digital') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'template_type must be email, physical, or digital']);
        exit;
    }

    if (trim($body) === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'body is required']);
        exit;
    }

    $pdo = getDB();
    $existingStmt = $pdo->prepare('CALL SP_Vati_Payfiller_MailTemplates_List(?, ?)');
    $existingStmt->execute([$type !== '' ? $type : null, 1]);
    $existingActiveRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($existingStmt->nextRowset()) {}

    foreach ($existingActiveRows as $er) {
        $existingName = tmpl_normalize_key((string)($er['template_name'] ?? ''));
        $existingId = (int)($er['template_id'] ?? 0);
        if ($existingName === $name && $isActive === 1 && $existingId !== $templateId) {
            http_response_code(400);
            echo json_encode(['status' => 0, 'message' => 'Duplicate active template key is not allowed']);
            exit;
        }
    }

    $ph = tmpl_validate_placeholders_for_key($name, $subject, $body);
    if (!empty($ph['invalid_placeholders'])) {
        tmpl_log_warning('admin_template_invalid_placeholders', [
            'template_key' => $name,
            'invalid_placeholders' => $ph['invalid_placeholders'],
            'allowed_placeholders' => $ph['allowed_placeholders'],
            'template_id' => $templateId,
        ]);
    }

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_MailTemplate_Upsert(?,?,?,?,?,?)');
    $stmt->execute([$templateId > 0 ? $templateId : null, $name, $type, $subject, $body, $isActive]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    $newId = isset($row['template_id']) ? (int)$row['template_id'] : $templateId;
    if ($newId <= 0) {
        throw new RuntimeException('Failed to save template');
    }

    $resp = ['status' => 1, 'message' => 'Saved', 'data' => ['template_id' => $newId]];
    if (!empty($ph['invalid_placeholders'])) {
        $resp['warnings'] = [
            'invalid_placeholders' => $ph['invalid_placeholders'],
            'allowed_placeholders' => $ph['allowed_placeholders'],
        ];
    }
    echo json_encode($resp);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
