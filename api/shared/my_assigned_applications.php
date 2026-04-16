<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/integration.php';

integration_bootstrap_json_api();
$actor = integration_resolve_actor(true);

function ma_get_int(string $key, int $default = 0): int
{
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

function ma_current_stage_expr(): string
{
    return "CASE
        WHEN UPPER(TRIM(COALESCE(c.case_status, ''))) IN ('REJECTED','STOP_BGV') THEN 'Closed'
        WHEN UPPER(TRIM(COALESCE(c.case_status, ''))) IN ('APPROVED','VERIFIED','COMPLETED','CLEAR') THEN 'Completed'
        WHEN UPPER(TRIM(COALESCE(c.case_status, ''))) = 'PENDING_QA' THEN 'Pending QA'
        WHEN UPPER(TRIM(COALESCE(c.case_status, ''))) = 'PENDING_VERIFIER' THEN 'Pending Verifier'
        WHEN UPPER(TRIM(COALESCE(c.case_status, ''))) = 'PENDING_VALIDATOR' THEN 'Pending Validator'
        WHEN LOWER(TRIM(COALESCE(app.status, ''))) = 'submitted' THEN 'Candidate Submitted'
        WHEN c.invite_sent_at IS NOT NULL THEN 'Invited'
        ELSE 'Created'
    END";
}

function ma_resolve_effective_actor(PDO $pdo, array $actor): array
{
    if (($actor['service'] ?? false) !== true) {
        return $actor;
    }

    $userId = ma_get_int('user_id', 0);
    if ($userId <= 0) {
        integration_json_error(400, 'user_id is required when using a service token');
    }

    $stmt = $pdo->prepare("SELECT user_id, client_id, LOWER(TRIM(role)) AS role, TRIM(CONCAT(first_name, ' ', last_name)) AS full_name, email FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        integration_json_error(404, 'User not found');
    }

    return [
        'authType' => 'service_token',
        'userId' => (int)$row['user_id'],
        'role' => integration_role_normalized((string)($row['role'] ?? '')),
        'clientId' => isset($row['client_id']) ? (int)$row['client_id'] : 0,
        'username' => (string)($row['full_name'] ?? ''),
        'email' => integration_normalize_email((string)($row['email'] ?? '')),
        'service' => true,
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        integration_json_error(405, 'Method not allowed');
    }

    $pdo = getDB();
    $actor = ma_resolve_effective_actor($pdo, $actor);
    $userId = (int)($actor['userId'] ?? 0);
    $role = integration_role_normalized((string)($actor['role'] ?? ''));
    $clientId = (int)($actor['clientId'] ?? 0);
    $stageExpr = ma_current_stage_expr();

    $rows = [];

    if (in_array($role, ['gss_admin', 'qa', 'team_lead'], true)) {
        $stmt = $pdo->query(
            "SELECT c.case_id, c.application_id, $stageExpr AS current_stage, 'role_scope' AS access_reason
               FROM Vati_Payfiller_Cases c
               LEFT JOIN Vati_Payfiller_Candidate_Applications app ON app.application_id = c.application_id
              ORDER BY c.case_id DESC
              LIMIT 500"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($role === 'client_admin') {
        if ($clientId <= 0) {
            integration_json_error(401, 'Unauthorized', [], 'auth');
        }
        $stmt = $pdo->prepare(
            "SELECT c.case_id, c.application_id, $stageExpr AS current_stage, 'client_scope' AS access_reason
               FROM Vati_Payfiller_Cases c
               LEFT JOIN Vati_Payfiller_Candidate_Applications app ON app.application_id = c.application_id
              WHERE c.client_id = ?
              ORDER BY c.case_id DESC
              LIMIT 500"
        );
        $stmt->execute([$clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($role === 'validator') {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT c.case_id, c.application_id, $stageExpr AS current_stage, 'validator_assignment' AS access_reason
               FROM Vati_Payfiller_Cases c
               LEFT JOIN Vati_Payfiller_Candidate_Applications app ON app.application_id = c.application_id
               JOIN Vati_Payfiller_Validator_Queue q ON q.case_id = c.case_id
              WHERE q.assigned_user_id = ?
              ORDER BY c.case_id DESC
              LIMIT 500"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($role === 'verifier') {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT c.case_id, c.application_id, $stageExpr AS current_stage, 'verifier_assignment' AS access_reason
               FROM Vati_Payfiller_Cases c
               LEFT JOIN Vati_Payfiller_Candidate_Applications app ON app.application_id = c.application_id
               LEFT JOIN Vati_Payfiller_Case_Components cc ON cc.case_id = c.case_id AND cc.assigned_user_id = ?
               LEFT JOIN Vati_Payfiller_Verifier_Group_Queue q ON q.case_id = c.case_id AND q.assigned_user_id = ?
              WHERE cc.assigned_user_id IS NOT NULL OR q.assigned_user_id IS NOT NULL
              ORDER BY c.case_id DESC
              LIMIT 500"
        );
        $stmt->execute([$userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($role === 'db_verifier') {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT c.case_id, c.application_id, $stageExpr AS current_stage, 'db_verifier_assignment' AS access_reason
               FROM Vati_Payfiller_Cases c
               LEFT JOIN Vati_Payfiller_Candidate_Applications app ON app.application_id = c.application_id
               LEFT JOIN Vati_Payfiller_Case_Components cc ON cc.case_id = c.case_id AND cc.assigned_user_id = ?
              WHERE c.dbv_assigned_user_id = ? OR cc.assigned_user_id IS NOT NULL
              ORDER BY c.case_id DESC
              LIMIT 500"
        );
        $stmt->execute([$userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $rows = [];
    }

    $data = [];
    foreach ($rows as $row) {
        $applicationId = integration_normalize_application_id((string)($row['application_id'] ?? ''));
        if ($applicationId === '') {
            integration_log('integration_failures', 'Missing application_id in my_assigned_applications row', ['caseId' => $row['case_id'] ?? null, 'role' => $role]);
            continue;
        }
        $caseId = isset($row['case_id']) ? (int)$row['case_id'] : null;
        $links = integration_deep_links($applicationId, $caseId);
        $data[] = [
            'applicationId' => $applicationId,
            'caseId' => $caseId,
            'currentRoleForUser' => $role,
            'currentStage' => trim((string)($row['current_stage'] ?? '')),
            'accessReason' => trim((string)($row['access_reason'] ?? '')),
            'applicationUrl' => $links['applicationUrl'],
            'candidateUrl' => $links['candidateUrl'],
            'timelineUrl' => $links['timelineUrl'],
        ];
    }

    integration_json_response(200, [
        'status' => 1,
        'message' => 'ok',
        'data' => $data,
    ]);
} catch (PDOException $e) {
    integration_json_error(500, 'Database error. Please try again.', [], 'integration_failures');
} catch (Throwable $e) {
    integration_json_error(400, $e->getMessage(), [], 'integration_failures');
}
