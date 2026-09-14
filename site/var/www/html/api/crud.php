<?php
// CASA/JARVIS - CRUD central MySQL/MariaDB
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
verify_api_auth();
require_once(__DIR__ . '/seguranca.php');

$pdo = get_db_pdo();
$tabela = isset($_GET['tabela']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['tabela']) : '';
$acao = $_GET['acao'] ?? ($_GET['action'] ?? 'listar');
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$tabelas_permitidas = [
    'devices','devpar','sensores_telemetria','frases','usuarios','arm_nodes',
    'configuracoes_sistema','comandos_log','falas','llm_conversas','tarefas_agendadas',
    'dispositivos_cluster','seguranca_logs','seguranca_ips_bloqueados','agentes_externos',
    'iot_leituras','camera_eventos','mobile_eventos','mobile_notificacoes','watch_notificacoes',
    'jarvis_planos','jarvis_tarefas','internet_pesquisas','api_client_tokens'
];

function crud_json($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function crud_pk($tabela) {
    if ($tabela === 'devices') return 'iddevice';
    if ($tabela === 'devpar') return 'idpar';
    if ($tabela === 'falas') return 'idfala';
    if ($tabela === 'configuracoes_sistema') return 'chave';
    if ($tabela === 'seguranca_ips_bloqueados') return 'ip_address';
    return 'id';
}

function crud_clean_columns($input) {
    $out = [];
    foreach ($input as $k => $v) {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $k)) $out[$k] = $v;
    }
    return $out;
}

if ($tabela !== '' && !in_array($tabela, $tabelas_permitidas, true)) {
    crud_json(['status'=>'erro','mensagem'=>'Tabela inválida'], 400);
}

