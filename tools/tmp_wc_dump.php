<?php
require 'config/db.php';
$pdo = getDB();
$app='APP-20260520075926356';
$st=$pdo->prepare("SELECT communication_id, component_key, role_key, actor_role, direction, delivery_status, communication_type, message_id, in_reply_to, references_header, thread_id, sent_by_name, subject, sent_at FROM workflow_communications WHERE application_id=? ORDER BY communication_id ASC");
$st->execute([$app]);
foreach(($st->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES).PHP_EOL;}
