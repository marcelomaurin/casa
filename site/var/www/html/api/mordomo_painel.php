<?php
// CASA/JARVIS - Painel do Mordomo (sessão do painel + token da sessão nos POST).
// GET  ?acao=estado                 -> ajustes, situação do ciclo, casos e decisões
// POST {acao:'salvar', ajustes:{}}   -> autonomia e limites (perfil admin)
// POST {acao:'dispensar', id}        -> "já vi" num caso aberto
// POST {acao:'ciclo'}                -> roda um ciclo agora
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mordomo.php';

function mp_out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['auth_user'])) mp_out(401, ['status' => 'erro', 'mensagem' => 'Sessão expirada.']);
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo === 'POST') {
    $sent = (string)($_SERVER['HTTP_X_CASA_SESSION_TOKEN'] ?? '');
    $tok = (string)($_SESSION['jarvis_session_token'] ?? '');
    if ($sent === '' || $tok === '' || !hash_equals($tok, $sent)) mp_out(403, ['status' => 'erro', 'mensagem' => 'Sessão inválida. Recarregue a página.']);
}
$usuario = (string)$_SESSION['auth_user'];
$admin = strtolower((string)($_SESSION['auth_perfil'] ?? 'admin')) === 'admin';
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$acao = (string)($in['acao'] ?? ($_GET['acao'] ?? 'estado'));
$pdo = get_db_pdo();

// Ajustes editáveis: chave => [tipo, mínimo, máximo, descrição]
const MP_AJUSTES = [
    'mordomo_autonomia' => ['autonomia', null, null, 'Autonomia do mordomo: observar, avisar ou agir'],
    'mordomo_offline_min' => ['int', 2, 1440, 'Minutos sem contato para considerar um dispositivo fora do ar'],
    'mordomo_bateria_min' => ['int', 1, 100, 'Bateria (%) abaixo da qual o mordomo avisa'],
    'mordomo_temp_min' => ['num', -20, 60, 'Temperatura mínima aceitável (°C)'],
    'mordomo_temp_max' => ['num', -10, 80, 'Temperatura máxima aceitável (°C)'],
    'mordomo_irrigacao_max_min' => ['int', 5, 1440, 'Minutos máximos com irrigação/bomba ligada'],
    'mordomo_silencio' => ['silencio', null, null, 'Horário de silêncio (início-fim, em horas)'],
    'mordomo_lembrete_min' => ['int', 5, 1440, 'Minutos entre lembretes de um caso grave'],
    'mordomo_ignorar_tipos' => ['tipos', null, null, 'Tipos de dispositivo que podem ficar fora do ar sem aviso'],
];

function mp_rotulo(string $k): string {
    return ['mordomo_autonomia' => 'autonomia', 'mordomo_offline_min' => 'fora do ar após', 'mordomo_bateria_min' => 'bateria baixa',
        'mordomo_temp_min' => 'temperatura mínima', 'mordomo_temp_max' => 'temperatura máxima', 'mordomo_irrigacao_max_min' => 'irrigação máxima',
        'mordomo_silencio' => 'silêncio', 'mordomo_lembrete_min' => 'lembrete', 'mordomo_ignorar_tipos' => 'tipos ignorados'][$k] ?? $k;
}
function mp_mostrar(string $k, string $v): string {
    if ($k === 'mordomo_silencio') return $v === '0-0' ? 'sem silêncio' : preg_replace('/^(\d+)-(\d+)$/', '$1h às $2h', $v);
    if ($k === 'mordomo_autonomia') return strtoupper($v);
    $un = ['mordomo_offline_min' => ' min', 'mordomo_bateria_min' => '%', 'mordomo_temp_min' => ' °C', 'mordomo_temp_max' => ' °C',
        'mordomo_irrigacao_max_min' => ' min', 'mordomo_lembrete_min' => ' min'][$k] ?? '';
    return $v . $un;
}

function mp_validar(string $chave, $valor): string {
    [$tipo, $min, $max] = MP_AJUSTES[$chave];
    $v = trim((string)$valor);
    switch ($tipo) {
        case 'autonomia':
            if (!in_array($v, ['observar', 'avisar', 'agir'], true)) throw new InvalidArgumentException('Autonomia inválida');
            return $v;
        case 'int':
            if (!preg_match('/^\d+$/', $v) || (int)$v < $min || (int)$v > $max) throw new InvalidArgumentException("Valor de {$chave} deve ser inteiro entre {$min} e {$max}");
            return (string)(int)$v;
        case 'num':
            $v = str_replace(',', '.', $v);
            if (!is_numeric($v) || (float)$v < $min || (float)$v > $max) throw new InvalidArgumentException("Valor de {$chave} deve estar entre {$min} e {$max}");
            return rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
        case 'silencio':
            if ($v === '' || $v === '0-0') return '0-0';
            if (!preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $v, $m) || (int)$m[1] > 23 || (int)$m[2] > 23 || $m[1] === $m[2]) {
                throw new InvalidArgumentException('Horário de silêncio inválido (use início-fim, de 0 a 23)');
            }
            return (int)$m[1] . '-' . (int)$m[2];
        case 'tipos':
            $lista = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim($x)), explode(',', $v)))));
            foreach ($lista as $t) if (!preg_match('/^[a-z0-9_\-]{1,40}$/', $t)) throw new InvalidArgumentException("Tipo inválido: {$t}");
            return implode(',', array_slice($lista, 0, 30));
    }
    throw new InvalidArgumentException('Ajuste desconhecido');
}

