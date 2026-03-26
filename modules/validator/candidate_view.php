<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login('validator');
auth_session_start();

$applicationId = isset($_GET['application_id']) ? trim((string)$_GET['application_id']) : '';
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$caseId = isset($_GET['case_id']) ? (int)$_GET['case_id'] : 0;

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

// Direct-open support from Candidate List:
// claim queue item first so report assignment checks do not fail.
$userId = (int)($_SESSION['auth_user_id'] ?? 0);
if ($userId > 0) {
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
            $claim = $pdo->prepare('CALL SP_Vati_Payfiller_VAL_ClaimCase(?, ?)');
            $claim->execute([$userId, $caseId]);
            while ($claim->nextRowset()) {
            }
        }
    } catch (Throwable $e) {
        // Ignore claim failures and continue; reportS API remains authoritative.
    }
}

$target = '../shared/candidate_report.php?role=validator&fullscreen=0';
if ($applicationId !== '') {
    $target .= '&application_id=' . urlencode($applicationId);
} elseif ($caseId > 0) {
    $target .= '&case_id=' . urlencode((string)$caseId);
}

if ($clientId > 0) {
    $target .= '&client_id=' . urlencode((string)$clientId);
}

header('Location: ' . $target);
exit;
