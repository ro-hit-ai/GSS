<?php
require 'config/db.php';
$pdo = getDB();
$caseId = 197;
$app = 'APP-20260519101316728';
$out = [];
$out['case'] = $pdo->query("SELECT case_id, application_id, client_id, case_status, workflow_version, dbv_assigned_user_id, dbv_claimed_at, dbv_completed_at FROM Vati_Payfiller_Cases WHERE case_id = 197")->fetch(PDO::FETCH_ASSOC);
$out['vr_queue'] = $pdo->query("SELECT id, group_key, status, assigned_user_id, dedicated_user_id, claimed_at, completed_at FROM Vati_Payfiller_Verifier_Group_Queue WHERE case_id = 197 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$out['workflow'] = $pdo->query("SELECT component_key, stage, status, actor_user_id, completed_at, invalidated_at FROM Vati_Payfiller_Case_Component_Workflow WHERE case_id = 197 ORDER BY component_key, FIELD(stage,'validator','verifier','qa'), id DESC")->fetchAll(PDO::FETCH_ASSOC);
$out['components'] = $pdo->query("SELECT component_key, assigned_role, assigned_user_id, status, completed_at FROM Vati_Payfiller_Case_Components WHERE case_id = 197 ORDER BY component_key")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($out, JSON_PRETTY_PRINT);
?>
