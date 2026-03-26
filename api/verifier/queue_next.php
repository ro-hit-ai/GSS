<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/queue_visibility.php';

auth_require_login('verifier');

auth_session_start();
$userId = (int)($_SESSION['auth_user_id'] ?? 0);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$clientId = isset($input['client_id']) ? (int)$input['client_id'] : 0;
$groupKey = strtoupper(trim((string)($input['group'] ?? '')));
$debug = !empty($input['debug']);

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

    if (!in_array($groupKey, ['BASIC', 'EDUCATION'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Valid group is required']);
        exit;
    }

    $pdo = getDB();
    $allowedSet = verifier_allowed_sections_set_from_session($pdo);
    if (!verifier_can_group_by_sections($allowedSet, $groupKey)) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Access denied']);
        exit;
    }

    // Pool vs Dedicated assignment rule (client_id + group_key)
    try {
        $ruleStmt = $pdo->prepare(
            "SELECT mode, dedicated_user_id\n" .
            "  FROM Vati_Payfiller_VR_Assignment_Rules\n" .
            " WHERE is_active = 1\n" .
            "   AND (client_id <=> ?)\n" .
            "   AND UPPER(TRIM(group_key)) = ?\n" .
            " LIMIT 1"
        );
        $ruleStmt->execute([$clientId > 0 ? $clientId : null, $groupKey]);
        $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $mode = $rule ? strtolower(trim((string)($rule['mode'] ?? ''))) : '';
        $dedicatedUserId = $rule && isset($rule['dedicated_user_id']) ? (int)$rule['dedicated_user_id'] : 0;
        if ($mode === 'dedicated' && $dedicatedUserId > 0 && $dedicatedUserId !== $userId) {
            echo json_encode(['status' => 1, 'message' => 'No pending cases for this group', 'data' => ['url' => null]]);
            exit;
        }
    } catch (Throwable $e) {
        // ignore
    }

    // For verifier queue we allow pulling from all clients unless an explicit client_id is provided.
    // Some sessions may carry auth_client_id which would incorrectly restrict availability.

    // Prefer continuing an in-progress case already assigned to this user
    $mine = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListMine(?, ?, ?, ?)');
    $mine->execute([$userId, $clientId > 0 ? $clientId : null, $groupKey, null]);
    $mineRows = $mine->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($mine->nextRowset()) {
    }
    $mineRows = verifier_filter_actionable_queue_rows($pdo, $mineRows, $allowedSet);

    if (!empty($mineRows)) {
        $row = $mineRows[0];
        $appId = trim((string)($row['application_id'] ?? ''));
        $cid = (string)($row['client_id'] ?? '');
        $caseIdInt = (int)($row['case_id'] ?? 0);
        $view = app_url('/modules/verifier/candidate_view.php');
        if ($appId !== '') {
            $view .= '?application_id=' . rawurlencode($appId);
        } else if ($caseIdInt > 0) {
            $view .= '?case_id=' . rawurlencode((string)$caseIdInt);
        } else {
            http_response_code(500);
            $resp = ['status' => 0, 'message' => 'Invalid case identifier returned from queue.'];
            if ($debug) {
                $resp['debug'] = [
                    'path' => 'mine_invalid_identifier',
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'group' => $groupKey,
                    'row' => $row
                ];
            }
            echo json_encode($resp);
            exit;
        }
        if ($cid !== '') {
            $view .= (strpos($view, '?') !== false ? '&' : '?') . 'client_id=' . rawurlencode($cid);
        }
        $view .= (strpos($view, '?') !== false ? '&' : '?') . 'group=' . rawurlencode($groupKey);
        $resp = ['status' => 1, 'message' => 'ok', 'data' => ['case' => $row, 'url' => $view]];
        if ($debug) {
            $resp['debug'] = [
                'path' => 'mine',
                'user_id' => $userId,
                'client_id' => $clientId,
                'group' => $groupKey,
                'mine_count' => count($mineRows)
            ];
        }
        echo json_encode($resp);
        exit;
    }

    // Otherwise claim the oldest available
    $avail = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ListAvailable(?, ?, ?, ?)');
    $avail->execute([$userId, $clientId > 0 ? $clientId : null, $groupKey, null]);
    $availRows = $avail->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($avail->nextRowset()) {
    }
    $availRows = verifier_filter_actionable_queue_rows($pdo, $availRows, $allowedSet);

    if (empty($availRows)) {
        $resp = ['status' => 1, 'message' => 'No pending cases for this group', 'data' => ['url' => null]];
        if ($debug) {
            $resp['debug'] = [
                'path' => 'available_empty',
                'user_id' => $userId,
                'client_id' => $clientId,
                'group' => $groupKey,
                'mine_count' => count($mineRows),
                'available_count' => 0
            ];
        }
        echo json_encode($resp);
        exit;
    }

    $row = $availRows[0];
    $caseIdInt = (int)($row['case_id'] ?? 0);
    if ($caseIdInt <= 0) {
        http_response_code(500);
        $resp = ['status' => 0, 'message' => 'Invalid case identifier returned from available queue.'];
        if ($debug) {
            $resp['debug'] = [
                'path' => 'available_invalid_identifier',
                'user_id' => $userId,
                'client_id' => $clientId,
                'group' => $groupKey,
                'row' => $row
            ];
        }
        echo json_encode($resp);
        exit;
    }

    $claim = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ClaimCase(?, ?, ?)');
    $claim->execute([$userId, $caseIdInt, $groupKey]);
    $claimRow = $claim->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($claim->nextRowset()) {
    }

    $affected = isset($claimRow['affected_rows']) ? (int)$claimRow['affected_rows'] : 0;
    if ($affected <= 0) {
        http_response_code(409);
        $resp = ['status' => 0, 'message' => 'Unable to claim case. Try again.'];
        if ($debug) {
            $resp['debug'] = [
                'path' => 'claim_failed',
                'user_id' => $userId,
                'client_id' => $clientId,
                'group' => $groupKey,
                'mine_count' => count($mineRows),
                'available_count' => count($availRows),
                'case_id' => $caseIdInt,
                'claim_row' => $claimRow
            ];
        }
        echo json_encode($resp);
        exit;
    }

    // Ensure dashboard counters reflect claim immediately even if SP doesn't set status fields.
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
        $sync->execute([$userId, $caseIdInt, $groupKey]);
    } catch (Throwable $e) {
        // ignore
    }

    $appId = trim((string)($row['application_id'] ?? ''));
    $cid = (string)($row['client_id'] ?? '');
    // Use the validated integer id in the URL (avoid case_id=0 redirect loop)
    $caseIdStr = (string)$caseIdInt;

    // Best-effort: log to case timeline
    try {
        if ($appId !== '') {
            $log = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
            $role = !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : 'verifier';
            $log->execute([
                $appId,
                $userId,
                $role,
                'update',
                'verifier',
                'Verifier claimed group: ' . $groupKey
            ]);
        }
    } catch (Throwable $e) {
        // ignore
    }

    $view = app_url('/modules/verifier/candidate_view.php');
    if ($appId !== '') {
        $view .= '?application_id=' . rawurlencode($appId);
    } else if ($caseIdStr !== '') {
        $view .= '?case_id=' . rawurlencode($caseIdStr);
    }
    if ($cid !== '') {
        $view .= (strpos($view, '?') !== false ? '&' : '?') . 'client_id=' . rawurlencode($cid);
    }
    $view .= (strpos($view, '?') !== false ? '&' : '?') . 'group=' . rawurlencode($groupKey);

    $resp = ['status' => 1, 'message' => 'ok', 'data' => ['case' => $row, 'url' => $view]];
    if ($debug) {
        $resp['debug'] = [
            'path' => 'available_claimed',
            'user_id' => $userId,
            'client_id' => $clientId,
            'group' => $groupKey,
            'mine_count' => count($mineRows),
            'available_count' => count($availRows),
            'case_id' => (int)($row['case_id'] ?? 0),
            'affected_rows' => $affected
        ];
    }
    echo json_encode($resp);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
