<?php
require __DIR__ . '/../config/db.php';
$pdo=getDB();
$app='APP-20260505140839550';
$st=$pdo->prepare("SELECT component_key,assigned_role,assigned_user_id,status,is_required FROM Vati_Payfiller_Case_Components WHERE application_id=? ORDER BY component_key");
$st->execute([$app]);
foreach($st as $r){echo implode("\t",[$r['component_key'],$r['assigned_role'],$r['assigned_user_id'],$r['status'],$r['is_required']])."\n";}
