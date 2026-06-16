<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/candidate_access_resend_service.php';

auth_require_login(null);
auth_session_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $caseId = isset($_GET['case_id']) ? (int)$_GET['case_id'] : 0;
    $applicationId = trim((string)($_GET['application_id'] ?? ''));
    if ($caseId <= 0 && $applicationId === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'case_id or application_id is required']);
        exit;
    }

    $pdo = getDB();
    car_ensure_resend_table($pdo);
    if ($caseId <= 0) {
        $q = $pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $q->execute([$applicationId]);
        $caseId = (int)($q->fetchColumn() ?: 0);
    }
    if ($caseId <= 0) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found']);
        exit;
    }

    $st = $pdo->prepare(
        'SELECT COUNT(*) AS resend_count, MAX(created_at) AS last_resent_at
         FROM Vati_Payfiller_Candidate_Access_Resend_Events
         WHERE case_id = ?'
    );
    $st->execute([$caseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['resend_count' => 0, 'last_resent_at' => null];

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'case_id' => $caseId,
            'resend_count' => (int)($row['resend_count'] ?? 0),
            'last_resent_at' => $row['last_resent_at'] ?? null
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}

