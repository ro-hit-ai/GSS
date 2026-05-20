<?php
require 'config/db.php';
require 'api/shared/workflow/WorkflowRepository.php';
$pdo = getDB();
$pdo->prepare("UPDATE Vati_Payfiller_Verifier_Group_Queue SET assigned_user_id = NULL, claimed_at = NULL, status = 'pending' WHERE case_id = ? AND completed_at IS NULL")->execute([197]);
$repo = new WorkflowRepository($pdo);
foreach (['BASIC','EDUCATION','ADDITIONAL'] as $g) {
    $repo->ensureVerifierGroupQueueRow(197, $g);
}
$rows = $pdo->query("SELECT id, group_key, status, assigned_user_id, dedicated_user_id, claimed_at, completed_at FROM Vati_Payfiller_Verifier_Group_Queue WHERE case_id = 197 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT user_id, username, allowed_sections FROM Vati_Payfiller_Users WHERE LOWER(TRIM(role))='verifier' AND is_active=1 ORDER BY user_id ASC")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['queue'=>$rows,'users'=>$users], JSON_PRETTY_PRINT);
?>
