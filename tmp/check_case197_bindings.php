<?php
require 'config/db.php';
$pdo = getDB();
$out = [];
$out['required'] = $pdo->query("SELECT component_key, is_required FROM Vati_Payfiller_Case_Components WHERE case_id=197 ORDER BY component_key")->fetchAll(PDO::FETCH_ASSOC);
$out['bindings'] = $pdo->query("SELECT * FROM Vati_Payfiller_Case_Component_Binding WHERE case_id=197 ORDER BY component_key")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($out, JSON_PRETTY_PRINT);
?>
