<?php
require 'config/db.php';
$pdo = getDB();
$app = 'APP-20260520075926356';
$st = $pdo->prepare("SELECT id, application_id, doc_type, file_path, original_name, mime_type, uploaded_by_role, created_at FROM Vati_Payfiller_Verification_Documents WHERE application_id = ? ORDER BY created_at DESC, id DESC");
$st->execute([$app]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
echo json_encode($rows, JSON_PRETTY_PRINT);
