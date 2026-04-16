<?php

require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../../includes/integration.php';

function candidate_account_notify_client_admins(PDO $pdo, int $clientId): array
{
    if ($clientId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT email, first_name, last_name
               FROM Vati_Payfiller_Users
              WHERE client_id = ?
                AND LOWER(TRIM(role)) = 'client_admin'
                AND COALESCE(is_active, 1) = 1
                AND TRIM(COALESCE(email, '')) <> ''"
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function candidate_account_notify_gss_admins(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT email, first_name, last_name
               FROM Vati_Payfiller_Users
              WHERE LOWER(TRIM(role)) = 'gss_admin'
                AND COALESCE(is_active, 1) = 1
                AND TRIM(COALESCE(email, '')) <> ''"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function send_candidate_account_created_notifications(PDO $pdo, array $payload): array
{
    $clientId = isset($payload['client_id']) ? (int)$payload['client_id'] : 0;
    $applicationId = trim((string)($payload['application_id'] ?? ''));
    $candidateName = trim((string)($payload['candidate_name'] ?? ''));
    $candidateEmail = trim((string)($payload['candidate_email'] ?? ''));
    $jobRole = trim((string)($payload['job_role'] ?? ''));
    $inviteUrl = trim((string)($payload['invite_url'] ?? ''));
    $recruiterName = trim((string)($payload['recruiter_name'] ?? ''));
    $recruiterEmail = trim((string)($payload['recruiter_email'] ?? ''));

    $recipients = [];
    $seen = [];

    $addRecipient = static function (string $email, string $name) use (&$recipients, &$seen): void {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $key = strtolower($email);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $recipients[] = [
            'email' => $email,
            'name' => trim($name),
        ];
    };

    $addRecipient($recruiterEmail, $recruiterName);
    foreach (candidate_account_notify_client_admins($pdo, $clientId) as $row) {
        $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        $addRecipient((string)($row['email'] ?? ''), $name);
    }

    $subject = 'Candidate Account Created - ' . ($candidateName !== '' ? $candidateName : $applicationId);
    $safeCandidateName = htmlspecialchars($candidateName !== '' ? $candidateName : 'Candidate');
    $safeCandidateEmail = htmlspecialchars($candidateEmail !== '' ? $candidateEmail : 'N/A');
    $safeApplicationId = htmlspecialchars($applicationId !== '' ? $applicationId : 'N/A');
    $safeJobRole = htmlspecialchars($jobRole !== '' ? $jobRole : 'N/A');
    $safeInviteUrl = htmlspecialchars($inviteUrl !== '' ? $inviteUrl : 'N/A');

    $sent = 0;
    foreach ($recipients as $recipient) {
        $displayName = trim((string)($recipient['name'] ?? ''));
        $safeRecipientName = htmlspecialchars($displayName !== '' ? $displayName : 'Team');
        $body = ''
            . '<div style="font-family:Arial, sans-serif; font-size:14px; color:#0f172a; line-height:1.6;">'
            . '<p>Hello ' . $safeRecipientName . ',</p>'
            . '<p>A candidate account has been created successfully for background verification.</p>'
            . '<p><b>Candidate Name:</b> ' . $safeCandidateName . '<br>'
            . '<b>Candidate Email:</b> ' . $safeCandidateEmail . '<br>'
            . '<b>Application ID:</b> ' . $safeApplicationId . '<br>'
            . '<b>Job Role:</b> ' . $safeJobRole . '</p>';

        if ($inviteUrl !== '') {
            $body .= '<p><b>Candidate Portal Link:</b> <a href="' . $safeInviteUrl . '">' . $safeInviteUrl . '</a></p>';
        }

        $body .= '<p>This is an intimation email for your reference.</p>'
            . '<p>Regards,<br>VATI GSS</p>'
            . '</div>';

        app_mail_set_log_meta([
            'application_id' => $applicationId,
            'event_type' => 'application.created',
            'role' => $_SESSION['auth_moduleAccess'] ?? 'system',
            'user_id' => $_SESSION['auth_user_id'] ?? 0,
        ]);
        $ok = send_app_mail((string)$recipient['email'], $subject, $body, 'VATI GSS', [
            'application_id' => $applicationId,
            'event_type' => 'application.created',
        ]);
        app_mail_clear_log_meta();
        if ($ok) {
            $sent++;
        }
    }

    return [
        'recipient_count' => count($recipients),
        'sent_count' => $sent,
    ];
}

function send_candidate_submitted_notifications(PDO $pdo, array $payload): array
{
    $clientId = isset($payload['client_id']) ? (int)$payload['client_id'] : 0;
    $applicationId = trim((string)($payload['application_id'] ?? ''));
    $candidateName = trim((string)($payload['candidate_name'] ?? ''));
    $candidateEmail = trim((string)($payload['candidate_email'] ?? ''));
    $jobRole = trim((string)($payload['job_role'] ?? ''));
    $clientName = trim((string)($payload['client_name'] ?? ''));
    $submittedAt = trim((string)($payload['submitted_at'] ?? ''));

    $recipients = [];
    $seen = [];

    $addRecipient = static function (string $email, string $name) use (&$recipients, &$seen): void {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $key = strtolower($email);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $recipients[] = [
            'email' => $email,
            'name' => trim($name),
        ];
    };

    foreach (candidate_account_notify_client_admins($pdo, $clientId) as $row) {
        $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        $addRecipient((string)($row['email'] ?? ''), $name);
    }

    foreach (candidate_account_notify_gss_admins($pdo) as $row) {
        $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        $addRecipient((string)($row['email'] ?? ''), $name);
    }

    $subject = 'Candidate Details Submitted - ' . ($candidateName !== '' ? $candidateName : $applicationId);
    $safeCandidateName = htmlspecialchars($candidateName !== '' ? $candidateName : 'Candidate');
    $safeCandidateEmail = htmlspecialchars($candidateEmail !== '' ? $candidateEmail : 'N/A');
    $safeApplicationId = htmlspecialchars($applicationId !== '' ? $applicationId : 'N/A');
    $safeJobRole = htmlspecialchars($jobRole !== '' ? $jobRole : 'N/A');
    $safeClientName = htmlspecialchars($clientName !== '' ? $clientName : 'N/A');
    $safeSubmittedAt = htmlspecialchars($submittedAt !== '' ? $submittedAt : date('Y-m-d H:i:s'));

    $sent = 0;
    foreach ($recipients as $recipient) { 
        $displayName = trim((string)($recipient['name'] ?? ''));
        $safeRecipientName = htmlspecialchars($displayName !== '' ? $displayName : 'Team');
        $body = ''
            . '<div style="font-family:Arial, sans-serif; font-size:14px; color:#0f172a; line-height:1.6;">'
            . '<p>Hello ' . $safeRecipientName . ',</p>'
            . '<p>The candidate has completed and submitted their details in the portal.</p>'
            . '<p><b>Candidate Name:</b> ' . $safeCandidateName . '<br>'
            . '<b>Candidate Email:</b> ' . $safeCandidateEmail . '<br>'
            . '<b>Application ID:</b> ' . $safeApplicationId . '<br>'
            . '<b>Client:</b> ' . $safeClientName . '<br>'
            . '<b>Job Role:</b> ' . $safeJobRole . '<br>'
            . '<b>Submitted At:</b> ' . $safeSubmittedAt . '</p>'
            . '<p>This is an intimation email for your reference.</p>'
            . '<p>Regards,<br>VATI GSS</p>'
            . '</div>';

        app_mail_set_log_meta([
            'application_id' => $applicationId,
            'event_type' => 'candidate.responded',
            'role' => $_SESSION['auth_moduleAccess'] ?? 'candidate',
            'user_id' => $_SESSION['auth_user_id'] ?? 0,
        ]);
        $ok = send_app_mail((string)$recipient['email'], $subject, $body, 'VATI GSS', [
            'application_id' => $applicationId,
            'event_type' => 'candidate.responded',
        ]);
        app_mail_clear_log_meta();
        if ($ok) {
            $sent++;
        }
    }

    return [
        'recipient_count' => count($recipients),
        'sent_count' => $sent,
    ];
}

function send_candidate_submission_confirmation(PDO $pdo, array $payload): bool
{
    $applicationId = trim((string)($payload['application_id'] ?? ''));
    $candidateName = trim((string)($payload['candidate_name'] ?? ''));
    $candidateEmail = trim((string)($payload['candidate_email'] ?? ''));
    $jobRole = trim((string)($payload['job_role'] ?? ''));
    $clientName = trim((string)($payload['client_name'] ?? ''));
    $submittedAt = trim((string)($payload['submitted_at'] ?? ''));

    if ($candidateEmail === '' || !filter_var($candidateEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = 'Application Submitted - ' . ($candidateName !== '' ? $candidateName : $applicationId);
    $safeCandidateName = htmlspecialchars($candidateName !== '' ? $candidateName : 'Candidate');
    $safeApplicationId = htmlspecialchars($applicationId !== '' ? $applicationId : 'N/A');
    $safeJobRole = htmlspecialchars($jobRole !== '' ? $jobRole : 'N/A');
    $safeClientName = htmlspecialchars($clientName !== '' ? $clientName : 'N/A');
    $safeSubmittedAt = htmlspecialchars($submittedAt !== '' ? $submittedAt : date('Y-m-d H:i:s'));

    $body = ''
        . '<div style="font-family:Arial, sans-serif; font-size:14px; color:#0f172a; line-height:1.6;">'
        . '<p>Dear ' . $safeCandidateName . ',</p>'
        . '<p>Your background verification application has been submitted successfully.</p>'
        . '<p><b>Application ID:</b> ' . $safeApplicationId . '<br>'
        . '<b>Client:</b> ' . $safeClientName . '<br>'
        . '<b>Job Role:</b> ' . $safeJobRole . '<br>'
        . '<b>Submitted At:</b> ' . $safeSubmittedAt . '</p>'
        . '<p>Our team will review your submission and contact you if anything else is required.</p>'
        . '<p>Regards,<br>VATI GSS</p>'
        . '</div>';

    app_mail_set_log_meta([
        'application_id' => $applicationId,
        'event_type' => 'candidate.responded',
        'role' => $_SESSION['auth_moduleAccess'] ?? 'candidate',
        'user_id' => $_SESSION['auth_user_id'] ?? 0,
    ]);
    $ok = send_app_mail($candidateEmail, $subject, $body, 'VATI GSS', [
        'application_id' => $applicationId,
        'event_type' => 'candidate.responded',
    ]);
    app_mail_clear_log_meta();
    return $ok;
}
