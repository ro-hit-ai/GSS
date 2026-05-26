<?php
require 'config/db.php';
$pdo=getDB();
$q=$pdo->query("SELECT id, application_id, component_key, recipient_email, node_thread_id, node_conversation_id, communication_status, created_at FROM verification_communications ORDER BY id DESC LIMIT 20");
foreach(($q->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES).PHP_EOL;}
