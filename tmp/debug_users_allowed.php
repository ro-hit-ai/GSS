<?php
require "config/db.php";
$pdo=getDB();
$st=$pdo->query("SELECT user_id,client_id,username,first_name,last_name,email,role,allowed_sections,is_active FROM Vati_Payfiller_Users WHERE role IN ('validator','db_verifier','verifier') ORDER BY role,user_id");
$rows=$st->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT), PHP_EOL;
?>
