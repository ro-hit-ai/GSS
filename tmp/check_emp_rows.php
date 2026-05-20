<?php
require 'config/db.php';
$pdo=getDB();
$app='APP-20260520075926356';
$st=$pdo->prepare('SELECT application_id,employment_index,currently_employed,joining_date,relieving_date,employment_doc_type,updated_at FROM Vati_Payfiller_Candidate_Employment_details WHERE application_id=? ORDER BY employment_index');
$st->execute([$app]);
while($r=$st->fetch(PDO::FETCH_ASSOC)){echo json_encode($r, JSON_UNESCAPED_SLASHES)."\n";}
