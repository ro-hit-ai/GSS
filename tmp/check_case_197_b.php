<?php
require 'config/db.php';
$pdo = getDB();
$out = [];
$out['wf_cols'] = $pdo->query("SHOW COLUMNS FROM Vati_Payfiller_Case_Component_Workflow")->fetchAll(PDO::FETCH_ASSOC);
$out['case'] = $pdo->query("SELECT case_id, application_id, client_id, case_status, workflow_version, dbv_assigned_user_id, dbv_claimed_at, dbv_completed_at FROM Vati_Payfiller_Cases WHERE case_id = 197")->fetch(PDO::FETCH_ASSOC);
$out['vr_queue'] = $pdo->query("SELECT id, group_key, status, assigned_user_id, dedicated_user_id, claimed_at, completed_at FROM Vati_Payfiller_Verifier_Group_Queue WHERE case_id = 197 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$out['workflow'] = $pdo->query("SELECT * FROM Vati_Payfiller_Case_Component_Workflow WHERE case_id = 197 ORDER BY component_key, stage, workflow_id DESC")->fetchAll(PDO::FETCH_ASSOC);
out($out);
function out($x){echo json_encode($x, JSON_PRETTY_PRINT);} 
?>
