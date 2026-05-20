<?php
require 'config/db.php';
$pdo = getDB();
$rows = $pdo->query("SELECT user_id, username, role, allowed_sections, is_active FROM Vati_Payfiller_Users WHERE LOWER(TRIM(role)) IN ('verifier','db_verifier') ORDER BY user_id ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
?>
