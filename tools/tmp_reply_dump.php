<?php
require 'config/db.php';
$pdo = getDB();
$app='APP-20260520075926356';
$st=$pdo->prepare("SELECT application_id, sender_email, created_at, subject, message_id, in_reply_to, references_header, thread_id, LEFT(message,200) AS message FROM Vati_Payfiller_GSS_Email_Replies WHERE application_id=? ORDER BY id ASC");
$st->execute([$app]);
foreach(($st->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES).PHP_EOL;}
