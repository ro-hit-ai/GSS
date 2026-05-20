<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_any_access(['client_admin', 'gss_admin']);
auth_session_start();

function get_int_qs(string $key, int $default = 0): int {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

function get_str_qs(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $jobRoleId = get_int_qs('job_role_id', 0);
    $levelKey = strtolower(get_str_qs('level_key', ''));
    $stageKey = strtolower(get_str_qs('stage_key', ''));
    if ($jobRoleId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'job_role_id is required']);
        exit;
    }

    $access = strtolower(auth_module_access());

    $clientId = 0;
    if (strpos($access, 'client_admin') !== false) {
        $clientId = !empty($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
    } else {
        $clientId = get_int_qs('client_id', 0);
    }

    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id is required']);
        exit;
    }

    $pdo = getDB();

    // Ensure role belongs to client
    $roleStmt = $pdo->prepare('SELECT job_role_id, role_name FROM Vati_Payfiller_Job_Roles WHERE job_role_id = ? AND client_id = ? LIMIT 1');
    $roleStmt->execute([$jobRoleId, $clientId]);
    $role = $roleStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$role) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Job role not found for client']);
        exit;
    }

    $steps = [];
    $availableLevels = [];
    $availableStages = [];

    $hasLevelKey = table_has_column($pdo, 'Vati_Payfiller_Job_Role_Verification_Types', 'level_key');
    $hasStageKey = table_has_column($pdo, 'Vati_Payfiller_Job_Role_Verification_Types', 'stage_key');

    // Prefer SP if available
    try {
        $sp = $pdo->prepare('CALL SP_Vati_Payfiller_GetClientVerificationSummary(?)');
        $sp->execute([$clientId]);
        $rolesRs = $sp->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stepsRs = [];
        if ($sp->nextRowset()) {
            $stepsRs = $sp->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        while ($sp->nextRowset()) {
        }
        $sp->closeCursor();

        foreach ($stepsRs as $s) {
            if ((int)($s['job_role_id'] ?? 0) !== $jobRoleId) continue;
            $steps[] = $s;
        }
    } catch (Throwable $e) {
        $steps = [];
    }

    // Fallback query
    if (empty($steps)) {
        $stmt = $pdo->prepare(
            'SELECT s.job_role_id, s.stage_key, s.verification_type_id, s.execution_group, s.assigned_role, s.is_active,\n'
            . '       t.type_name, t.type_category\n'
            . '  FROM Vati_Payfiller_Job_Role_Stage_Steps s\n'
            . '  LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = s.verification_type_id\n'
            . ' WHERE s.job_role_id = ?\n'
            . ' ORDER BY s.stage_key ASC, s.execution_group ASC, COALESCE(t.type_name, "") ASC'
        );
        $stmt->execute([$jobRoleId]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    try {
        if (!$hasLevelKey) throw new RuntimeException('level_key column not available');
        $lvl = $pdo->prepare(
            'SELECT DISTINCT LOWER(TRIM(COALESCE(level_key, ""))) AS level_key
               FROM Vati_Payfiller_Job_Role_Verification_Types
              WHERE job_role_id = ?
                AND TRIM(COALESCE(level_key, "")) <> ""
              ORDER BY level_key ASC'
        );
        $lvl->execute([$jobRoleId]);
        $lvlRows = $lvl->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($lvlRows as $lr) {
            $lk = strtolower(trim((string)($lr['level_key'] ?? '')));
            if ($lk !== '') {
                $availableLevels[$lk] = true;
            }
        }
    } catch (Throwable $e) {
        $availableLevels = [];
    }

    try {
        $stgSql = 'SELECT DISTINCT LOWER(TRIM(COALESCE(s.stage_key, ""))) AS stage_key
                     FROM Vati_Payfiller_Job_Role_Stage_Steps s
                    WHERE s.job_role_id = ?
                      AND s.is_active = 1';
        $stgParams = [$jobRoleId];
        if ($levelKey !== '' && $hasLevelKey) {
            $stgSql .= ' AND EXISTS (
                            SELECT 1
                              FROM Vati_Payfiller_Job_Role_Verification_Types j
                             WHERE j.job_role_id = s.job_role_id
                               AND j.verification_type_id = s.verification_type_id
                               AND LOWER(TRIM(COALESCE(j.level_key, ""))) = ?
                         )';
            $stgParams[] = $levelKey;
        }
        $stgSql .= ' ORDER BY stage_key ASC';
        $stg = $pdo->prepare($stgSql);
        $stg->execute($stgParams);
        $stgRows = $stg->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($stgRows as $sr) {
            $sk = strtolower(trim((string)($sr['stage_key'] ?? '')));
            if ($sk !== '') {
                $availableStages[$sk] = true;
            }
        }
    } catch (Throwable $e) {
        $availableStages = [];
    }

    $typeMetaById = [];
    try {
        $typesSql = 'SELECT j.verification_type_id, j.is_enabled, '
            . ($hasLevelKey ? 'j.level_key' : '\'\' AS level_key')
            . ', t.type_name, t.type_category
                       FROM Vati_Payfiller_Job_Role_Verification_Types j
                       LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
                      WHERE j.job_role_id = ?';
        $typeParams = [$jobRoleId];
        if ($levelKey !== '' && $hasLevelKey) {
            $typesSql .= ' AND LOWER(TRIM(COALESCE(j.level_key, ""))) = ?';
            $typeParams[] = $levelKey;
        }
        $typesSql .= ' ORDER BY COALESCE(j.sort_order, 0) ASC, COALESCE(t.type_name, "") ASC';
        $typesStmt = $pdo->prepare($typesSql);
        $typesStmt->execute($typeParams);
        $typeRows = $typesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($typeRows as $tr) {
            $enabled = isset($tr['is_enabled']) ? (int)$tr['is_enabled'] : 1;
            if ($enabled !== 1) continue;
            $vtId = isset($tr['verification_type_id']) ? (int)$tr['verification_type_id'] : 0;
            if ($vtId <= 0) continue;
            $typeMetaById[$vtId] = [
                'type_name' => (string)($tr['type_name'] ?? ''),
                'type_category' => (string)($tr['type_category'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $typeMetaById = [];
    }

    // Group by stage_key
    $byStage = [];
    foreach ($steps as $s) {
        $active = isset($s['is_active']) ? (int)$s['is_active'] : 1;
        if ($active !== 1) continue;
        $vtId = isset($s['verification_type_id']) ? (int)$s['verification_type_id'] : 0;
        if ($vtId <= 0) continue;
        if (!empty($typeMetaById) && !isset($typeMetaById[$vtId])) continue;

        $stage = (string)($s['stage_key'] ?? '');
        if ($stage === '') $stage = 'unknown';
        if ($stageKey !== '' && strtolower($stage) !== $stageKey) continue;

        if (!isset($byStage[$stage])) {
            $byStage[$stage] = [];
        }

        $byStage[$stage][] = [
            'verification_type_id' => $vtId,
            'type_name' => !empty($typeMetaById) ? (string)($typeMetaById[$vtId]['type_name'] ?? '') : (string)($s['type_name'] ?? ''),
            'type_category' => !empty($typeMetaById) ? (string)($typeMetaById[$vtId]['type_category'] ?? '') : (string)($s['type_category'] ?? ''),
            'execution_group' => isset($s['execution_group']) ? (int)$s['execution_group'] : 1,
            'assigned_role' => (string)($s['assigned_role'] ?? ''),
        ];
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'client_id' => $clientId,
            'job_role_id' => $jobRoleId,
            'job_role' => (string)($role['role_name'] ?? ''),
            'selected_level' => $levelKey,
            'selected_stage' => $stageKey,
            'available_levels' => array_values(array_keys($availableLevels)),
            'available_stages' => array_values(array_keys($availableStages)),
            'stages' => $byStage
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
