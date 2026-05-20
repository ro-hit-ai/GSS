<?php
require 'config/db.php';
$pdo = getDB();
$app = 'APP-20260520075926356';
$tables = [
 ['basic','SELECT profile_photo AS file1, photo_path AS file2, photo AS file3, candidate_photo AS file4, created_at FROM Vati_Payfiller_BasicDetails WHERE application_id = ? LIMIT 1'],
 ['contact','SELECT proof_file AS file1, address_proof_file AS file2, address_proof AS file3, proof_document AS file4, created_at FROM Vati_Payfiller_Current_Address WHERE application_id = ? LIMIT 1']
];
$out=[];
foreach($tables as [$name,$sql]){ $st=$pdo->prepare($sql); $st->execute([$app]); $out[$name]=$st->fetch(PDO::FETCH_ASSOC); }
echo json_encode($out, JSON_PRETTY_PRINT);
