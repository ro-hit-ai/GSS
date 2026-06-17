<?php
//to prevent accidental html output
while (ob_get_level()) {
    ob_end_clean();
}

ini_set('display_errors', 0);     // Prevent warnings on output
ini_set('html_errors', 0);
error_reporting(E_ALL);

// Always return JSON only
header('Content-Type: application/json');


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([ 
        'success' => false,
        'message' => 'Invalid request method. POST required.'
    ]);
    exit;
}

session_start();

$response = ['success' => false, 'message' => ''];
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/integration.php';
require_once __DIR__ . '/../shared/case_management/case_component_binding.php';
require_once __DIR__ . '/../shared/candidate_account_notify.php';
require_once __DIR__ . '/../shared/candidate_correction_service.php';
require_once __DIR__ . '/../shared/workflow/WorkflowTransitionService.php';
require_once __DIR__ . '/../shared/workflow/workflow_stage_config.php';
require_once __DIR__ . '/../shared/application_status_guard.php';
require_once __DIR__ . '/../shared/authorization/workflow_mode.php';

function submission_mail_debug_log(string $message): void
{
    $root = realpath(__DIR__ . '/../..');
    $logFile = $root ? ($root . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'submission_mail_debug.log') : '';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\r\n";

    if ($logFile !== '') {
        $dir = dirname($logFile);
        if (is_dir($dir) && is_writable($dir)) {
            @error_log($line, 3, $logFile);
            return;
        }
    }

    @error_log($line);
}

function validator_activation_debug_log(string $message): void
{
    $root = realpath(__DIR__ . '/../..');
    $logFile = $root ? ($root . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'validator_activation_debug.log') : '';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\r\n";
    if ($logFile !== '') {
        @file_put_contents($logFile, $line, FILE_APPEND);
        return;
    }
    @error_log($line);
}

function markCandidateWorkflowSubmitted(PDO $pdo, string $applicationId): int
{
    $stmt = $pdo->prepare(
        "UPDATE Vati_Payfiller_Case_Component_Workflow
         SET status = 'completed',
             completed_at = COALESCE(completed_at, NOW()),
             updated_at = NOW(),
             updated_by_role = 'candidate'
         WHERE application_id = ?
           AND LOWER(TRIM(stage)) = 'candidate'"
    );
    $stmt->execute([$applicationId]);
    return (int)$stmt->rowCount();
}

function ensureValidatorWorkflowPending(PDO $pdo, string $applicationId): int
{
    $stmt = $pdo->prepare(
        "UPDATE Vati_Payfiller_Case_Component_Workflow
         SET status = 'pending',
             updated_at = NOW()
         WHERE application_id = ?
           AND LOWER(TRIM(stage)) = 'validator'"
    );
    $stmt->execute([$applicationId]);
    return (int)$stmt->rowCount();
}

function is_authorization_completed(PDO $pdo, string $applicationId): bool
{
    $st = $pdo->prepare(
        "SELECT 1
           FROM Vati_Payfiller_Candidate_Authorization_documents
          WHERE application_id = ?
            AND TRIM(COALESCE(digital_signature, '')) <> ''
          LIMIT 1"
    );
    $st->execute([$applicationId]);
    return (bool)$st->fetchColumn();
}

