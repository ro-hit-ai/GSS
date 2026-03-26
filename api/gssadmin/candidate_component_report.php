<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('gss_admin');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function get_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $clientId = get_int('client_id', 0);
    $search = get_str('search', '');

    $pdo = getDB();

    $componentsSql =
        "(SELECT 'basic' AS component_key\n"
        . " UNION ALL SELECT 'id'\n"
        . " UNION ALL SELECT 'contact'\n"
        . " UNION ALL SELECT 'education'\n"
        . " UNION ALL SELECT 'employment'\n"
        . " UNION ALL SELECT 'reference'\n"
        . " UNION ALL SELECT 'socialmedia'\n"
        . " UNION ALL SELECT 'ecourt'\n"
        . " UNION ALL SELECT 'reports') comp";

    $sql =
        "SELECT\n"
        . "  c.case_id,\n"
        . "  c.client_id,\n"
        . "  c.application_id,\n"
        . "  c.candidate_first_name,\n"
        . "  c.candidate_last_name,\n"
        . "  c.candidate_email,\n"
        . "  c.candidate_mobile,\n"
        . "  c.case_status,\n"
        . "  app.status AS application_status,\n"
        . "  c.created_at,\n"
        . "  comp.component_key,\n"
        . "  COALESCE(LOWER(TRIM(vv.status)), 'pending') AS validator_status,\n"
        . "  COALESCE(LOWER(TRIM(vr.status)), 'pending') AS verifier_status,\n"
        . "  COALESCE(LOWER(TRIM(vq.status)), 'pending') AS qa_status,\n"
        . "  COALESCE(vv.updated_at, vv.completed_at, NULL) AS validator_at,\n"
        . "  COALESCE(vr.updated_at, vr.completed_at, NULL) AS verifier_at,\n"
        . "  COALESCE(vq.updated_at, vq.completed_at, NULL) AS qa_at,\n"
        . "  CASE\n"
        . "    WHEN vq.status IS NOT NULL AND GREATEST(COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00'), COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00'), COALESCE(vv.updated_at, vv.completed_at, '1970-01-01 00:00:00')) = COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00') THEN 'qa'\n"
        . "    WHEN vr.status IS NOT NULL AND GREATEST(COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00'), COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00'), COALESCE(vv.updated_at, vv.completed_at, '1970-01-01 00:00:00')) = COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00') THEN 'verifier'\n"
        . "    WHEN vv.status IS NOT NULL THEN 'validator'\n"
        . "    ELSE ''\n"
        . "  END AS latest_stage,\n"
        . "  CASE\n"
        . "    WHEN vq.status IS NOT NULL AND GREATEST(COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00'), COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00'), COALESCE(vv.updated_at, vv.completed_at, '1970-01-01 00:00:00')) = COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00') THEN LOWER(TRIM(vq.status))\n"
        . "    WHEN vr.status IS NOT NULL AND GREATEST(COALESCE(vq.updated_at, vq.completed_at, '1970-01-01 00:00:00'), COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00'), COALESCE(vv.updated_at, vv.completed_at, '1970-01-01 00:00:00')) = COALESCE(vr.updated_at, vr.completed_at, '1970-01-01 00:00:00') THEN LOWER(TRIM(vr.status))\n"
        . "    WHEN vv.status IS NOT NULL THEN LOWER(TRIM(vv.status))\n"
        . "    ELSE 'pending'\n"
        . "  END AS latest_status,\n"
        . "  CASE\n"
        . "    WHEN LOWER(TRIM(COALESCE(stoplog.actor_role, ''))) = 'gss_admin' THEN 'GA'\n"
        . "    WHEN LOWER(TRIM(COALESCE(stoplog.actor_role, ''))) IN ('client_admin', 'customer_admin') THEN 'CA'\n"
        . "    ELSE ''\n"
        . "  END AS stopped_by_short,\n"
        . "  COALESCE(stoplog.actor_role, '') AS stopped_by_role,\n"
        . "  stoplog.created_at AS stopped_at\n"
        . "FROM Vati_Payfiller_Cases c\n"
        . "LEFT JOIN Vati_Payfiller_Candidate_Applications app ON app.application_id = c.application_id\n"
        . "CROSS JOIN " . $componentsSql . "\n"
        . "LEFT JOIN Vati_Payfiller_Case_Component_Workflow vv\n"
        . "  ON vv.case_id = c.case_id\n"
        . " AND REPLACE(LOWER(TRIM(vv.component_key)), '_', '') = REPLACE(comp.component_key, '_', '')\n"
        . " AND LOWER(TRIM(vv.stage)) = 'validator'\n"
        . "LEFT JOIN Vati_Payfiller_Case_Component_Workflow vr\n"
        . "  ON vr.case_id = c.case_id\n"
        . " AND REPLACE(LOWER(TRIM(vr.component_key)), '_', '') = REPLACE(comp.component_key, '_', '')\n"
        . " AND LOWER(TRIM(vr.stage)) = 'verifier'\n"
        . "LEFT JOIN Vati_Payfiller_Case_Component_Workflow vq\n"
        . "  ON vq.case_id = c.case_id\n"
        . " AND REPLACE(LOWER(TRIM(vq.component_key)), '_', '') = REPLACE(comp.component_key, '_', '')\n"
        . " AND LOWER(TRIM(vq.stage)) = 'qa'\n"
        . "LEFT JOIN Vati_Payfiller_Case_Timeline stoplog\n"
        . "  ON stoplog.timeline_id = (\n"
        . "     SELECT t2.timeline_id\n"
        . "     FROM Vati_Payfiller_Case_Timeline t2\n"
        . "     WHERE t2.application_id = c.application_id\n"
        . "       AND LOWER(TRIM(COALESCE(t2.actor_role, ''))) IN ('gss_admin', 'client_admin', 'customer_admin')\n"
        . "       AND (\n"
        . "         LOWER(TRIM(COALESCE(t2.message, ''))) LIKE '%stop%'\n"
        . "         OR LOWER(TRIM(COALESCE(t2.section_key, ''))) IN ('case_status', 'stop_bgv', 'stop')\n"
        . "       )\n"
        . "     ORDER BY t2.created_at DESC, t2.timeline_id DESC\n"
        . "     LIMIT 1\n"
        . "  )\n"
        . "WHERE 1=1";

    $params = [];
    if ($clientId > 0) {
        $sql .= " AND c.client_id = ?";
        $params[] = $clientId;
    }
    if ($search !== '') {
        $sql .= " AND (\n"
            . "    c.candidate_first_name LIKE ? OR\n"
            . "    c.candidate_last_name LIKE ? OR\n"
            . "    c.candidate_email LIKE ? OR\n"
            . "    c.candidate_mobile LIKE ? OR\n"
            . "    c.application_id LIKE ? OR\n"
            . "    c.case_status LIKE ?\n"
            . " )";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY c.created_at DESC, c.case_id DESC, comp.component_key ASC LIMIT 3500";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'status' => 1,
        'message' => 'OK',
        'data' => $rows
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
