<?php
require 'config/db.php';
require 'config/env.php';
$pdo = getDB();
$out = [];
$out['imap_extension'] = function_exists('imap_open');
$out['imap_user_set'] = trim((string)(env_get('MAIL_REPLY_IMAP_USER','') ?? '')) !== '';
$out['imap_pass_set'] = trim((string)(env_get('MAIL_REPLY_IMAP_PASS','') ?? '')) !== '';
$out['node_api_url_set'] = trim((string)(env_get('NODE_API_URL','') ?? '')) !== '';
$out['mail_from_set'] = trim((string)(env_get('APP_MAIL_FROM','') ?? '')) !== '';
$out['workflow_communications_count'] = (int)$pdo->query("SELECT COUNT(*) FROM workflow_communications")->fetchColumn();
$out['incoming_wc_count'] = (int)$pdo->query("SELECT COUNT(*) FROM workflow_communications WHERE direction='incoming'")->fetchColumn();
$out['legacy_replies_table'] = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('GSS_Email_Replies','email_replies') ORDER BY table_name LIMIT 1")->fetchColumn();
if ($out['legacy_replies_table']) {
  $tbl = $out['legacy_replies_table'];
  $out['legacy_replies_count'] = (int)$pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
}
$out['recent_wc'] = $pdo->query("SELECT application_id, component_key, action_key, direction, delivery_status, sent_at FROM workflow_communications ORDER BY communication_id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($out, JSON_PRETTY_PRINT);
?>
