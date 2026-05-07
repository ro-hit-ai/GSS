<?php
require __DIR__ . '/../config/db.php';
$pdo=getDB();
$app='APP-20260505140839550';
$st=$pdo->prepare("SELECT case_id,application_id,case_status,workflow_version FROM Vati_Payfiller_Cases WHERE application_id=? LIMIT 1");
$st->execute([$app]);
$c=$st->fetch(PDO::FETCH_ASSOC)?:[];
if(!$c){echo "No case\n";exit;}
$cid=(int)$c['case_id'];

echo "CASE\t".json_encode($c,JSON_UNESCAPED_SLASHES)."\n";

$w=$pdo->prepare("SELECT component_key,stage,status FROM Vati_Payfiller_Case_Component_Workflow WHERE case_id=? ORDER BY component_key,stage");
$w->execute([$cid]);
foreach($w as $r){echo "WF\t".$r['component_key']."\t".$r['stage']."\t".$r['status']."\n";}

$q1=$pdo->prepare("SELECT case_id,application_id,status,assigned_user_id,claimed_at,completed_at FROM Vati_Payfiller_Validator_Queue WHERE case_id=?");
$q1->execute([$cid]);
foreach($q1 as $r){echo "VQ\t".json_encode($r,JSON_UNESCAPED_SLASHES)."\n";}

$q2=$pdo->prepare("SELECT case_id,application_id,group_key,status,assigned_user_id,claimed_at,completed_at FROM Vati_Payfiller_Verifier_Group_Queue WHERE case_id=? ORDER BY group_key");
$q2->execute([$cid]);
foreach($q2 as $r){echo "VGQ\t".json_encode($r,JSON_UNESCAPED_SLASHES)."\n";}
