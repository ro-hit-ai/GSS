<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('gss_admin');

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

function post_int(string $key, int $default = 0): int {
    return isset($_POST[$key]) && $_POST[$key] !== '' ? (int)$_POST[$key] : $default;
}

function post_locations(): array {
    $raw = $_POST['locations'] ?? [];
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $s = trim((string)$v);
        if ($s === '') continue;
        $out[$s] = true;
    }
    return array_keys($out);
}

function post_allowed_sections(): string {
    $raw = $_POST['allowed_sections'] ?? [];
    if (is_string($raw)) {
        $raw = preg_split('/[\s,|]+/', trim($raw));
    }
    if (!is_array($raw)) return '';
    $out = [];
    foreach ($raw as $v) {
        $s = strtolower(trim((string)$v));
        if ($s === 'social_media' || $s === 'social-media' || $s === 'social media') $s = 'socialmedia';
        if ($s === 'e_court' || $s === 'e-court' || $s === 'e court') $s = 'ecourt';
        if ($s === '') continue;
        $out[$s] = true;
    }
    return implode(',', array_keys($out));
}

function post_tinyint_nullable(string $key): ?int {
    if (!isset($_POST[$key])) return null;
    $v = trim((string)$_POST[$key]);
    if ($v === '') return null;
    return (int)$v;
}

function friendlyDbError(PDOException $e): string {
    $info = $e->errorInfo ?? null;
    $driverCode = is_array($info) ? (int)($info[1] ?? 0) : 0;
    $driverMsg = is_array($info) ? (string)($info[2] ?? '') : '';

    if ($driverCode === 1062 || stripos($driverMsg, 'Duplicate entry') !== false) {
        return 'Username already exists. Please choose a different username.';
    }

    return 'Database error. Please try again.';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $userId = post_int('user_id');
    $clientId = post_int('client_id');
    $username = post_str('username');
    $firstName = post_str('first_name');
    $middleName = post_str('middle_name');
    $lastName = post_str('last_name');
    $phone = post_str('phone');
    $email = post_str('email');
    $role = post_str('role');
    $locations = post_locations();
    $location = !empty($locations) ? (string)$locations[0] : post_str('location');
    $isActive = post_tinyint_nullable('is_active');
    $allowedSections = post_allowed_sections();

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'user_id is required']);
        exit;
    }

    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id is required']);
        exit;
    }

    if ($username === '' || $firstName === '' || $lastName === '' || $phone === '' || $email === '' || $role === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id, username, first_name, last_name, phone, email and role are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Invalid email.']);
        exit;
    }

    $pdo = getDB();

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_UpdateUser(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $userId,
        $clientId,
        $username,
        $firstName,
        $middleName,
        $lastName,
        $phone,
        $email,
        $role,
        $location,
        $allowedSections,
        $isActive
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    $affected = isset($row['affected_rows']) ? (int)$row['affected_rows'] : 0;

    // Force exact allowed_sections from UI (some SP builds may apply role defaults).
    try {
        $fix = $pdo->prepare('UPDATE Vati_Payfiller_Users SET allowed_sections = ? WHERE user_id = ?');
        $fix->execute([$allowedSections, $userId]);
    } catch (Throwable $e) {
        // ignore; SP update result still used for API response
    }

    $del = $pdo->prepare('CALL SP_Vati_Payfiller_DeleteUserLocations(?)');
    $del->execute([$userId]);
    while ($del->nextRowset()) {
    }

    if (!empty($locations)) {
        $add = $pdo->prepare('CALL SP_Vati_Payfiller_AddUserLocationByName(?, ?, ?)');
        foreach ($locations as $locName) {
            $add->execute([$userId, $clientId, $locName]);
            while ($add->nextRowset()) {
            }
        }
    }

    if ($affected <= 0) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'User not found or no changes were made.']);
        exit;
    }

    echo json_encode([
        'status' => 1,
        'message' => 'User updated successfully.',
        'data' => ['affected_rows' => $affected]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => friendlyDbError($e)]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
