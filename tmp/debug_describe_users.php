<?php
require "config/db.php";
$pdo=getDB();
$st=$pdo->query("DESCRIBE Vati_Payfiller_Users");
$rows=$st->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT), PHP_EOL;
?>
