<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php';

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

function arr_int($v, int $default = 0): int {
    if ($v === null || $v === '') return $default;
    return (int)$v;
}

function arr_str($v, string $default = ''): string {
    if ($v === null) return $default;
    return trim((string)$v);
}

function arr_bool_int($v): int {
    return !empty($v) ? 1 : 0;
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

    $profileId = isset($data['profile_id']) ? (int)$data['profile_id'] : 0;
    $clientId = isset($data['client_id']) ? (int)$data['client_id'] : 0;

    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id is required']);
        exit;
    }

    $profileName = arr_str($data['profile_name'] ?? '');
    if ($profileName === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'profile_name is required']);
        exit;
    }

    $description = arr_str($data['description'] ?? '');
    $location = arr_str($data['location'] ?? '');
    $isActive = isset($data['is_active']) ? arr_bool_int($data['is_active']) : 1;
    $internalTat = ($data['internal_tat_days'] ?? '') === '' ? null : (int)$data['internal_tat_days'];
    $externalTat = ($data['external_tat_days'] ?? '') === '' ? null : (int)$data['external_tat_days'];

    $components = $data['components'] ?? [];
    if (!is_array($components)) $components = [];

    $jobRoles = $data['job_roles'] ?? [];
    if (!is_array($jobRoles)) $jobRoles = [];

    $pdo = getDB();
    $pdo->beginTransaction();

    if ($profileId > 0) {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_UpdateVerificationProfile(?,?,?,?,?,?,?)');
        $stmt->execute([$profileId, $profileName, $description, $location, $isActive, $internalTat, $externalTat]);
        while ($stmt->nextRowset()) {
        }
    } else {
        $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_CreateVerificationProfile(?,?,?,?,?,?,?)');
        $stmt->execute([$clientId, $profileName, $description, $location, $isActive, $internalTat, $externalTat]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $profileId = isset($row['profile_id']) ? (int)$row['profile_id'] : 0;
        while ($stmt->nextRowset()) {
        }

        if ($profileId <= 0) {
            throw new RuntimeException('Failed to create profile');
        }
    }

    $delStmt = $pdo->prepare('CALL SP_Vati_Payfiller_DeleteVerificationProfileComponents(?)');
    $delStmt->execute([$profileId]);
    while ($delStmt->nextRowset()) {
    }

    $addStmt = $pdo->prepare('CALL SP_Vati_Payfiller_AddVerificationProfileComponent(?,?,?,?,?,?,?,?,?,?,?,?,?)');

    $sort = 1;
    foreach ($components as $c) {
        if (!is_array($c)) continue;

        $vtId = arr_int($c['verification_type_id'] ?? 0, 0);
        if ($vtId <= 0) continue;

        $addStmt->execute([
            $profileId,
            isset($c['sort_order']) ? arr_int($c['sort_order'], $sort) : $sort,
            $vtId,
            arr_str($c['comparison_template'] ?? ''),
            arr_str($c['mail_template'] ?? ''),
            arr_str($c['printable_template'] ?? ''),
            ($c['cost_inr'] ?? '') === '' ? null : (float)$c['cost_inr'],
            ($c['internal_tat_days'] ?? '') === '' ? null : (int)$c['internal_tat_days'],
            ($c['external_tat_days'] ?? '') === '' ? null : (int)$c['external_tat_days'],
            arr_bool_int($c['before_delegation'] ?? 0),
            arr_bool_int($c['supplement_component'] ?? 0),
            ($c['copy_from_component_id'] ?? '') === '' ? null : (int)$c['copy_from_component_id'],
            arr_bool_int($c['enable_add_more'] ?? 0),
        ]);
        while ($addStmt->nextRowset()) {
        }

        $sort++;
    }

    // Replace job role mappings
    try {
        $delJR = $pdo->prepare('CALL SP_Vati_Payfiller_DeleteVerificationProfileJobRoles(?)');
        $delJR->execute([$profileId]);
        while ($delJR->nextRowset()) {
        }

        if (!empty($jobRoles)) {
            $addRole = $pdo->prepare('CALL SP_Vati_Payfiller_AddJobRole(?, ?)');
            $addMap = $pdo->prepare('CALL SP_Vati_Payfiller_AddVerificationProfileJobRole(?, ?)');

            foreach ($jobRoles as $jr) {
                if (!is_array($jr)) continue;
                $jrId = isset($jr['job_role_id']) ? (int)$jr['job_role_id'] : 0;
                $jrName = arr_str($jr['role_name'] ?? '');
                if ($jrId <= 0 && $jrName === '') continue;

                if ($jrId <= 0) {
                    $addRole->execute([$clientId, $jrName]);
                    $r = $addRole->fetch(PDO::FETCH_ASSOC) ?: [];
                    while ($addRole->nextRowset()) {
                    }
                    $jrId = isset($r['job_role_id']) ? (int)$r['job_role_id'] : 0;
                } else {
                    while ($addRole->nextRowset()) {
                    }
                }

                if ($jrId > 0) {
                    $addMap->execute([$profileId, $jrId]);
                    while ($addMap->nextRowset()) {
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // keep profile save working even if job role SPs are not installed yet
    }

    $pdo->commit();

    echo json_encode([
        'status' => 1,
        'message' => 'Saved',
        'data' => ['profile_id' => $profileId]
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