try {
    if ($acao === 'listar') {
        if ($tabela === '') crud_json(['status'=>'erro','mensagem'=>'Tabela obrigatória'], 400);
        $limite = max(1, min(500, intval($_GET['limite'] ?? 100)));
        $pk = crud_pk($tabela);
        $stmt = $pdo->query("SELECT * FROM `{$tabela}` ORDER BY `{$pk}` DESC LIMIT {$limite}");
        crud_json([
            'status'=>'sucesso',
            'tabela'=>$tabela,
            'origem_dados'=>'casa.maurinsoft.com.br / MySQL',
            'total'=>$stmt->rowCount(),
            'dados'=>$stmt->fetchAll()
        ]);
    }

    if ($acao === 'criar') {
        if ($tabela === '') crud_json(['status'=>'erro','mensagem'=>'Tabela obrigatória'], 400);
        $input = crud_clean_columns($input);
        $pk = crud_pk($tabela);
        if ($pk !== 'chave' && $pk !== 'ip_address') unset($input[$pk]);
        if (!$input) crud_json(['status'=>'erro','mensagem'=>'Dados vazios'], 400);

        $cols = array_keys($input);
        $quoted = array_map(fn($c)=>"`{$c}`", $cols);
        $marks = array_map(fn($c)=>":{$c}", $cols);
        $stmt = $pdo->prepare("INSERT INTO `{$tabela}` (" . implode(',', $quoted) . ") VALUES (" . implode(',', $marks) . ")");
        $params = [];
        foreach ($input as $k=>$v) $params[":{$k}"] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
        $stmt->execute($params);
        crud_json(['status'=>'sucesso','mensagem'=>'Registro criado','id'=>$pdo->lastInsertId() ?: null]);
    }

    if ($acao === 'atualizar') {
        if ($tabela === '') crud_json(['status'=>'erro','mensagem'=>'Tabela obrigatória'], 400);
        $input = crud_clean_columns($input);
        $pk = crud_pk($tabela);
        $pkVal = $input[$pk] ?? ($_GET['id'] ?? null);
        if ($pkVal === null || $pkVal === '') crud_json(['status'=>'erro','mensagem'=>"Identificador {$pk} ausente"], 400);
        unset($input[$pk]);
        if (!$input) crud_json(['status'=>'erro','mensagem'=>'Nenhum campo para atualizar'], 400);

        $sets=[]; $params=[':pk'=>$pkVal];
        foreach ($input as $k=>$v) {
            $sets[] = "`{$k}`=:{$k}";
            $params[":{$k}"] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
        }
        $stmt=$pdo->prepare("UPDATE `{$tabela}` SET " . implode(',', $sets) . " WHERE `{$pk}`=:pk");
        $stmt->execute($params);
        crud_json(['status'=>'sucesso','mensagem'=>'Registro atualizado']);
    }

    if ($acao === 'excluir') {
        if ($tabela === '') crud_json(['status'=>'erro','mensagem'=>'Tabela obrigatória'], 400);
        $pk=crud_pk($tabela);
        $pkVal=$input[$pk] ?? ($_GET['id'] ?? null);
        if ($pkVal === null || $pkVal === '') crud_json(['status'=>'erro','mensagem'=>"Identificador {$pk} ausente"], 400);
        $pdo->prepare("DELETE FROM `{$tabela}` WHERE `{$pk}`=:pk")->execute([':pk'=>$pkVal]);
        crud_json(['status'=>'sucesso','mensagem'=>'Registro removido']);
    }

    if ($acao === 'heartbeat') {
        $deviceId = trim($input['device_id'] ?? '');
        $hostname = trim($input['hostname'] ?? 'arm-node');
        $ip = trim($input['ip_address'] ?? get_client_ip());
        $cpu = trim($input['cpu_info'] ?? 'ARM');
        $ram = trim($input['ram_info'] ?? '');
        $status = trim($input['status'] ?? 'online');
        $caps = json_encode($input['capabilities'] ?? ['arm-agent'], JSON_UNESCAPED_UNICODE);

        if ($deviceId !== '') {
            $stmt=$pdo->prepare("SELECT id FROM arm_nodes WHERE device_id=:d LIMIT 1");
            $stmt->execute([':d'=>$deviceId]);
        } else {
            $stmt=$pdo->prepare("SELECT id FROM arm_nodes WHERE hostname=:h LIMIT 1");
            $stmt->execute([':h'=>$hostname]);
        }
        $id=$stmt->fetchColumn();

        if ($id) {
            $up=$pdo->prepare("UPDATE arm_nodes SET device_id=COALESCE(NULLIF(:d,''),device_id),hostname=:h,ip_address=:ip,cpu_info=:c,ram_info=:r,status=:s,capabilities=:caps,ultimo_ping=NOW() WHERE id=:id");
            $up->execute([':d'=>$deviceId,':h'=>$hostname,':ip'=>$ip,':c'=>$cpu,':r'=>$ram,':s'=>$status,':caps'=>$caps,':id'=>$id]);
        } else {
            $ins=$pdo->prepare("INSERT INTO arm_nodes (device_id,hostname,ip_address,papel,status,cpu_info,ram_info,capabilities,ultimo_ping) VALUES (NULLIF(:d,''),:h,:ip,'No Distribuido',:s,:c,:r,:caps,NOW())");
            $ins->execute([':d'=>$deviceId,':h'=>$hostname,':ip'=>$ip,':s'=>$status,':c'=>$cpu,':r'=>$ram,':caps'=>$caps]);
            $id=$pdo->lastInsertId();
        }
        crud_json(['status'=>'sucesso','mensagem'=>'Heartbeat recebido','node_id'=>intval($id)]);
    }

    if ($acao === 'heartbeat_iot') {
        $token=$_SERVER['HTTP_X_DEVICE_TOKEN'] ?? ($input['device_token'] ?? '');
        $dev=validar_hardware_token($token);
        if (!$dev) crud_json(['status'=>'erro','mensagem'=>'Hardware token inválido'], 401);

        $ram=intval($input['ram_livre'] ?? $dev['ram_livre'] ?? 0);
        $rssi=intval($input['sinal_rssi'] ?? $dev['sinal_rssi'] ?? 0);
        $reles=$input['reles_status'] ?? $dev['reles_status'] ?? [];
        if (is_string($reles)) $reles=json_decode($reles,true) ?: [];
        $caps=$input['capabilities'] ?? null;

        $sql="UPDATE dispositivos_cluster SET ip_address=:ip,status='online',ram_livre=:ram,sinal_rssi=:rssi,reles_status=:reles,ultimo_heartbeat=NOW()";
        $params=[':ip'=>get_client_ip(),':ram'=>$ram,':rssi'=>$rssi,':reles'=>json_encode($reles,JSON_UNESCAPED_UNICODE),':id'=>$dev['id']];
        if ($caps !== null) { $sql .= ",capabilities=:caps"; $params[':caps']=json_encode($caps,JSON_UNESCAPED_UNICODE); }
        $sql .= " WHERE id=:id";
        $pdo->prepare($sql)->execute($params);
        crud_json(['status'=>'sucesso','mensagem'=>'Telemetria IoT recebida','device_id'=>intval($dev['id'])]);
    }

    if ($acao === 'acionar_rele_iot') {
        $devId=intval($input['device_id'] ?? 0);
        $rele=preg_replace('/[^a-zA-Z0-9_\-]/','',$input['rele'] ?? 'rele1');
        $estado=!empty($input['estado']) ? 1 : 0;
        $stmt=$pdo->prepare("SELECT * FROM dispositivos_cluster WHERE id=:id");
        $stmt->execute([':id'=>$devId]);
        $dev=$stmt->fetch();
        if (!$dev) crud_json(['status'=>'erro','mensagem'=>'Dispositivo não encontrado'],404);

        $reles=$dev['reles_status'] ? json_decode($dev['reles_status'],true) : [];
        if (!is_array($reles)) $reles=[];
        $reles[$rele]=$estado;
        $pdo->prepare("UPDATE dispositivos_cluster SET reles_status=:j WHERE id=:id")
            ->execute([':j'=>json_encode($reles,JSON_UNESCAPED_UNICODE),':id'=>$devId]);
        $pdo->prepare("INSERT INTO comandos_log (comando,origem,resultado) VALUES (:c,'WEB_HUD_IOT','Pendente para no distribuido')")
            ->execute([':c'=>"{$dev['nome']} {$rele}={$estado}"]);
        crud_json(['status'=>'sucesso','device_id'=>$devId,'reles_status'=>$reles]);
    }

    if ($acao === 'gerar_token_iot') {
        $nome=trim($input['nome'] ?? 'Novo Dispositivo');
        $tipo=trim($input['tipo'] ?? 'iot_generic');
        $local=trim($input['localizacao'] ?? 'Residencia');
        $deviceId=trim($input['device_id'] ?? '');
        $token=gerar_novo_hardware_token($tipo);
        $stmt=$pdo->prepare("INSERT INTO dispositivos_cluster (device_id,nome,tipo,device_token,localizacao,status) VALUES (NULLIF(:did,''),:n,:t,:tok,:loc,'aguardando_conexao')");
        $stmt->execute([':did'=>$deviceId,':n'=>$nome,':t'=>$tipo,':tok'=>$token,':loc'=>$local]);
        crud_json(['status'=>'sucesso','device'=>['id'=>intval($pdo->lastInsertId()),'device_id'=>$deviceId ?: null,'nome'=>$nome,'tipo'=>$tipo],'token'=>$token]);
    }

    if ($acao === 'obter_external_api_key') {
        $key=$pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave='external_api_key' LIMIT 1")->fetchColumn();
        if (!$key) {
            $key='jarvis_sec_v1_' . bin2hex(random_bytes(24));
            $pdo->prepare("INSERT INTO configuracoes_sistema (chave,valor,descricao) VALUES ('external_api_key',:k,'Chave mestre API v1') ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
                ->execute([':k'=>$key]);
        }
        crud_json(['status'=>'sucesso','api_key'=>$key]);
    }

    if ($acao === 'rotacionar_external_api_key') {
        $key='jarvis_sec_v1_' . bin2hex(random_bytes(24));
        $pdo->prepare("INSERT INTO configuracoes_sistema (chave,valor,descricao) VALUES ('external_api_key',:k,'Chave mestre API v1') ON DUPLICATE KEY UPDATE valor=VALUES(valor),atualizado_em=NOW()")
            ->execute([':k'=>$key]);
        registrar_evento_seguranca(get_client_ip(),'ROTACAO_CHAVE_API','Chave externa rotacionada','AVISO');
        crud_json(['status'=>'sucesso','mensagem'=>'Nova chave gerada','nova_api_key'=>$key]);
    }

    if ($acao === 'executar_tarefa') {
        $id=intval($input['id'] ?? ($_GET['id'] ?? 0));
        if ($id<=0) crud_json(['status'=>'erro','mensagem'=>'ID de tarefa inválido'],400);
        $stmt=$pdo->prepare("SELECT * FROM tarefas_agendadas WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        $t=$stmt->fetch();
        if (!$t) crud_json(['status'=>'erro','mensagem'=>'Tarefa não encontrada'],404);
        $pdo->prepare("UPDATE tarefas_agendadas SET ultima_execucao=NOW() WHERE id=:id")->execute([':id'=>$id]);
        $pdo->prepare("INSERT INTO comandos_log (comando,origem,resultado) VALUES (:c,'MANUAL_SCHEDULER','Enfileirado')")
            ->execute([':c'=>'Disparo manual: ' . $t['titulo']]);
        crud_json(['status'=>'sucesso','mensagem'=>'Tarefa enfileirada para arquitetura distribuída','tarefa'=>$t]);
    }

    if ($acao === 'desbloquear_ip') {
        $ip=trim($input['ip'] ?? ($_GET['ip'] ?? ''));
        if ($ip==='') crud_json(['status'=>'erro','mensagem'=>'IP obrigatório'],400);
        $pdo->prepare("DELETE FROM seguranca_ips_bloqueados WHERE ip_address=:ip")->execute([':ip'=>$ip]);
        registrar_evento_seguranca($ip,'DESBLOQUEIO_MANUAL','IP desbloqueado pelo administrador','INFO');
        crud_json(['status'=>'sucesso','mensagem'=>'IP desbloqueado']);
    }

    crud_json(['status'=>'erro','mensagem'=>'Ação desconhecida'],404);

} catch (Throwable $e) {
    error_log('CRUD CASA: ' . $e->getMessage());
    crud_json(['status'=>'erro','mensagem'=>'Falha interna ao acessar o banco CASA'],500);
}
?>
