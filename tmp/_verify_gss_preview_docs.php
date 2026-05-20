<?php
session_start();
$_SESSION['auth_moduleAccess']='gss_admin';
$_SESSION['auth_user_id']=1;
$_SERVER['REQUEST_METHOD']='GET';
$_GET['role']='gss_admin';
$_GET['application_id']='APP-20260520075926356';
ob_start();
include 'api/shared/candidate_report_get.php';
$out=ob_get_clean();
$data = json_decode($out, true);
$payload = $data['data'] ?? [];
$checks = [
  'profile_photo' => $payload['basic']['photo_path'] ?? '',
  'contact_proof' => $payload['contact']['proof_file'] ?? '',
  'education_marksheet' => $payload['education'][0]['marksheet_file'] ?? '',
  'employment_proof' => $payload['employment'][0]['employment_doc'] ?? '',
  'ecourt_evidence' => $payload['ecourt']['evidence_document'] ?? '',
];
$result = [];
foreach ($checks as $label => $path) {
  $p = str_replace('\\', '/', trim((string)$path));
  $local = 'C:/xampp/htdocs/GSS/' . ltrim($p, '/');
  $result[$label] = [
    'payload_path' => $p,
    'local_exists' => is_file($local),
    'local_path' => $local,
  ];
}
echo json_encode($result, JSON_PRETTY_PRINT);
