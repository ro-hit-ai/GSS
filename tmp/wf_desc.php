<?php
require __DIR__ . '/../config/db.php';
$pdo=getDB();
$st=$pdo->query("SHOW COLUMNS FROM Vati_Payfiller_Workflow_Transitions");
foreach($st as $r){echo $r['Field']."\t".$r['Type']."\n";}
