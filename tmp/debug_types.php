<?php
require "config/db.php";
$pdo=getDB();
$st=$pdo->prepare("SELECT verification_type_id,type_name,type_category,is_active FROM Vati_Payfiller_Verification_Types WHERE LOWER(type_name) LIKE '%world%' OR LOWER(type_name) LIKE '%manupatra%' OR LOWER(type_name) LIKE '%judis%' OR LOWER(type_name) LIKE '%social%' OR LOWER(type_name) LIKE '%court%' ORDER BY verification_type_id");
$st->execute();
$rows=$st->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT), PHP_EOL;
?>
