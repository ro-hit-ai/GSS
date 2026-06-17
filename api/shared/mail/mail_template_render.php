<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/template_governance.php';
require_once __DIR__ . '/../workflow/workflow_semantics.php';

auth_require_login(null);

auth_session_start();

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

function get_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function looks_like_html(string $s): bool {
    return preg_match('/<\s*[a-zA-Z][^>]*>/', $s) === 1;
}

function safe_html_from_text(string $s): string {
    $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $s = str_replace(["\r\n", "\r", "\n"], "\n", $s);
    $parts = explode("\n", $s);
    return implode('<br>', $parts);
}

function format_template_html(string $body): string {
    $body = (string)$body;
    if (looks_like_html($body)) {
        return $body;
    }
    return '<div style="font-family: Arial, sans-serif; font-size: 13px; color:#0f172a; line-height:1.45;">' . safe_html_from_text($body) . '</div>';
}

function render_placeholders(string $tpl, array $map): string {
    $meta = [];
    $out = tmpl_render_text($tpl, $map, $meta);
    if (!empty($meta['missing'])) {
        tmpl_log_warning('legacy_render_unresolved_placeholders', [
            'endpoint' => 'mail_template_render',
            'missing' => $meta['missing'],
        ]);
    }
    return $out;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $in = $method === 'POST' ? read_json_body() : [];

    $templateId = $method === 'POST' ? (int)($in['template_id'] ?? 0) : get_int('template_id', 0);
    $applicationId = $method === 'POST' ? trim((string)($in['application_id'] ?? '')) : get_str('application_id', '');
    $caseId = $method === 'POST' ? (int)($in['case_id'] ?? 0) : get_int('case_id', 0);
    $role = strtolower($method === 'POST' ? trim((string)($in['role'] ?? '')) : get_str('role', ''));
    $groupKey = strtoupper($method === 'POST' ? trim((string)($in['group'] ?? '')) : get_str('group', ''));

    if ($templateId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'template_id is required']);
        exit;
    }

    if ($applicationId === '' && $caseId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id or case_id is required']);
        exit;
    }

    $pdo = getDB();

    if ($applicationId === '' && $caseId > 0) {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_ReportResolveApplicationId(?)');
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        while ($stmt->nextRowset()) {}
        $applicationId = $row && isset($row['application_id']) ? trim((string)$row['application_id']) : '';
    }

    if ($applicationId === '') {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'application_id not found']);
        exit;
    }

    $userId = !empty($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;

    if ($role === 'verifier') {
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
            exit;
        }
        if (!wf_is_valid_verifier_group($groupKey)) {
            http_response_code(400);
            echo json_encode(['status' => 0, 'message' => 'Valid group is required']);
            exit;
        }

        $chk = $pdo->prepare('CALL SP_Vati_Payfiller_ReportCheckVerifierAssignment(?, ?, ?)');
        $chk->execute([(int)$caseId, $userId, $groupKey]);
        $ok = (int)($chk->fetchColumn() ?: 0) === 1;
        while ($chk->nextRowset()) {}
        if (!$ok) {
            http_response_code(403);
            echo json_encode(['status' => 0, 'message' => 'Forbidden']);
            exit;
        }
    }

    $bundle = $pdo->prepare('CALL SP_Vati_Payfiller_ReportBundle(?)');
    $bundle->execute([$applicationId]);
    $case = $bundle->fetch(PDO::FETCH_ASSOC) ?: null;
    $bundle->nextRowset();
    $application = $bundle->fetch(PDO::FETCH_ASSOC) ?: null;
    $bundle->nextRowset();
    $basic = $bundle->fetch(PDO::FETCH_ASSOC) ?: null;
    while ($bundle->nextRowset()) {}

    if (!$case) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found']);
        exit;
    }

    $clientId = isset($case['client_id']) ? (int)$case['client_id'] : 0;
    $customerName = '';
    if ($clientId > 0) {
        try {
            $cStmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetClientById(?)');
            $cStmt->execute([$clientId]);
            $cRow = $cStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            while ($cStmt->nextRowset()) {}
            if ($cRow) {
                $customerName = (string)($cRow['customer_name'] ?? $cRow['client_name'] ?? '');
            }
        } catch (Throwable $e) {
            $customerName = '';
        }
    }

    $tStmt = $pdo->prepare('CALL SP_Vati_Payfiller_MailTemplate_Get(?)');
    $tStmt->execute([$templateId]);
    $tplRow = $tStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    while ($tStmt->nextRowset()) {}

    if (!$tplRow) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Template not found']);
        exit;
    }

    $candidateFirst = (string)($case['candidate_first_name'] ?? ($basic['first_name'] ?? ''));
    $candidateLast = (string)($case['candidate_last_name'] ?? ($basic['last_name'] ?? ''));

    $userName = !empty($_SESSION['auth_user_name']) ? (string)$_SESSION['auth_user_name'] : '';
    $userEmail = !empty($_SESSION['auth_user_email']) ? (string)$_SESSION['auth_user_email'] : '';
    $userMobile = !empty($_SESSION['auth_user_mobile']) ? (string)$_SESSION['auth_user_mobile'] : '';

    $map = [
        'candidate_name' => trim($candidateFirst),
        'candidate_lastname' => trim($candidateLast),
        'candidate_lastName' => trim($candidateLast),
        'candidate_middleName' => (string)($basic['middle_name'] ?? ''),
        'candidate_dob' => (string)($basic['dob'] ?? ''),
        'candidate_cotrol_no' => (string)($case['control_no'] ?? $case['case_id'] ?? ''),
        'customer_name' => $customerName,
        'username' => (string)($basic['email'] ?? $case['candidate_email'] ?? ''),
        'user_name' => $userName,
        'user_email' => $userEmail,
        'user_phone_number' => $userMobile,
        'current_date' => date('Y-m-d'),
        'sent_date' => date('Y-m-d'),
        'sent_time' => date('H:i'),
        'comments' => '',
        'component_name' => '',
        'index_number' => '',
    ];

    $subject = (string)($tplRow['subject'] ?? '');
    $body = (string)($tplRow['body'] ?? '');

    $renderedSubject = render_placeholders($subject, $map);
    $renderedBody = render_placeholders($body, $map);
    $html = format_template_html($renderedBody);

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'template_id' => $templateId,
            'template_name' => (string)($tplRow['template_name'] ?? ''),
            'template_type' => (string)($tplRow['template_type'] ?? ''),
            'subject' => $renderedSubject,
            'body' => $renderedBody,
            'html' => $html,
            'application_id' => $applicationId,
            'case_id' => isset($case['case_id']) ? (int)$case['case_id'] : 0,
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
