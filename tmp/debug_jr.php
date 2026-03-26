<?php
require "config/db.php";
$pdo=getDB();
$clientId=3; $role='SDE78';
$jr=$pdo->prepare("SELECT job_role_id, role_name FROM Vati_Payfiller_Job_Roles WHERE client_id=? AND LOWER(TRIM(role_name))=LOWER(TRIM(?)) LIMIT 1");
$jr->execute([$clientId,$role]);
$r=$jr->fetch(PDO::FETCH_ASSOC);
echo "JOBROLE\n"; echo json_encode($r, JSON_PRETTY_PRINT), PHP_EOL;
if($r){
  $id=(int)$r['job_role_id'];
  $q=$pdo->prepare("SELECT j.verification_type_id,j.required_count,j.is_enabled,j.sort_order,t.type_name,t.type_category FROM Vati_Payfiller_Job_Role_Verification_Types j JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id=j.verification_type_id WHERE j.job_role_id=? ORDER BY j.sort_order, j.verification_type_id");
  $q->execute([$id]);
  $rows=$q->fetchAll(PDO::FETCH_ASSOC);
  echo "JR_TYPES\n"; echo json_encode($rows, JSON_PRETTY_PRINT), PHP_EOL;
}
?>
