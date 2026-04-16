<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../shared/case_component_binding.php';

auth_require_login('gss_admin');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function component_label(string $key): string
{
    $map = [
        'basic' => 'Basic',
        'id' => 'ID',
        'contact' => 'Contact',
        'education' => 'Education',
        'employment' => 'Employment',
        'reference' => 'Reference',
        'socialmedia' => 'Social Media',
        'ecourt' => 'ECourt',
        'database' => 'Database',
        'reports' => 'Authorization',
        'timeline' => 'Timeline',
    ];
    return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
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

    $pdo = getDB();
    $keys = [
        'basic' => true,
        'contact' => true,
        'reports' => true,
        'timeline' => true,
    ];

    $jr = $pdo->prepare('SELECT job_role_id FROM Vati_Payfiller_Job_Roles WHERE client_id = ?');
    $jr->execute([$clientId]);
    $jobRoleIds = array_map('intval', $jr->fetchAll(PDO::FETCH_COLUMN) ?: []);

    foreach ($jobRoleIds as $jobRoleId) {
        if ($jobRoleId <= 0) {
            continue;
        }
        $types = case_component_binding_fetch_types($pdo, $jobRoleId);
        foreach ($types as $t) {
            $vtId = isset($t['verification_type_id']) ? (int)$t['verification_type_id'] : 0;
            $isEnabled = isset($t['is_enabled']) ? (int)$t['is_enabled'] : 1;
            if ($vtId <= 0 || $isEnabled !== 1) {
                continue;
            }
            $components = case_component_binding_map_verification_type_to_components(
                (string)($t['type_name'] ?? ''),
                (string)($t['type_category'] ?? '')
            );
            foreach ($components as $componentKey) {
                $componentKey = case_component_binding_norm_component_key((string)$componentKey);
                if ($componentKey === '') {
                    continue;
                }
                $keys[$componentKey] = true;
            }
        }
    }

    $order = ['basic', 'id', 'contact', 'education', 'employment', 'reference', 'socialmedia', 'ecourt', 'database', 'reports', 'timeline'];
    $out = [];
    foreach ($order as $key) {
        if (!isset($keys[$key])) {
            continue;
        }
        $out[] = ['key' => $key, 'label' => component_label($key)];
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => $out
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
