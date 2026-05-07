<?php
require __DIR__ . '/../config/db.php';
$pdo=getDB();
$st=$pdo->query("SHOW CREATE PROCEDURE SP_Vati_Payfiller_save_employment_details");
$row=$st->fetch(PDO::FETCH_ASSOC);
print($row['Create Procedure'] ?? 'not found');
