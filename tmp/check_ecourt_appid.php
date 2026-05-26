<?php
$cfg=parse_ini_file('config/db_config.txt');
$pdo=new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['dbname'].';charset='.$cfg['charset'],$cfg['username'],$cfg['password']);
$app='APP-20260521150410320';
$st=$pdo->prepare('SELECT application_id,current_address,permanent_address,evidence_document,period_from_date,period_to_date,period_duration_years,dob,verification_status,created_at,updated_at FROM Vati_Payfiller_Candidate_Ecourt_Details WHERE application_id=?');
$st->execute([$app]);
$row=$st->fetch(PDO::FETCH_ASSOC);
if(!$row){echo "NO_ROW\n"; exit;}
foreach($row as $k=>$v){echo $k.'='.$v.PHP_EOL;}
?>
