<?php
require 'config/db.php';
$pdo=getDB();
$st=$pdo->query("SHOW COLUMNS FROM Vati_Payfiller_Candidate_Employment_details");
while($r=$st->fetch(PDO::FETCH_ASSOC)){echo $r['Field']."|".$r['Type']."\n";}
