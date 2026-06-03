<?php
require 'config/db.php';
$pdo=getDB();
$sql="SELECT actor_role, direction, COUNT(*) AS cnt,
SUM(CASE WHEN COALESCE(thread_id,'')='' THEN 1 ELSE 0 END) AS blank_thread,
SUM(CASE WHEN COALESCE(message_id,'')='' THEN 1 ELSE 0 END) AS blank_msg,
SUM(CASE WHEN COALESCE(in_reply_to,'')='' THEN 1 ELSE 0 END) AS blank_in_reply_to,
SUM(CASE WHEN COALESCE(references_header,'')='' THEN 1 ELSE 0 END) AS blank_refs
FROM Vati_Payfiller_Workflow_Communications
WHERE LOWER(TRIM(COALESCE(actor_role,''))) IN ('validator','verifier','db_verifier','candidate','qa')
GROUP BY actor_role, direction
ORDER BY actor_role, direction";
foreach(($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES).PHP_EOL;}
