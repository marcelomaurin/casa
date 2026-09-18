<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once(__DIR__.'/db.php');
verify_api_auth();

$pdo=get_db_pdo();

function tel_out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function tel_dt($v,$fallback){
    $v=trim((string)$v);
    if($v==='')return $fallback;
    $t=strtotime($v);
    return $t===false?$fallback:date('Y-m-d H:i:s',$t);
}

$inicio=tel_dt($_GET['inicio']??'',date('Y-m-d H:i:s',time()-86400));
$fim=tel_dt($_GET['fim']??'',date('Y-m-d H:i:s'));
$origem=trim((string)($_GET['origem']??''));
$busca=trim((string)($_GET['busca']??''));
$pagina=max(1,(int)($_GET['pagina']??1));
$limite=max(5,min(100,(int)($_GET['limite']??20)));
$offset=($pagina-1)*$limite;

try{
    // Consolida a nova telemetria estruturada com históricos já existentes.
    $sql="
      SELECT
        CONCAT('TEL-',t.id) uid,t.data_hora,t.origem,t.canal,t.ip_cliente,t.operacao,
        t.solicitacao,t.resposta_ia,t.acao_executada,t.status,t.modelo,t.duracao_ms,
        'telemetria_operacional' fonte
      FROM telemetria_operacional t
      WHERE t.data_hora BETWEEN :ini1 AND :fim1

      UNION ALL

      SELECT
        CONCAT('CMD-',c.id),c.data_hora,c.origem,'operacao',NULL,'COMANDO',
        c.comando,NULL,c.resultado,
        CASE WHEN c.resultado IS NULL OR c.resultado='' THEN 'REGISTRADO' ELSE 'CONCLUIDO' END,
        NULL,NULL,'comandos_log'
      FROM comandos_log c
      WHERE c.data_hora BETWEEN :ini2 AND :fim2

      UNION ALL

      SELECT
        CONCAT('LLM-',l.id),l.data_hora,l.contexto,'ia',NULL,'CONVERSA_IA',
        l.user_msg,l.bot_msg,NULL,'SUCESSO',l.contexto,NULL,'llm_conversas'
      FROM llm_conversas l
      WHERE l.data_hora BETWEEN :ini3 AND :fim3

      UNION ALL

      SELECT
        CONCAT('API-',s.id),s.data_hora,COALESCE(s.cliente,'EXTERNO'),'api',s.ip,s.evento,
        CONCAT(COALESCE(s.metodo,''),' ',COALESCE(s.rota,'')),
        NULL,CAST(s.detalhes AS CHAR),s.severidade,NULL,NULL,'api_v1_security_log'
      FROM api_v1_security_log s
      WHERE s.data_hora BETWEEN :ini4 AND :fim4
    ";

    $params=[
      ':ini1'=>$inicio,':fim1'=>$fim,
      ':ini2'=>$inicio,':fim2'=>$fim,
      ':ini3'=>$inicio,':fim3'=>$fim,
      ':ini4'=>$inicio,':fim4'=>$fim
    ];
    $outer="SELECT * FROM (".$sql.") x WHERE 1=1";
    if($origem!==''){$outer.=" AND (x.origem LIKE :origem OR x.canal LIKE :origem2)";$params[':origem']='%'.$origem.'%';$params[':origem2']='%'.$origem.'%';}
    if($busca!==''){
      $outer.=" AND (x.operacao LIKE :b1 OR x.solicitacao LIKE :b2 OR x.resposta_ia LIKE :b3 OR x.acao_executada LIKE :b4)";
      $v='%'.$busca.'%';$params[':b1']=$v;$params[':b2']=$v;$params[':b3']=$v;$params[':b4']=$v;
    }

    $count=$pdo->prepare("SELECT COUNT(*) FROM (".$outer.") z");
    $count->execute($params);
    $total=(int)$count->fetchColumn();

    $stmt=$pdo->prepare($outer." ORDER BY data_hora DESC LIMIT ".$limite." OFFSET ".$offset);
    $stmt->execute($params);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    tel_out([
      'status'=>'sucesso',
      'inicio'=>$inicio,'fim'=>$fim,
      'pagina'=>$pagina,'limite'=>$limite,
      'total'=>$total,
      'paginas'=>max(1,(int)ceil($total/$limite)),
      'dados'=>$rows
    ]);
}catch(Throwable $e){
    error_log('Telemetria operacional: '.$e->getMessage());
    tel_out(['status'=>'erro','mensagem'=>'Falha ao consultar telemetria operacional.'],500);
}
