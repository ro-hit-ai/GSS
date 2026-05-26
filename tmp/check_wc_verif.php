<?php
require 'config/db.php';
$pdo=getDB();
$q=$pdo->query("SELECT communication_id, application_id, component_key, direction, actor_role, communication_type, subject, thread_id, thread_owner_role, sent_at FROM workflow_communications WHERE communication_type='verification_request' ORDER BY communication_id DESC LIMIT 20");
foreach(($q->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES).PHP_EOL;}
