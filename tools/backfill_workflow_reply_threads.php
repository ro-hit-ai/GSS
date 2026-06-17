<?php
require_once __DIR__ . '/../api/shared/communications/workflow_communication_service.php';

$applicationId = '';
if (PHP_SAPI === 'cli') {
    global $argv;
    if (!empty($argv[1])) {
        $applicationId = trim((string)$argv[1]);
    }
} else {
    $applicationId = trim((string)($_GET['application_id'] ?? ''));
}

try {
    $pdo = getDB();
    wc_backfill_thread_metadata($pdo, $applicationId);

    $params = [];
    $sql = 'SELECT communication_id, application_id, component_key, direction, actor_role, thread_id, thread_owner_role, root_outgoing_communication_id
              FROM Vati_Payfiller_Workflow_Communications';
    if ($applicationId !== '') {
        $sql .= ' WHERE application_id = ?';
        $params[] = $applicationId;
    }
    $sql .= ' ORDER BY communication_id ASC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 1,
        'message' => 'backfill completed',
        'application_id' => $applicationId,
        'row_count' => count($rows),
        'data' => $rows,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }
    echo json_encode([
        'status' => 0,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
