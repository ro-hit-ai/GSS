<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/queue_visibility.php';
require_once __DIR__ . '/../shared/verifier_case_queue.php';

auth_require_login('verifier');

auth_session_start();
$userId = (int)($_SESSION['auth_user_id'] ?? 0);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$caseId = isset($input['case_id']) ? (int)$input['case_id'] : 0;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    if ($caseId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'case_id is required']);
        exit;
    }

    $pdo = getDB();
    $claim = verifier_case_queue_claim($pdo, $caseId, $userId);
    if (empty($claim['ok'])) {
        http_response_code(409);
        echo json_encode(['status' => 0, 'message' => (string)($claim['message'] ?? 'No claimable components are available')]);
        exit;
    }

    $components = array_values(array_filter(array_map('strval', $claim['components'] ?? [])));
    $claimedCount = count($components);
    if ($claimedCount === 1) {
        $claimMessage = 'Claimed component: ' . $components[0];
    } elseif ($claimedCount > 1) {
        $claimMessage = 'Claimed ' . $claimedCount . ' components';
    } else {
        $claimMessage = 'Components claimed';
    }

    try {
        $log = $pdo->prepare('INSERT INTO Vati_Payfiller_Case_Timeline (application_id, actor_user_id, actor_role, event_type, section_key, message, created_at) SELECT application_id, ?, ?, ?, ?, ?, NOW() FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $role = !empty($_SESSION['auth_moduleAccess']) ? (string)$_SESSION['auth_moduleAccess'] : 'verifier';
        $claimedComponents = implode(', ', $components);
        $message = $claimedComponents !== '' ? ('Verifier claimed components: ' . $claimedComponents) : 'Verifier claimed components';
        $log->execute([$userId, $role, 'update', 'verifier', $message, $caseId]);
    } catch (Throwable $e) {
    }

    echo json_encode(['status' => 1, 'message' => $claimMessage, 'data' => ['case_id' => $caseId, 'components' => $components]]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
