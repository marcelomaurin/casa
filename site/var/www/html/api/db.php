<?php
// Banco central do JARVIS/CASA.
// Produção Hostinger: MySQL/MariaDB via config.local.php privado ou variáveis de ambiente.
// A senha do MySQL nunca deve ser versionada no Git.

if (session_status() === PHP_SESSION_NONE) { session_start(); }

const CASA_SCHEMA_VERSION = '1.32';

function local_config(): array { static $cfg=null; if(is_array($cfg))return $cfg; $file=__DIR__.'/config.local.php'; if(is_file($file)){ $loaded=require $file; if(is_array($loaded)){ $cfg=$loaded; return $cfg; }} $cfg=[]; return $cfg; }
function cfg_value(string $configKey,string $envKey,?string $default=null):?string { $cfg=local_config(); if(array_key_exists($configKey,$cfg)&&$cfg[$configKey]!=='')return(string)$cfg[$configKey]; $value=getenv($envKey); return($value===false||$value==='')?$default:$value; }
function cfg_bool(string $configKey,string $envKey,bool $default=true):bool { $value=strtolower((string)cfg_value($configKey,$envKey,$default?'1':'0')); return !in_array($value,['0','false','off','no'],true); }

function casa_required_tables():array { return [
'param','configuracoes_sistema','usuarios','devices','devpar','sensores_telemetria','falas','frases','llm_conversas','comandos_log','arm_nodes','dispositivos_cluster','iot_leituras','camera_eventos','seguranca_logs','seguranca_ips_bloqueados','agentes_externos','api_client_tokens','api_v1_rate_limit','api_v1_security_log','mobile_eventos','mobile_notificacoes','watch_notificacoes','internet_pesquisas','jarvis_planos','jarvis_tarefas','tarefas_agendadas','device_pairing_requests','device_capabilities','device_commands','device_events','device_heartbeats','device_command_audit','device_environment_readings','device_desired_state','scenes','scene_actions','scene_runs','scene_run_actions','automation_rules','automation_rule_actions','automation_rule_runs','automation_rule_run_actions','jarvis_acoes','ia_modelos']; }
function casa_missing_tables(PDO $pdo):array { $dbName=(string)$pdo->query('SELECT DATABASE()')->fetchColumn(); if($dbName==='')throw new RuntimeException('Nenhum banco MySQL selecionado.'); $stmt=$pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db'); $stmt->execute([':db'=>$dbName]); $existing=array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN),true); $missing=[]; foreach(casa_required_tables() as $table)if(!isset($existing[$table]))$missing[]=$table; return $missing; }
function casa_execute_sql_statement(PDO $pdo, string $sql): void {
    $sql = trim($sql);
    if ($sql === '') return;
    try {
        $pdo->exec($sql);
        return;
    } catch (PDOException $e) {
        $code = (int)($e->errorInfo[1] ?? 0);
        if (in_array($code, [1050, 1060, 1061, 1022], true)) {
            return;
        }
        if ($code === 1064 && stripos($sql, 'IF NOT EXISTS') !== false) {
            if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+`?([a-zA-Z0-9_]+)`?\s+ON\s+`?([a-zA-Z0-9_]+)`?\s*\((.+)\)/is', $sql, $m)) {
                $unique = !empty($m[1]) ? 'UNIQUE ' : '';
                $indexName = $m[2];
                $tableName = $m[3];
                $indexCols = $m[4];
                $chk = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND INDEX_NAME = :idx LIMIT 1");
                $chk->execute([':tbl' => $tableName, ':idx' => $indexName]);
                if (!$chk->fetchColumn()) {
                    try {
                        $pdo->exec("CREATE {$unique}INDEX `{$indexName}` ON `{$tableName}` ({$indexCols})");
                    } catch (PDOException $ex) {
                        if (!in_array((int)($ex->errorInfo[1] ?? 0), [1061, 1022], true)) throw $ex;
                    }
                }
                return;
            }
            if (preg_match('/^ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s+(.+)$/is', $sql, $m)) {
                $tableName = $m[1];
                $rest = $m[2];
                $clauses = preg_split('/,\s*(?=ADD\b)/i', $rest);
                foreach ($clauses as $clause) {
                    $clause = trim($clause);
                    if (preg_match('/^ADD\s+(?:COLUMN\s+)?IF\s+NOT\s+EXISTS\s+`?([a-zA-Z0-9_]+)`?\s+(.+)$/is', $clause, $cm)) {
                        $colName = $cm[1];
                        $colDef = $cm[2];
                        $chk = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND COLUMN_NAME = :col LIMIT 1");
                        $chk->execute([':tbl' => $tableName, ':col' => $colName]);
                        if (!$chk->fetchColumn()) {
                            try {
                                $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN `{$colName}` {$colDef}");
                            } catch (PDOException $ex) {
                                if ((int)($ex->errorInfo[1] ?? 0) !== 1060) throw $ex;
                            }
                        }
                    } else {
                        $cleaned = preg_replace('/\bIF\s+NOT\s+EXISTS\b/i', '', $clause);
                        try {
                            $pdo->exec("ALTER TABLE `{$tableName}` " . $cleaned);
                        } catch (PDOException $ex) {
                            if (!in_array((int)($ex->errorInfo[1] ?? 0), [1060, 1061], true)) throw $ex;
                        }
                    }
                }
                return;
            }
        }
        $msg = $e->getMessage();
        if (stripos($msg, 'duplicate') !== false || stripos($msg, 'already exists') !== false) {
            return;
        }
        throw $e;
    }
}
function casa_execute_schema_file(PDO $pdo, string $file): void {
    if (!is_file($file)) throw new RuntimeException('Arquivo de schema MySQL não encontrado: ' . basename($file));
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) throw new RuntimeException('Não foi possível ler o schema MySQL.');
    $statement = '';
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || strpos($trim, '--') === 0) continue;
        $statement .= $line . "\n";
        if (substr(rtrim($trim), -1) === ';') {
            $sql = trim($statement);
            $statement = '';
            if ($sql !== '') casa_execute_sql_statement($pdo, $sql);
        }
    }
    if (trim($statement) !== '') casa_execute_sql_statement($pdo, trim($statement));
}
function casa_ensure_param_table(PDO $pdo):void { $pdo->exec("CREATE TABLE IF NOT EXISTS param (chave VARCHAR(120) NOT NULL,valor TEXT NULL,atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (chave)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); }
function casa_schema_version(PDO $pdo):?string { casa_ensure_param_table($pdo);$stmt=$pdo->prepare("SELECT valor FROM param WHERE chave='VERSAO' LIMIT 1");$stmt->execute();$value=$stmt->fetchColumn();return$value===false?null:trim((string)$value); }
function casa_set_schema_version(PDO $pdo,string $version):void { $stmt=$pdo->prepare("INSERT INTO param(chave,valor) VALUES('VERSAO',:v) ON DUPLICATE KEY UPDATE valor=VALUES(valor), atualizado_em=CURRENT_TIMESTAMP");$stmt->execute([':v'=>$version]); }
function casa_run_version_migrations(PDO $pdo,?string $currentVersion):void { if($currentVersion===CASA_SCHEMA_VERSION)return; $migrations=['1.20'=>[__DIR__.'/migrations/1.20.sql',__DIR__.'/migrations/1.20_audit.sql'],'1.21'=>[__DIR__.'/migrations/1.21.sql'],'1.22'=>[__DIR__.'/migrations/1.22.sql'],'1.23'=>[__DIR__.'/migrations/1.23.sql'],'1.24'=>[__DIR__.'/migrations/1.24.sql'],'1.25'=>[__DIR__.'/migrations/1.25.sql'],'1.26'=>[__DIR__.'/migrations/1.26.sql'],'1.27'=>[__DIR__.'/migrations/1.27.sql'],'1.28'=>[__DIR__.'/migrations/1.28.sql'],'1.29'=>[__DIR__.'/migrations/1.29.sql'],'1.30'=>[__DIR__.'/migrations/1.30.sql'],'1.31'=>[__DIR__.'/migrations/1.31.sql'],'1.32'=>[__DIR__.'/migrations/1.32.sql']]; foreach($migrations as $version=>$files){if($currentVersion!==null&&version_compare($currentVersion,$version,'>='))continue;foreach($files as $file)casa_execute_schema_file($pdo,$file);casa_set_schema_version($pdo,$version);$currentVersion=$version;} }
function ensure_database_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked || !cfg_bool('auto_init_db', 'JARVIS_AUTO_INIT_DB', true)) return;
    $checked = true;
    $lockName = 'casa_jarvis_schema_install';
    try {
        $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 20)');
        $lock->execute([':lock_name' => $lockName]);
        $acquired = (int)$lock->fetchColumn() === 1;
    } catch (Throwable $e) { $acquired = true; }
    if (!$acquired) throw new RuntimeException('Timeout aguardando instalação automática do banco.');
    try {
        casa_ensure_param_table($pdo);
        $version = casa_schema_version($pdo);
        $missingNow = casa_missing_tables($pdo);
        if ($version === CASA_SCHEMA_VERSION && empty($missingNow)) return;
        $baseRequired = ['configuracoes_sistema', 'usuarios', 'devices', 'devpar', 'dispositivos_cluster', 'api_client_tokens'];
        $missingLookup = array_fill_keys($missingNow, true);
        $needsBase = false;
        foreach ($baseRequired as $table) {
            if (isset($missingLookup[$table])) { $needsBase = true; break; }
        }
        try {
            if ($needsBase || !empty($missingNow)) {
                casa_execute_schema_file($pdo, __DIR__ . '/schema_mysql.sql');
                casa_run_version_migrations($pdo, null);
            } else {
                casa_run_version_migrations($pdo, $version);
            }
            $remaining = casa_missing_tables($pdo);
            if (!empty($remaining)) {
                error_log('Instalação automática do banco: tabelas pendentes: ' . implode(', ', $remaining));
            }
            casa_set_schema_version($pdo, CASA_SCHEMA_VERSION);
        } catch (Throwable $ex) {
            error_log('Falha na instalação/migração automática de schema: ' . $ex->getMessage());
        }
    } finally {
        try {
            $unlock = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $unlock->execute([':lock_name' => $lockName]);
        } catch (Throwable $e) {}
    }
}
function casa_ensure_ai_config_defaults(PDO $pdo):void { $defaults=[['ia_provider','local','Provedor principal de IA: local, openai_compatible ou runpod'],['ia_routing_mode','auto','Roteamento de IA'],['local_ollama_url','http://127.0.0.1:11434','URL base do Ollama local'],['local_model','jarvis-local:latest','Modelo do Ollama local'],['openai_base_url','','URL base de API OpenAI-compatible, normalmente terminando em /v1'],['openai_api_key','','Chave Bearer do provedor OpenAI-compatible'],['openai_model','','Modelo exposto pelo endpoint OpenAI-compatible'],['stt_base_url','https://api.openai.com/v1','URL base do serviço de transcrição compatível OpenAI'],['stt_api_key','','Chave do serviço STT'],['stt_model','whisper-1','Modelo de transcrição de áudio']];$stmt=$pdo->prepare("INSERT INTO configuracoes_sistema(chave,valor,descricao) VALUES(:chave,:valor,:descricao) ON DUPLICATE KEY UPDATE chave=VALUES(chave)");foreach($defaults as $d)$stmt->execute([':chave'=>$d[0],':valor'=>$d[1],':descricao'=>$d[2]]); }
function get_db_pdo():PDO { static $pdo=null;if($pdo instanceof PDO)return$pdo;$host=cfg_value('db_host','JARVIS_DB_HOST','localhost');$port=cfg_value('db_port','JARVIS_DB_PORT','3306');$name=cfg_value('db_name','JARVIS_DB_NAME','u820932905_casadb');$user=cfg_value('db_user','JARVIS_DB_USER','u820932905_root');$pass=cfg_value('db_pass','JARVIS_DB_PASS','');$charset=cfg_value('db_charset','JARVIS_DB_CHARSET','utf8mb4');if($pass==='')throw new RuntimeException('Senha do banco MySQL não configurada no ambiente.');$dsn="mysql:host={$host};port={$port};dbname={$name};charset={$charset}";$pdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");ensure_database_schema($pdo);casa_ensure_ai_config_defaults($pdo);return$pdo; }
function get_secondary_db_pdo(){return false;} function qryExec($sql){return get_db_pdo()->exec($sql);}
function get_system_api_token():string { $token=cfg_value('system_api_token','JARVIS_SYSTEM_API_TOKEN','');if($token!=='')return$token;try{$pdo=get_db_pdo();$stmt=$pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave = 'system_api_token' LIMIT 1");$stmt->execute();$row=$stmt->fetch();if($row&&!empty($row['valor']))return$row['valor'];}catch(Throwable $e){}return''; }
function verify_api_auth(){if(!empty($_SESSION['auth_user']))return true;$token='';$authHeader=$_SERVER['HTTP_AUTHORIZATION']??'';if(preg_match('/Bearer\s+(\S+)/i',$authHeader,$m)){$token=trim($m[1]);}elseif(!empty($_SERVER['HTTP_X_API_KEY'])){$token=trim($_SERVER['HTTP_X_API_KEY']);}elseif(!empty($_SERVER['HTTP_X_DEVICE_TOKEN'])){$token=trim($_SERVER['HTTP_X_DEVICE_TOKEN']);}$expectedToken=get_system_api_token();if($token!==''){if($expectedToken!==''&&hash_equals($expectedToken,$token))return true;try{$pdo=get_db_pdo();$hash=hash('sha256',$token);$stmt=$pdo->prepare("SELECT id,nome FROM api_client_tokens WHERE token_hash=:h AND ativo=1 AND revogado_em IS NULL AND (expira_em IS NULL OR expira_em > NOW()) LIMIT 1");$stmt->execute([':h'=>$hash]);$row=$stmt->fetch();if($row){$ip=$_SERVER['REMOTE_ADDR']??'';$pdo->prepare("UPDATE api_client_tokens SET ultimo_uso=NOW(),ultimo_ip=:ip WHERE id=:id")->execute([':ip'=>$ip?:null,':id'=>$row['id']]);return true;}}catch(Throwable $e){}}if($expectedToken===''&&$token===''){http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo json_encode(['status'=>'erro','mensagem'=>'API ainda não configurada no ambiente de produção.'],JSON_UNESCAPED_UNICODE);exit;}if($token!==''){/* Chave/token apresentado e recusado: conta como falha de autenticação do IP (sem sessão expirada do painel, que não envia token). */try{if(!function_exists('registrar_falha_seguranca')){if(!defined('CASA_SEGURANCA_SEM_AUTOCHECK'))define('CASA_SEGURANCA_SEM_AUTOCHECK',true);require_once __DIR__.'/seguranca.php';}registrar_falha_seguranca('Chave de API inválida ('.substr($token,0,6).'…) em '.substr((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),0,120),'AVISO','api');}catch(Throwable $e){}}http_response_code(401);header('Content-Type: application/json; charset=utf-8');echo json_encode(['status'=>'erro','mensagem'=>'Acesso negado: autenticação requerida.'],JSON_UNESCAPED_UNICODE);exit;}
?>
