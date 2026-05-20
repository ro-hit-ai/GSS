<?php
require 'config/db.php';
$pdo = getDB();
$queue = $pdo->query("SELECT id,case_id,client_id,application_id,group_key,status,assigned_user_id,dedicated_user_id,claimed_at,completed_at FROM Vati_Payfiller_Verifier_Group_Queue WHERE case_id=197 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$cols = $pdo->query("SHOW COLUMNS FROM Vati_Payfiller_VR_Assignment_Rules")->fetchAll(PDO::FETCH_ASSOC);
$rules = $pdo->query("SELECT * FROM Vati_Payfiller_VR_Assignment_Rules WHERE client_id = 3 OR client_id IS NULL ORDER BY group_key")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['queue'=>$queue,'columns'=>$cols,'rules'=>$rules], JSON_PRETTY_PRINT);
?>
