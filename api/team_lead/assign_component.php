<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/integration.php';

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

function norm_component_key(string $k): string {
    $k = strtolower(trim($k));
    if ($k === 'identification') return 'id';
    if ($k === 'driving' || $k === 'driving_license') return 'driving_licence';
    return $k;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $caseId = arr_int($input, 'case_id', 0);
    $componentKey = norm_component_key(arr_str($input, 'component_key', ''));
    $assignedRole = strtolower(arr_str($input, 'assigned_role', ''));
    $assignedUserId = arr_int($input, 'assigned_user_id', 0);

    if ($caseId <= 0 || $componentKey === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'case_id and component_key are required']);
        exit;
    }

    if (!in_array($assignedRole, ['verifier', 'validator', 'db_verifier'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'assigned_role must be verifier|validator|db_verifier']);
        exit;
    }

    if ($assignedUserId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'assigned_user_id is required']);
        exit;
    }

    $pdo = getDB();

    $caseStmt = $pdo->prepare('SELECT case_id, application_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
    $caseStmt->execute([$caseId]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$case) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found']);
        exit;
    }

    $applicationId = trim((string)($case['application_id'] ?? ''));
    if ($applicationId === '') {
        http_response_code(500);
        echo json_encode(['status' => 0, 'message' => 'application_id missing for case']);
        exit;
    }

    // Ensure row exists (table may not be installed yet)
    try {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO Vati_Payfiller_Case_Components (case_id, application_id, component_key, is_required, status) '
            . 'VALUES (?, ?, ?, 1, \'pending\')'
        );
        $ins->execute([$caseId, $applicationId, $componentKey]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 0, 'message' => 'Component table not installed']);
        exit;
    }

    // Ensure user is active and role compatible
    $uStmt = $pdo->prepare('SELECT user_id, is_active, LOWER(TRIM(role)) AS role FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1');
    $uStmt->execute([$assignedUserId]);
    $u = $uStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$u || (int)($u['is_active'] ?? 0) !== 1) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Target user is not active']);
        exit;
    }

    $userRole = strtolower(trim((string)($u['role'] ?? '')));
    if ($assignedRole === 'db_verifier') {
        if ($userRole !== 'db_verifier') {
            http_response_code(400);
            echo json_encode(['status' => 0, 'message' => 'Target user must be db_verifier']);
            exit;
        }
    } elseif ($assignedRole === 'validator') {
        if ($userRole !== 'validator') {
            http_response_code(400);
            echo json_encode(['status' => 0, 'message' => 'Target user must be validator']);
            exit;
        }
    } else {
        if ($userRole !== 'verifier') {
            http_response_code(400);
            echo json_encode(['status' => 0, 'message' => 'Target user must be verifier']);
            exit;
        }
    }

    $upd = $pdo->prepare(
        'UPDATE Vati_Payfiller_Case_Components '
        . 'SET assigned_role = ?, assigned_user_id = ?, updated_at = NOW() '
        . 'WHERE case_id = ? AND application_id = ? AND LOWER(TRIM(component_key)) = ?'
    );
    $upd->execute([$assignedRole, $assignedUserId, $caseId, $applicationId, $componentKey]);

    // Best-effort: log to case timeline
    try {
        $actorRole = !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : 'team_lead';
        $msg = 'Assigned component ' . $componentKey . ' (' . $assignedRole . ') to user_id=' . $assignedUserId;
        $log = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $log->execute([
            $applicationId,
            (int)($_SESSION['auth_user_id'] ?? 0),
            $actorRole,
            'update',
            'assignment',
            $msg
        ]);
    } catch (Throwable $e) {
    }

    $links = integration_deep_links($applicationId, $caseId);
    $webhook = integration_send_webhook('application.assigned', [
        'applicationId' => $applicationId,
        'caseId' => $caseId,
        'currentStage' => $assignedRole,
        'status' => 'ASSIGNED',
        'triggeredBy' => [
            'userId' => (int)($_SESSION['auth_user_id'] ?? 0),
            'role' => integration_role_normalized((string)($_SESSION['auth_moduleAccess'] ?? 'team_lead')),
        ],
        'triggeredAt' => gmdate('c'),
        'metadata' => array_merge([
            'componentKey' => $componentKey,
            'assignedRole' => $assignedRole,
            'assignedUserId' => $assignedUserId,
        ], $links),
    ]);

    echo json_encode([
        'status' => 1,
        'message' => 'assigned',
        'data' => [
            'case_id' => $caseId,
            'application_id' => $applicationId,
            'applicationId' => $applicationId,
            'component_key' => $componentKey,
            'assigned_role' => $assignedRole,
            'assigned_user_id' => $assignedUserId,
            'applicationUrl' => $links['applicationUrl'],
            'candidateUrl' => $links['candidateUrl'],
            'timelineUrl' => $links['timelineUrl'],
            'webhook_event_id' => $webhook['eventId'] ?? null
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
