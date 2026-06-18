<?php
require 'c:/xampp/htdocs/GSS/config/env.php';
require 'c:/xampp/htdocs/GSS/config/db.php';
$pdo = getDB();
$stmt = $pdo->query('SELECT DISTINCT name AS value FROM Vati_Payfiller_Institution_Master WHERE is_active = 1 LIMIT 5');
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
