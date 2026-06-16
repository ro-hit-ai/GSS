<?php
header('Content-Type: application/json');

require_once __DIR__ . '/candidate_correction_service.php';
require_once __DIR__ . '/../workflow/WorkflowTransitionService.php';

auth_require_login();
auth_session_start();

function ccs_read_json(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }
    $in = ccs_read_json();
    $pdo = getDB();
    ccs_ensure_table($pdo);

    $sessionRole = ccs_role_norm((string)($_SESSION['auth_moduleAccess'] ?? $_SESSION['auth_role'] ?? ''));
    $userId = (int)($_SESSION['auth_user_id'] ?? 0);
    $userName = trim((string)($_SESSION['auth_user_name'] ?? ''));
    $clientId = (int)($_SESSION['auth_client_id'] ?? 0);
    $role = ccs_role_norm((string)($in['role'] ?? $sessionRole));
    if ($role === '') $role = $sessionRole;
    if (!ccs_is_role_allowed($role)) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Forbidden']);
        exit;
    }

    $caseId = (int)($in['case_id'] ?? 0);
    $applicationId = trim((string)($in['application_id'] ?? ''));
    $reason = trim((string)($in['reason'] ?? ''));
    $requestId = trim((string)($in['request_id'] ?? ''));
    $componentsIn = isset($in['components']) && is_array($in['components']) ? $in['components'] : [];
    $components = [];
    foreach ($componentsIn as $c) {
        $n = ccs_component_norm((string)$c);
        if ($n !== '') $components[$n] = true;
    }
    $components = array_values(array_keys($components));
    if (!$components) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Please select at least one component']);
        exit;
    }
    if ($requestId === '') {
        $requestId = 'ccs-' . ($caseId > 0 ? $caseId : $applicationId) . '-' . $userId . '-' . time();
    }

    $dup = $pdo->prepare('SELECT correction_session_id, token, status FROM Vati_Payfiller_Candidate_Correction_Sessions WHERE request_id = ? LIMIT 1');
    $dup->execute([$requestId]);
    $old = $dup->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($old) {
        $oldFull = null;
        try {
            $oldQ = $pdo->prepare('SELECT * FROM Vati_Payfiller_Candidate_Correction_Sessions WHERE correction_session_id = ? LIMIT 1');
            $oldQ->execute([(int)$old['correction_session_id']]);
            $oldFull = $oldQ->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
        }
        $resend = ['attempted' => false, 'sent' => false, 'reason' => 'not_checked'];
        if ($oldFull) {
            $oldCase = ccs_get_case($pdo, (int)($oldFull['case_id'] ?? 0), (string)($oldFull['application_id'] ?? ''));
            if ($oldCase) {
                $resend = ccs_resend_existing_session_if_mail_missing($pdo, $oldFull, $oldCase);
            }
        }
        echo json_encode(['status' => 1, 'message' => 'Already processed', 'data' => [
            'correction_session_id' => (int)$old['correction_session_id'],
            'token' => (string)$old['token'],
            'status' => (string)$old['status'],
            'invite_url' => ccs_candidate_correction_url((string)$old['token']),
            'mail_sent' => !empty($resend['sent']) ? 1 : 0,
            'mail_resend_attempted' => !empty($resend['attempted']) ? 1 : 0,
            'mail_resend_reason' => (string)($resend['reason'] ?? '')
        ]]);
        exit;
    }

    $case = ccs_get_case($pdo, $caseId, $applicationId);
    if (!$case) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found']);
        exit;
    }
    $caseId = (int)$case['case_id'];
    $applicationId = (string)$case['application_id'];
    $caseClientId = (int)($case['client_id'] ?? 0);

    if ($role === 'client_admin' && ($clientId <= 0 || $clientId !== $caseClientId)) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Forbidden']);
        exit;
    }
    if ($role === 'validator' && !ccs_user_has_validator_visibility($pdo, $caseId, $userId)) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Validator not assigned']);
        exit;
    }
    if ($role === 'verifier' && !ccs_user_has_verifier_visibility($pdo, $caseId, $userId)) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Verifier visibility missing']);
        exit;
    }

    $eligible = ccs_get_eligible_components($pdo, $caseId, $role, $userId, $clientId, $caseClientId);
    $eligibleSet = [];
    foreach ($eligible as $ec) $eligibleSet[$ec] = true;
    $owned = [];
    foreach ($components as $c) {
        $k = ccs_component_norm((string)$c);
        if (isset($eligibleSet[$k])) $owned[$k] = true;
    }
    $components = array_values(array_keys($owned));
    if (!$components) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'No selected components are eligible for this role']);
        exit;
    }

    $conflictSessions = ccs_active_conflict_sessions($pdo, $caseId, $components);
    if ($conflictSessions) {
        $conflicts = [];
        $resendResults = [];
        foreach ($conflictSessions as $conflictSession) {
            foreach (($conflictSession['components'] ?? []) as $conflictComponent) {
                $conflicts[$conflictComponent] = true;
            }
            $resendResults[] = ccs_resend_existing_session_if_mail_missing($pdo, $conflictSession, $case);
        }
        $mailResent = false;
        $mailKnownSent = false;
        foreach ($resendResults as $resendResult) {
            if (!empty($resendResult['sent'])) $mailKnownSent = true;
            if (!empty($resendResult['attempted']) && !empty($resendResult['sent'])) $mailResent = true;
        }
        http_response_code(409);
        $conflictList = array_values(array_keys($conflicts));
        $message = 'Active correction already exists for: ' . implode(', ', $conflictList);
        if ($mailResent) {
            $message .= '. Correction mail resent.';
        } elseif (!$mailKnownSent) {
            $message .= '. Mail could not be confirmed as sent.';
        }
        echo json_encode(['status' => 0, 'message' => $message, 'data' => [
            'conflicts' => $conflictList,
            'mail_sent' => $mailKnownSent ? 1 : 0,
            'mail_resent' => $mailResent ? 1 : 0,
            'resend_results' => $resendResults
        ]]);
        exit;
    }

    if (function_exists('verifier_case_queue_ensure_table')) {
        verifier_case_queue_ensure_table($pdo);
    }
    $pdo->beginTransaction();
    $token = ccs_new_token();
    $componentsJson = json_encode($components, JSON_UNESCAPED_UNICODE);
    $expiresAt = date('Y-m-d H:i:s', time() + (72 * 3600));
    $ins = $pdo->prepare(
        'INSERT INTO Vati_Payfiller_Candidate_Correction_Sessions
        (request_id, case_id, application_id, requested_by_user_id, requested_by_name, requested_role, correction_reason, allowed_components_json, token, status, expires_at, workflow_snapshot_version, thread_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, ?, NOW(), NOW())'
    );
    $ins->execute([
        $requestId,
        $caseId,
        $applicationId,
        $userId > 0 ? $userId : null,
        $userName !== '' ? $userName : null,
        $role,
        $reason !== '' ? $reason : null,
        $componentsJson,
        $token,
        $expiresAt,
        isset($case['workflow_version']) ? (int)$case['workflow_version'] : null,
        'app:' . strtolower($applicationId)
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    ccs_insert_correction_cycles($pdo, $sessionId, $caseId, $applicationId, $components, $userId, $role, $reason);
    ccs_snapshot_document_versions($pdo, $caseId, $applicationId, $components, $sessionId);
    $changedRows = ccs_update_components_waiting_candidate($pdo, $caseId, $applicationId, $components, $userId, $role);
    $svc = new WorkflowTransitionService($pdo);
    $reconcile = $svc->reconcileCorrectionLifecycle(
        $caseId,
        $applicationId,
        ccs_component_stage_for_role($role),
        $components,
        $userId,
        $role,
        $reason
    );
    if (empty($reconcile['ok'])) {
        throw new RuntimeException((string)($reconcile['message'] ?? 'Correction lifecycle reconcile failed'));
    }
    $pdo->commit();

    $primaryComponentKey = '';
    foreach ($components as $component) {
        $normalizedComponent = ccs_component_norm((string)$component);
        if ($normalizedComponent !== '') {
            $primaryComponentKey = $normalizedComponent;
            break;
        }
    }
    $ownerRole = function_exists('wc_norm_thread_owner_role')
        ? wc_norm_thread_owner_role($role)
        : strtolower(trim($role));
    $phpThreadId = ($primaryComponentKey !== '' && function_exists('wc_build_thread_id'))
        ? wc_build_thread_id($applicationId, $primaryComponentKey, $ownerRole)
        : 'app:' . strtolower($applicationId);
    $messageId = 'wc.' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $applicationId)) . '.' . bin2hex(random_bytes(8)) . '@payfiller.com';
    $workflowCommunication = ['primary' => null, 'by_component' => []];
    try {
        $workflowCommunication = ccs_log_workflow_communication($pdo, $caseId, $applicationId, $components, $reason, $userId, $userName, $role, $phpThreadId, $messageId, $ownerRole, $primaryComponentKey);
    } catch (Throwable $e) {
    }

    $primaryCommunication = is_array($workflowCommunication['primary'] ?? null) ? $workflowCommunication['primary'] : [];
    $mailSent = ccs_send_mail($pdo, $case, $components, $reason, $token, $role, $sessionId, [
        'message_id' => $messageId,
        'thread_id' => (string)($primaryCommunication['thread_id'] ?? $phpThreadId),
        'thread_owner_role' => $ownerRole,
        'communication_id' => (int)($primaryCommunication['communication_id'] ?? 0),
        'source_message_key' => (string)($primaryCommunication['source_message_key'] ?? ''),
    ]);
    try {
        $timelineComponents = [];
        foreach ($components as $component) {
            $componentKey = ccs_component_norm((string)$component);
            if ($componentKey !== '') {
                $timelineComponents[$componentKey] = true;
            }
        }
        foreach (array_keys($timelineComponents) as $componentKey) {
            ccs_timeline($pdo, $applicationId, $userId, $role, $componentKey, 'Correction session requested | component: ' . $componentKey . ($reason !== '' ? (' | reason: ' . $reason) : ''));
        }
    } catch (Throwable $e) {
    }

    echo json_encode([
        'status' => 1,
        'message' => $mailSent ? 'Correction request sent.' : 'Correction session created. Mail not sent.',
        'data' => [
            'correction_session_id' => $sessionId,
            'case_id' => $caseId,
            'application_id' => $applicationId,
            'components' => $components,
            'invite_url' => ccs_candidate_correction_url($token),
            'mail_sent' => $mailSent ? 1 : 0,
            'communication_id' => (int)($primaryCommunication['communication_id'] ?? 0),
            'message_id' => $messageId,
            'thread_id' => (string)($primaryCommunication['thread_id'] ?? $phpThreadId),
            'thread_owner_role' => $ownerRole,
            'workflow_rows_changed' => $changedRows
        ]
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
