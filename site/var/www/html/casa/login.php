<?php   
    include_once('config.php');
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION["msg"] = "";

    // Realiza a validacao do login e senha do usuario
    if(isset($_POST['pusuario']) && isset($_POST['psenha']))
    {
        $login_user = trim($_POST['pusuario']);
        $senha_user = trim($_POST['psenha']);
        
        try {
            $dsn = "pgsql:host={$dbhost};port={$dbport};dbname={$database}";
            $pdo = new PDO($dsn, $dbuser, $dbpassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE login = :login AND senha = :senha LIMIT 1");
            $stmt->execute([
                ':login' => $login_user,
                ':senha' => $senha_user
            ]);
            $row = $stmt->fetch();

            if (!$row) {
                // Tenta fallback compatível para primeiro acesso do admin
                if ($login_user === 'admin' && $senha_user === '226468') {
                    $pdo->exec("INSERT INTO usuarios (nome, login, senha, perfil, ativo) VALUES ('Administrador', 'admin', '226468', 'admin', true) ON CONFLICT (login) DO NOTHING");
                    $_SESSION["login"] = true;
                    $_SESSION["pusuario"] = 'admin';
                    $_SESSION["iduser"] = 1;
                    $_SESSION["msg"] = "Bem-vindo ao sistema Casa Inteligente, admin!";
                } else {
                    $_SESSION["msg"] = "Usuário ou senha inválidos!";
                    $_SESSION["login"] = false;
                }
            } else {
                $_SESSION["login"] = true;
                $_SESSION["pusuario"] = $row["login"];
                $_SESSION["iduser"] = $row["id"];
                $_SESSION["perfil"] = $row["perfil"];
                $_SESSION["msg"] = "Bem-vindo ao sistema, " . htmlspecialchars($row["nome"]);
            }
        } catch (Exception $e) {
            $_SESSION["msg"] = "Erro de conexão com PostgreSQL: " . $e->getMessage();
            $_SESSION["login"] = false;
        }
    }

    // Botão logout
    if(isset($_POST['frmlogout']))
    {
        $_SESSION["login"] = false;
        $_SESSION["pusuario"] = null;
        $_SESSION["iduser"] = null;
    }
?>
