<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/queue_visibility.php';

auth_require_login('verifier');

auth_session_start();
$userId = (int)($_SESSION['auth_user_id'] ?? 0);
$clientId = 0;

try {
    $pdo = getDB();

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListMine(?, ?, ?, ?)');
    $stmt->execute([$userId, $clientId > 0 ? $clientId : null, null, null]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    // Include unassigned actionable cases so verifier sees candidates immediately
    // before explicit Start Verify claim.
    $avail = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListAvailable(?, ?, ?, ?)');
    $avail->execute([$userId, $clientId > 0 ? $clientId : null, null, null]);
    $availRows = $avail->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($avail->nextRowset()) {
    }
    if ($availRows) {
        $seen = [];
        foreach ($rows as $r) {
            $key = (string)($r['id'] ?? '') . '|' . (string)($r['case_id'] ?? '') . '|' . strtoupper(trim((string)($r['group_key'] ?? '')));
            $seen[$key] = true;
        }
        foreach ($availRows as $r) {
            $key = (string)($r['id'] ?? '') . '|' . (string)($r['case_id'] ?? '') . '|' . strtoupper(trim((string)($r['group_key'] ?? '')));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $rows[] = $r;
            }
        }
    }

    $allowedSet = verifier_allowed_sections_set_from_session($pdo);
    $rows = verifier_filter_actionable_queue_rows($pdo, $rows, $allowedSet);

    // Limit to top 10 tasks
    if (count($rows) > 10) {
        $rows = array_slice($rows, 0, 10);
    }

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
