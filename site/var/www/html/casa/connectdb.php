<?php
    define("HOSTNAME", "127.0.0.1");
    define("PORT", "5432");
    define("USERNAME", "casadb_user");
    define("PASSWORD", "casadb_password_2026");
    define("DATABASE", "casadb");

    class PgResultCompat {
        private $stmt;
        public function __construct($stmt) {
            $this->stmt = $stmt;
        }
        public function fetch_assoc() {
            return $this->stmt ? $this->stmt->fetch(PDO::FETCH_ASSOC) : false;
        }
        public function fetch_all() {
            return $this->stmt ? $this->stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }
    }

    class PgDbHandleCompat {
        private $pdo;
        public function __construct($host, $port, $dbname, $user, $password) {
            try {
                $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
                $this->pdo = new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            } catch (Exception $e) {
                die("Erro ao conectar no banco de dados PostgreSQL: " . $e->getMessage());
            }
        }

        public function real_escape_string($str) {
            if ($str === null) return "";
            return str_replace("'", "''", (string)$str);
        }

        public function query($sql) {
            try {
                $stmt = $this->pdo->query($sql);
                return new PgResultCompat($stmt);
            } catch (Exception $e) {
                // Log de erro
                error_log("Erro SQL: " . $e->getMessage() . " | Query: " . $sql);
                return false;
            }
        }

        public function getPdo() {
            return $this->pdo;
        }
    }

    $dbhandle = new PgDbHandleCompat(HOSTNAME, PORT, DATABASE, USERNAME, PASSWORD);

    function get_secondary_db_pdo() {
        static $secondary_pdo = null;
        if ($secondary_pdo === null) {
            try {
                $dsn = "pgsql:host=192.168.2.8;port=5432;dbname=casadb";
                $secondary_pdo = new PDO($dsn, "casadb_user", "casadb_password_2026", [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 2
                ]);
            } catch (Exception $e) {
                $secondary_pdo = false;
            }
        }
        return $secondary_pdo;
    }
?>
