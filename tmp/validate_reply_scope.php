<?php
require 'config/db.php';
require 'api/shared/communications/workflow_communication_service.php';
$pdo = getDB();
$app = 'APP-20260519120154998';
wc_ingest_incoming_replies($pdo, $app);
$all = $pdo->prepare("SELECT communication_id, component_key, direction, communication_type, subject, thread_id FROM workflow_communications WHERE application_id = ? ORDER BY communication_id DESC LIMIT 20");
$all->execute([$app]);
$rows = $all->fetchAll(PDO::FETCH_ASSOC);
function infer_component_from_text_local($subject, $body='') {
  $haystack = strtolower(trim($subject . ' ' . $body));
  $map = [
    'basic' => ['basic details', 'basic'],
    'id' => ['identification'],
    'contact' => ['contact information', 'contact'],
    'education' => ['education details', 'education'],
    'employment' => ['employment details', 'employment'],
    'reference' => ['reference', 'references'],
    'socialmedia' => ['social media', 'socialmedia'],
    'ecourt' => ['e-court', 'ecourt', 'e court'],
    'reports' => ['reports', 'report'],
  ];
  foreach ($map as $componentKey => $needles) foreach ($needles as $needle) if ($needle !== '' && strpos($haystack, $needle) !== false) return $componentKey;
  return '';
}
$education = array_values(array_filter($rows, function($r){
  $k = strtolower(trim((string)($r['component_key'] ?? '')));
  if ($k === 'education') return true;
  if ($k !== '' && $k !== 'timeline') return false;
  $subject = (string)($r['subject'] ?? '');
  return infer_component_from_text_local($subject) === 'education';
}));
echo json_encode(['all'=>$rows,'education_scope'=>$education], JSON_PRETTY_PRINT);
?>
