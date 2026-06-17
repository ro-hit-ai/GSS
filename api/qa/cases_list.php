<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../shared/workflow/workflow_status_semantics.php';
require_once __DIR__ . '/../shared/workflow/workflow_semantics.php';
require_once __DIR__ . '/../shared/governance/operational_status_governance.php';
auth_require_any_access(['qa', 'team_lead']);
auth_session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function qa_cases_debug_log(string $event, array $data = []): void
{
    if ((string)getenv('WF_STATUS_DEBUG_LOGS') !== '1') return;
    $entry = ['ts' => date('c'), 'event' => $event, 'data' => $data];
    @file_put_contents(__DIR__ . '/../../logs/qa_operational_status.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
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
    $status = get_str('status', '');
    $from = get_str('from', '');
    $to = get_str('to', '');
    $view = strtolower(get_str('view', 'ready')); // ready|all|pending|completed
    $debug = get_str('debug', '') === '1';

    $validatorUserId = get_int('validator_user_id', 0);
    $verifierUserId = get_int('verifier_user_id', 0);
    $verifierGroup = strtoupper(get_str('verifier_group', ''));

    $role = strtolower((string)($_SESSION['auth_moduleAccess'] ?? 'qa'));
    if ($role !== 'team_lead') {
        // QA role only sees cases that are ready for QA.
        $view = 'ready';
        $validatorUserId = 0;
        $verifierUserId = 0;
        $verifierGroup = '';
    }

    if (!in_array($view, ['ready', 'all', 'pending', 'completed'], true)) {
        $view = 'ready';
    }

    $allowedVerifierGroups = array_values(array_filter(array_keys(wf_verifier_group_map()), static function ($g) {
        return $g !== '';
    }));
    if ($verifierGroup !== '' && !in_array($verifierGroup, $allowedVerifierGroups, true)) {
        $verifierGroup = '';
    }

    $pdo = getDB();

    $sql = "SELECT 
                c.case_id,
                c.client_id,
                cl.customer_name,
                c.application_id,
                c.candidate_first_name,
                c.candidate_last_name,
                c.candidate_email,
                c.candidate_mobile,
                c.case_status,
                c.created_at,
                (SELECT q.assigned_user_id
                   FROM Vati_Payfiller_Validator_Queue q
                  WHERE q.case_id = c.case_id
                  ORDER BY q.id DESC
                  LIMIT 1) AS validator_user_id,
                (SELECT TRIM(CONCAT(u.first_name, ' ', u.last_name))
                   FROM Vati_Payfiller_Validator_Queue q
                   JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id
                  WHERE q.case_id = c.case_id
                  ORDER BY q.id DESC
                  LIMIT 1) AS validator_assigned_name,
                (SELECT q.assigned_user_id
                   FROM Vati_Payfiller_Verifier_Group_Queue q
                  WHERE q.case_id = c.case_id AND q.group_key = 'BASIC'
                  ORDER BY q.id DESC
                  LIMIT 1) AS verifier_basic_user_id,
                (SELECT TRIM(CONCAT(u.first_name, ' ', u.last_name))
                   FROM Vati_Payfiller_Verifier_Group_Queue q
                   JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id
                  WHERE q.case_id = c.case_id AND q.group_key = 'BASIC'
                  ORDER BY q.id DESC
                  LIMIT 1) AS verifier_basic_assigned_name,
                (SELECT q.assigned_user_id
                   FROM Vati_Payfiller_Verifier_Group_Queue q
                  WHERE q.case_id = c.case_id AND q.group_key = 'EDUCATION'
                  ORDER BY q.id DESC
                  LIMIT 1) AS verifier_education_user_id,
                (SELECT TRIM(CONCAT(u.first_name, ' ', u.last_name))
                   FROM Vati_Payfiller_Verifier_Group_Queue q
                   JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id
                  WHERE q.case_id = c.case_id AND q.group_key = 'EDUCATION'
                  ORDER BY q.id DESC
                  LIMIT 1) AS verifier_education_assigned_name,
                (CASE
                    WHEN EXISTS (SELECT 1 FROM Vati_Payfiller_Validator_Queue vq WHERE vq.case_id = c.case_id AND vq.completed_at IS NOT NULL) THEN 1
                    ELSE 0
                END) AS is_validator_done,
                (CASE
                    WHEN EXISTS (
                        SELECT 1
                          FROM Vati_Payfiller_Verifier_Group_Queue q
                         WHERE q.case_id = c.case_id
                         GROUP BY q.case_id
                        HAVING SUM(CASE WHEN q.completed_at IS NULL THEN 1 ELSE 0 END) = 0
                    ) THEN 1
                    ELSE 0
                END) AS is_verifier_done,
                (CASE
                    WHEN EXISTS (
                        SELECT 1
                          FROM Vati_Payfiller_Case_Component_Workflow w
                         WHERE w.case_id = c.case_id
                           AND LOWER(TRIM(COALESCE(w.stage, ''))) = 'verifier'
                           AND LOWER(TRIM(COALESCE(w.status, ''))) IN ('approved','rejected','hold','insufficient_documents','completed','clear','verified')
                    ) THEN 1
                    ELSE 0
                END) AS has_verifier_progress
            FROM Vati_Payfiller_Cases c
            LEFT JOIN Vati_Payfiller_Clients cl ON cl.client_id = c.client_id
            WHERE 1=1";

    $params = [];

    if ($view === 'ready') {
        $sql .= " AND EXISTS (SELECT 1 FROM Vati_Payfiller_Validator_Queue vq WHERE vq.case_id = c.case_id AND vq.completed_at IS NOT NULL)";
        $sql .= " AND EXISTS (SELECT 1 FROM Vati_Payfiller_Verifier_Group_Queue q WHERE q.case_id = c.case_id)";
        // Controlled collaborative overlap:
        // QA may begin once verifier has produced authoritative progress,
        // even if some verifier group rows remain open for later completion.
        $sql .= " AND EXISTS (
                        SELECT 1
                          FROM Vati_Payfiller_Case_Component_Workflow w
                         WHERE w.case_id = c.case_id
                           AND LOWER(TRIM(COALESCE(w.stage, ''))) = 'verifier'
                           AND LOWER(TRIM(COALESCE(w.status, ''))) IN ('approved','rejected','hold','insufficient_documents','completed','clear','verified')
                  )";
    } elseif ($view === 'completed') {
        $sql .= " AND UPPER(TRIM(c.case_status)) IN ('APPROVED','VERIFIED','COMPLETED','CLEAR')";
    } elseif ($view === 'pending') {
        $sql .= " AND (UPPER(TRIM(c.case_status)) NOT IN ('APPROVED','VERIFIED','COMPLETED','CLEAR') OR c.case_status IS NULL OR TRIM(c.case_status) = '')";
    }

    if ($validatorUserId > 0) {
        $sql .= " AND EXISTS (SELECT 1 FROM Vati_Payfiller_Validator_Queue vq2 WHERE vq2.case_id = c.case_id AND vq2.assigned_user_id = ?)";
        $params[] = $validatorUserId;
    }

    if ($verifierUserId > 0) {
        $sql .= " AND EXISTS (SELECT 1 FROM Vati_Payfiller_Verifier_Group_Queue q2 WHERE q2.case_id = c.case_id AND q2.assigned_user_id = ?";
        $params[] = $verifierUserId;
        if ($verifierGroup !== '') {
            $sql .= " AND q2.group_key = ?";
            $params[] = $verifierGroup;
        }
        $sql .= ")";
    } elseif ($verifierGroup !== '') {
        $sql .= " AND EXISTS (SELECT 1 FROM Vati_Payfiller_Verifier_Group_Queue q2 WHERE q2.case_id = c.case_id AND q2.group_key = ?)";
        $params[] = $verifierGroup;
    }

    if ($clientId > 0) {
        $sql .= " AND c.client_id = ?";
        $params[] = $clientId;
    }

    if ($status !== '') {
        $sql .= " AND UPPER(TRIM(c.case_status)) = UPPER(TRIM(?))";
        $params[] = $status;
    }

    if ($from !== '') {
        $sql .= " AND DATE(c.created_at) >= DATE(?)";
        $params[] = $from;
    }

    if ($to !== '') {
        $sql .= " AND DATE(c.created_at) <= DATE(?)";
        $params[] = $to;
    }

    if ($search !== '') {
        $sql .= " AND (
            c.candidate_first_name LIKE ? OR
            c.candidate_last_name LIKE ? OR
            c.candidate_email LIKE ? OR
            c.candidate_mobile LIKE ? OR
            c.application_id LIKE ? OR
            c.case_status LIKE ?
        )";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY c.created_at DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($view === 'ready' && (string)getenv('WF_STATUS_DEBUG_LOGS') === '1') {
        try {
            $candSql = "SELECT c.case_id, c.application_id, c.case_status,
                           (SELECT COUNT(*) FROM Vati_Payfiller_Validator_Queue vq WHERE vq.case_id = c.case_id AND vq.completed_at IS NOT NULL) AS validator_completed_rows,
                           (SELECT COUNT(*) FROM Vati_Payfiller_Verifier_Group_Queue q WHERE q.case_id = c.case_id) AS verifier_total_rows,
                           (SELECT COUNT(*) FROM Vati_Payfiller_Verifier_Group_Queue q WHERE q.case_id = c.case_id AND q.completed_at IS NULL) AS verifier_open_rows,
                           (SELECT COUNT(*) FROM Vati_Payfiller_Verifier_Group_Queue q WHERE q.case_id = c.case_id AND q.completed_at IS NOT NULL) AS verifier_completed_rows,
                           (SELECT COUNT(*) FROM Vati_Payfiller_Case_Component_Workflow w WHERE w.case_id = c.case_id AND LOWER(TRIM(COALESCE(w.stage,''))) = 'verifier' AND LOWER(TRIM(COALESCE(w.status,''))) IN ('approved','rejected','hold','insufficient_documents','completed','clear','verified')) AS verifier_progress_rows
                      FROM Vati_Payfiller_Cases c
                     WHERE 1=1";
            $candParams = [];
            if ($clientId > 0) {
                $candSql .= " AND c.client_id = ?";
                $candParams[] = $clientId;
            }
            if ($status !== '') {
                $candSql .= " AND UPPER(TRIM(c.case_status)) = UPPER(TRIM(?))";
                $candParams[] = $status;
            }
            if ($from !== '') {
                $candSql .= " AND DATE(c.created_at) >= DATE(?)";
                $candParams[] = $from;
            }
            if ($to !== '') {
                $candSql .= " AND DATE(c.created_at) <= DATE(?)";
                $candParams[] = $to;
            }
            if ($search !== '') {
                $candSql .= " AND (
                    c.candidate_first_name LIKE ? OR
                    c.candidate_last_name LIKE ? OR
                    c.candidate_email LIKE ? OR
                    c.candidate_mobile LIKE ? OR
                    c.application_id LIKE ? OR
                    c.case_status LIKE ?
                )";
                $like = '%' . $search . '%';
                $candParams[] = $like;
                $candParams[] = $like;
                $candParams[] = $like;
                $candParams[] = $like;
                $candParams[] = $like;
                $candParams[] = $like;
            }
            $candSql .= " ORDER BY c.created_at DESC LIMIT 500";
            $candStmt = $pdo->prepare($candSql);
            $candStmt->execute($candParams);
            $candidateRows = $candStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $included = [];
            foreach ($rows as $r) {
                $included[(int)($r['case_id'] ?? 0)] = true;
            }

            $excluded = [];
            foreach ($candidateRows as $c) {
                $cid = (int)($c['case_id'] ?? 0);
                if ($cid <= 0 || isset($included[$cid])) continue;
                $reasons = [];
                if ((int)($c['validator_completed_rows'] ?? 0) <= 0) $reasons[] = 'validator_not_completed';
                if ((int)($c['verifier_total_rows'] ?? 0) <= 0) $reasons[] = 'verifier_rows_missing';
                if ((int)($c['verifier_progress_rows'] ?? 0) <= 0) $reasons[] = 'verifier_progress_missing';
                $excluded[] = [
                    'case_id' => $cid,
                    'application_id' => (string)($c['application_id'] ?? ''),
                    'case_status' => (string)($c['case_status'] ?? ''),
                    'verifier_open_rows' => (int)($c['verifier_open_rows'] ?? 0),
                    'verifier_completed_rows' => (int)($c['verifier_completed_rows'] ?? 0),
                    'verifier_progress_rows' => (int)($c['verifier_progress_rows'] ?? 0),
                    'validator_completed_rows' => (int)($c['validator_completed_rows'] ?? 0),
                    'exclusion_reason' => $reasons,
                ];
            }

            qa_cases_debug_log('qa_cases_ready_exclusion', [
                'view' => 'ready',
                'included_count' => count($rows),
                'candidate_count' => count($candidateRows),
                'excluded_count' => count($excluded),
                'excluded_cases' => $excluded,
            ]);
        } catch (Throwable $e) {
            qa_cases_debug_log('qa_cases_ready_exclusion_failed', ['error' => $e->getMessage()]);
        }
    }

    // Role-based stage label + combined assignee display for TL
    if (!empty($rows)) {
        foreach ($rows as &$r) {
            $valDone = !empty($r['is_validator_done']) ? ((int)$r['is_validator_done'] === 1) : false;
            $vrDone = !empty($r['is_verifier_done']) ? ((int)$r['is_verifier_done'] === 1) : false;

            $vb = trim((string)($r['verifier_basic_assigned_name'] ?? ''));
            $ve = trim((string)($r['verifier_education_assigned_name'] ?? ''));

            $verifierAssigned = '';
            if ($vb !== '' && $ve !== '') {
                $verifierAssigned = 'BASIC: ' . $vb . ' | EDUCATION: ' . $ve;
            } elseif ($vb !== '') {
                $verifierAssigned = 'BASIC: ' . $vb;
            } elseif ($ve !== '') {
                $verifierAssigned = 'EDUCATION: ' . $ve;
            }
            $r['verifier_assigned_name'] = $verifierAssigned;

            if ($role === 'team_lead') {
                $caseStatusUpper = strtoupper(trim((string)($r['case_status'] ?? '')));
                $isCompleted = in_array($caseStatusUpper, ['APPROVED', 'VERIFIED', 'COMPLETED', 'CLEAR'], true);
                $isRejected = in_array($caseStatusUpper, ['REJECTED', 'STOP_BGV'], true);
                if ($isRejected) {
                    $r['current_stage'] = 'QA REJECTED';
                } elseif ($isCompleted) {
                    $r['current_stage'] = 'QA COMPLETED';
                } else {
                    $r['current_stage'] = ($valDone && $vrDone) ? 'Pending Ready' : 'Pending Not Ready';
                }
            } else {
                $r['current_stage'] = 'QA PENDING';
            }

            $caseStatus = strtolower(trim((string)($r['case_status'] ?? '')));
            $active = wf_is_active_queue_status($caseStatus) || in_array($caseStatus, ['pending_qa', 'qa_pending', 'pending_verifier', 'pending_validator'], true);
            $evaluated = wf_is_visible_historical_status($caseStatus) || wf_is_resolved_status($caseStatus);
            $r['is_active_work'] = $active ? 1 : 0;
            $r['evaluated_visible'] = $evaluated ? 1 : 0;
            $r['is_evaluated'] = $evaluated ? 1 : 0;
            $r['rejected_visible'] = ($evaluated && $caseStatus === 'rejected') ? 1 : 0;
            $r['visibility_class'] = $active ? 'active_work' : ($evaluated ? 'evaluated_history' : 'other');
        }
        unset($r);
        $rows = os_enrich_rows($pdo, $rows, 'qa');
        foreach ($rows as &$r) {
            $r['current_stage'] = (string)($r['stage_status_label'] ?? $r['operational_status_label'] ?? $r['current_stage'] ?? 'QA PENDING');
        }
        unset($r);
    }

    $resp = [
        'status' => 1,
        'message' => 'ok',
        'data' => $rows
    ];
    if ($debug) {
        $active = 0; $eval = 0; $rej = 0;
        foreach ($rows as $r) {
            if ((int)($r['is_active_work'] ?? 0) === 1) $active++;
            if ((int)($r['evaluated_visible'] ?? 0) === 1) $eval++;
            if ((int)($r['rejected_visible'] ?? 0) === 1) $rej++;
        }
        $resp['debug'] = [
            'active_queue_count' => $active,
            'evaluated_visible_count' => $eval,
            'rejected_visible_count' => $rej,
            'hidden_closed_count' => 0
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
