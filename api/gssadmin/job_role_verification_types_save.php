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

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool {
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$tableName, $columnName]);
        return (int)($stmt->fetchColumn() ?: 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
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
    $stageKey = isset($data['stage_key']) ? trim((string)$data['stage_key']) : '';
    $levelKey = isset($data['level_key']) ? trim((string)$data['level_key']) : '';
    $types = $data['types'] ?? [];
    if (!is_array($types)) $types = [];

    if ($jobRoleId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'job_role_id is required']);
        exit;
    }

    $pdo = getDB();
    $pdo->beginTransaction();

    $savedCount = 0;
    $requestedContextualMode = ($stageKey !== '' || $levelKey !== '');
    $hasStageKeyColumn = table_has_column($pdo, 'Vati_Payfiller_Job_Role_Verification_Types', 'stage_key');
    $hasLevelKeyColumn = table_has_column($pdo, 'Vati_Payfiller_Job_Role_Verification_Types', 'level_key');
    $contextualMode = $requestedContextualMode && $hasStageKeyColumn && $hasLevelKeyColumn;

    if (!$contextualMode) {
        $del = $pdo->prepare('CALL SP_Vati_Payfiller_DeleteJobRoleVerificationTypes(?)');
        $del->execute([$jobRoleId]);
        while ($del->nextRowset()) {
        }
    }

    $upsertStmt = null;
    $deleteContextStmt = null;
    try {
        $upsertStmt = $pdo->prepare(
            'INSERT INTO Vati_Payfiller_Job_Role_Verification_Types
                (job_role_id, verification_type_id, stage_key, level_key, sort_order, is_enabled, required_count)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                sort_order = VALUES(sort_order),
                is_enabled = VALUES(is_enabled),
                required_count = VALUES(required_count)'
        );
        $deleteContextStmt = $pdo->prepare(
            'DELETE FROM Vati_Payfiller_Job_Role_Verification_Types
             WHERE job_role_id = ?
               AND stage_key = ?
               AND level_key = ?'
        );
    } catch (Throwable $e) {
        $upsertStmt = null;
        $deleteContextStmt = null;
    }

    if ($contextualMode && !$upsertStmt) {
        throw new RuntimeException('Contextual verification mapping schema is not available. Please apply the required database update first.');
    }

    if ($contextualMode && $deleteContextStmt) {
        if ($stageKey === '') {
            throw new RuntimeException('stage_key is required for contextual verification mapping save');
        }
        if ($levelKey === '') {
            throw new RuntimeException('level_key is required for contextual verification mapping save');
        }
        $deleteContextStmt->execute([$jobRoleId, $stageKey, $levelKey]);
    }

    foreach ($types as $t) {
        if (!is_array($t)) continue;
        $vtId = isset($t['verification_type_id']) ? (int)$t['verification_type_id'] : 0;
        if ($vtId <= 0) continue;
        $isEnabled = !empty($t['is_enabled']) ? 1 : 0;
        if ($isEnabled !== 1) continue;

        $itemStageKey = isset($t['stage_key']) ? trim((string)$t['stage_key']) : $stageKey;
        $itemLevelKey = isset($t['level_key']) ? trim((string)$t['level_key']) : $levelKey;
        $sortOrder = isset($t['sort_order']) ? (int)$t['sort_order'] : ($savedCount + 1);
        $requiredCount = isset($t['required_count']) ? (int)$t['required_count'] : 1;
        if ($requiredCount <= 0) $requiredCount = 1;

        if ($contextualMode && $itemStageKey === '') {
            throw new RuntimeException('stage_key is required for contextual verification mapping save');
        }
        if ($contextualMode && $itemLevelKey === '') {
            throw new RuntimeException('level_key is required for contextual verification mapping save');
        }

        if ($upsertStmt && $contextualMode) {
            $upsertStmt->execute([$jobRoleId, $vtId, $itemStageKey, $itemLevelKey, $sortOrder, 1, $requiredCount]);
        } else {
            $addNew = $pdo->prepare('CALL SP_Vati_Payfiller_AddJobRoleVerificationType(?, ?, ?, ?, ?)');
            $addNew->execute([$jobRoleId, $vtId, $sortOrder, 1, $requiredCount]);
            while ($addNew->nextRowset()) {
            }
        }

        $savedCount++;
    }
    $pdo->commit();

    echo json_encode([
        'status' => 1,
        'message' => 'Saved',
        'saved_count' => $savedCount,
        'contextual_mode' => $contextualMode ? 1 : 0,
        'legacy_mode' => $contextualMode ? 0 : 1
    ]);

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
