<?php
// CASA/JARVIS - scheduler interno de regras schedule/state.
// Chamado oportunisticamente por heartbeats e pelo endpoint rules_tick.php.
require_once(__DIR__.'/rules_engine.php');

function rules_scheduler_day_matches(array $cfg, int $weekday): bool {
    $days = $cfg['days'] ?? [];
    if (!is_array($days) || !$days) return true;
    return in_array($weekday, array_map('intval',$days), true);
}

function rules_scheduler_due(array $cfg, ?string $lastTriggered): bool {
    if (!array_key_exists('hour',$cfg) || !array_key_exists('minute',$cfg)) return false;
    $hour=max(0,min(23,(int)$cfg['hour']));
    $minute=max(0,min(59,(int)$cfg['minute']));
    $now=new DateTimeImmutable('now');
    if(!rules_scheduler_day_matches($cfg,(int)$now->format('N'))) return false;
    if((int)$now->format('G')!==$hour || (int)$now->format('i')!==$minute) return false;
    if($lastTriggered){$last=new DateTimeImmutable($lastTriggered);if($last->format('Y-m-d H:i')===$now->format('Y-m-d H:i'))return false;}
    return true;
}

function rules_scheduler_run_rule(PDO $pdo,array $rule,array $context,string $source):array {
    $conditions=rules_json($rule['conditions_json']??null,[]);
    if(!rules_conditions_match($conditions,$context))return ['rule_id'=>(int)$rule['id'],'status'=>'SKIPPED_CONDITION'];
    $cool=max(0,(int)($rule['cooldown_seconds']??0));
    if($cool>0&&!empty($rule['last_triggered_at'])){$last=strtotime((string)$rule['last_triggered_at']);if($last!==false&&time()-$last<$cool)return ['rule_id'=>(int)$rule['id'],'status'=>'SKIPPED_COOLDOWN'];}
    $ruleId=(int)$rule['id'];$corr='rule_'.bin2hex(random_bytes(12));
    $pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO automation_rule_runs(rule_id,source_event_id,correlation_id,status,details) VALUES(:r,NULL,:c,'QUEUED',:d)")->execute([':r'=>$ruleId,':c'=>$corr,':d'=>json_encode(['source'=>$source],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $runId=(int)$pdo->lastInsertId();
        $q=$pdo->prepare("SELECT id,device_id,comando,payload,prioridade,required_capability,risk_level,ttl_seconds FROM automation_rule_actions WHERE rule_id=:r AND enabled=1 ORDER BY ordem,id");$q->execute([':r'=>$ruleId]);
        $queued=[];$errors=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $a){try{$cmd=rules_enqueue_action($pdo,$a,'RULE:'.$rule['slug'],$runId);$pdo->prepare("INSERT INTO automation_rule_run_actions(run_id,rule_action_id,command_id,status) VALUES(:r,:a,:c,'QUEUED')")->execute([':r'=>$runId,':a'=>$a['id'],':c'=>$cmd]);$queued[]=$cmd;}catch(Throwable $e){$pdo->prepare("INSERT INTO automation_rule_run_actions(run_id,rule_action_id,status,erro) VALUES(:r,:a,'FAILED',:e)")->execute([':r'=>$runId,':a'=>$a['id'],':e'=>substr($e->getMessage(),0,1000)]);$errors[]=['action_id'=>(int)$a['id'],'error'=>$e->getMessage()];}}
        $status=$errors?($queued?'PARTIAL':'FAILED'):'QUEUED';
        $pdo->prepare("UPDATE automation_rule_runs SET status=:s,details=:d,concluido_em=CASE WHEN :done=1 THEN NOW() ELSE NULL END WHERE id=:id")->execute([':s'=>$status,':d'=>json_encode(['source'=>$source,'commands'=>$queued,'errors'=>$errors],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':done'=>$status==='FAILED'?1:0,':id'=>$runId]);
        $pdo->prepare("UPDATE automation_rules SET last_triggered_at=NOW() WHERE id=:id")->execute([':id'=>$ruleId]);$pdo->commit();
        return ['rule_id'=>$ruleId,'run_id'=>$runId,'status'=>$status,'commands'=>$queued,'errors'=>$errors];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();return ['rule_id'=>$ruleId,'status'=>'FAILED','error'=>$e->getMessage()];}
}

function rules_scheduler_tick(PDO $pdo,bool $force=false):array {
    // No tráfego normal roda no máximo uma vez a cada 45s entre todos os devices/processos.
    if(!$force){
        try{
            $pdo->exec("INSERT IGNORE INTO param(chave,valor) VALUES('AUTOMATION_LAST_TICK','1970-01-01 00:00:00')");
            $stmt=$pdo->prepare("UPDATE param SET valor=NOW() WHERE chave='AUTOMATION_LAST_TICK' AND (valor IS NULL OR valor='' OR STR_TO_DATE(valor,'%Y-%m-%d %H:%i:%s')<=DATE_SUB(NOW(),INTERVAL 45 SECOND))");
            $stmt->execute();if($stmt->rowCount()===0)return [];
        }catch(Throwable $e){return [];}
    }
    $stmt=$pdo->query("SELECT id,slug,nome,trigger_type,trigger_config,conditions_json,cooldown_seconds,last_triggered_at FROM automation_rules WHERE enabled=1 AND trigger_type IN('schedule','state') ORDER BY id");
    $runs=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule){$cfg=rules_json($rule['trigger_config']??null,[]);
        if($rule['trigger_type']==='schedule'){
            if(!rules_scheduler_due($cfg,$rule['last_triggered_at']??null))continue;
            $runs[]=rules_scheduler_run_rule($pdo,$rule,['schedule'=>['now'=>date('c'),'hour'=>(int)date('G'),'minute'=>(int)date('i'),'weekday'=>(int)date('N')]],'schedule');continue;
        }
        $deviceId=trim((string)($cfg['device_id']??''));if($deviceId==='')continue;
        $d=$pdo->prepare("SELECT device_id,nome,tipo,status,health,battery_pct,sinal_rssi,ultimo_heartbeat,metadata FROM dispositivos_cluster WHERE device_id=:d LIMIT 1");$d->execute([':d'=>$deviceId]);$dev=$d->fetch(PDO::FETCH_ASSOC);if(!$dev)continue;
        $dev['battery_pct']=$dev['battery_pct']===null?null:(int)$dev['battery_pct'];$dev['sinal_rssi']=$dev['sinal_rssi']===null?null:(int)$dev['sinal_rssi'];$dev['metadata']=rules_json($dev['metadata']??null,[]);
        $runs[]=rules_scheduler_run_rule($pdo,$rule,['device'=>$dev,'state'=>$dev,'data'=>$dev['metadata']],'state');
    }
    return $runs;
}
