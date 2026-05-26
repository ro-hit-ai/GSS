<?php
$cfg=parse_ini_file('config/db_config.txt');
$pdo=new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['dbname'].';charset='.$cfg['charset'],$cfg['username'],$cfg['password']);
$app='APP-20260520075926356';
$sql='SELECT application_id,current_address,permanent_address,evidence_document,period_from_date,period_to_date,period_duration_years,dob,updated_at FROM Vati_Payfiller_Candidate_Ecourt_Details WHERE application_id=?';
$st=$pdo->prepare($sql);$st->execute([$app]);$r=$st->fetch(PDO::FETCH_ASSOC);var_export($r);
?>
