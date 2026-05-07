<?php
require __DIR__ . '/../config/db.php';
$pdo=getDB();
$app='APP-20260505140839550';
$st=$pdo->prepare("SELECT c.case_id,c.application_id,c.case_status,c.workflow_version,cc.component_key,cc.status as cc_status,w.stage,w.status FROM Vati_Payfiller_Cases c JOIN Vati_Payfiller_Case_Components cc ON cc.case_id=c.case_id LEFT JOIN Vati_Payfiller_Case_Component_Workflow w ON w.case_id=c.case_id AND LOWER(TRIM(w.component_key))=LOWER(TRIM(cc.component_key)) WHERE c.application_id=? ORDER BY cc.component_key,w.stage");
$st->execute([$app]);
foreach($st as $r){echo implode("\t",[$r['case_id'],$r['application_id'],$r['case_status'],$r['workflow_version'],$r['component_key'],$r['cc_status'],$r['stage'],$r['status']])."\n";}
