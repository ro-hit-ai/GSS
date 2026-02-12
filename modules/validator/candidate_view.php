<?php
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
