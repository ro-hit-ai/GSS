<?php
require 'config/db.php';
$pdo = getDB();
$app = 'APP-20260520075926356';
$st = $pdo->prepare("SELECT case_id, client_id FROM Vati_Payfiller_Cases WHERE application_id=? LIMIT 1");
$st->execute([$app]);
$row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
echo json_encode($row, JSON_PRETTY_PRINT);
