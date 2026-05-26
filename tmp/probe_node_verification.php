<?php
require 'includes/mail.php';
$r=app_node_api_json_request('POST','/api/php/workflow/send-verification-mail',[
  'case_id'=>'0',
  'application_id'=>'APP-TEST',
  'component_key'=>'education',
  'recipient_email'=>'nobody@example.invalid',
  'recipient_name'=>'Test Org',
  'template_key'=>'education_verification',
  'sender_role'=>'validator',
  'sender_user_id'=>'0',
  'remarks'=>null,
  'subject'=>'Thread Probe',
  'message_body'=>'Probe body',
  'node_thread_id'=>null,
  'node_conversation_id'=>null
],10);
echo json_encode($r, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
