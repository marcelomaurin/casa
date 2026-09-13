<?php
// API REST generica para CRUDs das principais tabelas do casadb
// Suporta:
// - devices
// - devpar
// - sensores_telemetria
// - frases
// - usuarios
// - arm_nodes
// - configuracoes_sistema

header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');

// Verificação de Segurança (Sessão Web ou Token de API)
verify_api_auth();

$pdo = get_db_pdo();

$tabela = isset($_GET['tabela']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['tabela']) : '';
$acao = isset($_GET['acao']) ? $_GET['acao'] : (isset($_GET['action']) ? $_GET['action'] : 'listar');

require_once(__DIR__ . '/seguranca.php');

$tabelas_permitidas = [
    'devices', 'devpar', 'sensores_telemetria', 'frases', 
    'usuarios', 'arm_nodes', 'configuracoes_sistema', 'comandos_log', 'falas', 'llm_conversas',
    'tarefas_agendadas', 'dispositivos_cluster', 'seguranca_logs', 'seguranca_ips_bloqueados', 'agentes_externos'
];

if (!in_array($tabela, $tabelas_permitidas)) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'Tabela invalida']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

try {
    if ($acao === 'listar') {
        $limite = isset($_GET['limite']) ? intval($_GET['limite']) : 100;
        $busca = isset($_GET['q']) ? trim($_GET['q']) : '';
        
        // Identificar coluna de ordenação primária
        $ordem_col = 'id';
        if ($tabela === 'devices') $ordem_col = 'iddevice';
        elseif ($tabela === 'devpar') $ordem_col = 'idpar';
        elseif ($tabela === 'falas') $ordem_col = 'idfala';
        elseif ($tabela === 'configuracoes_sistema') $ordem_col = 'chave';

        $target_pdo = $pdo;
        $db_origem = "mestre (192.168.2.12)";
        if ($tabela === 'sensores_telemetria' || $tabela === 'comandos_log') {
            $sec = get_secondary_db_pdo();
            if ($sec) {
                $target_pdo = $sec;
                $db_origem = "secundario_cluster (192.168.2.8 - Cubieboard)";
            }
        }

        $sql = "SELECT * FROM {$tabela} ORDER BY {$ordem_col} DESC LIMIT {$limite}";
        $stmt = $target_pdo->query($sql);
        $dados = $stmt->fetchAll();

        echo json_encode(['status' => 'sucesso', 'tabela' => $tabela, 'origem_dados' => $db_origem, 'total' => count($dados), 'dados' => $dados], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'criar') {
        if (empty($input)) {
            echo json_encode(['status' => 'erro', 'mensagem' => 'Dados vazios para insercao']);
            exit;
        }

        // Remover campos nulos ou ids autoincremento se vazios
        unset($input['id']);
        unset($input['iddevice']);
        unset($input['idpar']);
        unset($input['idfala']);

        $colunas = array_keys($input);
        $placeholders = array_map(function($c) { return ":{$c}"; }, $colunas);

        $sql = "INSERT INTO {$tabela} (" . implode(',', $colunas) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        
        $params = [];
        foreach ($input as $k => $v) {
            $params[":{$k}"] = $v;
        }
        $stmt->execute($params);

        echo json_encode(['status' => 'sucesso', 'mensagem' => 'Registro criado com sucesso!']);
        exit;
    }

    if ($acao === 'atualizar') {
        $pk_col = 'id';
        if ($tabela === 'devices') $pk_col = 'iddevice';
        elseif ($tabela === 'devpar') $pk_col = 'idpar';
        elseif ($tabela === 'falas') $pk_col = 'idfala';
        elseif ($tabela === 'configuracoes_sistema') $pk_col = 'chave';

        $pk_val = isset($input[$pk_col]) ? $input[$pk_col] : (isset($_GET['id']) ? $_GET['id'] : null);
        if (!$pk_val) {
            echo json_encode(['status' => 'erro', 'mensagem' => "Identificador ({$pk_col}) ausente"]);
            exit;
        }

        unset($input[$pk_col]);
        $sets = [];
        $params = [":pk" => $pk_val];
        foreach ($input as $k => $v) {
            $sets[] = "{$k} = :{$k}";
            $params[":{$k}"] = $v;
        }

        $sql = "UPDATE {$tabela} SET " . implode(', ', $sets) . " WHERE {$pk_col} = :pk";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['status' => 'sucesso', 'mensagem' => 'Registro atualizado com sucesso!']);
        exit;
    }

    if ($acao === 'excluir') {
        $pk_col = 'id';
        if ($tabela === 'devices') $pk_col = 'iddevice';
        elseif ($tabela === 'devpar') $pk_col = 'idpar';
        elseif ($tabela === 'falas') $pk_col = 'idfala';
        elseif ($tabela === 'configuracoes_sistema') $pk_col = 'chave';

        $pk_val = isset($input[$pk_col]) ? $input[$pk_col] : (isset($_GET['id']) ? $_GET['id'] : null);
        if (!$pk_val) {
            echo json_encode(['status' => 'erro', 'mensagem' => "Identificador ({$pk_col}) ausente"]);
            exit;
        }

        $sql = "DELETE FROM {$tabela} WHERE {$pk_col} = :pk";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pk' => $pk_val]);

        echo json_encode(['status' => 'sucesso', 'mensagem' => 'Registro removido com sucesso!']);
        exit;
    }

    if ($acao === 'ping_nodes') {
        // Testar conectividade com os 4 nós ARM
        $stmt = $pdo->query("SELECT * FROM arm_nodes");
        $nodes = $stmt->fetchAll();
        $resultados = [];

        foreach ($nodes as $n) {
            $ip = $n['ip_address'];
            $online = false;
            // Teste rápido via socket na porta 22 (SSH) com timeout de 0.5s
            $fp = @fsockopen($ip, 22, $errno, $errstr, 0.5);
            if ($fp) {
                $online = true;
                fclose($fp);
            }
            $status = $online ? 'online' : 'inacessivel';
            
            $pdo->prepare("UPDATE arm_nodes SET status = :st, ultimo_ping = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':st' => $status, ':id' => $n['id']]);

            $resultados[] = [
                'id' => $n['id'],
                'hostname' => $n['hostname'],
                'ip' => $ip,
                'papel' => $n['papel'],
                'status' => $status
            ];
        }

        echo json_encode(['status' => 'sucesso', 'nodes' => $resultados]);
        exit;
    }

    if ($acao === 'heartbeat') {
        $hostname = isset($input['hostname']) ? $input['hostname'] : 'arm-node';
        $ip = isset($input['ip_address']) ? $input['ip_address'] : $_SERVER['REMOTE_ADDR'];
        $cpu = isset($input['cpu_info']) ? $input['cpu_info'] : 'ARM';
        $ram = isset($input['ram_info']) ? $input['ram_info'] : '';
        $st = isset($input['status']) ? $input['status'] : 'online';

        $check = $pdo->prepare("SELECT id FROM arm_nodes WHERE ip_address = :ip");
        $check->execute([':ip' => $ip]);
        $row = $check->fetch();

        if ($row) {
            $up = $pdo->prepare("UPDATE arm_nodes SET hostname = :h, ip_address = :ip, cpu_info = :c, ram_info = :r, status = :s, ultimo_ping = CURRENT_TIMESTAMP WHERE id = :id");
            $up->execute([':h' => $hostname, ':ip' => $ip, ':c' => $cpu, ':r' => $ram, ':s' => $st, ':id' => $row['id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO arm_nodes (hostname, ip_address, papel, status, cpu_info, ram_info) VALUES (:h, :ip, 'Nó Adicional', :s, :c, :r)");
            $ins->execute([':h' => $hostname, ':ip' => $ip, ':s' => $st, ':c' => $cpu, ':r' => $ram]);
        }

        echo json_encode(['status' => 'sucesso', 'mensagem' => 'Heartbeat recebido']);
        exit;
    }

    if ($acao === 'executar_tarefa') {
        $id_tarefa = isset($input['id']) ? intval($input['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
        if ($id_tarefa <= 0) {
            echo json_encode(['status' => 'erro', 'mensagem' => 'ID de tarefa inválido']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM tarefas_agendadas WHERE id = :id");
        $stmt->execute([':id' => $id_tarefa]);
        $tarefa = $stmt->fetch();

        if (!$tarefa) {
            echo json_encode(['status' => 'erro', 'mensagem' => 'Tarefa não encontrada']);
            exit;
        }

        $res_exec = "Disparado com sucesso";
        // Executar ação de acordo com o tipo
        if ($tarefa['tipo_acao'] === 'comando_jarvis') {
            $ch = curl_init("http://127.0.0.1/api/jarvis.php");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['comando' => $tarefa['payload']]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . get_system_api_token()]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $r = curl_exec($ch);
            curl_close($ch);
            if ($r) {
                $j = json_decode($r, true);
                $res_exec = isset($j['resposta']) ? $j['resposta'] : $r;
            }
        } elseif ($tarefa['tipo_acao'] === 'aviso_fala') {
            $target = $tarefa['target_node'];
            $url = ($target === 'local' || empty($target)) ? "http://127.0.0.1:8097/falar" : "http://{$target}:8098/falar";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['texto' => $tarefa['payload'], 'reproduzir' => true]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $r = curl_exec($ch);
            curl_close($ch);
            $res_exec = "Áudio disparado para {$target}";
        }

        $pdo->prepare("UPDATE tarefas_agendadas SET ultima_execucao = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':id' => $id_tarefa]);

        $pdo->prepare("INSERT INTO comandos_log (comando, origem, resultado) VALUES (:cmd, 'MANUAL_SCHEDULER', :res)")
            ->execute([':cmd' => "Disparo manual da tarefa: " . $tarefa['titulo'], ':res' => $res_exec]);

        echo json_encode(['status' => 'sucesso', 'mensagem' => "Tarefa '{$tarefa['titulo']}' executada!", 'detalhes' => $res_exec], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'heartbeat_iot') {
        $token = isset($_SERVER['HTTP_X_DEVICE_TOKEN']) ? $_SERVER['HTTP_X_DEVICE_TOKEN'] : (isset($input['device_token']) ? $input['device_token'] : '');
        $dev = validar_hardware_token($token);
        if (!$dev) {
            http_response_code(401);
            echo json_encode(['status' => 'erro', 'mensagem' => 'Hardware Token inválido ou não autorizado']);
            exit;
        }

        $ram = isset($input['ram_livre']) ? intval($input['ram_livre']) : $dev['ram_livre'];
        $rssi = isset($input['sinal_rssi']) ? intval($input['sinal_rssi']) : $dev['sinal_rssi'];
        $reles = isset($input['reles_status']) ? (is_array($input['reles_status']) ? json_encode($input['reles_status']) : $input['reles_status']) : (is_array($dev['reles_status']) ? json_encode($dev['reles_status']) : $dev['reles_status']);
        $ip = get_client_ip();

        $up = $pdo->prepare("UPDATE dispositivos_cluster SET 
            ip_address = :ip, 
            status = 'online', 
            ram_livre = :ram, 
            sinal_rssi = :rssi, 
            reles_status = :reles::jsonb, 
            ultimo_heartbeat = CURRENT_TIMESTAMP 
            WHERE id = :id");
        $up->execute([
            ':ip' => $ip,
            ':ram' => $ram,
            ':rssi' => $rssi,
            ':reles' => $reles,
            ':id' => $dev['id']
        ]);

        // Registrar métrica no histórico de sensores_telemetria
        if ($ram > 0) {
            $pdo->prepare("INSERT INTO sensores_telemetria (iddevice, sensor_nome, valor_numerico, unidade, raw_data) VALUES (1, :sname, :val, 'bytes', :raw)")
                ->execute([
                    ':sname' => 'RAM_Livre_' . $dev['nome'],
                    ':val' => $ram,
                    ':raw' => "RSSI: {$rssi} dBm, IP: {$ip}"
                ]);
        }

        echo json_encode(['status' => 'sucesso', 'mensagem' => 'Telemetria IoT recebida pelo cluster JARVIS', 'device_id' => $dev['id']]);
        exit;
    }

    if ($acao === 'acionar_rele_iot') {
        $dev_id = isset($input['device_id']) ? intval($input['device_id']) : 0;
        $rele_num = isset($input['rele']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $input['rele']) : 'rele1';
        $novo_estado = !empty($input['estado']) ? 1 : 0;

        $stmt = $pdo->prepare("SELECT * FROM dispositivos_cluster WHERE id = :id");
        $stmt->execute([':id' => $dev_id]);
        $dev = $stmt->fetch();

        if (!$dev) {
            echo json_encode(['status' => 'erro', 'mensagem' => 'Dispositivo não encontrado']);
            exit;
        }

        $reles = !empty($dev['reles_status']) ? (is_array($dev['reles_status']) ? $dev['reles_status'] : json_decode($dev['reles_status'], true)) : [];
        $reles[$rele_num] = $novo_estado;

        $pdo->prepare("UPDATE dispositivos_cluster SET reles_status = :r::jsonb WHERE id = :id")
            ->execute([':r' => json_encode($reles), ':id' => $dev_id]);

        // Se o dispositivo tiver IP online, despachar comando HTTP REST diretamente para a placa
        if (!empty($dev['ip_address']) && $dev['status'] === 'online') {
            $ch = curl_init("http://{$dev['ip_address']}/rele/{$rele_num}/" . ($novo_estado ? '1' : '0'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-JARVIS-TOKEN: " . $dev['device_token']]);
            @curl_exec($ch);
            curl_close($ch);
        }

        $pdo->prepare("INSERT INTO comandos_log (comando, origem, resultado) VALUES (:cmd, 'WEB_HUD_IOT', :res)")
            ->execute([':cmd' => "Acionamento IoT {$dev['nome']} ({$rele_num} = {$novo_estado})", ':res' => 'Executado']);

        echo json_encode(['status' => 'sucesso', 'reles_status' => $reles]);
        exit;
    }

    if ($acao === 'desbloquear_ip') {
        $ip = isset($input['ip']) ? trim($input['ip']) : (isset($_GET['ip']) ? trim($_GET['ip']) : '');
        if (!empty($ip)) {
            $pdo->prepare("DELETE FROM seguranca_ips_bloqueados WHERE ip_address = :ip")->execute([':ip' => $ip]);
            registrar_evento_seguranca($ip, 'DESBLOQUEIO_MANUAL', "IP desbloqueado manualmente pelo administrador via painel", 'INFO');
            echo json_encode(['status' => 'sucesso', 'mensagem' => "IP $ip desbloqueado com sucesso"]);
            exit;
        }
    }

    if ($acao === 'gerar_token_iot') {
        $nome = isset($input['nome']) ? trim($input['nome']) : 'Novo Dispositivo';
        $tipo = isset($input['tipo']) ? trim($input['tipo']) : 'esp32_generic';
        $local = isset($input['localizacao']) ? trim($input['localizacao']) : 'Residência';
        $token = gerar_novo_hardware_token($tipo);

        $stmt = $pdo->prepare("INSERT INTO dispositivos_cluster (nome, tipo, device_token, localizacao, status) 
            VALUES (:n, :t, :tok, :loc, 'aguardando_conexao') RETURNING id, device_token");
        $stmt->execute([':n' => $nome, ':t' => $tipo, ':tok' => $token, ':loc' => $local]);
        $row = $stmt->fetch();

        echo json_encode(['status' => 'sucesso', 'device' => $row, 'token' => $token]);
        exit;
    }

    if ($acao === 'status_tunnel') {
        $status_data = ['status' => 'offline', 'url' => '', 'atualizado_em' => ''];
        if (file_exists('/var/www/html/api/tunnel_status.json')) {
            $status_data = json_decode(file_get_contents('/var/www/html/api/tunnel_status.json'), true);
        }
        $stmt = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave = 'external_web_url'");
        $db_url = $stmt->fetchColumn();
        if (empty($status_data['url']) && !empty($db_url)) {
            $status_data['url'] = $db_url;
            $status_data['status'] = 'online';
        }
        echo json_encode(['status' => 'sucesso', 'tunnel' => $status_data]);
        exit;
    }

    if ($acao === 'obter_external_api_key') {
        $stmt = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave = 'external_api_key'");
        $key = $stmt->fetchColumn();
        if (!$key) {
            $key = "jarvis_sec_v1_" . bin2hex(random_bytes(24));
            $pdo->prepare("INSERT INTO configuracoes_sistema (chave, valor) VALUES ('external_api_key', :k) ON CONFLICT (chave) DO UPDATE SET valor = :k")
                ->execute([':k' => $key]);
        }
        echo json_encode(['status' => 'sucesso', 'api_key' => $key]);
        exit;
    }

    if ($acao === 'rotacionar_external_api_key') {
        $nova_chave = "jarvis_sec_v1_" . bin2hex(random_bytes(24));
        $pdo->prepare("INSERT INTO configuracoes_sistema (chave, valor) VALUES ('external_api_key', :k) ON CONFLICT (chave) DO UPDATE SET valor = :k")
            ->execute([':k' => $nova_chave]);
        registrar_evento_seguranca(get_client_ip(), 'ROTACAO_CHAVE_API', "Chave Mestre de Acesso Externo à API v1 rotacionada pelo administrador", 'AVISO');
        echo json_encode(['status' => 'sucesso', 'mensagem' => 'Nova chave de API gerada com sucesso!', 'nova_api_key' => $nova_chave]);
        exit;
    }

    if ($acao === 'reiniciar_tunnel') {
        exec("sudo systemctl restart casa-tunnel > /dev/null 2>&1 &");
        registrar_evento_seguranca(get_client_ip(), 'REINICIO_TUNEL', "Comando de reinicialização do túnel externo Cloudflare executado", 'INFO');
        echo json_encode(['status' => 'sucesso', 'mensagem' => 'Serviço do túnel seguro reiniciado. A nova URL estará disponível em alguns segundos.']);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'erro', 'mensagem' => $e->getMessage()]);
    exit;
}
?>
