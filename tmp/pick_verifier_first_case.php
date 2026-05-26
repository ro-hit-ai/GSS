<?php
require 'c:\xampp\htdocs\GSS\config\db.php';
$pdo = getDB();
$sql = "SELECT application_id, case_id, case_status, COALESCE(NULLIF(TRIM(workflow_mode), ''), 'validator_first') AS workflow_mode, created_at FROM Vati_Payfiller_Cases WHERE COALESCE(NULLIF(TRIM(workflow_mode), ''), 'validator_first')='verifier_first' ORDER BY case_id DESC LIMIT 10";
foreach ($pdo->query($sql) as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
