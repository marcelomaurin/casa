<?php
/**
 * JARVIS RESIDENCIAL
 * Autorização de reconfiguração da ESP32-CAM.
 *
 * Este endpoint NÃO grava SSID ou senha Wi-Fi.
 * Ele apenas confirma se um usuário ativo do JARVIS possui credenciais válidas.
 * A ESP32-CAM usa a resposta para decidir se pode persistir uma nova rede Wi-Fi.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once(__DIR__ . '/db.php');

function responder(int $status, array $dados): never
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip(): string
{
    // Não confia automaticamente em X-Forwarded-For/CF-Connecting-IP.
    // O IP usado aqui é o endereço que realmente abriu a conexão com o Apache.
    return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responder(405, [
        'ok' => false,
        'erro' => 'method_not_allowed'
    ]);
}

$raw = file_get_contents('php://input');
$dados = json_decode($raw ?: '', true);

if (!is_array($dados)) {
    responder(400, [
        'ok' => false,
        'erro' => 'json_invalido'
    ]);
}

$usuario = trim((string)($dados['usuario'] ?? ''));
$senha = (string)($dados['senha'] ?? '');
$dispositivo = trim((string)($dados['dispositivo'] ?? 'ESP32-CAM'));
$ip = client_ip();

if ($usuario === '' || $senha === '') {
    responder(400, [
        'ok' => false,
        'erro' => 'credenciais_obrigatorias'
    ]);
}

// Limites simples para impedir payloads excessivos.
if (strlen($usuario) > 150 || strlen($senha) > 512 || strlen($dispositivo) > 150) {
    responder(400, [
        'ok' => false,
        'erro' => 'dados_invalidos'
    ]);
}

try {
    $pdo = get_db_pdo();

    // Rate limit por IP apenas para falhas deste endpoint.
    // 10 falhas em 15 minutos bloqueiam temporariamente novas tentativas.
    $stmtRate = $pdo->prepare(
        "SELECT COUNT(*)
           FROM comandos_log
          WHERE origem = 'ESPCAM_PROVISION_FAIL'
            AND resultado LIKE :ip
            AND data_hora > NOW() - INTERVAL '15 minutes'"
    );
    $stmtRate->execute([':ip' => '%IP=' . $ip . '%']);
    $falhasRecentes = (int)$stmtRate->fetchColumn();

    if ($falhasRecentes >= 10) {
        responder(429, [
            'ok' => false,
            'erro' => 'muitas_tentativas'
        ]);
    }

    $stmt = $pdo->prepare(
        "SELECT id, nome, login, email, senha, perfil
           FROM usuarios
          WHERE (login = :u OR email = :u)
            AND ativo = TRUE
          LIMIT 1"
    );
    $stmt->execute([':u' => $usuario]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $autenticado = false;

    if ($user) {
        $hashOuSenha = (string)$user['senha'];

        // Compatibilidade com a base atual:
        // 1) hashes password_hash/password_verify;
        // 2) registros legados ainda armazenados como texto puro.
        // O ideal é migrar todos os usuários para password_hash e remover
        // a segunda comparação quando a migração estiver concluída.
        if (password_verify($senha, $hashOuSenha) || hash_equals($hashOuSenha, $senha)) {
            $autenticado = true;
        }
    }

    if (!$autenticado) {
        $stmtLog = $pdo->prepare(
            "INSERT INTO comandos_log (comando, origem, resultado)
             VALUES (:comando, 'ESPCAM_PROVISION_FAIL', :resultado)"
        );
        $stmtLog->execute([
            ':comando' => 'Reconfiguração ESP32-CAM negada (' . mb_substr($usuario, 0, 80) . ')',
            ':resultado' => 'Falha - IP=' . $ip . ' - DEVICE=' . mb_substr($dispositivo, 0, 80)
        ]);

        // Resposta propositalmente genérica para não revelar se o usuário existe.
        responder(401, [
            'ok' => false,
            'erro' => 'credenciais_invalidas'
        ]);
    }

    $stmtLog = $pdo->prepare(
        "INSERT INTO comandos_log (comando, origem, resultado)
         VALUES (:comando, 'ESPCAM_PROVISION', :resultado)"
    );
    $stmtLog->execute([
        ':comando' => 'Reconfiguração ESP32-CAM autorizada (' . mb_substr((string)$user['login'], 0, 80) . ')',
        ':resultado' => 'Sucesso - IP=' . $ip . ' - DEVICE=' . mb_substr($dispositivo, 0, 80)
    ]);

    responder(200, [
        'ok' => true,
        'usuario' => (string)$user['login'],
        'perfil' => (string)($user['perfil'] ?? '')
    ]);

} catch (Throwable $e) {
    error_log('espcam_provision.php: ' . $e->getMessage());

    responder(500, [
        'ok' => false,
        'erro' => 'erro_interno'
    ]);
}
