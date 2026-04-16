<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';

auth_require_login('client_admin');

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
    return 'APP-' . date('YmdHis') . rand(100, 999);
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
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

function candidate_portal_base_url(): string {
    $env = trim((string)(env_get('CANDIDATE_PORTAL_BASE_URL', '') ?? ''));
    if ($env !== '') return rtrim($env, '/');
    return 'https://vati.payfiller.com/myapplication';
}

function canonical_job_role_name(PDO $pdo, int $clientId, string $input): string {
    $input = trim($input);
    if ($clientId <= 0 || $input === '') return '';

    // If Excel provides a numeric job_role_id, accept it.
    if (preg_match('/^\d+$/', $input)) {
        $stmt = $pdo->prepare('SELECT role_name FROM Vati_Payfiller_Job_Roles WHERE client_id = ? AND job_role_id = ? LIMIT 1');
        $stmt->execute([$clientId, (int)$input]);
        $name = (string)($stmt->fetchColumn() ?: '');
        $name = trim($name);
        if ($name !== '') return $name;
    }

    // Normalize whitespace (multiple spaces) from Excel.
    $normalized = preg_replace('/\s+/', ' ', $input) ?? $input;
    $normalized = trim($normalized);

    $stmt = $pdo->prepare('SELECT role_name FROM Vati_Payfiller_Job_Roles WHERE client_id = ? AND LOWER(TRIM(role_name)) = LOWER(TRIM(?)) LIMIT 1');
    $stmt->execute([$clientId, $normalized]);
    $name = (string)($stmt->fetchColumn() ?: '');
    return trim($name);
}

function parse_date(string $input): string {
    $input = trim($input);
    if ($input === '') return '';

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
        return $input;
    }

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $input, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $input, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    return '';
}

function normalize_mobile(string $input): string {
    $raw = trim($input);
    if ($raw === '') return '';
    return preg_replace('/\D+/', '', $raw) ?? '';
}

function is_valid_mobile(string $digits): bool {
    if ($digits === '') return false;
    $len = strlen($digits);
    return $len >= 8 && $len <= 15;
}

function read_rows_from_csv(string $tmpPath): array {
    $fh = fopen($tmpPath, 'r');
    if (!$fh) return [];

    $rows = [];
    while (($r = fgetcsv($fh)) !== false) {
        $rows[] = $r;
    }
    fclose($fh);
    return $rows;
}

