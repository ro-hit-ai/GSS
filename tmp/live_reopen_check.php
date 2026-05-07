<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../api/shared/workflow/WorkflowTransitionService.php';
$pdo=getDB();
$app='APP-20260505140839550';
$caseId=(int)$pdo->query("SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id='".$app."' LIMIT 1")->fetchColumn();
$svc=new WorkflowTransitionService($pdo);

// backup
$tabs=['Vati_Payfiller_Cases'=>'application_id','Vati_Payfiller_Case_Components'=>'application_id','Vati_Payfiller_Case_Component_Workflow'=>'application_id','Vati_Payfiller_Validator_Queue'=>'case_id'];
function fr($pdo,$t,$k,$v){$s=$pdo->prepare("SELECT * FROM $t WHERE $k=?");$s->execute([$v]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}
function del($pdo,$t,$k,$v){$s=$pdo->prepare("DELETE FROM $t WHERE $k=?");$s->execute([$v]);}
function ins($pdo,$t,$rows){if(!$rows)return;$c=array_keys($rows[0]);$ph=implode(',',array_fill(0,count($c),'?'));$s=$pdo->prepare("INSERT IGNORE INTO $t (".implode(',',$c).") VALUES ($ph)");foreach($rows as $r){$v=[];foreach($c as $cc){$v[]=$r[$cc];}$s->execute($v);} }
$base=[]; foreach($tabs as $t=>$k){$base[$t]=fr($pdo,$t,$k,$k==='application_id'?$app:$caseId);} 
function restoreB($pdo,$tabs,$base,$app,$cid){foreach(array_reverse(array_keys($tabs)) as $t){$k=$tabs[$t];del($pdo,$t,$k,$k==='application_id'?$app:$cid);}foreach(array_keys($tabs) as $t){ins($pdo,$t,$base[$t]??[]);} }
restoreB($pdo,$tabs,$base,$app,$caseId);

$pdo->prepare("UPDATE Vati_Payfiller_Cases SET case_status='PENDING_VALIDATOR',workflow_version=300 WHERE case_id=?")->execute([$caseId]);
$comps=['basic','id','contact','education','employment','reference','socialmedia','ecourt'];
foreach($comps as $c){
 $pdo->prepare("INSERT INTO Vati_Payfiller_Case_Component_Workflow (case_id,application_id,component_key,stage,status,updated_by_role) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),updated_by_role=VALUES(updated_by_role),updated_at=NOW()")
 ->execute([$caseId,$app,$c,'candidate','completed','candidate']);
 $pdo->prepare("INSERT INTO Vati_Payfiller_Case_Component_Workflow (case_id,application_id,component_key,stage,status,updated_by_role) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),updated_by_role=VALUES(updated_by_role),updated_at=NOW(),completed_at=CASE WHEN VALUES(status) IN ('approved','rejected') THEN NOW() ELSE NULL END")
 ->execute([$caseId,$app,$c,'validator','approved','validator']);
}
$pdo->prepare("UPDATE Vati_Payfiller_Validator_Queue SET status='done',completed_at=NOW() WHERE case_id=?")->execute([$caseId]);

$r=$svc->applyTransition(['application_id'=>$app,'case_id'=>$caseId,'component_key'=>'contact','action'=>'hold','reason'=>'reopen needed','actor_user_id'=>22,'actor_role'=>'validator','expected_workflow_version'=>300,'transition_request_id'=>'reopen-'.time()]);
$q=$pdo->prepare("SELECT status,completed_at FROM Vati_Payfiller_Validator_Queue WHERE case_id=?");$q->execute([$caseId]);$vq=$q->fetch(PDO::FETCH_ASSOC);

echo json_encode(['res'=>$r,'queue'=>$vq],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
restoreB($pdo,$tabs,$base,$app,$caseId);
