<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../shared/workflow/workflow_semantics.php';
require_once __DIR__ . '/../shared/authorization/workflow_mode.php';
require_once __DIR__ . '/../shared/verifier_case_queue.php';

auth_require_login('team_lead');
auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function arr_str(array $a, string $k, string $d = ''): string {
    return trim((string)($a[$k] ?? $d));
}

function arr_int(array $a, string $k, int $d = 0): int {
    return isset($a[$k]) && $a[$k] !== '' ? (int)$a[$k] : $d;
}

function allowed_sections_set(string $raw): array {
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

function can_work_group(array $set, string $groupKey): bool {
    if (isset($set['*'])) return true;
    $need = wf_verifier_group_components($groupKey);
    foreach ($need as $k) {
        if (isset($set[$k])) return true;
    }
    return false;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $queue = strtolower(arr_str($input, 'queue', ''));
    $caseId = arr_int($input, 'case_id', 0);
    $userId = arr_int($input, 'user_id', 0);
    $groupKey = strtoupper(arr_str($input, 'group_key', ''));

    if (!in_array($queue, ['validator', 'vr', 'dbv'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'queue must be validator|vr|dbv']);
        exit;
    }

    if ($caseId <= 0 || $userId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'case_id and user_id are required']);
        exit;
    }

    $pdo = getDB();
    $mode = wf_mode_get_case_mode($pdo, $caseId, '');
    if ($queue === 'validator' && $mode === 'verifier_first') {
        http_response_code(409);
        echo json_encode(['status' => 0, 'message' => 'Validator queue assignment is disabled for verifier-first cases']);
        exit;
    }

    // Ensure user is active + role compatible
    $uStmt = $pdo->prepare('SELECT user_id, is_active, LOWER(TRIM(role)) AS role, allowed_sections FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1');
    $uStmt->execute([$userId]);
    $u = $uStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$u || (int)($u['is_active'] ?? 0) !== 1) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Target user is not active']);
        exit;
    }

    $targetRole = strtolower(trim((string)($u['role'] ?? '')));
    if ($queue === 'validator' && $targetRole !== 'validator') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Target user must be validator']);
        exit;
    }
    if (($queue === 'vr' || $queue === 'dbv') && !in_array($targetRole, ['verifier', 'db_verifier'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Target user must be verifier or db_verifier']);
        exit;
    }

    // VR: legacy group capability check remains only for non-case-model flow.
    if ($queue === 'vr') {
        if (!verifier_case_queue_is_case_model($pdo, $caseId, '')) {
            if (!wf_is_valid_verifier_group($groupKey)) {
                http_response_code(400);
                echo json_encode(['status' => 0, 'message' => 'group_key is required for legacy vr assignment']);
                exit;
            }
            $set = allowed_sections_set((string)($u['allowed_sections'] ?? ''));
            if (!can_work_group($set, $groupKey)) {
                http_response_code(400);
                echo json_encode(['status' => 0, 'message' => 'Target user is not allowed for this VR group']);
                exit;
            }
        }
    }

    // Assign using the same SPs used by the actual queues.
    if ($queue === 'validator') {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VAL_ClaimCase(?, ?)');
        $stmt->execute([$userId, $caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        while ($stmt->nextRowset()) {
        }
        $affected = isset($row['affected_rows']) ? (int)$row['affected_rows'] : 0;
        if ($affected <= 0) {
            http_response_code(409);
            echo json_encode(['status' => 0, 'message' => 'Already assigned/claimed or completed']);
            exit;
        }
    } elseif ($queue === 'vr') {
        if (verifier_case_queue_is_case_model($pdo, $caseId, '')) {
            $claim = verifier_case_queue_claim($pdo, $caseId, $userId, true);
            if (empty($claim['ok'])) {
                http_response_code(409);
                echo json_encode(['status' => 0, 'message' => (string)($claim['message'] ?? 'Already assigned/claimed or completed')]);
                exit;
            }
        } else {
            $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ClaimCase(?, ?, ?)');
            $stmt->execute([$userId, $caseId, $groupKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            while ($stmt->nextRowset()) {
            }
            $affected = isset($row['affected_rows']) ? (int)$row['affected_rows'] : 0;
            if ($affected <= 0) {
                http_response_code(409);
                echo json_encode(['status' => 0, 'message' => 'Already assigned/claimed or completed']);
                exit;
            }
        }
    } else {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_DBV_ClaimCase(?, ?)');
        $stmt->execute([$caseId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        while ($stmt->nextRowset()) {
        }
        $affected = isset($row['affected'] ) ? (int)$row['affected'] : 0;
        if ($affected <= 0) {
            http_response_code(409);
            echo json_encode(['status' => 0, 'message' => 'Already assigned/claimed or completed']);
            exit;
        }
    }

    // Best-effort: log to case timeline
    try {
        $role = !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : 'team_lead';
        $msg = 'Assigned (' . $queue . ') to user_id=' . $userId;
        if ($queue === 'vr' && $groupKey !== '') {
            $msg .= ' group=' . $groupKey;
        }
        $log = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) SELECT application_id, ?, ?, ?, ?, ?, NOW() FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $log->execute([(int)($_SESSION['auth_user_id'] ?? 0), $role, 'update', 'team_lead', $msg, $caseId]);
    } catch (Throwable $e) {
    }

    echo json_encode(['status' => 1, 'message' => 'assigned', 'data' => ['queue' => $queue, 'case_id' => $caseId, 'user_id' => $userId]]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
