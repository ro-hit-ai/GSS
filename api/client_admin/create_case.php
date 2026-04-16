<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../../includes/integration.php';
require_once __DIR__ . '/../shared/candidate_account_notify.php';

auth_require_any_access(['client_admin', 'gss_admin']);

auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function post_str(string $key, string $default = ''): string {
    return trim((string)($_POST[$key] ?? $default));
}

function post_int(string $key, int $default = 0): int {
    return isset($_POST[$key]) && $_POST[$key] !== '' ? (int)$_POST[$key] : $default;
}

function resolve_client_id(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $role = strtolower(auth_module_access());
    if ($role === 'gss_admin') {
        $cid = isset($_POST['client_id']) && $_POST['client_id'] !== '' ? (int)$_POST['client_id'] : 0;
        if ($cid > 0) return $cid;

        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id is required']);
        exit;
    }

    $cid = isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
    if ($cid > 0) return $cid;

    http_response_code(401);
    echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
    exit;
}

function resolve_user_id(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return !empty($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
}

function new_application_id(): string {
    return integration_normalize_application_id('APP-' . date('YmdHis') . rand(100, 999));
}

function new_token(): string {
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return bin2hex(openssl_random_pseudo_bytes(16));
    }
}

function generate_temp_password(int $len = 10): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#';
    $max = strlen($chars) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        try {
            $idx = random_int(0, $max);
        } catch (Throwable $e) {
            $idx = mt_rand(0, $max);
        }
        $out .= $chars[$idx];
    }
    return $out;
}

