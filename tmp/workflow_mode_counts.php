<?php
require 'c:\xampp\htdocs\GSS\config\db.php';
$pdo = getDB();
$sql = "SELECT COALESCE(NULLIF(TRIM(workflow_mode), ''), 'validator_first') AS workflow_mode, COUNT(*) AS total FROM Vati_Payfiller_Cases GROUP BY COALESCE(NULLIF(TRIM(workflow_mode), ''), 'validator_first') ORDER BY total DESC";
foreach ($pdo->query($sql) as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