function read_rows_from_xlsx(string $tmpPath): array {
    if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        throw new Exception('XLSX upload requires PhpSpreadsheet. Please upload CSV or install PhpSpreadsheet on server.');
    }

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
    $sheet = $spreadsheet->getActiveSheet();
    return $sheet->toArray(null, true, true, false);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'CSV/XLSX file is required.']);
        exit;
    }

    $originalName = (string)($_FILES['file']['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Only CSV or XLSX files are allowed.']);
        exit;
    }

    $clientId = resolve_client_id();
    $createdByUserId = resolve_user_id();

    $joiningLocation = post_str('joining_location');
    $jobRole = post_str('job_role');
    $recruiterName = post_str('recruiter_name');
    $recruiterEmail = post_str('recruiter_email');

    $tmp = $_FILES['file']['tmp_name'];

    $allRows = [];
    if ($ext === 'xlsx') {
        $allRows = read_rows_from_xlsx($tmp);
    } else {
        $allRows = read_rows_from_csv($tmp);
    }

    if (count($allRows) === 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Uploaded file is empty.']);
        exit;
    }

    $header = $allRows[0];
    if (!is_array($header) || count($header) === 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'Header row missing.']);
        exit;
    }

    $map = [];
    foreach ($header as $idx => $col) {
        $key = strtolower(trim((string)$col));
        $map[$key] = $idx;
    }

    $requiredCols = [
        'candidate_first_name',
        'candidate_last_name',
        'candidate_dob',
        'candidate_father_name',
        'candidate_mobile',
        'candidate_email',
        'candidate_state',
        'candidate_city',
        'joining_location',
        'job_role',
        'recruiter_name',
        'recruiter_email'
    ];

    foreach ($requiredCols as $c) {
        if (!array_key_exists($c, $map)) {
            http_response_code(400);
            echo json_encode(['status' => 0, 'message' => "Missing required column: {$c}"]); 
            exit;
        }
    }

    $pdo = getDB();

    $seenEmails = [];
    $results = [];
    $rowNum = 1;
    for ($i = 1; $i < count($allRows); $i++) {
        $row = $allRows[$i];
        $rowNum = $i + 1;

        if (!is_array($row) || !array_filter($row, function ($v) {
            return trim((string)$v) !== '';
        })) {
            continue;
        }

        $get = function (string $key) use ($map, $row): string {
            if (!array_key_exists($key, $map)) return '';
            $idx = $map[$key];
            return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
        };

        $firstName = $get('candidate_first_name');
        $middleName = $get('candidate_middle_name');
        $lastName = $get('candidate_last_name');
        $dobRaw = $get('candidate_dob');
        $dob = parse_date($dobRaw);
        $fatherName = $get('candidate_father_name');
        $mobile = $get('candidate_mobile');
        $email = $get('candidate_email');
        $state = $get('candidate_state');
        $city = $get('candidate_city');

        $rowJoiningLocation = $get('joining_location');
        $rowJobRole = $get('job_role');
        $rowRecruiterName = $get('recruiter_name');
        $rowRecruiterEmail = $get('recruiter_email');

        $effectiveJoiningLocation = $rowJoiningLocation !== '' ? $rowJoiningLocation : $joiningLocation;
        $effectiveJobRole = $rowJobRole !== '' ? $rowJobRole : $jobRole;
        $effectiveRecruiterName = $rowRecruiterName !== '' ? $rowRecruiterName : $recruiterName;
        $effectiveRecruiterEmail = $rowRecruiterEmail !== '' ? $rowRecruiterEmail : $recruiterEmail;

        $candidateReferenceId = $get('candidate_reference_id');
        $requisitionId = $get('requisition_id');
        $customerCostCenter = $get('customer_cost_center');
        $rehireCandidateStr = $get('rehire_candidate');
        $rehireCandidate = in_array(strtolower($rehireCandidateStr), ['1', 'yes', 'true'], true) ? 1 : 0;

        $candidateLabel = trim($firstName . ' ' . $lastName);

        try {
            $missing = [];
            if ($firstName === '') $missing[] = 'candidate_first_name';
            if ($lastName === '') $missing[] = 'candidate_last_name';
            if ($dob === '') $missing[] = 'candidate_dob';
            if ($fatherName === '') $missing[] = 'candidate_father_name';
            if ($mobile === '') $missing[] = 'candidate_mobile';
            if ($email === '') $missing[] = 'candidate_email';
            if ($state === '') $missing[] = 'candidate_state';
            if ($city === '') $missing[] = 'candidate_city';

            if ($effectiveJoiningLocation === '') $missing[] = 'joining_location';
            if ($effectiveJobRole === '') $missing[] = 'job_role';
            if ($effectiveRecruiterName === '') $missing[] = 'recruiter_name';
            if ($effectiveRecruiterEmail === '') $missing[] = 'recruiter_email';

            if (!empty($missing)) {
                throw new Exception('Missing required fields: ' . implode(', ', $missing));
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid candidate_email');
            }

            $emailKey = strtolower(trim($email));
            if ($emailKey !== '' && isset($seenEmails[$emailKey])) {
                throw new Exception('Duplicate candidate_email in upload: ' . $email);
            }
            $seenEmails[$emailKey] = true;

            if (!filter_var($effectiveRecruiterEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid recruiter_email');
            }

            $canonicalRole = canonical_job_role_name($pdo, $clientId, $effectiveJobRole);
            if ($canonicalRole === '') {
                throw new Exception('Invalid job_role (not mapped for this client): ' . $effectiveJobRole);
            }
            $effectiveJobRole = $canonicalRole;

            $mobileDigits = normalize_mobile($mobile);
            if (!is_valid_mobile($mobileDigits)) {
                throw new Exception('Invalid candidate_mobile');
            }
            $mobile = $mobileDigits;

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
                $effectiveJoiningLocation,
                $effectiveJobRole,
                $effectiveRecruiterName,
                $effectiveRecruiterEmail,
                $candidateReferenceId,
                $requisitionId,
                $customerCostCenter,
                $rehireCandidate
            ]);

            $out = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            while ($stmt->nextRowset()) {
            }

            $caseId = isset($out['case_id']) ? (int)$out['case_id'] : 0;
            if ($caseId <= 0) {
                throw new Exception('case_id missing after create');
            }

            // Create candidate login credentials (always reset temp password and force password change)
            $candidateUserId = 0;
            $tempPassword = '';
            try {
                $candidateUsername = $applicationId;

                // Mirror single-create flow: if credentials already exist for this APPID, reuse them.
                $credCheck = $pdo->prepare('SELECT user_id FROM Vati_Payfiller_User_Credentials WHERE username = ? AND is_active = 1 LIMIT 1');
                $credCheck->execute([$candidateUsername]);
                $existingUid = (int)($credCheck->fetchColumn() ?: 0);

                if ($existingUid > 0) {
                    $candidateUserId = $existingUid;
                }

                if ($candidateUserId <= 0) {
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
                        $effectiveJoiningLocation,
                        ''
                    ]);
                    $uRow = $u->fetch(PDO::FETCH_ASSOC) ?: [];
                    while ($u->nextRowset()) {
                    }
                    $candidateUserId = isset($uRow['user_id']) ? (int)$uRow['user_id'] : 0;
                }

                if ($candidateUserId > 0) {
                    if ($tempPassword === '') {
                        $tempPassword = generate_temp_password(10);
                    }

                    try {
                        $pwdStmt = $pdo->prepare('CALL SP_Vati_Payfiller_SetUserPassword(?, ?, ?, ?)');
                        $pwdStmt->execute([$candidateUserId, $candidateUsername, $tempPassword, 1]);
                        while ($pwdStmt->nextRowset()) {
                        }
                    } catch (Throwable $e) {
                    }

                    try {
                        $candCred = $pdo->prepare(
                            'INSERT INTO Vati_Payfiller_Candidate_Credentials (client_id, user_id, username, password_plain, must_change_password, is_active)\n'
                            . 'VALUES (?, ?, ?, ?, 1, 1)\n'
                            . 'ON DUPLICATE KEY UPDATE password_plain = VALUES(password_plain), must_change_password = 1, is_active = 1, updated_at = NOW()'
                        );
                        $candCred->execute([$clientId, $candidateUserId, $candidateUsername, $tempPassword]);
                    } catch (Throwable $e) {
                    }

                    $cred = $pdo->prepare(
                        'INSERT INTO Vati_Payfiller_User_Credentials (user_id, username, password_plain, must_change_password, is_active)\n'
                        . 'VALUES (?, ?, ?, 1, 1)\n'
                        . 'ON DUPLICATE KEY UPDATE password_plain = VALUES(password_plain), must_change_password = 1, is_active = 1, updated_at = NOW()'
                    );
                    $cred->execute([$candidateUserId, $candidateUsername, $tempPassword]);
                }
            } catch (Throwable $e) {
                // Non-fatal: keep bulk flow moving, but do not overwrite a temp password if it was already generated.
            }

            if ($candidateUserId > 0 && $tempPassword === '') {
                try {
                    $tempPassword = generate_temp_password(10);
                    try {
                        $pwdStmt = $pdo->prepare('CALL SP_Vati_Payfiller_SetUserPassword(?, ?, ?, ?)');
                        $pwdStmt->execute([$candidateUserId, $applicationId, $tempPassword, 1]);
                        while ($pwdStmt->nextRowset()) {
                        }
                    } catch (Throwable $e) {
                    }
                    $cred = $pdo->prepare(
                        'INSERT INTO Vati_Payfiller_User_Credentials (user_id, username, password_plain, must_change_password, is_active)\n'
                        . 'VALUES (?, ?, ?, 1, 1)\n'
                        . 'ON DUPLICATE KEY UPDATE password_plain = VALUES(password_plain), must_change_password = 1, is_active = 1, updated_at = NOW()'
                    );
                    $cred->execute([$candidateUserId, $applicationId, $tempPassword]);

                    $candCred = $pdo->prepare(
                        'INSERT INTO Vati_Payfiller_Candidate_Credentials (client_id, user_id, username, password_plain, must_change_password, is_active)\n'
                        . 'VALUES (?, ?, ?, ?, 1, 1)\n'
                        . 'ON DUPLICATE KEY UPDATE password_plain = VALUES(password_plain), must_change_password = 1, is_active = 1, updated_at = NOW()'
                    );
                    $candCred->execute([$clientId, $candidateUserId, $applicationId, $tempPassword]);
                } catch (Throwable $e) {
                }
            }

            $token = new_token();
            $inviteStmt = $pdo->prepare('CALL SP_Vati_Payfiller_SetCaseInvite(?, ?)');
            $inviteStmt->execute([$caseId, $token]);
            $inviteOut = $inviteStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            while ($inviteStmt->nextRowset()) {
            }

            $affected = isset($inviteOut['affected_rows']) ? (int)$inviteOut['affected_rows'] : 0;
            if ($affected <= 0) {
                throw new Exception('Invite could not be saved');
            }

            $inviteUrl = app_url('/modules/candidate/login.php?token=' . urlencode($token));

            $portalUrl = candidate_portal_base_url();
            $portalLoginUrl = rtrim($portalUrl, '/') . '/modules/candidate/login.php';

            $subject = 'Background Verification - Candidate Invitation';
            $safeName = htmlspecialchars($candidateLabel);
            $safeUrl = htmlspecialchars($inviteUrl);
            $safePortalLogin = htmlspecialchars($portalLoginUrl);
            $safeUsername = htmlspecialchars($applicationId);
            $safeTempPwd = htmlspecialchars($tempPassword);
            $body = ''
                . '<div style="font-family:Arial, sans-serif; font-size:14px; color:#0f172a; line-height:1.5;">'
                . '<p>Dear ' . $safeName . ',</p>'
                . '<p>You have been invited to complete your Background Verification.</p>'
                . '<p><b>Login Page:</b> <a href="' . $safePortalLogin . '">' . $safePortalLogin . '</a></p>'
                . '<p><b>Username (Application ID):</b> ' . $safeUsername . '<br>'
                . '<b>Temporary Password:</b> ' . ($safeTempPwd !== '' ? $safeTempPwd : 'N/A') . '</p>'
                . '<p><a href="' . $safeUrl . '" style="display:inline-block; padding:10px 14px; background:#2563eb; color:#fff; text-decoration:none; border-radius:10px; font-weight:700;">Start Verification</a></p>'
                . '<p style="font-size:12px; color:#64748b;">If the button does not work, copy and paste this link into your browser:<br>'
                . '<span style="word-break:break-all;">' . $safeUrl . '</span></p>'
                . '<p>Thanks,<br>VATI GSS</p>'
                . '</div>';
            $sent = send_app_mail($email, $subject, $body, 'VATI GSS');

            $results[] = [
                'row' => $rowNum,
                'candidate' => $candidateLabel,
                'email' => $email,
                'status' => 'success',
                'case_id' => $caseId,
                'invite_url' => $inviteUrl,
                'email_sent' => $sent ? 1 : 0,
                'message' => $sent ? 'Invite sent' : 'Invite saved (email not configured)'
            ];
        } catch (Throwable $e) {
            $results[] = [
                'row' => $rowNum,
                'candidate' => $candidateLabel,
                'email' => $email,
                'status' => 'failed',
                'case_id' => 0,
                'invite_url' => '',
                'email_sent' => 0,
                'message' => $e->getMessage()
            ];
        }
    }

    $ok = 0;
    $fail = 0;
    foreach ($results as $r) {
        if (($r['status'] ?? '') === 'success') $ok++; else $fail++;
    }

    echo json_encode([
        'status' => 1,
        'message' => "Bulk upload completed. Success: {$ok}, Failed: {$fail}",
        'data' => [
            'success_count' => $ok,
            'failed_count' => $fail,
            'results' => $results
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
