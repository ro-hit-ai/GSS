<?php
header('Content-Type: application/json');
require_once __DIR__ . '/workflow_communication_service.php';

auth_require_login(null);
auth_session_start();

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $in = read_json_body();
    $pdo = getDB();
    $role = wc_norm_role((string)($in['role'] ?? wc_session_role()));
    $component = strtolower(trim((string)($in['component'] ?? '')));
    $actionRaw = strtolower(trim((string)($in['action'] ?? '')));
    $mode = strtolower(trim((string)($in['mode'] ?? 'workflow')));
    $action = wc_canonical_action($actionRaw, $component);
    $applicationId = wc_resolve_application_id($pdo, (string)($in['application_id'] ?? ''), (int)($in['case_id'] ?? 0));
    if ($applicationId === '' || $component === '' || $action === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id/component/action required']);
        exit;
    }

    [$case, $application, $basic] = wc_read_case_bundle($pdo, $applicationId);
    $tpl = wc_find_template($pdo, $role, $component, $action);
    $checkMap = wc_component_checklist_map();
    $checklist = $checkMap[$component] ?? [];
    $map = [
        'candidate_name' => trim((string)($case['candidate_first_name'] ?? $basic['first_name'] ?? '')),
        'application_id' => $applicationId,
        'component_name' => ucfirst($component),
        'insufficiency_list' => implode(', ', $checklist),
        'deadline' => (string)($in['deadline'] ?? 'Within 48 hours'),
        'validator_name' => (string)($_SESSION['auth_user_name'] ?? ''),
        'verifier_name' => (string)($_SESSION['auth_user_name'] ?? ''),
    ];

    $subject = $tpl ? wc_render_placeholders((string)($tpl['subject'] ?? ''), $map) : ('Action Required: ' . ucfirst($component));
    $body = $tpl ? wc_render_placeholders((string)($tpl['body'] ?? ''), $map) : ('Dear {candidate_name}, please provide requested documents for {component_name}.');
    $body = wc_render_placeholders($body, $map);
    $resolvedTemplateKey = $tpl ? tmpl_normalize_key((string)($tpl['template_name'] ?? '')) : '';
    $expectedTemplateKey = wc_template_key_for_action($action, $component);

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'template_id' => $tpl ? (int)($tpl['template_id'] ?? 0) : null,
            'template_name' => $tpl ? (string)($tpl['template_name'] ?? '') : '',
            'template_key' => $resolvedTemplateKey,
            'expected_template_key' => $expectedTemplateKey,
            'action_raw' => $actionRaw,
            'action' => $action,
            'mode' => $mode,
            'subject' => $subject,
            'body' => $body,
            'html' => wc_format_html($body),
            'required_fields' => ['to_email', 'action', 'component'],
            'checklist' => $checklist,
            'actions' => wc_action_catalog($role)
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
