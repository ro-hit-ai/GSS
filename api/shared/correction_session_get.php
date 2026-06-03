<?php
header('Content-Type: application/json');

require_once __DIR__ . '/candidate_correction_service.php';

function ccs_read_json_get(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }
    $in = $method === 'POST' ? ccs_read_json_get() : $_GET;
    $token = trim((string)($in['token'] ?? ''));
    $id = (int)($in['correction_session_id'] ?? 0);
    if ($token === '' && $id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'token or correction_session_id is required']);
        exit;
    }

    $pdo = getDB();
    ccs_ensure_table($pdo);
    if ($token !== '') {
        $st = $pdo->prepare('SELECT * FROM Vati_Payfiller_Candidate_Correction_Sessions WHERE token = ? LIMIT 1');
        $st->execute([$token]);
    } else {
        auth_require_login();
        auth_session_start();
        $st = $pdo->prepare('SELECT * FROM Vati_Payfiller_Candidate_Correction_Sessions WHERE correction_session_id = ? LIMIT 1');
        $st->execute([$id]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Correction session not found']);
        exit;
    }
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $expiresAt = trim((string)($row['expires_at'] ?? ''));
    $expired = $expiresAt !== '' && strtotime($expiresAt) < time();
    if ($status !== 'completed' && $expired && $status !== 'expired') {
        $u = $pdo->prepare("UPDATE Vati_Payfiller_Candidate_Correction_Sessions SET status = 'expired', updated_at = NOW() WHERE correction_session_id = ?");
        $u->execute([(int)$row['correction_session_id']]);
        $row['status'] = 'expired';
    }
    $components = json_decode((string)($row['allowed_components_json'] ?? '[]'), true);
    if (!is_array($components)) $components = [];

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => [
        'correction_session_id' => (int)$row['correction_session_id'],
        'case_id' => (int)$row['case_id'],
        'application_id' => (string)$row['application_id'],
        'requested_by' => (string)($row['requested_by_name'] ?? ''),
        'requested_role' => (string)($row['requested_role'] ?? ''),
        'reason' => (string)($row['correction_reason'] ?? ''),
        'allowed_components' => array_values($components),
        'status' => (string)$row['status'],
        'expires_at' => (string)($row['expires_at'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
    ]]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}