try {
    $caseId = 0;
    $clientId = 0;
    $verifierFirst = false;
    $workflowCaseStatus = 'PENDING_VALIDATOR';

    if (empty($_SESSION['application_id'])) {
        throw new Exception("Session expired. Please restart your application.");
    }

    $application_id = integration_normalize_application_id((string)$_SESSION['application_id']);
    $pdo = getDB();

    if (!$pdo) {
        throw new Exception("Database connection failed.");
    }

    $pdo->query("SELECT 1");

    // Correction mode submit: complete only targeted correction workflow.
    if (!empty($_SESSION['candidate_correction_mode']) && !empty($_SESSION['candidate_correction_session_id'])) {
        ccs_ensure_table($pdo);
        $corrId = (int)$_SESSION['candidate_correction_session_id'];
        $corr = $pdo->prepare('SELECT * FROM Vati_Payfiller_Candidate_Correction_Sessions WHERE correction_session_id = ? LIMIT 1');
        $corr->execute([$corrId]);
        $corrRow = $corr->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$corrRow || strtolower(trim((string)($corrRow['status'] ?? ''))) !== 'active') {
            throw new Exception('Correction session is not active.');
        }
        $allowed = json_decode((string)($corrRow['allowed_components_json'] ?? '[]'), true);
        if (!is_array($allowed) || !$allowed) {
            throw new Exception('No correction components found for session.');
        }
        $components = [];
        foreach ($allowed as $ac) {
            $k = ccs_component_norm((string)$ac);
            if ($k !== '') $components[$k] = true;
        }
        $components = array_values(array_keys($components));
        $requestedRole = ccs_role_norm((string)($corrRow['requested_role'] ?? wf_mode_default_requested_role($pdo, (int)$corrRow['case_id'], $application_id)));
        $resumeStage = ccs_component_stage_for_role($requestedRole);
        if ($resumeStage === '') {
            $resumeStage = wf_mode_first_human_stage($pdo, (int)$corrRow['case_id'], $application_id);
        }
        $pdo->beginTransaction();
        $changed = ccs_resume_components_after_candidate_submit($pdo, (int)$corrRow['case_id'], $application_id, $components, 'correction_submitted', $resumeStage);
        $setAppPending = $pdo->prepare(
            "UPDATE Vati_Payfiller_Candidate_Applications
                SET status = 'submitted',
                    updated_at = NOW()
              WHERE application_id = ?"
        );
        $setAppPending->execute([$application_id]);
        $done = $pdo->prepare("UPDATE Vati_Payfiller_Candidate_Correction_Sessions SET status = 'completed', completed_at = NOW(), updated_at = NOW(), completed_by_role = 'candidate' WHERE correction_session_id = ?");
        $done->execute([$corrId]);
        $svc = new WorkflowTransitionService($pdo);
        $reconcile = $svc->reconcileCorrectionLifecycle(
            (int)$corrRow['case_id'],
            $application_id,
            $resumeStage,
            $components,
            0,
            'candidate',
            'candidate correction submitted'
        );
        if (empty($reconcile['ok'])) {
            throw new Exception((string)($reconcile['message'] ?? 'Correction lifecycle reconcile failed'));
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        ccs_timeline($pdo, $application_id, 0, 'candidate', 'candidate_correction', 'Candidate submitted requested corrections: ' . implode(', ', $components));
        unset(
            $_SESSION['candidate_correction_mode'],
            $_SESSION['candidate_correction_session_id'],
            $_SESSION['candidate_correction_token'],
            $_SESSION['candidate_correction_allowed_components'],
            $_SESSION['candidate_correction_allowed_pages']
        );
        $response['success'] = true;
        $response['message'] = 'Correction submitted successfully.';
        $response['correction'] = [
            'components' => $components,
            'workflow_rows_changed' => $changed
        ];
        echo json_encode($response);
        exit;
    }

    if (!is_authorization_completed($pdo, $application_id)) {
        validator_activation_debug_log(
            'source=submit'
            . ' application_id=' . $application_id
            . ' final_submit_completed=0 authorization_completed=0 queue_created=0 queue_seed_reason=blocked_missing_authorization'
        );
        throw new Exception('Please complete authorization before final submit.');
    }

    // Ensure candidate application lifecycle row exists; submit action must be idempotent.
    $caseMetaStmt = $pdo->prepare(
        "SELECT candidate_first_name, candidate_middle_name, candidate_last_name
         FROM Vati_Payfiller_Cases
         WHERE application_id = ?
         LIMIT 1"
    );
    $caseMetaStmt->execute([$application_id]);
    $caseMeta = $caseMetaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $candidateName = trim(
        (string)($caseMeta['candidate_first_name'] ?? '') . ' '
        . (string)($caseMeta['candidate_middle_name'] ?? '') . ' '
        . (string)($caseMeta['candidate_last_name'] ?? '')
    );

    $submittedStatus = wf_assert_valid_application_status('submitted', 'candidate.submit.final_submit');
    $upsert = $pdo->prepare(
        "INSERT INTO Vati_Payfiller_Candidate_Applications
            (application_id, candidate_name, status, submitted_at, created_at, updated_at)
         VALUES (?, ?, 'submitted', NOW(), NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            status = 'submitted',
            submitted_at = COALESCE(submitted_at, VALUES(submitted_at)),
            updated_at = NOW()"
    );
    $upsert->execute([$application_id, $candidateName]);

    // Best-effort: enqueue into validator queue (if this application maps to a Payfiller case)
    try {
        $caseStmt = $pdo->prepare('SELECT case_id, client_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $caseStmt->execute([$application_id]);
        $c = $caseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($c && !empty($c['case_id'])) {
            $caseId = (int)$c['case_id'];
            $clientId = isset($c['client_id']) ? (int)$c['client_id'] : 0;
            $verifierFirst = wf_mode_is_verifier_first($pdo, $caseId, $application_id);
            $workflowCaseStatus = $verifierFirst ? 'PENDING_VERIFIER' : 'PENDING_VALIDATOR';
            case_component_binding_sync_case_components($pdo, $caseId, $application_id);
            $ensure = $pdo->prepare('CALL SP_Vati_Payfiller_VAL_EnsureQueue(?)');
            $ensure->execute([$clientId > 0 ? $clientId : null]);
            while ($ensure->nextRowset()) {
            }
            $qchk = $pdo->prepare('SELECT 1 FROM Vati_Payfiller_Validator_Queue WHERE case_id = ? LIMIT 1');
            $qchk->execute([$caseId]);
            $queueCreated = (bool)$qchk->fetchColumn();
            validator_activation_debug_log(
                'source=submit'
                . ' application_id=' . $application_id
                . ' case_id=' . $caseId
                . ' final_submit_completed=1 authorization_completed=1 queue_created=' . ($queueCreated ? '1' : '0')
                . ' queue_seed_reason=final_submit'
            );

            $pdo->prepare(
                "UPDATE Vati_Payfiller_Cases
                 SET case_status = ?
                 WHERE case_id = ?
                   AND UPPER(TRIM(COALESCE(case_status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED','CLEAR')"
            )->execute([$workflowCaseStatus, $caseId]);

            $pdo->prepare(
                "UPDATE Vati_Payfiller_Candidate_Applications
                 SET status = ?
                 WHERE application_id = ?
                   AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('rejected','verified')"
            )->execute([$submittedStatus, $application_id]);

            // Seed candidate-stage workflow as approved for all required components.
            try {
                $pdo->prepare(
                    "INSERT INTO Vati_Payfiller_Case_Component_Workflow
                        (case_id, application_id, component_key, stage, status, updated_by_user_id, updated_by_role, completed_at)
                     SELECT c.case_id, c.application_id, LOWER(TRIM(c.component_key)), 'candidate', 'approved', NULL, 'candidate', NOW()
                     FROM Vati_Payfiller_Case_Components c
                     WHERE c.case_id = ?
                       AND c.is_required = 1
                     ON DUPLICATE KEY UPDATE
                        status = 'approved',
                        completed_at = COALESCE(completed_at, NOW()),
                        updated_at = NOW()"
                )->execute([$caseId]);
            } catch (Throwable $e) {
                // ignore
            }

        }
    } catch (Throwable $e) {
        validator_activation_debug_log(
            'source=submit'
            . ' application_id=' . $application_id
            . ' final_submit_completed=1 authorization_completed=1 queue_created=0 queue_seed_reason=queue_seed_exception'
            . ' error=' . $e->getMessage()
        );
        // ignore
    }

    // Finalize candidate-stage workflow statuses once per submit request.
    try {
        $wfAffected = markCandidateWorkflowSubmitted($pdo, $application_id);
        $validatorReady = 0;
        if ($verifierFirst && $caseId > 0) {
            $shadow = wf_mode_shadow_validator_and_seed_verifier($pdo, $caseId, $application_id, $clientId);
            submission_mail_debug_log(
                'verifier-first shadow validator applied: groups=' . implode(',', $shadow['groups_seeded'] ?? [])
                . '; unassigned=' . implode(',', $shadow['groups_unassigned'] ?? [])
                . '; application_id=' . $application_id
            );
        } else {
            $validatorReady = ensureValidatorWorkflowPending($pdo, $application_id);
        }
        if ($wfAffected <= 0) {
            submission_mail_debug_log('candidate workflow transition: 0 rows updated; application_id=' . $application_id);
        } else {
            submission_mail_debug_log('candidate workflow transition: rows updated=' . $wfAffected . '; application_id=' . $application_id);
        }
        submission_mail_debug_log(($verifierFirst ? 'verifier-first shadow validator ready' : 'validator workflow ready') . ': rows updated=' . $validatorReady . '; application_id=' . $application_id);
    } catch (Throwable $e) {
        submission_mail_debug_log('candidate workflow transition: exception=' . $e->getMessage() . '; application_id=' . $application_id);
    }

    try {
        submission_mail_debug_log('submit notify: start application_id=' . $application_id);
        $notifyStmt = $pdo->prepare(
            "SELECT c.client_id,
                    c.candidate_first_name,
                    c.candidate_middle_name,
                    c.candidate_last_name,
                    c.candidate_email,
                    c.job_role,
                    cl.customer_name,
                    a.submitted_at
               FROM Vati_Payfiller_Cases c
               LEFT JOIN Vati_Payfiller_Clients cl ON cl.client_id = c.client_id
               LEFT JOIN Vati_Payfiller_Candidate_Applications a ON a.application_id = c.application_id
              WHERE c.application_id = ?
              LIMIT 1"
        );
        $notifyStmt->execute([$application_id]);
        $notifyRow = $notifyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        submission_mail_debug_log('submit notify: row_found=' . (!empty($notifyRow) ? 'yes' : 'no'));

        if (!empty($notifyRow)) {
            $candidateName = trim(
                (string)($notifyRow['candidate_first_name'] ?? '') . ' '
                . (string)($notifyRow['candidate_middle_name'] ?? '') . ' '
                . (string)($notifyRow['candidate_last_name'] ?? '')
            );

            $candidateEmail = integration_normalize_email((string)($notifyRow['candidate_email'] ?? ''));
            submission_mail_debug_log(
                'submit notify: candidate_email=' . $candidateEmail
                . ' client_id=' . (int)($notifyRow['client_id'] ?? 0)
                . ' job_role=' . (string)($notifyRow['job_role'] ?? '')
            );

            $candidateSent = send_candidate_submission_confirmation($pdo, [
                'client_id' => (int)($notifyRow['client_id'] ?? 0),
                'application_id' => $application_id,
                'candidate_name' => $candidateName,
                'candidate_email' => $candidateEmail,
                'job_role' => (string)($notifyRow['job_role'] ?? ''),
                'client_name' => (string)($notifyRow['customer_name'] ?? ''),
                'submitted_at' => (string)($notifyRow['submitted_at'] ?? ''),
            ]);
            submission_mail_debug_log('submit notify: candidate_confirmation_sent=' . ($candidateSent ? 'yes' : 'no'));

            $internalMeta = send_candidate_submitted_notifications($pdo, [
                'client_id' => (int)($notifyRow['client_id'] ?? 0),
                'application_id' => $application_id,
                'candidate_name' => $candidateName,
                'candidate_email' => $candidateEmail,
                'job_role' => (string)($notifyRow['job_role'] ?? ''),
                'client_name' => (string)($notifyRow['customer_name'] ?? ''),
                'submitted_at' => (string)($notifyRow['submitted_at'] ?? ''),
            ]);
            submission_mail_debug_log(
                'submit notify: internal_recipient_count=' . (int)($internalMeta['recipient_count'] ?? 0)
                . ' internal_sent_count=' . (int)($internalMeta['sent_count'] ?? 0)
            );
        }
    } catch (Throwable $e) {
        submission_mail_debug_log('submit notify: exception=' . $e->getMessage());
        // ignore notification failures so candidate submission still succeeds
    }

  // success
    $response['success'] = true;
    $response['message'] = "Application submitted successfully!";
    try {
        $summary = integration_fetch_case_summary($pdo, $application_id);
        $webhook = integration_send_webhook('candidate.responded', [
            'applicationId' => $application_id,
            'caseId' => $summary['caseId'] ?? null,
            'candidateEmail' => $summary['candidateEmail'] ?? '',
            'candidateName' => $summary['candidateName'] ?? '',
            'currentStage' => 'Candidate Submitted',
            'status' => $workflowCaseStatus,
            'triggeredBy' => [
                'userId' => 0,
                'role' => 'candidate',
            ],
            'triggeredAt' => gmdate('c'),
            'metadata' => integration_deep_links($application_id, isset($summary['caseId']) ? (int)$summary['caseId'] : null),
        ]);
        $response['eventId'] = $webhook['eventId'] ?? null;
    } catch (Throwable $e) {
        submission_mail_debug_log('submit webhook: exception=' . $e->getMessage());
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
