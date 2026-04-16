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
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../../api/shared/case_component_binding.php';
require_once __DIR__ . '/../../api/shared/candidate_account_notify.php';

try {

    if (empty($_SESSION['application_id'])) {
        throw new Exception("Session expired. Please restart your application.");
    }

    $application_id = $_SESSION['application_id'];
    $pdo = getDB();

    if (!$pdo) {
        throw new Exception("Database connection failed.");
    }

    $pdo->query("SELECT 1");

   // Check application exists in the actual Payfiller applications table
    $stmt = $pdo->prepare("
        SELECT application_id
        FROM Vati_Payfiller_Candidate_Applications
        WHERE application_id = ?
    ");
    $stmt->execute([$application_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("No application found with ID: $application_id");
    }

    $update = $pdo->prepare("
        UPDATE Vati_Payfiller_Candidate_Applications
        SET status = 'submitted',
            submitted_at = NOW()
        WHERE application_id = ?
    ");
    $update->execute([$application_id]);

    try {
        $caseStmt = $pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $caseStmt->execute([$application_id]);
        $caseId = (int)($caseStmt->fetchColumn() ?: 0);
        if ($caseId > 0) {
            case_component_binding_sync_case_components($pdo, $caseId, $application_id);
            $pdo->prepare(
                "UPDATE Vati_Payfiller_Cases
                 SET case_status = 'PENDING_VALIDATOR'
                 WHERE case_id = ?
                   AND UPPER(TRIM(COALESCE(case_status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED','CLEAR')"
            )->execute([$caseId]);
        }
        $pdo->prepare(
            "UPDATE Vati_Payfiller_Candidate_Applications
             SET status = 'PENDING_VALIDATOR'
             WHERE application_id = ?
               AND UPPER(TRIM(COALESCE(status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED','CLEAR')"
        )->execute([$application_id]);

        if ($caseId > 0) {
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
        // ignore
    }

    try {
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

        if (!empty($notifyRow)) {
            $candidateName = trim(
                (string)($notifyRow['candidate_first_name'] ?? '') . ' '
                . (string)($notifyRow['candidate_middle_name'] ?? '') . ' '
                . (string)($notifyRow['candidate_last_name'] ?? '')
            );

            send_candidate_submission_confirmation($pdo, [
                'client_id' => (int)($notifyRow['client_id'] ?? 0),
                'application_id' => $application_id,
                'candidate_name' => $candidateName,
                'candidate_email' => (string)($notifyRow['candidate_email'] ?? ''),
                'job_role' => (string)($notifyRow['job_role'] ?? ''),
                'client_name' => (string)($notifyRow['customer_name'] ?? ''),
                'submitted_at' => (string)($notifyRow['submitted_at'] ?? ''),
            ]);

            send_candidate_submitted_notifications($pdo, [
                'client_id' => (int)($notifyRow['client_id'] ?? 0),
                'application_id' => $application_id,
                'candidate_name' => $candidateName,
                'candidate_email' => (string)($notifyRow['candidate_email'] ?? ''),
                'job_role' => (string)($notifyRow['job_role'] ?? ''),
                'client_name' => (string)($notifyRow['customer_name'] ?? ''),
                'submitted_at' => (string)($notifyRow['submitted_at'] ?? ''),
            ]);
        }
    } catch (Throwable $e) {
        // ignore notification failures so candidate submission still succeeds
    }

  // success
    $response['success'] = true;
    $response['message'] = "Application submitted successfully!";

} catch (Throwable $e) {

    http_response_code(400);
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