function candidate_portal_base_url(): string {
    $env = trim((string)(env_get('CANDIDATE_PORTAL_BASE_URL', '') ?? ''));
    if ($env !== '') return rtrim($env, '/');
    return 'https://vati.payfiller.com/myapplication';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $clientId = resolve_client_id();
    $createdByUserId = resolve_user_id();

    $firstName = post_str('candidate_first_name');
    $middleName = post_str('candidate_middle_name');
    $lastName = post_str('candidate_last_name');
    $dob = post_str('candidate_dob');
    $fatherName = post_str('candidate_father_name');

    $mobile = post_str('candidate_mobile');
    $email = integration_normalize_email(post_str('candidate_email'));
    $state = post_str('candidate_state');
    $city = post_str('candidate_city');

    $joiningLocation = post_str('joining_location');
    $jobRole = post_str('job_role');

    $recruiterName = post_str('recruiter_name');
    $recruiterEmail = integration_normalize_email(post_str('recruiter_email'));

    $candidateReferenceId = post_str('candidate_reference_id');
    $requisitionId = post_str('requisition_id');
    $customerCostCenter = post_str('customer_cost_center');
    $rehireCandidateStr = post_str('rehire_candidate', '0');
    $rehireCandidate = in_array(strtolower($rehireCandidateStr), ['1', 'yes', 'true'], true) ? 1 : 0;

    $required = [
        'candidate_first_name' => $firstName,
        'candidate_last_name' => $lastName,
        'candidate_dob' => $dob,
        'candidate_father_name' => $fatherName,
        'candidate_mobile' => $mobile,
        'candidate_email' => $email,
        'candidate_state' => $state,
        'candidate_city' => $city,
        'joining_location' => $joiningLocation,
        'job_role' => $jobRole,
        'recruiter_name' => $recruiterName,
        'recruiter_email' => $recruiterEmail,
    ];

    foreach ($required as $key => $val) {
        if ($val === '') {
            http_response_code(400);
            echo json_encode(['status' => 0, 'message' => "{$key} is required"]);
            exit;
        }
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Invalid candidate_email']);
        exit;
    }

    if (!filter_var($recruiterEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Invalid recruiter_email']);
        exit;
    }

    $pdo = getDB();

    // Idempotency guard: avoid duplicate inserts on double-click / retry.
    // If a matching case was created very recently, reuse it.
    $existing = null;
    try {
        $dupe = $pdo->prepare(
            'SELECT case_id, application_id, invite_token\n'
            . '  FROM Vati_Payfiller_Cases\n'
            . ' WHERE client_id = ?\n'
            . '   AND candidate_email = ?\n'
            . '   AND candidate_dob = ?\n'
            . '   AND job_role = ?\n'
            . '   AND created_at >= (NOW() - INTERVAL 5 MINUTE)\n'
            . ' ORDER BY case_id DESC\n'
            . ' LIMIT 1'
        );
        $dupe->execute([$clientId, $email, $dob, $jobRole]);
        $existing = $dupe->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $existing = null;
    }

    if ($existing && (int)($existing['case_id'] ?? 0) > 0) {
        $caseId = (int)$existing['case_id'];
        $applicationId = (string)($existing['application_id'] ?? '');
        $token = (string)($existing['invite_token'] ?? '');

        // If token missing, create one now
        if ($token === '') {
            $token = new_token();
            try {
                $inviteStmt = $pdo->prepare('CALL SP_Vati_Payfiller_SetCaseInvite(?, ?)');
                $inviteStmt->execute([$caseId, $token]);
                $inviteOut = $inviteStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                while ($inviteStmt->nextRowset()) {
                }
                $affected = isset($inviteOut['affected_rows']) ? (int)$inviteOut['affected_rows'] : 0;
                if ($affected <= 0) {
                    $token = '';
                }
            } catch (Throwable $e) {
                $token = '';
            }
        }

        $inviteUrl = $token !== ''
            ? app_url('/modules/candidate/login.php?token=' . urlencode($token))
            : app_url('/modules/candidate/login.php');
        $portalUrl = candidate_portal_base_url();
        $portalLoginUrl = rtrim($portalUrl, '/') . '/modules/candidate/login.php';

        echo json_encode([
            'status' => 1,
            'message' => 'Case already created recently. Reusing existing case.',
            'data' => [
                'case_id' => $caseId,
                'application_id' => $applicationId,
                'invite_token' => $token,
                'invite_url' => $inviteUrl,
                'portal_url' => $portalUrl,
                'portal_login_url' => $portalLoginUrl,
                'email_sent' => 0
            ]
        ]);
        exit;
    }

    $applicationId = new_application_id();

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_CreateCase(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $clientId,
        $createdByUserId,
        $applicationId,
        $firstName,
        $middleName,
        $lastName,
        $dob,
        $fatherName,
        $mobile,
        $email,
        $state,
        $city,
        $joiningLocation,
        $jobRole,
        $recruiterName,
        $recruiterEmail,
        $candidateReferenceId,
        $requisitionId,
        $customerCostCenter,
        $rehireCandidate
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {
    }

    $caseId = isset($row['case_id']) ? (int)$row['case_id'] : 0;

    if ($caseId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Case created but case_id missing']);
        exit;
    }

    // Create candidate login credentials (always reset temp password and force password change)
    $candidateUserId = 0;
    $tempPassword = '';
    try {
        $candidateUsername = $applicationId; // APPID

        // If credentials already exist for this APPID, reuse its user_id.
        $credCheck = $pdo->prepare('SELECT user_id FROM Vati_Payfiller_User_Credentials WHERE username = ? AND is_active = 1 LIMIT 1');
        $credCheck->execute([$candidateUsername]);
        $existingUid = (int)($credCheck->fetchColumn() ?: 0);

        if ($existingUid > 0) {
            $candidateUserId = $existingUid;
        }

        if ($candidateUserId <= 0) {
            // Create candidate user profile (role=candidate)
            $u = $pdo->prepare('CALL SP_Vati_Payfiller_CreateUser(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $u->execute([
                $clientId,
                $candidateUsername,
                $firstName,
                $middleName,
                $lastName,
                $mobile,
                $email,
                'candidate',
                $joiningLocation,
                ''
            ]);
            $uRow = $u->fetch(PDO::FETCH_ASSOC) ?: [];
            while ($u->nextRowset()) {
            }
            $candidateUserId = isset($uRow['user_id']) ? (int)$uRow['user_id'] : 0;
        }

        if ($candidateUserId > 0) {
            $tempPassword = generate_temp_password(10);

            try {
                $pwdStmt = $pdo->prepare('CALL SP_Vati_Payfiller_SetUserPassword(?, ?, ?, ?)');
                $pwdStmt->execute([$candidateUserId, $candidateUsername, $tempPassword, 1]);
                while ($pwdStmt->nextRowset()) {
                }
            } catch (Throwable $e) {
            }

            // Store candidate credentials in candidate-only table keyed by client_id
            try {
                $candCred = $pdo->prepare(
                    'INSERT INTO Vati_Payfiller_Candidate_Credentials (client_id, user_id, username, password_plain, must_change_password, is_active)\n'
                    . 'VALUES (?, ?, ?, ?, 1, 1)\n'
                    . 'ON DUPLICATE KEY UPDATE password_plain = VALUES(password_plain), must_change_password = 1, is_active = 1, updated_at = NOW()'
                );
                $candCred->execute([$clientId, $candidateUserId, $candidateUsername, $tempPassword]);
            } catch (Throwable $e) {
            }

            // Store credentials in credentials table (preferred for candidate login)
            $cred = $pdo->prepare(
                'INSERT INTO Vati_Payfiller_User_Credentials (user_id, username, password_plain, must_change_password, is_active)\n'
                . 'VALUES (?, ?, ?, 1, 1)\n'
                . 'ON DUPLICATE KEY UPDATE password_plain = VALUES(password_plain), must_change_password = 1, is_active = 1, updated_at = NOW()'
            );
            $cred->execute([$candidateUserId, $candidateUsername, $tempPassword]);
        }
    } catch (Throwable $e) {
        // Non-fatal: case can still be created + invite token can still be used
        // Keep $tempPassword if it was already generated, so the invite email can show it.
    }

    $token = new_token();
    $inviteStmt = $pdo->prepare('CALL SP_Vati_Payfiller_SetCaseInvite(?, ?)');
    $inviteStmt->execute([$caseId, $token]);
    $inviteOut = $inviteStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($inviteStmt->nextRowset()) {
    }

    $affected = isset($inviteOut['affected_rows']) ? (int)$inviteOut['affected_rows'] : 0;
    if ($affected <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Invite token could not be saved']);
        exit;
    }

    $inviteUrl = app_url('/modules/candidate/login.php?token=' . urlencode($token));
    $portalUrl = candidate_portal_base_url();
    $portalLoginUrl = rtrim($portalUrl, '/') . '/modules/candidate/login.php';

    $candidateLabel = trim($firstName . ' ' . $lastName);
    $subject = 'Background Verification - Candidate Invitation';
    $safeName = htmlspecialchars($candidateLabel);
    $safeUrl = htmlspecialchars($inviteUrl);
    $safePortal = htmlspecialchars($portalUrl);
    $safePortalLogin = htmlspecialchars($portalLoginUrl);
    $safeUsername = htmlspecialchars($applicationId);
    $safeTempPwd = htmlspecialchars($tempPassword);

    $companyName = trim((string)(env_get('COMPANY_NAME', 'VATI GSS') ?? 'VATI GSS'));
    if ($companyName === '') {
        $companyName = 'VATI GSS';
    }
    $safeCompany = htmlspecialchars($companyName);
    $timeline = trim((string)(env_get('BGV_SUBMIT_TIMELINE', '48 hours') ?? '48 hours'));
    if ($timeline === '') {
        $timeline = '48 hours';
    }
    $safeTimeline = htmlspecialchars($timeline);

    $body = ''
        . '<div style="font-family:Arial, sans-serif; font-size:14px; color:#0f172a; line-height:1.6;">'
        . '<p>Dear ' . $safeName . ',</p>'
        . '<p>Greetings from ' . $safeCompany . '.</p>'
        . '<p>We are pleased to inform you that, as part of our hiring process, we would like to initiate your Background Verification (BGV).</p>'
        . '<p>To proceed, we request you to complete the required steps outlined below:</p>'
        . '<ol style="margin:0 0 12px 18px; padding:0;">'
        . '<li>Review the instructions shared in the portal.</li>'
        . '<li>Complete the BGV form with accurate and up-to-date information.</li>'
        . '<li>Upload the necessary supporting documents as requested.</li>'
        . '<li>Submit the completed details within ' . $safeTimeline . '.</li>'
        . '</ol>'
        . '<b>Login Page:</b> <a href="' . $safePortalLogin . '">' . $safePortalLogin . '</a></p>'
        . '<p><b>Username (Application ID):</b> ' . $safeUsername . '<br>'
        . '<b>Temporary Password:</b> ' . ($safeTempPwd !== '' ? $safeTempPwd : 'N/A') . '</p>'
        . '<p><b>Note:</b> You will be asked to change your password on first login.</p>'
        . '<p><a href="' . $safeUrl . '" style="display:inline-block; padding:10px 14px; background:#2563eb; color:#fff; text-decoration:none; border-radius:10px; font-weight:700;">Start Verification</a></p>'
        . '<p style="font-size:12px; color:#64748b;">If the button does not work, copy and paste this link into your browser:<br>'
        . '<span style="word-break:break-all;">' . $safeUrl . '</span></p>'
        . '<p>Kindly note that the BGV process is mandatory and will be carried out in accordance with applicable data protection and confidentiality standards.</p>'
        . '<p>If you have any questions or require assistance while completing the process, please feel free to reach out to us.</p>'
        . '<p>Warm regards,<br>' . $safeCompany . '</p>'
        . '</div>';

    $actorRole = integration_role_normalized(auth_module_access());
    app_mail_set_log_meta([
        'application_id' => $applicationId,
        'case_id' => $caseId,
        'role' => $actorRole,
        'user_id' => $createdByUserId,
        'event_type' => 'application.created',
    ]);
    $sent = send_app_mail($email, $subject, $body, 'VATI GSS', [
        'application_id' => $applicationId,
        'event_type' => 'application.created',
        'user_id' => $createdByUserId,
        'user_role' => $actorRole,
    ]);
    app_mail_clear_log_meta();
    $notifyMeta = ['recipient_count' => 0, 'sent_count' => 0];
    try {
        $notifyMeta = send_candidate_account_created_notifications($pdo, [
            'client_id' => $clientId,
            'application_id' => $applicationId,
            'candidate_name' => $candidateLabel,
            'candidate_email' => $email,
            'job_role' => $jobRole,
            'invite_url' => $inviteUrl,
            'recruiter_name' => $recruiterName,
            'recruiter_email' => $recruiterEmail,
        ]);
    } catch (Throwable $e) {
        $notifyMeta = ['recipient_count' => 0, 'sent_count' => 0];
    }

    $links = integration_deep_links($applicationId, $caseId);
    $webhook = integration_send_webhook('application.created', [
        'applicationId' => $applicationId,
        'caseId' => $caseId,
        'candidateEmail' => $email,
        'candidateName' => $candidateLabel,
        'currentStage' => 'Invited',
        'status' => 'CREATED',
        'triggeredBy' => [
            'userId' => $createdByUserId,
            'role' => $actorRole !== '' ? $actorRole : 'client_admin',
        ],
        'triggeredAt' => gmdate('c'),
        'metadata' => array_merge([
            'inviteUrl' => $inviteUrl,
            'portalLoginUrl' => $portalLoginUrl,
            'jobRole' => $jobRole,
            'emailSent' => $sent ? 1 : 0,
        ], $links),
    ]);

    echo json_encode([
        'status' => 1,
        'message' => $sent ? 'Case created and invite sent.' : 'Case created and invite saved (email not configured).',
        'data' => [
            'case_id' => $caseId,
            'application_id' => $applicationId,
            'applicationId' => $applicationId,
            'invite_token' => $token,
            'invite_url' => $inviteUrl,
            'portal_url' => $portalUrl,
            'portal_login_url' => $portalLoginUrl,
            'candidate_user_id' => $candidateUserId,
            'email_sent' => $sent ? 1 : 0,
            'account_notification_sent_count' => (int)($notifyMeta['sent_count'] ?? 0),
            'account_notification_recipient_count' => (int)($notifyMeta['recipient_count'] ?? 0),
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
