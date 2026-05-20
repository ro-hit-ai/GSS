<?php
require 'config/db.php';
$pdo=getDB();
foreach (['SP_Vati_Payfiller_get_employment_details','SP_Vati_Payfiller_save_employment_details'] as $name) {
  $st=$pdo->prepare("SHOW CREATE PROCEDURE $name");
  $st->execute();
  $row=$st->fetch(PDO::FETCH_ASSOC);
  echo "===== $name =====\n";
  echo ($row['Create Procedure'] ?? 'NOT FOUND')."\n\n";
}