try {
    if ($acao === 'estado') {
        $cfg = md_config($pdo);
        $ajustes = [];
        foreach (array_keys(MP_AJUSTES) as $k) $ajustes[$k] = $cfg[$k];
        $ultimo = $cfg['mordomo_ultimo_ciclo'] ?? null;
        $daemon = [];
        try {
            $daemon = $pdo->query("SELECT nome, host, versao, ultimo_contato FROM jarvis_trabalhadores
                WHERE ultimo_contato >= NOW() - INTERVAL 5 MINUTE ORDER BY ultimo_contato DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        $casos = $pdo->query("SELECT id,tipo,severidade,titulo,status,primeira_vez,ultima_vez,ultimo_aviso,avisos
            FROM jarvis_mordomo_observacoes WHERE status IN ('ABERTA','DISPENSADA')
            ORDER BY status='ABERTA' DESC, FIELD(severidade,'alta','media','baixa'), primeira_vez DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
        $resolvidos = $pdo->query("SELECT id,tipo,severidade,titulo,primeira_vez,resolvida_em FROM jarvis_mordomo_observacoes
            WHERE status='RESOLVIDA' AND resolvida_em >= NOW() - INTERVAL 1 DAY ORDER BY resolvida_em DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        $decisoes = $pdo->query("SELECT d.id,d.acao,d.mensagem,d.ordem,d.motivo,d.origem,d.executado,d.resultado,d.criado_em,o.titulo,o.severidade
            FROM jarvis_mordomo_decisoes d LEFT JOIN jarvis_mordomo_observacoes o ON o.id=d.observacao_id
            ORDER BY d.id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
        $cont = $pdo->query("SELECT SUM(acao='avisar') avisos, SUM(acao='agir') acoes, SUM(acao='ignorar') ignorados
            FROM jarvis_mordomo_decisoes WHERE criado_em >= NOW() - INTERVAL 1 DAY")->fetch(PDO::FETCH_ASSOC) ?: [];
        mp_out(200, [
            'status' => 'ok', 'admin' => $admin, 'agora' => date('Y-m-d H:i:s'),
            'ajustes' => $ajustes,
            'ciclo' => ['ultimo' => $ultimo, 'segundos' => $ultimo ? time() - strtotime($ultimo) : null, 'daemons' => $daemon],
            'casos' => $casos, 'resolvidos_24h' => $resolvidos, 'decisoes' => $decisoes,
            'contagem_24h' => ['avisos' => (int)($cont['avisos'] ?? 0), 'acoes' => (int)($cont['acoes'] ?? 0), 'ignorados' => (int)($cont['ignorados'] ?? 0)],
        ]);
    }
    if ($metodo !== 'POST') mp_out(405, ['status' => 'erro', 'mensagem' => 'Use POST para esta ação.']);

    if ($acao === 'salvar') {
        if (!$admin) mp_out(403, ['status' => 'erro', 'mensagem' => 'Somente administradores alteram os ajustes do mordomo.']);
        $novos = is_array($in['ajustes'] ?? null) ? $in['ajustes'] : [];
        $validos = [];
        foreach ($novos as $k => $v) {
            if (!isset(MP_AJUSTES[$k])) continue;
            try { $validos[$k] = mp_validar($k, $v); }
            catch (InvalidArgumentException $e) { mp_out(422, ['status' => 'erro', 'mensagem' => $e->getMessage(), 'campo' => $k]); }
        }
        $atual = md_config($pdo);
        $tmin = (float)($validos['mordomo_temp_min'] ?? $atual['mordomo_temp_min']);
        $tmax = (float)($validos['mordomo_temp_max'] ?? $atual['mordomo_temp_max']);
        if ($tmin >= $tmax) mp_out(422, ['status' => 'erro', 'mensagem' => 'A temperatura mínima deve ser menor que a máxima.', 'campo' => 'mordomo_temp_min']);
        $mudou = [];
        foreach ($validos as $k => $v) {
            if ((string)$atual[$k] === $v) continue;
            md_gravar_config($pdo, $k, $v, MP_AJUSTES[$k][3]);
            $mudou[] = mp_rotulo($k) . ': ' . mp_mostrar($k, (string)$atual[$k]) . ' → ' . mp_mostrar($k, $v);
        }
        if ($mudou) {
            try {
                $pdo->prepare("INSERT INTO comandos_log (comando,origem,resultado) VALUES (:c,'PAINEL',:r)")
                    ->execute([':c' => 'Ajustes do mordomo', ':r' => mb_substr(implode('; ', $mudou) . ' (por ' . $usuario . ')', 0, 2000, 'UTF-8')]);
            } catch (Throwable $e) {}
        }
        mp_out(200, ['status' => 'ok', 'alterados' => $mudou]);
    }

    if ($acao === 'dispensar') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) mp_out(400, ['status' => 'erro', 'mensagem' => 'Caso não informado.']);
        try { $r = md_dispensar($pdo, $id, $usuario); }
        catch (RuntimeException $e) { mp_out(409, ['status' => 'erro', 'mensagem' => $e->getMessage()]); }
        mp_out(200, ['status' => 'ok'] + $r);
    }

    if ($acao === 'ciclo') {
        if ((int)$pdo->query("SELECT GET_LOCK('casa_mordomo_ciclo', 0)")->fetchColumn() !== 1) {
            mp_out(409, ['status' => 'erro', 'mensagem' => 'Um ciclo já está em andamento. Tente em instantes.']);
        }
        try { $r = md_ciclo($pdo); }
        finally { $pdo->query("SELECT RELEASE_LOCK('casa_mordomo_ciclo')"); }
        mp_out(200, ['status' => 'ok'] + $r);
    }

    mp_out(404, ['status' => 'erro', 'mensagem' => 'Ação desconhecida.']);
} catch (Throwable $e) {
    error_log('mordomo_painel: ' . $e->getMessage());
    mp_out(500, ['status' => 'erro', 'mensagem' => 'Falha no painel do mordomo.']);
}
