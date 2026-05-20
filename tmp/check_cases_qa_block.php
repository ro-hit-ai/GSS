<?php
require 'config/db.php';
$pdo = getDB();
$cases = [197,193,199];
$out = [];
foreach ($cases as $caseId) {
  $st = $pdo->prepare("SELECT case_id, application_id, case_status, workflow_version FROM Vati_Payfiller_Cases WHERE case_id = ?");
  $st->execute([$caseId]);
  $case = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$case) continue;
  $app = $case['application_id'];
  $q1 = $pdo->prepare("SELECT id, group_key, status, assigned_user_id, dedicated_user_id, claimed_at, completed_at FROM Vati_Payfiller_Verifier_Group_Queue WHERE case_id = ? ORDER BY id ASC");
  $q1->execute([$caseId]);
  $vrq = $q1->fetchAll(PDO::FETCH_ASSOC);
  $q2 = $pdo->prepare("SELECT component_key, stage, status, completed_at, updated_at, updated_by_role, updated_by_user_id FROM Vati_Payfiller_Case_Component_Workflow WHERE case_id = ? ORDER BY component_key, stage");
  $q2->execute([$caseId]);
  $wf = $q2->fetchAll(PDO::FETCH_ASSOC);
  $out[$caseId] = ['case'=>$case,'vrq'=>$vrq,'wf'=>$wf];
}
echo json_encode($out, JSON_PRETTY_PRINT);
?>
