<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../api/shared/workflow/WorkflowTransitionService.php';

$pdo = getDB();
$appId = $argv[1] ?? 'APP-20260505140839550';
$caseIdSt = $pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id=? LIMIT 1');
$caseIdSt->execute([$appId]);
$caseId = (int)($caseIdSt->fetchColumn() ?: 0);
if ($caseId <= 0) { echo "Case not found for {$appId}\n"; exit(1);} 

$tables = [
  'Vati_Payfiller_Cases' => 'application_id',
  'Vati_Payfiller_Case_Components' => 'application_id',
  'Vati_Payfiller_Case_Component_Workflow' => 'application_id',
  'Vati_Payfiller_Validator_Queue' => 'case_id',
  'Vati_Payfiller_Verifier_Group_Queue' => 'case_id',
];

function fetchRows(PDO $pdo, string $table, string $key, $value): array {
  $st = $pdo->prepare("SELECT * FROM {$table} WHERE {$key} = ?");
  $st->execute([$value]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function describeCols(PDO $pdo, string $table): array {
  $st = $pdo->query("SHOW COLUMNS FROM {$table}");
  $cols=[]; foreach($st as $r){$cols[]=$r['Field'];}
  return $cols;
}
function deleteRows(PDO $pdo, string $table, string $key, $value): void {
  $st = $pdo->prepare("DELETE FROM {$table} WHERE {$key} = ?");
  $st->execute([$value]);
}
function insertRows(PDO $pdo, string $table, array $rows): void {
  if (!$rows) return;
  $cols = array_keys($rows[0]);
  $ph = implode(',', array_fill(0, count($cols), '?'));
  $sql = "INSERT IGNORE INTO {$table} (" . implode(',', $cols) . ") VALUES ({$ph})";
  $st = $pdo->prepare($sql);
  foreach ($rows as $r) {
    $vals=[]; foreach($cols as $c){$vals[]=$r[$c];}
    $st->execute($vals);
  }
}

$baseline=[];
foreach($tables as $t=>$k){
  $v = ($k==='application_id') ? $appId : $caseId;
  $baseline[$t] = fetchRows($pdo,$t,$k,$v);
}

function restoreAll(PDO $pdo, array $tables, array $baseline, string $appId, int $caseId): void {
  foreach(array_reverse(array_keys($tables)) as $t){
    $k=$tables[$t]; $v=($k==='application_id')?$appId:$caseId;
    deleteRows($pdo,$t,$k,$v);
  }
  foreach(array_keys($tables) as $t){
    insertRows($pdo,$t,$baseline[$t] ?? []);
  }
}

function setCase(PDO $pdo,int $caseId,string $appId,string $status,int $version): void {
  $st=$pdo->prepare("UPDATE Vati_Payfiller_Cases SET case_status=?, workflow_version=? WHERE case_id=? AND application_id=?");
  $st->execute([$status,$version,$caseId,$appId]);
}
function setWf(PDO $pdo,int $caseId,string $comp,string $stage,string $status): void {
  $st=$pdo->prepare("INSERT INTO Vati_Payfiller_Case_Component_Workflow (case_id,application_id,component_key,stage,status,updated_by_user_id,updated_by_role,completed_at) SELECT case_id,application_id,?, ?, ?, NULL, ?, NULL FROM Vati_Payfiller_Cases WHERE case_id=? LIMIT 1 ON DUPLICATE KEY UPDATE status=VALUES(status),updated_by_role=VALUES(updated_by_role),updated_at=NOW(), completed_at=CASE WHEN VALUES(status) IN ('approved','rejected') THEN NOW() ELSE NULL END");
  $st->execute([strtolower($comp),strtolower($stage),strtolower($status),strtolower($stage),$caseId]);
}
function setComp(PDO $pdo,int $caseId,string $appId,string $comp,string $status,?string $assignedRole=null,?int $assignedUser=null): void {
  $sql="UPDATE Vati_Payfiller_Case_Components SET status=?";
  $params=[$status];
  if($assignedRole!==null){$sql .= ", assigned_role=?"; $params[]=$assignedRole;}
  if($assignedUser!==null){$sql .= ", assigned_user_id=?"; $params[]=$assignedUser;}
  $sql .= " WHERE case_id=? AND application_id=? AND LOWER(TRIM(component_key))=?";
  $params[]=$caseId; $params[]=$appId; $params[]=strtolower($comp);
  $st=$pdo->prepare($sql); $st->execute($params);
}

$svc = new WorkflowTransitionService($pdo);
$results=[];
$runId = (string)time();

$run = function(string $name, callable $setup, array $cmd, callable $assert) use (&$results,$pdo,$tables,$baseline,$appId,$caseId,$svc){
  restoreAll($pdo,$tables,$baseline,$appId,$caseId);
  $setup();
  $before = $pdo->query("SELECT case_status,workflow_version FROM Vati_Payfiller_Cases WHERE case_id={$caseId}")->fetch(PDO::FETCH_ASSOC);
  $t0=microtime(true);
  $res=$svc->applyTransition($cmd);
  $dur=(int)round((microtime(true)-$t0)*1000);
  $after = $pdo->query("SELECT case_status,workflow_version FROM Vati_Payfiller_Cases WHERE case_id={$caseId}")->fetch(PDO::FETCH_ASSOC);
  [$pass,$obs]=$assert($res,$before,$after);
  $results[]=[
    'scenario'=>$name,
    'expected'=>$obs['expected'] ?? '',
    'actual'=>$obs['actual'] ?? ($res['code']??''),
    'pass'=>$pass?'PASS':'FAIL',
    'version_before'=>(int)$before['workflow_version'],
    'version_after'=>(int)$after['workflow_version'],
    'latency_ms'=>$dur,
    'code'=>$res['code'] ?? '',
    'http'=>$res['http'] ?? 0,
  ];
};

$run('normal_validator_approve', function() use($pdo,$caseId,$appId){
  setCase($pdo,$caseId,$appId,'PENDING_VALIDATOR',10);
  setWf($pdo,$caseId,'contact','candidate','completed');
  setWf($pdo,$caseId,'contact','validator','pending');
  setComp($pdo,$caseId,$appId,'contact','pending');
}, [
  'application_id'=>$appId,'case_id'=>$caseId,'component_key'=>'contact','action'=>'approve','reason'=>'ok','actor_user_id'=>22,'actor_role'=>'validator','expected_workflow_version'=>10,'transition_request_id'=>'t1-'.$runId
], function($res,$b,$a){
  $pass=($res['ok']??false) && ($res['data']['component_status']??'')==='approved' && ((int)$a['workflow_version']===11);
  return [$pass,['expected'=>'approved + version+1','actual'=>($res['data']['component_status']??'').' v'.($a['workflow_version']??'')]];
});

$run('invalid_stage_verifier_before_validator', function() use($pdo,$caseId,$appId){
  setCase($pdo,$caseId,$appId,'PENDING_VALIDATOR',20);
  setWf($pdo,$caseId,'education','candidate','completed');
  setWf($pdo,$caseId,'education','validator','pending');
  setWf($pdo,$caseId,'education','verifier','pending');
  setComp($pdo,$caseId,$appId,'education','pending');
}, [
  'application_id'=>$appId,'case_id'=>$caseId,'component_key'=>'education','action'=>'approve','reason'=>'x','actor_user_id'=>41,'actor_role'=>'verifier','expected_workflow_version'=>20,'transition_request_id'=>'t2-'.$runId
], function($res,$b,$a){
  $pass=($res['ok']??true)===false && ($res['code']??'')==='WF_PREVIOUS_STAGE_PENDING' && ((int)$a['workflow_version']===20);
  return [$pass,['expected'=>'WF_PREVIOUS_STAGE_PENDING + no version change','actual'=>($res['code']??'').' v'.($a['workflow_version']??'')]];
});

$run('stale_version_conflict', function() use($pdo,$caseId,$appId){
  setCase($pdo,$caseId,$appId,'PENDING_VALIDATOR',30);
  setWf($pdo,$caseId,'basic','candidate','completed');
  setWf($pdo,$caseId,'basic','validator','pending');
}, [
  'application_id'=>$appId,'case_id'=>$caseId,'component_key'=>'basic','action'=>'approve','reason'=>'ok','actor_user_id'=>22,'actor_role'=>'validator','expected_workflow_version'=>29,'transition_request_id'=>'t3-'.$runId
], function($res,$b,$a){
  $pass=($res['ok']??true)===false && ($res['code']??'')==='WF_VERSION_CONFLICT' && ((int)$a['workflow_version']===30);
  return [$pass,['expected'=>'WF_VERSION_CONFLICT','actual'=>$res['code']??'']];
});

$run('unauthorized_role', function() use($pdo,$caseId,$appId){
  setCase($pdo,$caseId,$appId,'PENDING_VALIDATOR',40);
}, [
  'application_id'=>$appId,'case_id'=>$caseId,'component_key'=>'id','action'=>'approve','reason'=>'ok','actor_user_id'=>18,'actor_role'=>'client_admin','expected_workflow_version'=>40,'transition_request_id'=>'t4-'.$runId
], function($res,$b,$a){
  $pass=($res['ok']??true)===false && ($res['code']??'')==='WF_FORBIDDEN_ROLE';
  return [$pass,['expected'=>'WF_FORBIDDEN_ROLE','actual'=>$res['code']??'']];
});

$run('assignment_mismatch', function() use($pdo,$caseId,$appId){
  setCase($pdo,$caseId,$appId,'PENDING_VERIFIER',50);
  setWf($pdo,$caseId,'employment','candidate','completed');
  setWf($pdo,$caseId,'employment','validator','approved');
  setWf($pdo,$caseId,'employment','verifier','pending');
  setComp($pdo,$caseId,$appId,'employment','pending','verifier',999);
}, [
  'application_id'=>$appId,'case_id'=>$caseId,'component_key'=>'employment','action'=>'approve','reason'=>'ok','actor_user_id'=>41,'actor_role'=>'verifier','expected_workflow_version'=>50,'transition_request_id'=>'t5-'.$runId
], function($res,$b,$a){
  $pass=($res['ok']??true)===false && ($res['code']??'')==='WF_NOT_ASSIGNED';
  return [$pass,['expected'=>'WF_NOT_ASSIGNED','actual'=>$res['code']??'']];
});

$run('invalid_status_transition', function() use($pdo,$caseId,$appId){
  setCase($pdo,$caseId,$appId,'PENDING_VERIFIER',60);
  setWf($pdo,$caseId,'reference','candidate','completed');
  setWf($pdo,$caseId,'reference','validator','approved');
  setWf($pdo,$caseId,'reference','verifier','approved');
}, [
  'application_id'=>$appId,'case_id'=>$caseId,'component_key'=>'reference','action'=>'approve','reason'=>'ok','actor_user_id'=>41,'actor_role'=>'verifier','expected_workflow_version'=>60,'transition_request_id'=>'t6-'.$runId
], function($res,$b,$a){
  $pass=($res['ok']??true)===false && ($res['code']??'')==='WF_INVALID_TRANSITION';
  return [$pass,['expected'=>'WF_INVALID_TRANSITION','actual'=>$res['code']??'']];
});

// idempotency
restoreAll($pdo,$tables,$baseline,$appId,$caseId);
setCase($pdo,$caseId,$appId,'PENDING_VERIFIER',70);
setWf($pdo,$caseId,'ecourt','candidate','completed');
setWf($pdo,$caseId,'ecourt','validator','approved');
setWf($pdo,$caseId,'ecourt','verifier','pending');
$cmd=['application_id'=>$appId,'case_id'=>$caseId,'component_key'=>'ecourt','action'=>'approve','reason'=>'ok','actor_user_id'=>41,'actor_role'=>'verifier','expected_workflow_version'=>70,'transition_request_id'=>'dup-1-'.$runId];
$r1=$svc->applyTransition($cmd);
$r2=$svc->applyTransition($cmd);
$aft=$pdo->query("SELECT workflow_version FROM Vati_Payfiller_Cases WHERE case_id={$caseId}")->fetchColumn();
$results[]=[
  'scenario'=>'idempotent_replay_duplicate_request_id',
  'expected'=>'first WF_OK, second WF_IDEMPOTENT_REPLAY, one version increment',
  'actual'=>($r1['code']??'').',' . ($r2['code']??'') . ',v'.$aft,
  'pass'=>((($r1['code']??'')==='WF_OK' && ($r2['code']??'')==='WF_IDEMPOTENT_REPLAY' && (int)$aft===71)?'PASS':'FAIL'),
  'version_before'=>70,'version_after'=>(int)$aft,'latency_ms'=>0,'code'=>$r2['code']??'','http'=>$r2['http']??0,
];

// invariant rollback qa approve with unresolved verifier required
$run('qa_invariant_failure_verifier_not_final', function() use($pdo,$caseId,$appId){
  setCase($pdo,$caseId,$appId,'PENDING_QA',80);
  setWf($pdo,$caseId,'basic','candidate','completed');
  setWf($pdo,$caseId,'basic','validator','approved');
  setWf($pdo,$caseId,'basic','verifier','pending');
  setWf($pdo,$caseId,'basic','qa','pending');
}, [
  'application_id'=>$appId,'case_id'=>$caseId,'component_key'=>'basic','action'=>'approve','reason'=>'ok','actor_user_id'=>31,'actor_role'=>'qa','expected_workflow_version'=>80,'transition_request_id'=>'t8-'.$runId
], function($res,$b,$a){
  $pass=($res['ok']??true)===false && ($res['code']??'')==='WF_PREVIOUS_STAGE_PENDING' && ((int)$a['workflow_version']===80);
  return [$pass,['expected'=>'blocked + rollback/no bump','actual'=>($res['code']??'').' v'.($a['workflow_version']??'')]];
});

restoreAll($pdo,$tables,$baseline,$appId,$caseId);

$out=[
  'application_id'=>$appId,
  'case_id'=>$caseId,
  'executed_at'=>date('c'),
  'results'=>$results,
];
file_put_contents(__DIR__.'/../logs/workflow_validation_report.json', json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
