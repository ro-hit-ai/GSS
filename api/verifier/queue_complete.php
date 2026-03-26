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
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VR_CompleteCase(?, ?, ?)');
    $stmt->execute([$userId, $caseId, $groupKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    $affected = isset($row['affected_rows']) ? (int)$row['affected_rows'] : 0;
    if ($affected <= 0) {
        http_response_code(409);
        echo json_encode(['status' => 0, 'message' => 'Not claimed by you or already completed']);
        exit;
    }

    // Best-effort: log to case timeline
    try {
        $log = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) SELECT application_id, ?, ?, ?, ?, ?, NOW() FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $role = !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : 'verifier';
        $log->execute([$userId, $role, 'action', 'verifier', 'Verifier completed the group: ' . $groupKey, $caseId]);

        // If all verifier groups are completed for this case, add one case-level completion event.
        $left = $pdo->prepare(
            "SELECT COUNT(*) AS open_count
             FROM Vati_Payfiller_Verifier_Group_Queue
             WHERE case_id = ? AND completed_at IS NULL"
        );
        $left->execute([$caseId]);
        $openCount = (int)($left->fetchColumn() ?: 0);

        if ($openCount === 0) {
            // Move overall case/app to next stage when verifier work is done.
            try {
                $uCase = $pdo->prepare(
                    "UPDATE Vati_Payfiller_Cases
                     SET case_status = 'PENDING_QA'
                     WHERE case_id = ?
                       AND UPPER(TRIM(COALESCE(case_status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED')"
                );
                $uCase->execute([$caseId]);
            } catch (Throwable $e) {
            }
            try {
                $uApp = $pdo->prepare(
                    "UPDATE Vati_Payfiller_Candidate_Applications
                     SET status = 'PENDING_QA'
                     WHERE application_id = (
                        SELECT application_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1
                     )
                       AND UPPER(TRIM(COALESCE(status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED')"
                );
                $uApp->execute([$caseId]);
            } catch (Throwable $e) {
            }

            $logCase = $pdo->prepare(
                "INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at)
                 SELECT c.application_id, ?, ?, 'action', 'verifier', 'Verifier completed the case', NOW()
                 FROM Vati_Payfiller_Cases c
                 WHERE c.case_id = ?
                   AND NOT EXISTS (
                       SELECT 1
                       FROM Vati_Payfiller_Case_Timeline t
                       WHERE t.application_id = c.application_id
                         AND t.section_key = 'verifier'
                         AND t.event_type = 'action'
                         AND t.message = 'Verifier completed the case'
                   )
                 LIMIT 1"
            );
            $logCase->execute([$userId, $role, $caseId]);
        }
    } catch (Throwable $e) {
        // ignore
    }

    echo json_encode(['status' => 1, 'message' => 'completed', 'data' => ['affected_rows' => $affected]]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
