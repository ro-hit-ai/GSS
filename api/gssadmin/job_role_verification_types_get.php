<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $jobRoleId = isset($_GET['job_role_id']) ? (int)$_GET['job_role_id'] : 0;
    if ($jobRoleId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'job_role_id is required']);
        exit;
    }

    $pdo = getDB();

    // New SP. If not installed OR returns no rows, fallback to all active verification types.
    try {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetVerificationTypesByJobRole(?)');
        $stmt->execute([$jobRoleId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        while ($stmt->nextRowset()) {
        }
        if (!is_array($rows) || count($rows) === 0) {
            $stmt2 = $pdo->prepare('CALL SP_Vati_Payfiller_GetAllActiveVerificationTypes()');
            $stmt2->execute();
            $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            while ($stmt2->nextRowset()) {
            }
        }
    } catch (Throwable $e) {
        $stmt2 = $pdo->prepare('CALL SP_Vati_Payfiller_GetAllActiveVerificationTypes()');
        $stmt2->execute();
        $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        while ($stmt2->nextRowset()) {
        }
    }

    // Fallback map if SP does not return required_count in this environment.
    $requiredByTypeId = [];
    try {
        $rcStmt = $pdo->prepare(
            'SELECT verification_type_id, required_count
             FROM Vati_Payfiller_Job_Role_Verification_Types
             WHERE job_role_id = ?'
        );
        $rcStmt->execute([$jobRoleId]);
        $rcRows = $rcStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rcRows as $rr) {
            $vtId = isset($rr['verification_type_id']) ? (int)$rr['verification_type_id'] : 0;
            $rc = isset($rr['required_count']) ? (int)$rr['required_count'] : 1;
            if ($vtId > 0) $requiredByTypeId[$vtId] = $rc > 0 ? $rc : 1;
        }
    } catch (Throwable $e) {
        $requiredByTypeId = [];
    }

    $out = [];
    foreach ($rows as $r) {
        $vtId = isset($r['verification_type_id']) ? (int)$r['verification_type_id'] : 0;
        $req = isset($r['required_count']) ? (int)$r['required_count'] : 1;
        if ($vtId > 0 && isset($requiredByTypeId[$vtId])) {
            $req = (int)$requiredByTypeId[$vtId];
        }
        if ($req <= 0) $req = 1;
        $out[] = [
            'verification_type_id' => $vtId,
            'type_name' => (string)($r['type_name'] ?? ''),
            'type_category' => (string)($r['type_category'] ?? ''),
            'is_enabled' => isset($r['is_enabled']) ? (int)$r['is_enabled'] : 0,
            'sort_order' => isset($r['sort_order']) ? (int)$r['sort_order'] : 0,
            'required_count' => $req,
        ];
    }

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $out]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
