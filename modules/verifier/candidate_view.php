<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../api/shared/verifier_case_queue.php';
auth_require_login('verifier');
auth_session_start();

$applicationId = isset($_GET['application_id']) ? trim((string)$_GET['application_id']) : '';
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$caseId = isset($_GET['case_id']) ? (int)$_GET['case_id'] : 0;
$fromBoard = isset($_GET['board']) && (string)$_GET['board'] === '1';
$view = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : '';
$filter = isset($_GET['filter']) ? strtolower(trim((string)$_GET['filter'])) : '';
$priorityBucket = isset($_GET['priority_bucket']) ? strtolower(trim((string)$_GET['priority_bucket'])) : '';
$reportMode = isset($_GET['report_mode']) ? strtolower(trim((string)$_GET['report_mode'])) : '';
$allowedViews = ['mine', 'available', 'claimable', 'active', 'all', 'followup', 'participated', 'history', 'completed'];
$allowedFilters = ['all', 'active_work', 'awaiting_evaluation', 'waiting_candidate', 'evaluated', 'reopened', 'downstream_processing', 'review_complete'];
$allowedPriorityBuckets = ['p1', 'p2', 'p3'];
$allowedReportModes = ['readonly'];

if ($applicationId === '' && $caseId > 0) {
    require_once __DIR__ . '/../../config/db.php';
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT application_id, client_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            $applicationId = isset($row['application_id']) ? trim((string)$row['application_id']) : '';
            if ($clientId <= 0 && isset($row['client_id'])) {
                $clientId = (int)$row['client_id'];
            }
        }
    } catch (Throwable $e) {
    }
}

if ($applicationId === '' && $caseId <= 0) {
    header('Location: dashboard.php');
    exit;
}

// Direct-open support from legacy list routes: auto-claim case only when not coming
// from the board. Board flow is explicit claim-first, open-second.
$userId = (int)($_SESSION['auth_user_id'] ?? 0);
if (!$fromBoard && $reportMode !== 'readonly' && $userId > 0) {
    require_once __DIR__ . '/../../config/db.php';
    try {
        $pdo = getDB();

        if ($caseId <= 0 && $applicationId !== '') {
            $stmt = $pdo->prepare('SELECT case_id, client_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
            $stmt->execute([$applicationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                $caseId = isset($row['case_id']) ? (int)$row['case_id'] : 0;
                if ($clientId <= 0 && isset($row['client_id'])) {
                    $clientId = (int)$row['client_id'];
                }
            }
        }

        if ($caseId > 0) {
            verifier_case_queue_claim($pdo, $caseId, $userId);
        }
    } catch (Throwable $e) {
        // Ignore claim errors and continue; assignment check in report API is authoritative.
    }
}

$target = '../shared/candidate_report.php?role=verifier&fullscreen=0';
if ($applicationId !== '') {
    $target .= '&application_id=' . urlencode($applicationId);
} elseif ($caseId > 0) {
    $target .= '&case_id=' . urlencode((string)$caseId);
}

if ($clientId > 0) {
    $target .= '&client_id=' . urlencode((string)$clientId);
}

if (in_array($priorityBucket, $allowedPriorityBuckets, true)) {
    $target .= '&priority_bucket=' . urlencode($priorityBucket);
}

if (in_array($reportMode, $allowedReportModes, true)) {
    $target .= '&report_mode=' . urlencode($reportMode);
}

if (in_array($view, $allowedViews, true)) {
    $_SESSION['verifier_last_list_view'] = $view;
    $target .= '&view=' . urlencode($view);
    $target .= '&list_view=' . urlencode($view);
} elseif (!empty($_SESSION['verifier_last_list_view']) && in_array((string)$_SESSION['verifier_last_list_view'], $allowedViews, true)) {
    $lastView = (string)$_SESSION['verifier_last_list_view'];
    $target .= '&view=' . urlencode($lastView);
    $target .= '&list_view=' . urlencode($lastView);
}
if (in_array($filter, $allowedFilters, true)) {
    $_SESSION['verifier_last_list_filter'] = $filter;
    $target .= '&filter=' . urlencode($filter);
    $target .= '&list_filter=' . urlencode($filter);
} elseif (!empty($_SESSION['verifier_last_list_filter']) && in_array((string)$_SESSION['verifier_last_list_filter'], $allowedFilters, true)) {
    $lastFilter = (string)$_SESSION['verifier_last_list_filter'];
    $target .= '&filter=' . urlencode($lastFilter);
    $target .= '&list_filter=' . urlencode($lastFilter);
}

header('Location: ' . $target);
exit;
