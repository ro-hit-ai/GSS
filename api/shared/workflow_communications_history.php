<?php
header('Content-Type: application/json');
require_once __DIR__ . '/workflow_communication_service.php';

auth_require_login(null);
auth_session_start();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }
    $pdo = getDB();
    wc_ensure_tables($pdo);
    $applicationId = trim((string)($_GET['application_id'] ?? ''));
    $caseId = (int)($_GET['case_id'] ?? 0);
    $component = strtolower(trim((string)($_GET['component'] ?? '')));
    $applicationId = wc_resolve_application_id($pdo, $applicationId, $caseId);
    if ($applicationId === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id required']);
        exit;
    }
    wc_ingest_incoming_replies($pdo, $applicationId);
    $sql = 'SELECT communication_id, application_id, case_id, component_key, role_key, action_key, template_id, subject, notes, deadline_label, sent_by_name, sent_at, delivery_status, communication_type, direction, actor_role, actor_name, workflow_stage
            FROM workflow_communications WHERE application_id = ?';
    $params = [$applicationId];
    if ($component !== '') {
        $sql .= ' AND component_key = ?';
        $params[] = $component;
    }
    $sql .= ' ORDER BY sent_at DESC, communication_id DESC LIMIT 60';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
