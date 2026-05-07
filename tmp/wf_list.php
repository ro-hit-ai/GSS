<?php
require __DIR__ . '/../config/db.php';
$pdo=getDB();
$st=$pdo->query("SELECT application_id,case_id,case_status,workflow_version FROM Vati_Payfiller_Cases ORDER BY case_id DESC LIMIT 20");
foreach($st as $r){echo implode("\t",[$r['application_id'],$r['case_id'],$r['case_status'],$r['workflow_version']])."\n";}
