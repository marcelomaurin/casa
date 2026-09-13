<?php
// JARVIS CYBER-HUD - CONTROLE DE ACESSO E SEGURANCA RESIDENCIAL
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once(__DIR__ . '/api/db.php');

$mensagem_erro = '';
$mensagem_sucesso = '';

// Processar Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    $mensagem_sucesso = "Sessão encerrada com segurança. Acesso bloqueado.";
}

// Se já estiver logado, redirecionar para a interface central
if (!empty($_SESSION['auth_user'])) {
    header("Location: /");
    exit;
}

// Processar Tentativa de Login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
    $senha = isset($_POST['senha']) ? trim($_POST['senha']) : '';

    if (empty($usuario) || empty($senha)) {
        $mensagem_erro = "Por favor, preencha o usuário e a senha de segurança.";
    } else {
        try {
            $pdo = get_db_pdo();
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE (login = :u OR email = :u) AND ativo = TRUE LIMIT 1");
            $stmt->execute([':u' => $usuario]);
            $user = $stmt->fetch();

            $autenticado = false;

            if ($user) {
                // Verificar hash ou comparação direta
                if (password_verify($senha, $user['senha']) || $senha === $user['senha']) {
                    $autenticado = true;
                }
            } else {
                // Fallback de primeiro acesso para admin ou mmm
                if (($usuario === 'admin' || $usuario === 'mmm') && $senha === '226468') {
                    $pdo->exec("INSERT INTO usuarios (nome, login, senha, email, perfil, ativo) 
                               VALUES ('Marcelo Maurin', '{$usuario}', '{$senha}', 'marcelomaurinmartins@gmail.com', 'admin', TRUE) 
                               ON CONFLICT (login) DO NOTHING");
                    $autenticado = true;
                    $user = ['nome' => 'Marcelo Maurin', 'login' => $usuario, 'perfil' => 'admin'];
                }
            }

            if ($autenticado) {
                $_SESSION['auth_user'] = $user['login'];
                $_SESSION['auth_name'] = $user['nome'];
                $_SESSION['auth_perfil'] = isset($user['perfil']) ? $user['perfil'] : 'admin';
                $_SESSION['auth_time'] = time();

                // Registrar log de acesso
                try {
                    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
                    $pdo->exec("INSERT INTO comandos_log (comando, origem, resultado) VALUES ('Login Autorizado ({$user['login']})', 'SEGURANCA', 'Sucesso - IP: {$ip}')");
                } catch (Exception $e) {}

                header("Location: /");
                exit;
            } else {
                $mensagem_erro = "Acesso negado: Credenciais inválidas ou operador inativo.";
                try {
                    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
                    $pdo->exec("INSERT INTO comandos_log (comando, origem, resultado) VALUES ('Tentativa Falha de Acesso ({$usuario})', 'SEGURANCA', 'Falha - IP: {$ip}')");
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            $mensagem_erro = "Falha no núcleo de segurança: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JARVIS OS - Acesso Restrito Residencial</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;800;900&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --cyan: #00f0ff;
            --cyan-glow: rgba(0, 240, 255, 0.4);
            --blue: #0070f3;
            --dark-bg: #050b14;
            --panel-bg: rgba(10, 25, 47, 0.85);
            --border-glow: rgba(0, 240, 255, 0.25);
            --danger: #ff0055;
            --success: #00ffaa;
            --text-main: #e2f1ff;
            --text-muted: #88a2b8;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--dark-bg);
            background-image: 
                radial-gradient(circle at 50% 30%, rgba(0, 112, 243, 0.15) 0%, transparent 60%),
                linear-gradient(rgba(5, 11, 20, 0.95), rgba(5, 11, 20, 0.95)),
                repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(0, 240, 255, 0.02) 2px, rgba(0, 240, 255, 0.02) 4px);
            color: var(--text-main);
            font-family: 'Rajdhani', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        /* Ambient scanline animation */
        body::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, transparent, var(--cyan), transparent);
            opacity: 0.6;
            animation: scanline 6s linear infinite;
        }

        @keyframes scanline {
            0% { transform: translateY(-100vh); }
            100% { transform: translateY(100vh); }
        }

        .login-card {
            background: var(--panel-bg);
            border: 1px solid var(--border-glow);
            border-radius: 16px;
            width: 100%;
            max-width: 440px;
            padding: 40px;
            backdrop-filter: blur(20px);
            box-shadow: 0 0 50px rgba(0, 240, 255, 0.12), inset 0 0 20px rgba(0, 240, 255, 0.05);
            position: relative;
            z-index: 10;
        }

        .login-card::after {
            content: "";
            position: absolute;
            top: -2px; left: -2px; right: -2px; bottom: -2px;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--cyan), transparent, var(--blue));
            z-index: -1;
            opacity: 0.3;
        }

        .reactor-mini {
            width: 70px;
            height: 70px;
            margin: 0 auto 20px auto;
            border-radius: 50%;
            border: 2px dashed var(--cyan);
            display: flex;
            align-items: center;
            justify-content: center;
            animation: spin 10s linear infinite;
            box-shadow: 0 0 20px var(--cyan-glow);
        }

        .reactor-core {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: radial-gradient(circle, #fff, var(--cyan) 70%, transparent);
            animation: pulse 2s ease-in-out infinite alternate;
        }

        @keyframes spin {
            100% { transform: rotate(360deg); }
        }

        @keyframes pulse {
            0% { transform: scale(0.85); box-shadow: 0 0 10px var(--cyan); }
            100% { transform: scale(1.1); box-shadow: 0 0 25px var(--cyan); }
        }

        .header-title {
            text-align: center;
            font-family: 'Orbitron', sans-serif;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: 3px;
            color: #fff;
            text-shadow: 0 0 15px var(--cyan-glow);
            margin-bottom: 6px;
        }

        .header-subtitle {
            text-align: center;
            color: var(--cyan);
            font-size: 13px;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 28px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            color: var(--text-muted);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 8px;
        }

        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 14px 18px;
            background: rgba(5, 15, 30, 0.7);
            border: 1px solid rgba(0, 240, 255, 0.3);
            border-radius: 8px;
            color: #fff;
            font-family: 'Rajdhani', sans-serif;
            font-size: 16px;
            font-weight: 600;
            letter-spacing: 1px;
            outline: none;
            transition: all 0.3s ease;
        }

        input[type="text"]:focus, input[type="password"]:focus {
            border-color: var(--cyan);
            box-shadow: 0 0 15px var(--cyan-glow);
            background: rgba(8, 22, 45, 0.9);
        }

        .btn-submit {
            width: 100%;
            padding: 15px;
            margin-top: 10px;
            background: linear-gradient(135deg, rgba(0, 240, 255, 0.2), rgba(0, 112, 243, 0.4));
            border: 1px solid var(--cyan);
            border-radius: 8px;
            color: #fff;
            font-family: 'Orbitron', sans-serif;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 0 15px rgba(0, 240, 255, 0.2);
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, var(--cyan), var(--blue));
            color: #000;
            box-shadow: 0 0 30px var(--cyan-glow);
            transform: translateY(-1px);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger {
            background: rgba(255, 0, 85, 0.15);
            border: 1px solid var(--danger);
            color: #ff8ca3;
        }

        .alert-success {
            background: rgba(0, 255, 170, 0.15);
            border: 1px solid var(--success);
            color: #99ffd5;
        }

        .footer-note {
            text-align: center;
            margin-top: 24px;
            font-size: 11px;
            color: var(--text-muted);
            letter-spacing: 1px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="reactor-mini">
        <div class="reactor-core"></div>
    </div>
    <div class="header-title">JARVIS RESIDENCIAL</div>
    <div class="header-subtitle">CONTROLE DE ACESSO &bull; SISTEMA PRIVADO</div>

    <?php if (!empty($mensagem_erro)): ?>
        <div class="alert alert-danger">
            <span>&#9888;</span>
            <div><?= htmlspecialchars($mensagem_erro) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($mensagem_sucesso)): ?>
        <div class="alert alert-success">
            <span>&#10004;</span>
            <div><?= htmlspecialchars($mensagem_sucesso) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="/login.php">
        <div class="form-group">
            <label for="usuario">Identificação do Operador</label>
            <input type="text" id="usuario" name="usuario" required placeholder="Digite seu usuário ou login" autofocus autocomplete="username">
        </div>

        <div class="form-group">
            <label for="senha">Chave de Segurança / Senha</label>
            <input type="password" id="senha" name="senha" required placeholder="Digite a chave de acesso" autocomplete="current-password">
        </div>

        <button type="submit" class="btn-submit">Autorizar Acesso</button>
    </form>

    <div class="footer-note">
        MAURINSOFT &bull; CLUSTER ARM 4X &bull; POSTGRESQL &bull; SECURE PROTOCOL
    </div>
</div>

</body>
</html>
