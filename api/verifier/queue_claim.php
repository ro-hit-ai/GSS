<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('verifier');

auth_session_start();
$userId = (int)($_SESSION['auth_user_id'] ?? 0);

function verifier_allowed_sections_set(): array {
    $raw = isset($_SESSION['auth_allowed_sections']) ? (string)$_SESSION['auth_allowed_sections'] : '';
    $raw = strtolower(trim($raw));
    if ($raw === '*') return ['*' => true];
    if ($raw === '') return [];
    $parts = preg_split('/[\s,|]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $k = strtolower(trim((string)$p));
        if ($k === '') continue;
        $out[$k] = true;
    }
    return $out;
}

function verifier_can_group(array $set, string $groupKey): bool {
    if (isset($set['*'])) return true;
    $g = strtoupper(trim($groupKey));
    $need = $g === 'BASIC' ? ['basic', 'id', 'contact'] : ($g === 'EDUCATION' ? ['education', 'employment', 'reference'] : []);
    foreach ($need as $k) {
        if (isset($set[$k])) return true;
    }
    return false;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$caseId = isset($input['case_id']) ? (int)$input['case_id'] : 0;
$groupKey = strtoupper(trim((string)($input['group'] ?? '')));

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    if ($caseId <= 0 || !in_array($groupKey, ['BASIC', 'EDUCATION'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'case_id and valid group are required']);
        exit;
    }

    $allowedSet = verifier_allowed_sections_set();
    if (!verifier_can_group($allowedSet, $groupKey)) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Access denied']);
        exit;
    }

    $pdo = getDB();

    // Pool vs Dedicated assignment rule (client_id + group_key)
    try {
        $clientLookup = $pdo->prepare('SELECT client_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $clientLookup->execute([$caseId]);
        $cidRow = $clientLookup->fetch(PDO::FETCH_ASSOC) ?: null;
        $caseClientId = $cidRow && isset($cidRow['client_id']) ? (int)$cidRow['client_id'] : null;

        $ruleStmt = $pdo->prepare(
            "SELECT mode, dedicated_user_id\n" .
            "  FROM Vati_Payfiller_VR_Assignment_Rules\n" .
            " WHERE is_active = 1\n" .
            "   AND (client_id <=> ?)\n" .
            "   AND UPPER(TRIM(group_key)) = ?\n" .
            " LIMIT 1"
        );
        $ruleStmt->execute([$caseClientId, $groupKey]);
        $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $mode = $rule ? strtolower(trim((string)($rule['mode'] ?? ''))) : '';
        $dedicatedUserId = $rule && isset($rule['dedicated_user_id']) ? (int)$rule['dedicated_user_id'] : 0;
        if ($mode === 'dedicated' && $dedicatedUserId > 0 && $dedicatedUserId !== $userId) {
            http_response_code(403);
            echo json_encode(['status' => 0, 'message' => 'Access denied']);
            exit;
        }
    } catch (Throwable $e) {
        // ignore
    }

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ClaimCase(?, ?, ?)');
    $stmt->execute([$userId, $caseId, $groupKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    $affected = isset($row['affected_rows']) ? (int)$row['affected_rows'] : 0;
    if ($affected <= 0) {
        http_response_code(409);
        echo json_encode(['status' => 0, 'message' => 'Already claimed by someone else or already completed']);
        exit;
    }

    // Ensure queue row state is visible to dashboard KPIs immediately.
    try {
        $sync = $pdo->prepare(
            "UPDATE Vati_Payfiller_Verifier_Group_Queue
             SET assigned_user_id = COALESCE(assigned_user_id, ?),
                 claimed_at = COALESCE(claimed_at, NOW()),
                 status = CASE
                     WHEN COALESCE(LOWER(TRIM(status)), '') = 'followup' THEN status
                     WHEN completed_at IS NULL THEN 'in_progress'
                     ELSE status
                 END
             WHERE case_id = ?
               AND UPPER(TRIM(group_key)) = ?
               AND completed_at IS NULL"
        );
        $sync->execute([$userId, $caseId, $groupKey]);
    } catch (Throwable $e) {
        // ignore
    }

    // Best-effort: log to case timeline
    try {
        $log = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) SELECT application_id, ?, ?, ?, ?, ?, NOW() FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $role = !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : 'verifier';
        $log->execute([$userId, $role, 'update', 'verifier', 'Verifier claimed group: ' . $groupKey, $caseId]);
    } catch (Throwable $e) {
        // ignore
    }

    echo json_encode(['status' => 1, 'message' => 'claimed', 'data' => ['affected_rows' => $affected]]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
