<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function is_local_debug(): bool {
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
    $serverName = isset($_SERVER['SERVER_NAME']) ? strtolower((string)$_SERVER['SERVER_NAME']) : '';
    return strpos($host, 'localhost') !== false || strpos($serverName, 'localhost') !== false;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $data = read_json_body();
    if (!$data) {
        $data = $_POST;
    }

    $jobRoleId = isset($data['job_role_id']) ? (int)$data['job_role_id'] : 0;
    $types = $data['types'] ?? [];
    if (!is_array($types)) $types = [];

    if ($jobRoleId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'job_role_id is required']);
        exit;
    }

    $pdo = getDB();
    $pdo->beginTransaction();

    // New SPs. If missing, hard fail with clear message.
    $del = $pdo->prepare('CALL SP_Vati_Payfiller_DeleteJobRoleVerificationTypes(?)');
    $del->execute([$jobRoleId]);
    while ($del->nextRowset()) {
    }

    $addNew = $pdo->prepare('CALL SP_Vati_Payfiller_AddJobRoleVerificationType(?, ?, ?, ?, ?)');
    $addOld = null;
    try {
        $addOld = $pdo->prepare('CALL SP_Vati_Payfiller_AddJobRoleVerificationType(?, ?, ?, ?)');
    } catch (Throwable $e) {
        $addOld = null;
    }
    $syncRequiredStmt = null;
    try {
        $syncRequiredStmt = $pdo->prepare(
            'UPDATE Vati_Payfiller_Job_Role_Verification_Types
             SET required_count = ?
             WHERE job_role_id = ? AND verification_type_id = ?'
        );
    } catch (Throwable $e) {
        $syncRequiredStmt = null;
    }

    $sort = 1;
    $savedCount = 0;
    foreach ($types as $t) {
        if (!is_array($t)) continue;
        $vtId = isset($t['verification_type_id']) ? (int)$t['verification_type_id'] : 0;
        if ($vtId <= 0) continue;
        $isEnabled = !empty($t['is_enabled']) ? 1 : 0;
        if ($isEnabled !== 1) continue;

        $sortOrder = isset($t['sort_order']) ? (int)$t['sort_order'] : $sort;
        $requiredCount = isset($t['required_count']) ? (int)$t['required_count'] : 1;
        if ($requiredCount <= 0) $requiredCount = 1;

        try {
            $addNew->execute([$jobRoleId, $vtId, $sortOrder, 1, $requiredCount]);
            while ($addNew->nextRowset()) {
            }
        } catch (Throwable $eNew) {
            $usedLegacy = false;
            if ($addOld) {
                try {
                    $addOld->execute([$jobRoleId, $vtId, $sortOrder, 1]);
                    while ($addOld->nextRowset()) {
                    }
                    $usedLegacy = true;
                } catch (Throwable $eOld) {
                    $msgOld = strtolower((string)$eOld->getMessage());
                    // If legacy signature is invalid in this DB, ignore legacy path and surface original error.
                    if (strpos($msgOld, 'incorrect number of arguments') === false) {
                        throw $eOld;
                    }
                }
            }
            if (!$usedLegacy) {
                throw $eNew;
            }
            // Legacy SP may not accept required_count; sync it directly when possible.
            if ($syncRequiredStmt) {
                try {
                    $syncRequiredStmt->execute([$requiredCount, $jobRoleId, $vtId]);
                } catch (Throwable $ignore) {
                }
            }
        }
        $savedCount++;
        $sort++;
    }

    $pdo->commit();

    echo json_encode(['status' => 1, 'message' => 'Saved', 'saved_count' => $savedCount]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    $resp = ['status' => 0, 'message' => 'Database error. Please try again.'];
    if (is_local_debug()) {
        $resp['debug'] = [
            'pdo_code' => (string)$e->getCode(),
            'error' => (string)$e->getMessage()
        ];
    }
    echo json_encode($resp);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
