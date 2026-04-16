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

    $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id is required']);
        exit;
    }

    $debug = isset($_GET['debug']) ? (int)$_GET['debug'] : 0;

    $pdo = getDB();

    // Prefer stored procedure (consistent DB contract)
    // Expected result sets:
    // 1) roles: job_role_id, role_name
    // 2) steps: job_role_id, stage_key, verification_type_id, execution_group, assigned_role, is_active, type_name, type_category
    $spRoles = [];
    $spSteps = [];
    try {
        $sp = $pdo->prepare('CALL SP_Vati_Payfiller_GetClientVerificationSummary(?)');
        $sp->execute([$clientId]);
        $spRoles = $sp->fetchAll(PDO::FETCH_ASSOC);
        if ($sp->nextRowset()) {
            $spSteps = $sp->fetchAll(PDO::FETCH_ASSOC);
        }
        while ($sp->nextRowset()) {
        }

        if (is_array($spRoles) && count($spRoles) > 0) {
            $roles = $spRoles;
        }
        if (is_array($spSteps) && count($spSteps) > 0) {
            $steps = $spSteps;
        }
    } catch (Throwable $e) {
        // Ignore: fallback to query-based logic below
    }

    // Job roles + stage mapping
    $roles = [];
    try {
        // NOTE: Do not join to Vati_Payfiller_Job_Role_Stage here.
        // With multi-level support, a role can have multiple stage_key rows; a JOIN duplicates roles and can also
        // hide roles if schema differs. Stages/levels are derived from steps (and optionally stage table) instead.
        $stmt = $pdo->prepare(
            'SELECT jr.job_role_id, jr.role_name\n'
            . '  FROM Vati_Payfiller_Job_Roles jr\n'
            . ' WHERE jr.client_id = ?\n'
            . ' ORDER BY jr.role_name ASC'
        );
        $stmt->execute([$clientId]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Fallback: try SP used in job_roles_list.php
        try {
            $stmt2 = $pdo->prepare('CALL SP_Vati_Payfiller_GetJobRolesByClient(?)');
            $stmt2->execute([$clientId]);
            $roles = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            while ($stmt2->nextRowset()) {
            }
        } catch (Throwable $e2) {
            $roles = [];
        }
    }

    // Only run fallback queries if SP did not provide data
    if (!isset($steps) || !is_array($steps)) $steps = [];

    if (empty($steps)) {
        $steps = [];
        // Prefer filtering steps by client_id to avoid any mismatch between role list and steps.
        // (If the role list is empty for any reason, this still ensures saved steps appear.)
        try {
            $stmt2 = $pdo->prepare(
                'SELECT s.job_role_id, s.stage_key, s.verification_type_id, s.execution_group, s.assigned_role, s.is_active,\n'
                . '       t.type_name, t.type_category\n'
                . '  FROM Vati_Payfiller_Job_Role_Stage_Steps s\n'
                . '  INNER JOIN Vati_Payfiller_Job_Roles jr ON jr.job_role_id = s.job_role_id\n'
                . '  LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = s.verification_type_id\n'
                . ' WHERE jr.client_id = ?\n'
                . ' ORDER BY s.job_role_id ASC, s.stage_key ASC, s.execution_group ASC, COALESCE(t.type_name, "") ASC'
            );
            $stmt2->execute([$clientId]);
            $steps = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Fallback: load steps without joining types table (table may not exist or schema differs)
            try {
                $stmt3 = $pdo->prepare(
                    'SELECT s.job_role_id, s.stage_key, s.verification_type_id, s.execution_group, s.assigned_role, s.is_active\n'
                    . '  FROM Vati_Payfiller_Job_Role_Stage_Steps s\n'
                    . '  INNER JOIN Vati_Payfiller_Job_Roles jr ON jr.job_role_id = s.job_role_id\n'
                    . ' WHERE jr.client_id = ?\n'
                    . ' ORDER BY s.job_role_id ASC, s.stage_key ASC, s.execution_group ASC, s.verification_type_id ASC'
                );
                $stmt3->execute([$clientId]);
                $steps = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e2) {
                $steps = [];
            }
        }
    }

    // Enrich step rows with type_name/type_category if missing
    if (!empty($steps)) {
        $needLookup = false;
        foreach ($steps as $s) {
            if (!isset($s['type_name']) || $s['type_name'] === null || $s['type_name'] === '') {
                $needLookup = true;
                break;
            }
        }

        if ($needLookup) {
            $typeMap = [];
            try {
                $tStmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetAllActiveVerificationTypes()');
                $tStmt->execute();
                $typeRows = $tStmt->fetchAll(PDO::FETCH_ASSOC);
                while ($tStmt->nextRowset()) {
                }

                foreach ($typeRows as $tr) {
                    $id = isset($tr['verification_type_id']) ? (int)$tr['verification_type_id'] : 0;
                    if ($id <= 0) continue;
                    $typeMap[$id] = [
                        'type_name' => (string)($tr['type_name'] ?? ''),
                        'type_category' => (string)($tr['type_category'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {
                $typeMap = [];
            }

            if (!empty($typeMap)) {
                foreach ($steps as $idx => $s) {
                    $vtId = isset($s['verification_type_id']) ? (int)$s['verification_type_id'] : 0;
                    if ($vtId > 0 && isset($typeMap[$vtId])) {
                        $steps[$idx]['type_name'] = $typeMap[$vtId]['type_name'];
                        $steps[$idx]['type_category'] = $typeMap[$vtId]['type_category'];
                    }
                }
            }
        }
    }

    // Group steps by role
    $stepsByRole = [];
    foreach ($steps as $s) {
        $rid = isset($s['job_role_id']) ? (int)$s['job_role_id'] : 0;
        if ($rid <= 0) continue;
        if (!isset($stepsByRole[$rid])) $stepsByRole[$rid] = [];
        $stepsByRole[$rid][] = [
            'stage_key' => (string)($s['stage_key'] ?? ''),
            'verification_type_id' => isset($s['verification_type_id']) ? (int)$s['verification_type_id'] : 0,
            'type_name' => (string)($s['type_name'] ?? ''),
            'type_category' => (string)($s['type_category'] ?? ''),
            'component_key' => resolve_component_key((string)($s['type_name'] ?? ''), (string)($s['type_category'] ?? '')),
            'display_label' => resolve_component_label((string)($s['type_name'] ?? ''), (string)($s['type_category'] ?? '')),
            'candidate_page' => resolve_component_page((string)($s['type_name'] ?? ''), (string)($s['type_category'] ?? '')),
            'candidate_subsection' => resolve_component_subsection((string)($s['type_name'] ?? ''), (string)($s['type_category'] ?? '')),
            'execution_group' => isset($s['execution_group']) ? (int)$s['execution_group'] : 1,
            'assigned_role' => (string)($s['assigned_role'] ?? ''),
            'is_active' => isset($s['is_active']) ? (int)$s['is_active'] : 1,
        ];
    }

    $out = [];
    foreach ($roles as $r) {
        $rid = isset($r['job_role_id']) ? (int)$r['job_role_id'] : 0;
        $out[] = [
            'job_role_id' => $rid,
            'role_name' => (string)($r['role_name'] ?? ''),
            'stage_key' => (string)($r['stage_key'] ?? ''),
            'stage_active' => isset($r['stage_active']) ? (int)$r['stage_active'] : 1,
            'steps' => $stepsByRole[$rid] ?? []
        ];
    }

    $resp = ['status' => 1, 'message' => 'ok', 'data' => $out];
    if ($debug === 1) {
        try {
            $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
            $dbName = '';
        }
        try {
            $dbHost = (string)$pdo->query('SELECT @@hostname')->fetchColumn();
        } catch (Throwable $e) {
            $dbHost = '';
        }

        $resp['debug'] = [
            'database' => $dbName,
            'hostname' => $dbHost,
        ];
    }

    echo json_encode($resp);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
