<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/component_resolver.php';

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
    $stageKey = isset($_GET['stage_key']) ? trim((string)$_GET['stage_key']) : '';
    $levelKey = isset($_GET['level_key']) ? trim((string)$_GET['level_key']) : '';
    if ($jobRoleId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'job_role_id is required']);
        exit;
    }

    $pdo = getDB();
    $rows = [];
    $usedDirectQuery = false;

    try {
        $sql = 'SELECT j.verification_type_id, t.type_name, t.type_category, j.is_enabled, j.sort_order, j.required_count, j.stage_key, j.level_key
                  FROM Vati_Payfiller_Job_Role_Verification_Types j
                  LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
                 WHERE j.job_role_id = ?';
        $params = [$jobRoleId];
        if ($stageKey !== '') {
            $sql .= ' AND j.stage_key = ?';
            $params[] = $stageKey;
        }
        if ($levelKey !== '') {
            $sql .= ' AND j.level_key = ?';
            $params[] = $levelKey;
        }
        $sql .= ' ORDER BY COALESCE(j.stage_key, "") ASC, COALESCE(j.level_key, "") ASC, COALESCE(j.sort_order, 0) ASC, COALESCE(t.type_name, "") ASC';

        $stmt0 = $pdo->prepare($sql);
        $stmt0->execute($params);
        $rows = $stmt0->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $usedDirectQuery = true;
    } catch (Throwable $e) {
        $rows = [];
        $usedDirectQuery = false;
    }

    // New SP. If not installed OR returns no rows, fallback to all active verification types.
    if (!$usedDirectQuery || (!is_array($rows) || count($rows) === 0)) {
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
    }

    // Fallback map if SP does not return required_count in this environment.
    $requiredByTypeId = [];
    try {
        $rcSql = 'SELECT verification_type_id, required_count
                    FROM Vati_Payfiller_Job_Role_Verification_Types
                   WHERE job_role_id = ?';
        $rcParams = [$jobRoleId];
        if ($stageKey !== '') {
            $rcSql .= ' AND stage_key = ?';
            $rcParams[] = $stageKey;
        }
        if ($levelKey !== '') {
            $rcSql .= ' AND level_key = ?';
            $rcParams[] = $levelKey;
        }
        $rcStmt = $pdo->prepare($rcSql);
        $rcStmt->execute($rcParams);
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
            'component_key' => resolve_component_key((string)($r['type_name'] ?? ''), (string)($r['type_category'] ?? '')),
            'display_label' => resolve_component_label((string)($r['type_name'] ?? ''), (string)($r['type_category'] ?? '')),
            'candidate_page' => resolve_component_page((string)($r['type_name'] ?? ''), (string)($r['type_category'] ?? '')),
            'candidate_subsection' => resolve_component_subsection((string)($r['type_name'] ?? ''), (string)($r['type_category'] ?? '')),
            'is_enabled' => isset($r['is_enabled']) ? (int)$r['is_enabled'] : 0,
            'sort_order' => isset($r['sort_order']) ? (int)$r['sort_order'] : 0,
            'required_count' => $req,
            'stage_key' => (string)($r['stage_key'] ?? ''),
            'level_key' => (string)($r['level_key'] ?? ''),
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
