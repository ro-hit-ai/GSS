<?php
require "config/db.php";
$pdo=getDB();
$app="APP-20260323113202225";
$q=$pdo->prepare("SELECT case_id,client_id,job_role,application_id FROM Vati_Payfiller_Cases WHERE application_id=? LIMIT 1");
$q->execute([$app]);
$r=$q->fetch(PDO::FETCH_ASSOC);
echo "CASE\n";
echo json_encode($r, JSON_PRETTY_PRINT), PHP_EOL;

$q2=$pdo->prepare("SELECT component_key,is_required,assigned_role,assigned_user_id,status FROM Vati_Payfiller_Case_Components WHERE application_id=? ORDER BY component_key");
$q2->execute([$app]);
$r2=$q2->fetchAll(PDO::FETCH_ASSOC);
echo "COMPONENTS\n";
echo json_encode($r2, JSON_PRETTY_PRINT), PHP_EOL;

foreach(["SP_Vati_Payfiller_get_social_media_details","SP_Vati_Payfiller_get_ecourt_details"] as $sp){
  try{
    $st=$pdo->prepare("CALL ".$sp."(?)");
    $st->execute([$app]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    while($st->nextRowset()){}
    echo $sp,": ",json_encode($row),PHP_EOL;
  } catch(Throwable $e){
    echo $sp,": ERR ",$e->getMessage(),PHP_EOL;
  }
}
?>
