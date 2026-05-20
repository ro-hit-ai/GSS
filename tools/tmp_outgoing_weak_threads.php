<?php
require 'config/db.php';
$pdo=getDB();
$sql="SELECT application_id, component_key, actor_role, direction, subject, thread_id, message_id, sent_at
FROM workflow_communications
WHERE direction='outgoing' AND LOWER(TRIM(COALESCE(actor_role,''))) IN ('validator','verifier','db_verifier') AND (COALESCE(thread_id,'')='' OR COALESCE(message_id,'')='')
ORDER BY sent_at DESC LIMIT 40";
foreach(($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES).PHP_EOL;}
