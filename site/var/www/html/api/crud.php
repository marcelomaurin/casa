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
include_once(__DIR__ . '/../casa/config.php');
include_once(__DIR__ . '/../casa/funcs.php');

$pdo = get_db_pdo();

$tabela = isset($_GET['tabela']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['tabela']) : '';
$acao = isset($_GET['acao']) ? $_GET['acao'] : (isset($_GET['action']) ? $_GET['action'] : 'listar');

$tabelas_permitidas = [
    'devices', 'devpar', 'sensores_telemetria', 'frases', 
    'usuarios', 'arm_nodes', 'configuracoes_sistema', 'comandos_log', 'falas', 'llm_conversas'
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

        $sql = "SELECT * FROM {$tabela} ORDER BY {$ordem_col} DESC LIMIT {$limite}";
        $stmt = $pdo->query($sql);
        $dados = $stmt->fetchAll();

        echo json_encode(['status' => 'sucesso', 'tabela' => $tabela, 'total' => count($dados), 'dados' => $dados], JSON_UNESCAPED_UNICODE);
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

        $check = $pdo->prepare("SELECT id FROM arm_nodes WHERE ip_address = :ip OR hostname = :h");
        $check->execute([':ip' => $ip, ':h' => $hostname]);
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

} catch (Exception $e) {
    echo json_encode(['status' => 'erro', 'mensagem' => $e->getMessage()]);
    exit;
}
?>
