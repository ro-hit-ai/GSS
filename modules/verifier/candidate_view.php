<?php
require_once __DIR__ . '/../../includes/auth.php';
$applicationId = isset($_GET['application_id']) ? trim((string)$_GET['application_id']) : '';
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$group = isset($_GET['group']) ? trim((string)$_GET['group']) : '';
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

// Direct-open support from Candidate List: auto-claim case when group is provided.
// This prevents Forbidden when opening an available row that wasn't claimed yet.
auth_session_start();
$userId = (int)($_SESSION['auth_user_id'] ?? 0);
$groupKey = strtoupper(trim((string)$group));
if ($userId > 0 && in_array($groupKey, ['BASIC', 'EDUCATION'], true)) {
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
            $claim = $pdo->prepare('CALL SP_Vati_Payfiller_VR_ClaimCase(?, ?, ?)');
            $claim->execute([$userId, $caseId, $groupKey]);
            while ($claim->nextRowset()) {
            }

            // Keep queue row state consistent for dashboard/list counters on direct-open flow.
            try {
                $sync = $pdo->prepare(
                    "UPDATE Vati_Payfiller_Verifier_Group_Queue
                     SET assigned_user_id = COALESCE(assigned_user_id, ?),
                         claimed_at = COALESCE(claimed_at, NOW()),
                         status = CASE
                             WHEN COALESCE(LOWER(TRIM(status)), '') = 'followup' THEN status
                             WHEN completed_at IS NULL THEN 'in_progress'
                             ELSE status
                         END
                     WHERE case_id = ?
                       AND UPPER(TRIM(group_key)) = ?
                       AND completed_at IS NULL"
                );
                $sync->execute([$userId, $caseId, $groupKey]);
            } catch (Throwable $e) {
            }
        }
    } catch (Throwable $e) {
        // Ignore claim errors and continue; assignment check in report API is authoritative.
    }
}

$target = '../shared/candidate_report.php?role=verifier';
if ($applicationId !== '') {
    $target .= '&application_id=' . urlencode($applicationId);
} elseif ($caseId > 0) {
    $target .= '&case_id=' . urlencode((string)$caseId);
}

if ($clientId > 0) {
    $target .= '&client_id=' . urlencode((string)$clientId);
}

if ($group !== '') {
    $target .= '&group=' . urlencode($group);
}

header('Location: ' . $target);
exit;
