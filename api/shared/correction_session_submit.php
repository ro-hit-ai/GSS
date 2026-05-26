<?php
header('Content-Type: application/json');

require_once __DIR__ . '/candidate_correction_service.php';
require_once __DIR__ . '/workflow/WorkflowTransitionService.php';
require_once __DIR__ . '/workflow_mode.php';

function ccs_read_json_submit(): array {
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
    $in = ccs_read_json_submit();
    $token = trim((string)($in['token'] ?? ''));
    if ($token === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'token is required']);
        exit;
    }
    $submitted = isset($in['submitted_components']) && is_array($in['submitted_components']) ? $in['submitted_components'] : [];
    $submittedSet = [];
    foreach ($submitted as $s) {
        $k = ccs_component_norm((string)$s);
        if ($k !== '') $submittedSet[$k] = true;
    }

    $pdo = getDB();
    ccs_ensure_table($pdo);
    $st = $pdo->prepare('SELECT * FROM candidate_correction_sessions WHERE token = ? LIMIT 1');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Correction session not found']);
        exit;
    }
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status === 'completed' || $status === 'submitted') {
        echo json_encode(['status' => 1, 'message' => 'Already completed']);
        exit;
    }
    if ($status === 'cancelled' || $status === 'expired') {
        http_response_code(409);
        echo json_encode(['status' => 0, 'message' => 'Correction session is not active']);
        exit;
    }
    if ($status !== 'active') {
        http_response_code(409);
        echo json_encode(['status' => 0, 'message' => 'Correction session state conflict']);
        exit;
    }
    $expiresAt = trim((string)($row['expires_at'] ?? ''));
    if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
        $u = $pdo->prepare("UPDATE candidate_correction_sessions SET status = 'expired', updated_at = NOW() WHERE correction_session_id = ?");
        $u->execute([(int)$row['correction_session_id']]);
        http_response_code(409);
        echo json_encode(['status' => 0, 'message' => 'Correction session expired']);
        exit;
    }

    $allowed = json_decode((string)($row['allowed_components_json'] ?? '[]'), true);
    if (!is_array($allowed)) $allowed = [];
    $final = [];
    foreach ($allowed as $a) {
        $k = ccs_component_norm((string)$a);
        if ($k !== '' && (empty($submittedSet) || isset($submittedSet[$k]))) $final[$k] = true;
    }
    $components = array_values(array_keys($final));
    if (!$components) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'No valid submitted components provided']);
        exit;
    }

    $caseId = (int)$row['case_id'];
    $applicationId = (string)$row['application_id'];
    $pdo->beginTransaction();
    $changed = ccs_resume_components_after_candidate_submit($pdo, $caseId, $applicationId, $components);
    ccs_mark_cycles_candidate_submitted($pdo, (int)$row['correction_session_id'], $components);

    $u = $pdo->prepare("UPDATE candidate_correction_sessions SET status = 'submitted', updated_at = NOW(), completed_by_role = 'candidate' WHERE correction_session_id = ? AND status = 'active'");
    $u->execute([(int)$row['correction_session_id']]);
    if ((int)$u->rowCount() <= 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['status' => 0, 'message' => 'Correction submit collision detected. Please refresh.']);
        exit;
    }
    $u2 = $pdo->prepare("UPDATE candidate_correction_sessions SET status = 'completed', completed_at = NOW(), updated_at = NOW() WHERE correction_session_id = ?");
    $u2->execute([(int)$row['correction_session_id']]);
    $requestedRole = ccs_role_norm((string)($row['requested_role'] ?? wf_mode_default_requested_role($pdo, $caseId, $applicationId)));
    $resumeStage = ccs_component_stage_for_role($requestedRole);
    if ($resumeStage === '') {
        $resumeStage = wf_mode_first_human_stage($pdo, $caseId, $applicationId);
    }
    $svc = new WorkflowTransitionService($pdo);
    $reconcile = $svc->reconcileCorrectionLifecycle(
        $caseId,
        $applicationId,
        $resumeStage,
        $components,
        0,
        'candidate',
        'candidate correction submitted'
    );
    if (empty($reconcile['ok'])) {
        throw new RuntimeException((string)($reconcile['message'] ?? 'Correction lifecycle reconcile failed'));
    }
    $pdo->commit();
    ccs_timeline($pdo, $applicationId, 0, 'candidate', 'candidate_correction', 'Candidate submitted correction components: ' . implode(', ', $components));

    echo json_encode([
        'status' => 1,
        'message' => 'Correction submitted',
        'data' => [
            'correction_session_id' => (int)$row['correction_session_id'],
            'application_id' => $applicationId,
            'components' => $components,
            'workflow_rows_changed' => $changed
        ]
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
