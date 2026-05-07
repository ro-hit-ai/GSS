<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../api/shared/workflow/WorkflowTransitionService.php';
$pdo=getDB();
$app='APP-20260505140839550';
$st=$pdo->prepare('SELECT case_id FROM Vati_Payfiller_Cases WHERE application_id=? LIMIT 1');
$st->execute([$app]);
$caseId=(int)($st->fetchColumn()?:0);
if($caseId<=0){echo "no case\n";exit(1);} 
$tables=['Vati_Payfiller_Cases'=>'application_id','Vati_Payfiller_Case_Components'=>'application_id','Vati_Payfiller_Case_Component_Workflow'=>'application_id','Vati_Payfiller_Validator_Queue'=>'case_id','Vati_Payfiller_Verifier_Group_Queue'=>'case_id'];
function fr($pdo,$t,$k,$v){$s=$pdo->prepare("SELECT * FROM $t WHERE $k=?");$s->execute([$v]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}
function del($pdo,$t,$k,$v){$s=$pdo->prepare("DELETE FROM $t WHERE $k=?");$s->execute([$v]);}
function ins($pdo,$t,$rows){if(!$rows)return;$cols=array_keys($rows[0]);$ph=implode(',',array_fill(0,count($cols),'?'));$s=$pdo->prepare("INSERT IGNORE INTO $t (".implode(',',$cols).") VALUES ($ph)");foreach($rows as $r){$vals=[];foreach($cols as $c){$vals[]=$r[$c];}$s->execute($vals);} }
$base=[]; foreach($tables as $t=>$k){$base[$t]=fr($pdo,$t,$k,$k==='application_id'?$app:$caseId);} 
function restore($pdo,$tables,$base,$app,$caseId){foreach(array_reverse(array_keys($tables)) as $t){$k=$tables[$t];del($pdo,$t,$k,$k==='application_id'?$app:$caseId);}foreach(array_keys($tables) as $t){ins($pdo,$t,$base[$t]??[]);} }
function setCase($pdo,$caseId,$app,$status,$ver){$s=$pdo->prepare('UPDATE Vati_Payfiller_Cases SET case_status=?,workflow_version=? WHERE case_id=? AND application_id=?');$s->execute([$status,$ver,$caseId,$app]);}
function setWF($pdo,$caseId,$comp,$stage,$status){$s=$pdo->prepare("INSERT INTO Vati_Payfiller_Case_Component_Workflow (case_id,application_id,component_key,stage,status,updated_by_user_id,updated_by_role,completed_at) SELECT case_id,application_id,?,?,?,NULL,?,NULL FROM Vati_Payfiller_Cases WHERE case_id=? LIMIT 1 ON DUPLICATE KEY UPDATE status=VALUES(status),updated_by_role=VALUES(updated_by_role),updated_at=NOW(),completed_at=CASE WHEN VALUES(status) IN ('approved','rejected') THEN NOW() ELSE NULL END");$s->execute([$comp,$stage,$status,$stage,$caseId]);}
$svc=new WorkflowTransitionService($pdo);
$out=[];

// validator hold keeps active
restore($pdo,$tables,$base,$app,$caseId);
setCase($pdo,$caseId,$app,'PENDING_VALIDATOR',200);
foreach(['basic','id','contact','education','employment','reference','socialmedia','ecourt'] as $c){setWF($pdo,$caseId,$c,'candidate','completed'); setWF($pdo,$caseId,$c,'validator','approved');}
setWF($pdo,$caseId,'contact','validator','hold');
$r=$svc->applyTransition(['application_id'=>$app,'case_id'=>$caseId,'component_key'=>'contact','action'=>'hold','reason'=>'need docs','actor_user_id'=>22,'actor_role'=>'validator','expected_workflow_version'=>200,'transition_request_id'=>'live-hold-'.time()]);
$q=$pdo->prepare('SELECT status,completed_at FROM Vati_Payfiller_Validator_Queue WHERE case_id=?');$q->execute([$caseId]);$vq=$q->fetch(PDO::FETCH_ASSOC)?:[];
$out[]=['scenario'=>'validator_hold_active','result'=>$r['code']??'','queue_status'=>$vq['status']??'','queue_completed_at'=>$vq['completed_at']??null];

// validator insufficient keeps active
restore($pdo,$tables,$base,$app,$caseId);
setCase($pdo,$caseId,$app,'PENDING_VALIDATOR',210);
foreach(['basic','id','contact','education','employment','reference','socialmedia','ecourt'] as $c){setWF($pdo,$caseId,$c,'candidate','completed'); setWF($pdo,$caseId,$c,'validator','approved');}
$r=$svc->applyTransition(['application_id'=>$app,'case_id'=>$caseId,'component_key'=>'contact','action'=>'insufficient_documents','reason'=>'missing','actor_user_id'=>22,'actor_role'=>'validator','expected_workflow_version'=>210,'transition_request_id'=>'live-insuff-'.time()]);
$q=$pdo->prepare('SELECT status,completed_at FROM Vati_Payfiller_Validator_Queue WHERE case_id=?');$q->execute([$caseId]);$vq=$q->fetch(PDO::FETCH_ASSOC)?:[];
$out[]=['scenario'=>'validator_insufficient_active','result'=>$r['code']??'','queue_status'=>$vq['status']??'','queue_completed_at'=>$vq['completed_at']??null];

// terminal rejection closes queue
restore($pdo,$tables,$base,$app,$caseId);
setCase($pdo,$caseId,$app,'REJECTED',220);
foreach(['basic','id','contact','education','employment','reference','socialmedia','ecourt'] as $c){setWF($pdo,$caseId,$c,'candidate','completed'); setWF($pdo,$caseId,$c,'validator','rejected');}
$r=$svc->applyTransition(['application_id'=>$app,'case_id'=>$caseId,'component_key'=>'contact','action'=>'reject','reason'=>'terminal','actor_user_id'=>22,'actor_role'=>'validator','expected_workflow_version'=>220,'transition_request_id'=>'live-term-'.time()]);
$q=$pdo->prepare('SELECT status,completed_at FROM Vati_Payfiller_Validator_Queue WHERE case_id=?');$q->execute([$caseId]);$vq=$q->fetch(PDO::FETCH_ASSOC)?:[];
$out[]=['scenario'=>'terminal_rejection_closed','result'=>$r['code']??'','queue_status'=>$vq['status']??'','queue_completed_at'=>$vq['completed_at']??null];

restore($pdo,$tables,$base,$app,$caseId);

echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
