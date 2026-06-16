<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php';

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

    $profileId = isset($_GET['profile_id']) ? (int)$_GET['profile_id'] : 0;
    if ($profileId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'profile_id is required']);
        exit;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetVerificationProfileById(?)');
    $stmt->execute([$profileId]);

    $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt->nextRowset();
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($stmt->nextRowset()) {
    }

    if (!$profile) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Profile not found']);
        exit;
    }

    $outProfile = [
        'profile_id' => isset($profile['profile_id']) ? (int)$profile['profile_id'] : 0,
        'client_id' => isset($profile['client_id']) ? (int)$profile['client_id'] : 0,
        'profile_name' => (string)($profile['profile_name'] ?? ''),
        'description' => (string)($profile['description'] ?? ''),
        'location' => (string)($profile['location'] ?? ''),
        'is_active' => isset($profile['is_active']) ? (int)$profile['is_active'] : 0,
        'internal_tat_days' => isset($profile['internal_tat_days']) ? (int)$profile['internal_tat_days'] : null,
        'external_tat_days' => isset($profile['external_tat_days']) ? (int)$profile['external_tat_days'] : null,
    ];

    $outComponents = [];
    foreach ($components as $c) {
        $outComponents[] = [
            'component_id' => isset($c['component_id']) ? (int)$c['component_id'] : 0,
            'sort_order' => isset($c['sort_order']) ? (int)$c['sort_order'] : 0,
            'verification_type_id' => isset($c['verification_type_id']) ? (int)$c['verification_type_id'] : 0,
            'verification_type_name' => (string)($c['verification_type_name'] ?? ''),
            'comparison_template' => (string)($c['comparison_template'] ?? ''),
            'mail_template' => (string)($c['mail_template'] ?? ''),
            'printable_template' => (string)($c['printable_template'] ?? ''),
            'cost_inr' => isset($c['cost_inr']) ? (float)$c['cost_inr'] : null,
            'internal_tat_days' => isset($c['internal_tat_days']) ? (int)$c['internal_tat_days'] : null,
            'external_tat_days' => isset($c['external_tat_days']) ? (int)$c['external_tat_days'] : null,
            'before_delegation' => isset($c['before_delegation']) ? (int)$c['before_delegation'] : 0,
            'supplement_component' => isset($c['supplement_component']) ? (int)$c['supplement_component'] : 0,
            'copy_from_component_id' => isset($c['copy_from_component_id']) ? (int)$c['copy_from_component_id'] : null,
            'enable_add_more' => isset($c['enable_add_more']) ? (int)$c['enable_add_more'] : 0,
        ];
    }

    $jobRolesOut = null;
    try {
        $cid = isset($profile['client_id']) ? (int)$profile['client_id'] : 0;
        $available = [];
        if ($cid > 0) {
            $jrStmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetJobRolesByClient(?)');
            $jrStmt->execute([$cid]);
            $available = $jrStmt->fetchAll(PDO::FETCH_ASSOC);
            while ($jrStmt->nextRowset()) {
            }
        }

        $selected = [];
        $selStmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetVerificationProfileJobRoles(?)');
        $selStmt->execute([$profileId]);
        $selected = $selStmt->fetchAll(PDO::FETCH_ASSOC);
        while ($selStmt->nextRowset()) {
        }

        $mapRow = function ($r) {
            return [
                'job_role_id' => isset($r['job_role_id']) ? (int)$r['job_role_id'] : 0,
                'role_name' => (string)($r['role_name'] ?? ''),
                'client_id' => isset($r['client_id']) ? (int)$r['client_id'] : 0,
            ];
        };

        $jobRolesOut = [
            'available' => array_values(array_map($mapRow, is_array($available) ? $available : [])),
            'selected' => array_values(array_map($mapRow, is_array($selected) ? $selected : [])),
        ];
    } catch (Throwable $e) {
        $jobRolesOut = null;
    }

    $resp = ['profile' => $outProfile, 'components' => $outComponents];
    if ($jobRolesOut !== null) {
        $resp['job_roles'] = $jobRolesOut;
    }

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $resp]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
