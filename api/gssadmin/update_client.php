<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/upload_helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function item_bool(array $item, string $key): int {
    return !empty($item[$key]) ? 1 : 0;
}

function item_str(array $item, string $key, string $default = ''): string {
    return trim((string)($item[$key] ?? $default));
}

function item_int(array $item, string $key, ?int $default = null): ?int {
    if (!array_key_exists($key, $item) || $item[$key] === '' || $item[$key] === null) return $default;
    return (int)$item[$key];
}

function validate_tat_pair(?int $internalTat, ?int $externalTat): void {
    if ($internalTat === null || $externalTat === null) return;
    if ($internalTat > $externalTat) {
        throw new InvalidArgumentException('GSS TAT cannot be greater than Client TAT.');
    }
}

function friendlyDbError(PDOException $e): string {
    $sqlState = (string)$e->getCode();
    $info = $e->errorInfo ?? null;
    $driverCode = is_array($info) ? (int)($info[1] ?? 0) : 0;
    $driverMsg = is_array($info) ? (string)($info[2] ?? '') : '';

    if ($driverCode === 1062 || stripos($driverMsg, 'Duplicate entry') !== false) {
        return 'Customer name already exists. Please use a different Customer Name.';
    }

    if ($sqlState === '23000') {
        return 'Database constraint error. Please check your inputs.';
    }

    return 'Database error. Please try again.';
}

function parse_locations(string $raw): array {
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $raw = str_replace([',', ';'], "\n", $raw);
    $parts = array_map('trim', explode("\n", $raw));
    $out = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        $out[$p] = true;
    }
    return array_keys($out);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $payload = file_get_contents('php://input');
    $decoded = null;
    if (is_string($payload) && trim($payload) !== '') {
        $decoded = json_decode($payload, true);
    }

    $item = !empty($_FILES) ? $_POST : (is_array($decoded) ? $decoded : $_POST);
    if (!is_array($item)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Invalid payload']);
        exit;
    }

    $clientId = isset($item['client_id']) ? (int)$item['client_id'] : 0;
    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id is required']);
        exit;
    }

    $customer_name = item_str($item, 'customer_name');
    $poc_name = '';
    $poc_email = '';
    $poc_phone = '';

    if ($customer_name === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Customer name is required.']);
        exit;
    }

    $logoPath = item_str($item, 'customer_logo_path', '');
    if (!empty($_FILES)) {
        $upload = upload_customer_logo($customer_name);
        if (!$upload['ok']) {
            http_response_code(400);
            echo json_encode(['status' => 0, 'message' => $upload['message'] ?? 'Logo upload failed.']);
            exit;
        }
        if (!empty($upload['path'])) {
            $logoPath = (string)$upload['path'];
        }
    }

    $sowPath = item_str($item, 'sow_pdf_path', '');
    if (!empty($_FILES)) {
        $sowUpload = upload_sow_pdf($customer_name);
        if (!$sowUpload['ok']) {
            http_response_code(400);
            echo json_encode(['status' => 0, 'message' => $sowUpload['message'] ?? 'SOW upload failed.']);
            exit;
        }
        if (!empty($sowUpload['path'])) {
            $sowPath = (string)$sowUpload['path'];
        }
    }

    $pdo = getDB();

    validate_tat_pair(item_int($item, 'internal_tat'), item_int($item, 'external_tat'));

    $params = [
        $clientId,
        $customer_name,
        item_str($item, 'location', ''),
        item_str($item, 'short_description', ''),
        item_str($item, 'detailed_description', ''),
        item_bool($item, 'show_customer_logo'),
        $logoPath,

        item_bool($item, 'authorization_form_required'),
        item_str($item, 'authorization_type', 'none'),
        item_bool($item, 'submit_auth_before_dataentry'),
        item_bool($item, 'enable_delegation_after_auth'),
        item_bool($item, 'unique_reference_required'),
        item_bool($item, 'extra_fields_required'),
        0,

        item_bool($item, 'candidate_mail_required'),
        item_bool($item, 'reminder_mail_required'),
        item_bool($item, 'insuff_reminders_required'),
        item_bool($item, 'show_candidate_verification_status'),
        item_bool($item, 'contact_support_required'),
        item_bool($item, 'additional_fields_required'),
        item_bool($item, 'basic_details_masked_required'),

        item_str($item, 'delegation_mechanism', ''),
        item_str($item, 'save_button_instruction', ''),
        item_str($item, 'authorization_submit_instruction', ''),

        $poc_name,
        $poc_email,
        $poc_phone,
        '',
        '',

        item_int($item, 'internal_tat'),
        item_int($item, 'external_tat'),
        item_int($item, 'escalation_days'),
        item_str($item, 'weekend_rules', ''),

        item_str($item, 'auto_allocation', ''),
        item_str($item, 'candidate_notification', ''),
        '',
        $sowPath,
    ];

    $placeholders = rtrim(str_repeat('?,', count($params)), ',');
    $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_UpdateClient($placeholders)");
    $stmt->execute($params);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    $rawLocations = item_str($item, 'location', '');
    $locations = parse_locations($rawLocations);

    $delStmt = $pdo->prepare('CALL SP_Vati_Payfiller_DeleteClientLocations(?)');
    $delStmt->execute([$clientId]);
    while ($delStmt->nextRowset()) {
    }

    if (!empty($locations)) {
        $addStmt = $pdo->prepare('CALL SP_Vati_Payfiller_AddClientLocation(?, ?)');
        foreach ($locations as $loc) {
            $addStmt->execute([$clientId, $loc]);
            while ($addStmt->nextRowset()) {
            }
        }
    }

    $rowsAffected = isset($row['rows_affected']) ? (int)$row['rows_affected'] : 0;

    echo json_encode([
        'status' => 1,
        'message' => 'Client updated successfully.',
        'data' => ['rows_affected' => $rowsAffected]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => friendlyDbError($e)]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
