<?php
require_once __DIR__ . '/config/db.php';
$pdo = getDB();
$app = 'APP-20260606142029642';
$out = [];
$out['users_roles'] = $pdo->query("SELECT LOWER(TRIM(role)) AS role, COUNT(*) AS cnt FROM Vati_Payfiller_Users GROUP BY LOWER(TRIM(role)) ORDER BY role")->fetchAll(PDO::FETCH_ASSOC);
$st = $pdo->prepare("SELECT case_id, application_id, case_status, workflow_mode FROM Vati_Payfiller_Cases WHERE application_id=? LIMIT 1");
$st->execute([$app]);
$case = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$out['case'] = $case;
if ($case) {
  $caseId = (int)$case['case_id'];
  $st = $pdo->prepare("SELECT component_key, stage, status, assigned_role, assigned_user_id, actor_user_id FROM Vati_Payfiller_Case_Component_Workflow WHERE case_id=? ORDER BY component_key, stage, id");
  $st->execute([$caseId]);
  $out['workflow_rows'] = $st->fetchAll(PDO::FETCH_ASSOC);
}
foreach (['Vati_Payfiller_Workflow_Communications','workflow_communications'] as $table) {
  try {
    $st = $pdo->prepare("SELECT component_key, direction, actor_role, thread_owner_role, thread_id, COUNT(*) AS cnt FROM $table WHERE application_id=? GROUP BY component_key, direction, actor_role, thread_owner_role, thread_id ORDER BY component_key, thread_owner_role, actor_role LIMIT 100");
    $st->execute([$app]);
    $out[$table] = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $out[$table.'_error'] = $e->getMessage(); }
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
