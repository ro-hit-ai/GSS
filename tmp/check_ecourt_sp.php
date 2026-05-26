<?php
$cfg=parse_ini_file('config/db_config.txt');
$pdo=new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['dbname'].';charset='.$cfg['charset'],$cfg['username'],$cfg['password']);
$sql="SELECT SPECIFIC_NAME,ORDINAL_POSITION,PARAMETER_MODE,PARAMETER_NAME,DTD_IDENTIFIER FROM information_schema.parameters WHERE SPECIFIC_SCHEMA=DATABASE() AND SPECIFIC_NAME='SP_Vati_Payfiller_save_ecourt_details' ORDER BY ORDINAL_POSITION";
$stmt=$pdo->query($sql);
foreach($stmt as $r){ echo $r['ORDINAL_POSITION'].'|'.$r['PARAMETER_MODE'].'|'.$r['PARAMETER_NAME'].'|'.$r['DTD_IDENTIFIER'].PHP_EOL; }
?>
