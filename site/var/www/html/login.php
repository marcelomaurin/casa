<?php
// JARVIS/CASA - CONTROLE DE ACESSO
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once(__DIR__ . '/api/db.php');
$mensagem_erro = '';
$mensagem_sucesso = '';
$basePath = '/casa';

function login_log(string $evento, string $detalhe = ''): void {
    $dir = __DIR__ . '/log';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $linha = '[' . date('Y-m-d H:i:s') . '] [' . $ip . '] ' . $evento;
    if ($detalhe !== '') { $linha .= ' | ' . $detalhe; }
    $linha .= PHP_EOL;
    @file_put_contents($dir . '/login.log', $linha, FILE_APPEND | LOCK_EX);
}

if (isset($_GET['logout'])) {
    login_log('LOGOUT', 'usuario=' . ($_SESSION['auth_user'] ?? 'desconhecido'));
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    $mensagem_sucesso = 'Sessão encerrada com segurança. Acesso bloqueado.';
}
if (!empty($_SESSION['auth_user'])) {
    header('Location: ' . $basePath . '/index.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
    $senha = isset($_POST['senha']) ? (string)$_POST['senha'] : '';
    login_log('LOGIN_INICIO', 'usuario=' . strtolower($usuario));
    if ($usuario === '' || $senha === '') {
        login_log('LOGIN_FALHA', 'campos obrigatorios vazios');
        $mensagem_erro = 'Por favor, preencha o usuário e a senha de segurança.';
    } else {
        try {
            login_log('BANCO_CONEXAO', 'iniciando');
            $pdo = get_db_pdo();
            $dbAtual = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            login_log('BANCO_OK', 'database=' . $dbAtual);
            $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE (LOWER(login) = LOWER(:u) OR LOWER(email) = LOWER(:u)) AND ativo = 1 LIMIT 1');
            $stmt->execute([':u' => $usuario]);
            $user = $stmt->fetch();
            $autenticado = false;
            if (!$user) {
                login_log('USUARIO_NAO_ENCONTRADO', 'usuario=' . strtolower($usuario));
            } else {
                login_log('USUARIO_ENCONTRADO', 'id=' . ($user['id'] ?? '?') . '; login=' . ($user['login'] ?? '?') . '; ativo=' . ($user['ativo'] ?? '?') . '; perfil=' . ($user['perfil'] ?? '?'));
                if (hash_equals((string)$user['senha'], $senha)) {
                    $autenticado = true;
                    login_log('SENHA_OK', 'usuario=' . ($user['login'] ?? strtolower($usuario)));
                } else {
                    login_log('SENHA_INVALIDA', 'usuario=' . ($user['login'] ?? strtolower($usuario)) . '; tamanho_banco=' . strlen((string)$user['senha']) . '; tamanho_digitada=' . strlen($senha));
                }
            }
            if ($autenticado) {
                session_regenerate_id(true);
                $_SESSION['auth_user'] = $user['login'];
                $_SESSION['auth_name'] = $user['nome'];
                $_SESSION['auth_perfil'] = $user['perfil'] ?? 'admin';
                $_SESSION['auth_time'] = time();
                login_log('LOGIN_OK', 'usuario=' . $user['login'] . '; destino=' . $basePath . '/index.php');
                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $log = $pdo->prepare("INSERT INTO comandos_log (comando, origem, resultado) VALUES (:cmd, 'SEGURANCA', :res)");
                    $log->execute([':cmd' => 'Login Autorizado (' . $user['login'] . ')', ':res' => 'Sucesso - IP: ' . $ip]);
                } catch (Throwable $e) { login_log('LOG_BANCO_ERRO', get_class($e) . ': ' . $e->getMessage()); }
                header('Location: ' . $basePath . '/index.php'); exit;
            }
            login_log('LOGIN_NEGADO', 'usuario=' . strtolower($usuario));
            $mensagem_erro = 'Acesso negado: credenciais inválidas ou operador inativo.';
            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $log = $pdo->prepare("INSERT INTO comandos_log (comando, origem, resultado) VALUES (:cmd, 'SEGURANCA', :res)");
                $log->execute([':cmd' => 'Tentativa Falha de Acesso (' . $usuario . ')', ':res' => 'Falha - IP: ' . $ip]);
            } catch (Throwable $e) { login_log('LOG_BANCO_ERRO', get_class($e) . ': ' . $e->getMessage()); }
        } catch (Throwable $e) {
            login_log('ERRO_FATAL', get_class($e) . ': ' . $e->getMessage());
            $mensagem_erro = 'Falha no núcleo de segurança. Verifique o arquivo log/login.log.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>CASA/JARVIS - Acesso Restrito</title>
<style>:root{--bg:#f4e8d1;--panel:#fff8e8;--ink:#241f24;--orange:#e98b52;--salmon:#d96c75;--lav:#8d78a8;--blue:#617fa3;--muted:#6d6466}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);color:var(--ink);font-family:Arial,sans-serif}.lcars{width:min(94vw,760px);display:grid;grid-template-columns:170px 1fr;min-height:480px}.rail{background:var(--orange);border-radius:48px 0 0 48px;padding:90px 18px 18px;display:flex;flex-direction:column;gap:12px}.rail div{background:var(--salmon);min-height:44px;border-radius:22px 0 0 22px;padding:12px;font-weight:700;text-transform:uppercase}.rail div:nth-child(2){background:var(--lav)}.rail div:nth-child(3){background:var(--blue)}.content{background:var(--panel);border:14px solid var(--orange);border-left:0;border-radius:0 42px 42px 0;padding:42px}h1{margin:0;font-size:30px;letter-spacing:2px}.sub{color:var(--muted);margin:8px 0 30px}label{display:block;margin:16px 0 6px;font-weight:700;text-transform:uppercase;font-size:12px}input{width:100%;padding:14px;border:2px solid var(--lav);border-radius:18px;background:#fff;font-size:16px}button{width:100%;margin-top:24px;padding:15px;border:0;border-radius:22px;background:var(--salmon);color:#171317;font-weight:800;font-size:15px;cursor:pointer}.alert{padding:12px 16px;margin:14px 0;border-radius:18px;background:#fff;border-left:8px solid var(--salmon)}.success{border-left-color:#5f9b76}.foot{margin-top:28px;color:var(--muted);font-size:12px}@media(max-width:640px){.lcars{grid-template-columns:72px 1fr}.rail{padding:70px 8px 8px}.rail div{font-size:0}.content{padding:26px 20px}}</style></head><body>
<div class="lcars"><aside class="rail"><div>Acesso</div><div>Casa</div><div>Jarvis</div></aside><main class="content"><h1>CASA / JARVIS</h1><div class="sub">Controle de acesso distribuído</div>
<?php if ($mensagem_erro !== ''): ?><div class="alert"><?= htmlspecialchars($mensagem_erro) ?></div><?php endif; ?><?php if ($mensagem_sucesso !== ''): ?><div class="alert success"><?= htmlspecialchars($mensagem_sucesso) ?></div><?php endif; ?>
<form method="POST" action="<?= htmlspecialchars($basePath) ?>/login.php"><label for="usuario">Operador</label><input type="text" id="usuario" name="usuario" required autocomplete="username" autofocus autocapitalize="none" spellcheck="false"><label for="senha">Senha</label><input type="password" id="senha" name="senha" required autocomplete="current-password"><button type="submit">AUTORIZAR ACESSO</button></form><div class="foot">MAURINSOFT • MYSQL • MAURINSOFT.COM.BR/CASA</div></main></div></body></html>
