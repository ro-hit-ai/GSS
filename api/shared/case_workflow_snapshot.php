<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/integration.php';

integration_bootstrap_json_api();
auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

integration_resolve_actor(true);

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

function session_role_norm(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $role = !empty($_SESSION['auth_moduleAccess']) ? strtolower(trim((string)$_SESSION['auth_moduleAccess'])) : '';
    if ($role === 'customer_admin') $role = 'client_admin';
    return $role;
}

function session_client_id(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
}

function enforce_client_admin_application_scope(PDO $pdo, string $applicationId): void {
    $role = session_role_norm();
    if ($role !== 'client_admin') return;

    $cid = session_client_id();
    if ($cid <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $st = $pdo->prepare('SELECT client_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
    $st->execute([$applicationId]);
    $appClientId = (int)($st->fetchColumn() ?: 0);
    if ($appClientId !== $cid) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Forbidden']);
        exit;
    }
}

function workflow_table_available(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM Vati_Payfiller_Case_Component_Workflow LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function norm_component_key(string $k): string {
    $k = strtolower(trim($k));
    if ($k === 'identification') return 'id';
    if ($k === 'social_media' || $k === 'social-media') return 'socialmedia';
    if ($k === 'driving' || $k === 'driving_license') return 'driving_licence';
    return $k;
}

function compute_component_stage_label(array $stages): string {
    $cand = strtolower(trim((string)($stages['candidate'] ?? '')));
    $val = strtolower(trim((string)($stages['validator'] ?? '')));
    $ver = strtolower(trim((string)($stages['verifier'] ?? '')));
    $qa = strtolower(trim((string)($stages['qa'] ?? '')));

    if ($qa === 'rejected') return 'QA Rejected';
    if ($qa === 'approved') return 'Completed';
    if ($ver === 'rejected') return 'Verifier Rejected';
    if ($val === 'rejected') return 'Validator Rejected';
    if ($cand === 'rejected') return 'Candidate Rejected';

    if ($ver === 'approved') return 'Pending QA';
    if ($val === 'approved') return 'Pending Verifier';
    if ($cand === 'approved') return 'Pending Validator';
    return 'Pending Candidate';
}

function fetch_latest_user_name(PDO $pdo, ?int $userId): ?string {
    if (!$userId || $userId <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT TRIM(CONCAT(first_name, ' ', last_name)) AS full_name FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $name = trim((string)($st->fetchColumn() ?: ''));
        return $name !== '' ? $name : null;
    } catch (Throwable $e) {
        return null;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $applicationId = integration_normalize_application_id(get_str('application_id', ''));
    if ($applicationId === '') {
        integration_json_error(400, 'application_id is required', [], 'integration_failures');
    }

    $pdo = getDB();
    enforce_client_admin_application_scope($pdo, $applicationId);

    $sql = "SELECT
                c.case_id,
                c.application_id,
                c.client_id,
                c.candidate_first_name,
                c.candidate_last_name,
                c.candidate_email,
                c.case_status,
                app.status AS application_status,
                cl.internal_tat,
                cl.weekend_rules,
                c.dbv_assigned_user_id,
                (SELECT q.assigned_user_id
                   FROM Vati_Payfiller_Validator_Queue q
                  WHERE q.case_id = c.case_id
                  ORDER BY q.id DESC
                  LIMIT 1) AS validator_user_id,
                (SELECT q.assigned_user_id
                   FROM Vati_Payfiller_Verifier_Group_Queue q
                  WHERE q.case_id = c.case_id AND UPPER(TRIM(q.group_key)) = 'BASIC'
                  ORDER BY q.id DESC
                  LIMIT 1) AS verifier_basic_user_id,
                (SELECT q.assigned_user_id
                   FROM Vati_Payfiller_Verifier_Group_Queue q
                  WHERE q.case_id = c.case_id AND UPPER(TRIM(q.group_key)) = 'EDUCATION'
                  ORDER BY q.id DESC
                  LIMIT 1) AS verifier_education_user_id,
                CASE
                    WHEN UPPER(TRIM(c.case_status)) IN ('REJECTED','STOP_BGV') THEN 'QA Rejected'
                    WHEN UPPER(TRIM(c.case_status)) IN ('APPROVED','VERIFIED','COMPLETED','CLEAR') THEN 'QA Completed'
                    WHEN (vq.completed_at IS NOT NULL AND COALESCE(vr.vr_pending, 0) = 0 AND COALESCE(vr.vr_total, 0) > 0) THEN 'QA Pending'
                    WHEN (COALESCE(vr.vr_total, 0) > 0 AND COALESCE(vr.vr_pending, 0) > 0 AND COALESCE(vr.vr_in_progress, 0) > 0) THEN 'Verifier In Progress'
                    WHEN (COALESCE(vr.vr_total, 0) > 0 AND COALESCE(vr.vr_pending, 0) > 0) THEN 'Verifier Pending'
                    WHEN (vq.assigned_user_id IS NOT NULL AND vq.completed_at IS NULL) THEN 'Validation In Progress'
                    WHEN (vq.case_id IS NOT NULL AND vq.completed_at IS NULL) THEN 'Validation Pending'
                    WHEN LOWER(TRIM(COALESCE(app.status, ''))) = 'submitted' THEN 'Validation Pending'
                    WHEN (bd.application_id IS NOT NULL) THEN 'Candidate Submitted'
                    WHEN (c.invite_sent_at IS NOT NULL) THEN 'Invited'
                    ELSE 'Created'
                END AS current_stage
            FROM Vati_Payfiller_Cases c
            LEFT JOIN Vati_Payfiller_Clients cl ON cl.client_id = c.client_id
            LEFT JOIN Vati_Payfiller_Candidate_Applications app ON app.application_id = c.application_id
            LEFT JOIN (
                SELECT application_id
                  FROM Vati_Payfiller_Candidate_Basic_details
                 GROUP BY application_id
            ) bd ON bd.application_id = c.application_id
            LEFT JOIN (
                SELECT q1.*
                  FROM Vati_Payfiller_Validator_Queue q1
                  JOIN (
                        SELECT case_id, MAX(COALESCE(completed_at, claimed_at)) AS max_ts
                          FROM Vati_Payfiller_Validator_Queue
                         GROUP BY case_id
                  ) q2 ON q2.case_id = q1.case_id
                      AND COALESCE(q2.max_ts, '1970-01-01 00:00:00') = COALESCE(COALESCE(q1.completed_at, q1.claimed_at), '1970-01-01 00:00:00')
            ) vq ON vq.case_id = c.case_id
            LEFT JOIN (
                SELECT case_id,
                       COUNT(*) AS vr_total,
                       SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END) AS vr_pending,
                       SUM(CASE WHEN completed_at IS NULL AND assigned_user_id IS NOT NULL THEN 1 ELSE 0 END) AS vr_in_progress
                  FROM Vati_Payfiller_Verifier_Group_Queue
                 GROUP BY case_id
            ) vr ON vr.case_id = c.case_id
            WHERE c.application_id = ?
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$applicationId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$case) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found']);
        exit;
    }

    $caseId = (int)($case['case_id'] ?? 0);
    $candidateName = trim((string)($case['candidate_first_name'] ?? '') . ' ' . (string)($case['candidate_last_name'] ?? ''));

    $validatorUserId = isset($case['validator_user_id']) ? (int)$case['validator_user_id'] : 0;
    $vrBasicUserId = isset($case['verifier_basic_user_id']) ? (int)$case['verifier_basic_user_id'] : 0;
    $vrEduUserId = isset($case['verifier_education_user_id']) ? (int)$case['verifier_education_user_id'] : 0;
    $dbvUserId = isset($case['dbv_assigned_user_id']) ? (int)$case['dbv_assigned_user_id'] : 0;

    $ownerSummary = [
        'mode' => 'queue_split',
        'validator' => [
            'userId' => $validatorUserId > 0 ? $validatorUserId : null,
            'name' => fetch_latest_user_name($pdo, $validatorUserId),
        ],
        'verifier' => [
            [
                'groupKey' => 'BASIC',
                'userId' => $vrBasicUserId > 0 ? $vrBasicUserId : null,
                'name' => fetch_latest_user_name($pdo, $vrBasicUserId),
            ],
            [
                'groupKey' => 'EDUCATION',
                'userId' => $vrEduUserId > 0 ? $vrEduUserId : null,
                'name' => fetch_latest_user_name($pdo, $vrEduUserId),
            ],
        ],
        'dbVerifier' => [
            'userId' => $dbvUserId > 0 ? $dbvUserId : null,
            'name' => fetch_latest_user_name($pdo, $dbvUserId),
        ],
    ];

    $pendingItemsSummary = [
        'totalRequired' => 0,
        'pendingCount' => 0,
        'holdCount' => 0,
        'rejectedCount' => 0,
        'items' => [],
    ];

    try {
        $componentsStmt = $pdo->prepare(
            'SELECT component_key, is_required, assigned_role, assigned_user_id, status, completed_at
               FROM Vati_Payfiller_Case_Components
              WHERE application_id = ?'
        );
        $componentsStmt->execute([$applicationId]);
        $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $workflowByComponent = [];
        if (workflow_table_available($pdo)) {
            $workflowStmt = $pdo->prepare(
                'SELECT component_key, stage, status
                   FROM Vati_Payfiller_Case_Component_Workflow
                  WHERE application_id = ?'
            );
            $workflowStmt->execute([$applicationId]);
            $workflowRows = $workflowStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($workflowRows as $row) {
                $ck = norm_component_key((string)($row['component_key'] ?? ''));
                $stage = strtolower(trim((string)($row['stage'] ?? '')));
                if ($ck === '' || $stage === '') continue;
                if (!isset($workflowByComponent[$ck])) $workflowByComponent[$ck] = [];
                $workflowByComponent[$ck][$stage] = strtolower(trim((string)($row['status'] ?? '')));
            }
        }

        foreach ($components as $component) {
            $componentKey = norm_component_key((string)($component['component_key'] ?? ''));
            $isRequired = isset($component['is_required']) ? (int)$component['is_required'] : 1;
            if ($componentKey === '' || $isRequired !== 1) continue;

            $pendingItemsSummary['totalRequired']++;

            $workflow = $workflowByComponent[$componentKey] ?? [];
            $stageLabel = compute_component_stage_label([
                'candidate' => $workflow['candidate'] ?? '',
                'validator' => $workflow['validator'] ?? '',
                'verifier' => $workflow['verifier'] ?? '',
                'qa' => $workflow['qa'] ?? '',
            ]);

            $componentStatus = strtolower(trim((string)($component['status'] ?? 'pending')));
            $isDone = in_array($stageLabel, ['Completed', 'QA Rejected'], true);
            $isRejected = str_contains(strtolower($stageLabel), 'rejected') || $componentStatus === 'rejected';
            $isHold = $componentStatus === 'hold';

            if ($isRejected) $pendingItemsSummary['rejectedCount']++;
            if ($isHold) $pendingItemsSummary['holdCount']++;
            if (!$isDone && !$isRejected) {
                $pendingItemsSummary['pendingCount']++;
                $pendingItemsSummary['items'][] = [
                    'componentKey' => $componentKey,
                    'stageLabel' => $stageLabel,
                    'assignedRole' => ($component['assigned_role'] ?? null) !== '' ? (string)$component['assigned_role'] : null,
                    'assignedUserId' => isset($component['assigned_user_id']) && (int)$component['assigned_user_id'] > 0
                        ? (int)$component['assigned_user_id']
                        : null,
                ];
            }
        }
    } catch (Throwable $e) {
        // Leave pendingItemsSummary as a safe empty summary if component tables are unavailable.
    }

    $lastTimelineEvent = null;
    try {
        $timelineStmt = $pdo->prepare(
            'SELECT t.created_at, t.event_type, t.section_key, t.message, t.actor_role, t.actor_user_id,
                    u.first_name, u.last_name
               FROM Vati_Payfiller_Case_Timeline t
               LEFT JOIN Vati_Payfiller_Users u ON u.user_id = t.actor_user_id
              WHERE t.application_id = ?
              ORDER BY t.created_at DESC, t.timeline_id DESC
              LIMIT 1'
        );
        $timelineStmt->execute([$applicationId]);
        $timeline = $timelineStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($timeline) {
            $actorName = trim((string)($timeline['first_name'] ?? '') . ' ' . (string)($timeline['last_name'] ?? ''));
            $lastTimelineEvent = [
            'at' => integration_iso_datetime($timeline['created_at'] ?? ''),
                'eventType' => (string)($timeline['event_type'] ?? ''),
                'sectionKey' => (string)($timeline['section_key'] ?? ''),
                'message' => (string)($timeline['message'] ?? ''),
                'actorRole' => (string)($timeline['actor_role'] ?? ''),
                'actorUserId' => isset($timeline['actor_user_id']) && (int)$timeline['actor_user_id'] > 0
                    ? (int)$timeline['actor_user_id']
                    : null,
                'actorName' => $actorName !== '' ? $actorName : null,
            ];
        }
    } catch (Throwable $e) {
        $lastTimelineEvent = null;
    }

    $tatConfig = [
        'clientInternalTatDays' => isset($case['internal_tat']) && $case['internal_tat'] !== null
            ? (int)$case['internal_tat']
            : null,
        'weekendRules' => (string)($case['weekend_rules'] ?? ''),
    ];

    $links = integration_deep_links((string)($case['application_id'] ?? $applicationId), $caseId > 0 ? $caseId : null);
    $includeDebug = strtolower(get_str('include_debug', '0')) === '1';
    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'applicationId' => integration_normalize_application_id((string)($case['application_id'] ?? $applicationId)),
            'caseId' => $caseId > 0 ? $caseId : null,
            'candidateName' => integration_nullable_string($candidateName),
            'candidateEmail' => integration_normalize_email((string)($case['candidate_email'] ?? '')),
            'currentStage' => (string)($case['current_stage'] ?? ''),
            'rawCaseStatus' => (string)($case['case_status'] ?? ''),
            'ownerSummary' => $ownerSummary,
            'pendingItemsSummary' => $pendingItemsSummary,
            'lastTimelineEvent' => $lastTimelineEvent,
            'tatConfig' => $tatConfig,
            'applicationUrl' => $links['applicationUrl'],
            'candidateUrl' => $links['candidateUrl'],
            'timelineUrl' => $links['timelineUrl'],
            'workflowDebug' => $includeDebug ? [
                'authType' => auth_user_id() > 0 ? 'session' : (integration_is_valid_service_token() ? 'service_token' : 'unknown'),
                'requestedApplicationId' => $applicationId,
            ] : null,
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
