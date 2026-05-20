<?php
require 'config/db.php';
require 'api/shared/workflow/WorkflowRepository.php';
$pdo = getDB();
$pdo->prepare("UPDATE Vati_Payfiller_Verifier_Group_Queue SET assigned_user_id = NULL, claimed_at = NULL, status = 'pending' WHERE case_id = ? AND completed_at IS NULL")->execute([197]);
$repo = new WorkflowRepository($pdo);
foreach (['BASIC','EDUCATION','ADDITIONAL'] as $g) {
    $repo->ensureVerifierGroupQueueRow(197, $g);
}
$rows = $pdo->query("SELECT id, case_id, application_id, group_key, status, assigned_user_id, dedicated_user_id, claimed_at, completed_at FROM Vati_Payfiller_Verifier_Group_Queue WHERE case_id = 197 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
?>
