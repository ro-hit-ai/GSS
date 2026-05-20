<?php
require 'config/db.php';
$pdo = getDB();
$app = 'APP-20260520075926356';
$st = $pdo->prepare("SELECT application_id, component_key, subject, direction, actor_role, sent_at, message_id, in_reply_to, thread_id FROM workflow_communications WHERE application_id=? ORDER BY communication_id DESC LIMIT 20");
$st->execute([$app]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
echo json_encode($rows, JSON_PRETTY_PRINT);
