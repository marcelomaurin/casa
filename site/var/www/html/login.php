<?php
// JARVIS/CASA - CONTROLE DE ACESSO
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once(__DIR__ . '/api/db.php');

$mensagem_erro = '';
$mensagem_sucesso = '';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    $mensagem_sucesso = 'Sessão encerrada com segurança. Acesso bloqueado.';
}

if (!empty($_SESSION['auth_user'])) {
    header('Location: /');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
    $senha = isset($_POST['senha']) ? (string)$_POST['senha'] : '';

    if ($usuario === '' || $senha === '') {
        $mensagem_erro = 'Por favor, preencha o usuário e a senha de segurança.';
    } else {
        try {
            $pdo = get_db_pdo();
            $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE (login = :u OR email = :u) AND ativo = 1 LIMIT 1');
            $stmt->execute([':u' => $usuario]);
            $user = $stmt->fetch();
            $autenticado = false;

            if ($user && password_verify($senha, $user['senha'])) {
                $autenticado = true;
            }

            // Bootstrap opcional e seguro para instalação nova.
            // Defina JARVIS_BOOTSTRAP_ADMIN_PASSWORD apenas para criar o primeiro admin
            // e remova a variável após o primeiro login.
            if (!$user) {
                $bootstrap = getenv('JARVIS_BOOTSTRAP_ADMIN_PASSWORD');
                if ($bootstrap !== false && $bootstrap !== '' && $usuario === 'admin' && hash_equals($bootstrap, $senha)) {
                    $hash = password_hash($senha, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("INSERT INTO usuarios (nome, login, senha, email, perfil, ativo)
                        VALUES ('Administrador CASA', 'admin', :senha, NULL, 'admin', 1)
                        ON DUPLICATE KEY UPDATE senha = VALUES(senha), ativo = 1");
                    $ins->execute([':senha' => $hash]);
                    $stmt->execute([':u' => 'admin']);
                    $user = $stmt->fetch();
                    $autenticado = (bool)$user;
                }
            }

            if ($autenticado) {
                session_regenerate_id(true);
                $_SESSION['auth_user'] = $user['login'];
                $_SESSION['auth_name'] = $user['nome'];
                $_SESSION['auth_perfil'] = $user['perfil'] ?? 'admin';
                $_SESSION['auth_time'] = time();

                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $log = $pdo->prepare("INSERT INTO comandos_log (comando, origem, resultado) VALUES (:cmd, 'SEGURANCA', :res)");
                    $log->execute([
                        ':cmd' => 'Login Autorizado (' . $user['login'] . ')',
                        ':res' => 'Sucesso - IP: ' . $ip
                    ]);
                } catch (Throwable $e) {}

                header('Location: /');
                exit;
            }

            $mensagem_erro = 'Acesso negado: credenciais inválidas ou operador inativo.';
            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $log = $pdo->prepare("INSERT INTO comandos_log (comando, origem, resultado) VALUES (:cmd, 'SEGURANCA', :res)");
                $log->execute([
                    ':cmd' => 'Tentativa Falha de Acesso (' . $usuario . ')',
                    ':res' => 'Falha - IP: ' . $ip
                ]);
            } catch (Throwable $e) {}
        } catch (Throwable $e) {
            error_log('Falha no login CASA: ' . $e->getMessage());
            $mensagem_erro = 'Falha no núcleo de segurança. Verifique a configuração do banco.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASA/JARVIS - Acesso Restrito</title>
    <style>
        :root { --bg:#f4e8d1; --panel:#fff8e8; --ink:#241f24; --orange:#e98b52; --salmon:#d96c75; --lav:#8d78a8; --blue:#617fa3; --muted:#6d6466; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; background:var(--bg); color:var(--ink); font-family:Arial,sans-serif; }
        .lcars { width:min(94vw,760px); display:grid; grid-template-columns:170px 1fr; min-height:480px; }
        .rail { background:var(--orange); border-radius:48px 0 0 48px; padding:90px 18px 18px; display:flex; flex-direction:column; gap:12px; }
        .rail div { background:var(--salmon); min-height:44px; border-radius:22px 0 0 22px; padding:12px; font-weight:700; text-transform:uppercase; }
        .rail div:nth-child(2){background:var(--lav)} .rail div:nth-child(3){background:var(--blue)}
        .content { background:var(--panel); border:14px solid var(--orange); border-left:0; border-radius:0 42px 42px 0; padding:42px; }
        h1 { margin:0; font-size:30px; letter-spacing:2px; } .sub { color:var(--muted); margin:8px 0 30px; }
        label { display:block; margin:16px 0 6px; font-weight:700; text-transform:uppercase; font-size:12px; }
        input { width:100%; padding:14px; border:2px solid var(--lav); border-radius:18px; background:#fff; font-size:16px; }
        button { width:100%; margin-top:24px; padding:15px; border:0; border-radius:22px; background:var(--salmon); color:#171317; font-weight:800; font-size:15px; cursor:pointer; }
        .alert { padding:12px 16px; margin:14px 0; border-radius:18px; background:#fff; border-left:8px solid var(--salmon); }
        .success { border-left-color:#5f9b76; } .foot { margin-top:28px; color:var(--muted); font-size:12px; }
        @media(max-width:640px){ .lcars{grid-template-columns:72px 1fr}.rail{padding:70px 8px 8px}.rail div{font-size:0}.content{padding:26px 20px} }
    </style>
</head>
<body>
<div class="lcars">
    <aside class="rail"><div>Acesso</div><div>Casa</div><div>Jarvis</div></aside>
    <main class="content">
        <h1>CASA / JARVIS</h1>
        <div class="sub">Controle de acesso distribuído</div>
        <?php if ($mensagem_erro !== ''): ?><div class="alert"><?= htmlspecialchars($mensagem_erro) ?></div><?php endif; ?>
        <?php if ($mensagem_sucesso !== ''): ?><div class="alert success"><?= htmlspecialchars($mensagem_sucesso) ?></div><?php endif; ?>
        <form method="POST" action="/login.php">
            <label for="usuario">Operador</label>
            <input type="text" id="usuario" name="usuario" required autocomplete="username" autofocus>
            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha" required autocomplete="current-password">
            <button type="submit">AUTORIZAR ACESSO</button>
        </form>
        <div class="foot">MAURINSOFT • MYSQL • CASA.MAURINSOFT.COM.BR</div>
    </main>
</div>
</body>
</html>
