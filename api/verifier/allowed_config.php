<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/queue_visibility.php';

auth_require_login('verifier');
auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function allowed_sections_set(): array {
    $raw = isset($_SESSION['auth_allowed_sections']) ? (string)$_SESSION['auth_allowed_sections'] : '';
    try {
        $pdo = getDB();
        $uid = (int)($_SESSION['auth_user_id'] ?? 0);
        if ($uid > 0) {
            $st = $pdo->prepare('SELECT allowed_sections FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1');
            $st->execute([$uid]);
            $dbRaw = (string)($st->fetchColumn() ?: '');
            $raw = $dbRaw;
            $_SESSION['auth_allowed_sections'] = $dbRaw;
        }
    } catch (Throwable $e) {
    }
    $raw = strtolower(trim($raw));
    if ($raw === '*') return ['*' => true];
    if ($raw === '') return [];
    $parts = preg_split('/[\s,|]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $k = strtolower(trim((string)$p));
        if ($k === 'social_media' || $k === 'social-media') $k = 'socialmedia';
        if ($k === 'identification') $k = 'id';
        if ($k === '') continue;
        $out[$k] = true;
    }
    return $out;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $set = allowed_sections_set();
    $allowedSectionsStr = isset($set['*']) ? '*' : implode(',', array_keys($set));
    $groups = [];
    foreach (['BASIC', 'EDUCATION'] as $g) {
        if (verifier_can_group_by_sections($set, $g)) $groups[] = $g;
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'allowed_sections' => $allowedSectionsStr,
            'allowed_groups' => $groups
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
