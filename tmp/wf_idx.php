<?php
require __DIR__ . '/../config/db.php';
$pdo=getDB();
$tables=['Vati_Payfiller_Case_Component_Workflow','Vati_Payfiller_Case_Components','Vati_Payfiller_Workflow_Transitions'];
foreach($tables as $t){
 echo "-- $t --\n";
 $st=$pdo->query("SHOW INDEX FROM $t");
 foreach($st as $r){echo $r['Key_name']."\t".$r['Column_name']."\n";}
}
