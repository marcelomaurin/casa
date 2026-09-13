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

$tabelas_permitidas = [
    'devices', 'devpar', 'sensores_telemetria', 'frases', 
    'usuarios', 'arm_nodes', 'configuracoes_sistema', 'comandos_log', 'falas', 'llm_conversas',
    'tarefas_agendadas'
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

} catch (Exception $e) {
    echo json_encode(['status' => 'erro', 'mensagem' => $e->getMessage()]);
    exit;
}
?>
