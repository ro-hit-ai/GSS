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
$scope = strtolower(trim((string)($_GET['scope'] ?? 'all'))); // all|mine

try {
    $pdo = getDB();

    // Ensure queue exists (all clients) so verifier dashboard doesn't appear empty
    $ensure = $pdo->prepare('CALL SP_Vati_Payfiller_VR_EnsureGroupQueue(?)');
    $ensure->execute([$clientId > 0 ? $clientId : null]);
    while ($ensure->nextRowset()) {
    }

    $rowStmt = $pdo->prepare(
        'SELECT id, case_id, application_id, client_id, group_key, status, assigned_user_id, completed_at '
        . 'FROM Vati_Payfiller_Verifier_Group_Queue '
        . 'WHERE (? = 0 OR client_id = ?)'
    );
    $rowStmt->execute([$clientId, $clientId]);
    $queueRows = $rowStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $allowedSet = verifier_allowed_sections_set_from_session($pdo);
    $queueRows = verifier_filter_actionable_queue_rows($pdo, $queueRows, $allowedSet);

    $rowsByGroup = [];
    foreach ($queueRows as $r) {
        $g = strtoupper(trim((string)($r['group_key'] ?? '')));
        if ($g === '') continue;
        if (!isset($rowsByGroup[$g])) {
            $rowsByGroup[$g] = [
                'group_key' => $g,
                'pending' => 0,
                'followup' => 0,
                'in_progress' => 0,
                'completed_total' => 0,
                'completed_today' => 0
            ];
        }

        $assigned = isset($r['assigned_user_id']) ? (int)$r['assigned_user_id'] : 0;
        $status = strtolower(trim((string)($r['status'] ?? '')));
        $completedAt = trim((string)($r['completed_at'] ?? ''));

        if ($completedAt !== '') {
            if ($assigned === $userId) {
                $rowsByGroup[$g]['completed_total']++;
                if (substr($completedAt, 0, 10) === date('Y-m-d')) {
                    $rowsByGroup[$g]['completed_today']++;
                }
            }
            continue;
        }

        // "mine" should include:
        // - unassigned pending rows (actionable for this verifier)
        // - rows assigned to current verifier
        // and exclude rows assigned to other verifiers.
        if ($scope === 'mine' && $assigned !== 0 && $assigned !== $userId) {
            continue;
        }

        if ($assigned === 0) {
            $rowsByGroup[$g]['pending']++;
            continue;
        }
        if ($assigned === $userId && $status === 'followup') {
            $rowsByGroup[$g]['followup']++;
            continue;
        }
        if ($assigned === $userId) {
            $rowsByGroup[$g]['in_progress']++;
        }
    }

    $rows = array_values($rowsByGroup);

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
