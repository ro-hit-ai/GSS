<?php
require 'config/db.php';
$pdo=getDB();
$sql="SELECT application_id, component_key, actor_role, direction, subject, thread_id, message_id, in_reply_to, references_header, sent_at
FROM workflow_communications
WHERE direction='incoming' AND LOWER(TRIM(COALESCE(actor_role,'')))='candidate' AND (COALESCE(thread_id,'')='' OR (COALESCE(in_reply_to,'')='' AND COALESCE(references_header,'')=''))
ORDER BY sent_at DESC LIMIT 40";
foreach(($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES).PHP_EOL;}
