<?php
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/seguranca.php');

verify_api_auth();
$pdo = get_db_pdo();

function internet_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function chamar_web_agent($query, $maxResults = 5, $fetchPages = true) {
    $payload = json_encode([
        'query' => $query,
        'max_results' => max(1, min(10, intval($maxResults))),
        'fetch_pages' => (bool)$fetchPages
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('http://127.0.0.1:8099/search');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$res || $code < 200 || $code >= 300) {
        return ['ok' => false, 'erro' => $err ?: ('HTTP web-agent ' . $code)];
    }
    $j = json_decode($res, true);
    if (!is_array($j)) return ['ok' => false, 'erro' => 'Resposta invalida do web-agent'];
    return ['ok' => true, 'dados' => $j];
}

function chamar_jarvis_com_fontes($pergunta, $fontes) {
    $contexto = "PESQUISA ATUAL NA INTERNET. Use as fontes abaixo para fatos atuais. " .
                "Se houver divergencia entre fontes, informe. Cite as fontes no texto como [1], [2], etc.\n\n";
    $i = 1;
    foreach ($fontes as $f) {
        $titulo = $f['titulo'] ?? '';
        $url = $f['url'] ?? '';
        $trecho = $f['trecho'] ?? '';
        $conteudo = $f['conteudo'] ?? '';
        if (strlen($conteudo) > 3500) $conteudo = substr($conteudo, 0, 3500);
        $contexto .= "[$i] TITULO: $titulo\nURL: $url\nTRECHO: $trecho\nCONTEUDO: $conteudo\n\n";
        $i++;
        if (strlen($contexto) > 16000) break;
    }

    $prompt = $pergunta . "\n\n" . $contexto;
    $token = get_system_api_token();
    $payload = json_encode([
        'comando' => '/cloud ' . $prompt,
        'origem' => 'AGENTE_INTERNET',
        'skip_planner' => true,
        'task_context' => $GLOBALS['taskContext']
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('http://127.0.0.1/api/jarvis.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . $token
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 50);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$res || $code < 200 || $code >= 300) {
        return ['ok' => false, 'erro' => $err ?: ('HTTP JARVIS ' . $code)];
    }
    $j = json_decode($res, true);
    if (!is_array($j)) return ['ok' => false, 'erro' => 'Resposta invalida do JARVIS'];
    return ['ok' => true, 'dados' => $j];
}

$in = internet_input();
$taskContext = is_array($in['task_context'] ?? null) ? $in['task_context'] : null;
$acao = $_GET['acao'] ?? ($_POST['acao'] ?? ($in['acao'] ?? 'pesquisar'));
$query = trim($in['query'] ?? ($in['pergunta'] ?? ($_POST['query'] ?? ($_GET['q'] ?? ''))));
$maxResults = intval($in['max_results'] ?? ($_POST['max_results'] ?? 5));

if ($acao === 'status') {
    $ch = curl_init('http://127.0.0.1:8099/health');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo json_encode([
        'status' => ($res && $code === 200) ? 'ok' : 'erro',
        'web_agent' => $res ? json_decode($res, true) : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($query === '') {
    http_response_code(400);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Consulta vazia'], JSON_UNESCAPED_UNICODE);
    exit;
}

$busca = chamar_web_agent($query, $maxResults, true);
if (!$busca['ok']) {
    try {
        $stmt = $pdo->prepare("INSERT INTO internet_pesquisas (consulta,status,erro) VALUES (:q,'ERRO',:e)");
        $stmt->execute([':q' => $query, ':e' => $busca['erro']]);
    } catch (Exception $e) {}
    http_response_code(502);
    echo json_encode(['status' => 'erro', 'mensagem' => $busca['erro']], JSON_UNESCAPED_UNICODE);
    exit;
}

$dados = $busca['dados'];
$fontes = $dados['resultados'] ?? [];
$provedor = $fontes[0]['provedor'] ?? 'desconhecido';
$respostaIa = null;
$jarvis = null;

if ($acao === 'perguntar' || $acao === 'responder') {
    $j = chamar_jarvis_com_fontes($query, $fontes);
    if (!$j['ok']) {
        http_response_code(502);
        echo json_encode(['status' => 'erro', 'mensagem' => $j['erro']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $jarvis = $j['dados'];
    $respostaIa = $jarvis['resposta'] ?? '';
}

try {
    $stmt = $pdo->prepare("INSERT INTO internet_pesquisas
        (consulta, provedor, quantidade_resultados, fontes, resposta_ia, status)
        VALUES (:q,:p,:n,CAST(:f AS jsonb),:r,'OK') RETURNING id");
    $stmt->execute([
        ':q' => $query,
        ':p' => $provedor,
        ':n' => count($fontes),
        ':f' => json_encode($fontes, JSON_UNESCAPED_UNICODE),
        ':r' => $respostaIa
    ]);
    $idPesquisa = intval($stmt->fetchColumn());
} catch (Exception $e) {
    $idPesquisa = null;
}

echo json_encode([
    'status' => 'ok',
    'id_pesquisa' => $idPesquisa,
    'query' => $query,
    'provedor' => $provedor,
    'fontes' => $fontes,
    'resposta' => $respostaIa,
    'audio_url' => $jarvis['audio_url'] ?? null
], JSON_UNESCAPED_UNICODE);
