<?php
// CASA/JARVIS - API v1: localizacao compartilhada pelos celulares da familia.
// O envio so acontece quando o usuario ativa o compartilhamento no Casa Mobile.
//
// POST ?acao=enviar     registra a posicao do proprio aparelho (location.write ou mobile.write)
// GET  ?acao=ultimas    ultima posicao de cada aparelho nos ultimos 7 dias (location.read, family.read ou mobile.read)
// GET  ?acao=historico  trajeto de um aparelho (device_id, horas<=72)
// POST ?acao=apagar     apaga todo o historico do proprio aparelho
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');

const LOC_RETENCAO_DIAS = 30;
const LOC_INTERVALO_MIN_S = 20;

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$acao = (string)($_GET['acao'] ?? ($metodo === 'POST' ? 'enviar' : 'ultimas'));

function loc_num($v, float $min, float $max): ?float {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    return ($f < $min || $f > $max || is_nan($f)) ? null : $f;
}

function loc_aparelho(array $client): string {
    $d = trim((string)($client['device_id'] ?? ''));
    if ($d === '' || $d === 'master') {
        api_v1_json_response(403, ['status' => 'erro', 'mensagem' => 'Somente aparelhos pareados compartilham localizacao']);
    }
    return $d;
}

if ($acao === 'enviar' || $acao === 'apagar') {
    if ($metodo !== 'POST') { header('Allow: POST'); api_v1_json_response(405, ['status' => 'erro', 'mensagem' => 'Use POST']); }
    $client = api_v1_auth_client_any($pdo, ['location.write', 'mobile.write']);
    $deviceId = loc_aparelho($client);

    if ($acao === 'apagar') {
        $st = $pdo->prepare('DELETE FROM mobile_localizacoes WHERE device_id=:d');
        $st->execute([':d' => $deviceId]);
        api_v1_log($pdo, 'LOCATION_DELETED', 'INFO', $client['nome'] ?? null, ['removidos' => $st->rowCount()]);
        api_v1_json_response(200, ['status' => 'ok', 'removidos' => $st->rowCount()]);
    }

    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 16384), true);
    if (!is_array($in)) api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'JSON invalido']);
    $lat = loc_num($in['latitude'] ?? null, -90, 90);
    $lon = loc_num($in['longitude'] ?? null, -180, 180);
    if ($lat === null || $lon === null) api_v1_json_response(422, ['status' => 'erro', 'mensagem' => 'Latitude e longitude obrigatorias']);

    $ultimo = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, MAX(recebido_em), NOW()) FROM mobile_localizacoes WHERE device_id=:d');
    $ultimo->execute([':d' => $deviceId]);
    $idade = $ultimo->fetchColumn();
    if ($idade !== null && $idade !== false && (int)$idade < LOC_INTERVALO_MIN_S) {
        api_v1_json_response(200, ['status' => 'ok', 'gravado' => false, 'motivo' => 'intervalo_minimo']);
    }

    $capturado = null;
    if (!empty($in['capturado_em']) && is_numeric($in['capturado_em'])) {
        $ts = (int)$in['capturado_em'];
        if ($ts > 1000000000000) $ts = intdiv($ts, 1000); // milissegundos
        if ($ts > time() - 86400 && $ts <= time() + 300) $capturado = date('Y-m-d H:i:s', $ts);
    }
    $origem = preg_replace('/[^a-z_]/', '', strtolower((string)($in['origem'] ?? 'gps')));
    $bateria = loc_num($in['bateria'] ?? null, 0, 100);

    $pdo->prepare('INSERT INTO mobile_localizacoes
        (device_id, cliente, latitude, longitude, precisao_m, altitude_m, velocidade_ms, bateria, origem, capturado_em)
        VALUES (:d, :c, :lat, :lon, :p, :alt, :vel, :bat, :o, :cap)')
        ->execute([
            ':d' => $deviceId,
            ':c' => mb_substr((string)($client['nome'] ?? ''), 0, 160),
            ':lat' => $lat, ':lon' => $lon,
            ':p' => loc_num($in['precisao'] ?? null, 0, 100000),
            ':alt' => loc_num($in['altitude'] ?? null, -1000, 20000),
            ':vel' => loc_num($in['velocidade'] ?? null, 0, 500),
            ':bat' => $bateria === null ? null : (int)round($bateria),
            ':o' => substr($origem ?: 'gps', 0, 20),
            ':cap' => $capturado,
        ]);
    // Retencao: posicoes antigas sao descartadas aos poucos.
    if (random_int(1, 20) === 1) {
        $pdo->exec('DELETE FROM mobile_localizacoes WHERE recebido_em < DATE_SUB(NOW(), INTERVAL ' . LOC_RETENCAO_DIAS . ' DAY) LIMIT 1000');
    }
    api_v1_json_response(200, ['status' => 'ok', 'gravado' => true]);
}

if ($acao === 'ultimas' || $acao === 'historico') {
    if ($metodo !== 'GET') api_v1_json_response(405, ['status' => 'erro', 'mensagem' => 'Use GET']);
    $client = api_v1_auth_client_any($pdo, ['location.read', 'family.read', 'mobile.read']);
    $meu = trim((string)($client['device_id'] ?? ''));

    if ($acao === 'historico') {
        $deviceId = trim((string)($_GET['device_id'] ?? $meu));
        if (!preg_match('/^[A-Za-z0-9._:-]{1,120}$/', $deviceId)) api_v1_json_response(422, ['status' => 'erro', 'mensagem' => 'device_id invalido']);
        $horas = max(1, min(72, (int)($_GET['horas'] ?? 24)));
        $st = $pdo->prepare("SELECT latitude, longitude, precisao_m, velocidade_ms, bateria, COALESCE(capturado_em, recebido_em) AS quando
            FROM mobile_localizacoes WHERE device_id=:d AND recebido_em >= DATE_SUB(NOW(), INTERVAL {$horas} HOUR)
            ORDER BY recebido_em DESC LIMIT 500");
        $st->execute([':d' => $deviceId]);
        api_v1_json_response(200, ['status' => 'ok', 'device_id' => $deviceId, 'horas' => $horas, 'pontos' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    $st = $pdo->query("SELECT l.device_id, COALESCE(d.nome, l.cliente) AS nome, l.latitude, l.longitude, l.precisao_m,
            l.velocidade_ms, l.bateria, l.origem, COALESCE(l.capturado_em, l.recebido_em) AS quando,
            TIMESTAMPDIFF(SECOND, l.recebido_em, NOW()) AS segundos
        FROM mobile_localizacoes l
        JOIN (SELECT device_id, MAX(id) AS ultimo FROM mobile_localizacoes
              WHERE recebido_em >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY device_id) u ON u.ultimo = l.id
        LEFT JOIN dispositivos_cluster d ON d.device_id = l.device_id
        ORDER BY l.recebido_em DESC");
    $lista = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lista as &$l) $l['proprio'] = ($meu !== '' && $l['device_id'] === $meu);
    unset($l);
    api_v1_json_response(200, ['status' => 'ok', 'aparelhos' => $lista]);
}

api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'Acao desconhecida']);
