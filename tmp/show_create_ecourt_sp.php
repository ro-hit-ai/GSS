<?php
$cfg=parse_ini_file('config/db_config.txt');
$pdo=new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['dbname'].';charset='.$cfg['charset'],$cfg['username'],$cfg['password']);
$stmt=$pdo->query("SHOW CREATE PROCEDURE SP_Vati_Payfiller_save_ecourt_details");
$row=$stmt->fetch(PDO::FETCH_ASSOC);
echo $row['Create Procedure'] ?? '';
?>
