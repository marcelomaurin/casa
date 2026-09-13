<?php
	// Libs utilizadas no sistema Casa Inteligente
	// Adaptado para PostgreSQL por Antigravity / Maurinsoft
	include_once('config.php');

	function get_db_pdo()
	{
		global $dbhost, $dbport, $database, $dbuser, $dbpassword;
		static $pdo = null;
		if ($pdo === null) {
			$port = isset($dbport) ? $dbport : "5432";
			$dsn = "pgsql:host={$dbhost};port={$port};dbname={$database}";
			$pdo = new PDO($dsn, $dbuser, $dbpassword, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
			]);
		}
		return $pdo;
	}

	/* Retorna o IP local do usuario */
	function get_ip()
	{
	   $variables = array('REMOTE_ADDR',
                      'HTTP_X_FORWARDED_FOR',
                      'HTTP_X_FORWARDED',
                      'HTTP_FORWARDED_FOR',
                      'HTTP_FORWARDED',
                      'HTTP_X_COMING_FROM',
                      'HTTP_COMING_FROM',
                      'HTTP_CLIENT_IP');

	   $return = 'Unknown';
	   foreach ($variables as $variable)
	   {
	       if (isset($_SERVER[$variable]))
	       {
	           $return = $_SERVER[$variable];
	           break;
	       }
	   }
	   return $return;
	}
	
	// Verifica se esta na rede interna
	function ConfereIPExtInt()
	{
		$ip_server = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '127.0.0.1';
		$ip_remoto = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';

		$array_ip_server = explode(".", $ip_server);
		$array_ip_remoto = explode(".", $ip_remoto);

		if (count($array_ip_server) > 1 && count($array_ip_remoto) > 1) {
			if (($array_ip_server[0] == $array_ip_remoto[0]) || ($array_ip_server[1] == $array_ip_remoto[1])){
				return 0; // ip interno
			}
		}
		return 1; // ip externo
	}	
    
	function get_subsis($opcao1)
	{
		$resultado = 0;
		try {
			$sql = "select count(*) as total from devpar where iddevice = :opc";
			$pdo = get_db_pdo();
			$stmt = $pdo->prepare($sql);
			$stmt->execute([':opc' => intval($opcao1)]);
			$row = $stmt->fetch();
			return $row ? intval($row['total']) : 0;
		} catch(Exception $e) {
			return 0;
		}
	}
	
	function ShowErro($msg, $tela)
	{
		echo "<div style='color:red; font-weight:bold; padding:10px;'>[Erro {$tela}]: {$msg}</div>";
	}

	function qryCount($sql)	
	{
		try {
			$pdo = get_db_pdo();
			$stmt = $pdo->query($sql);
			return $stmt->rowCount();
		} catch(Exception $e) {
			error_log("qryCount error: " . $e->getMessage() . " | SQL: " . $sql);
			return 0;
		}
	}

	function qryRow($sql, $fieldname)
	{
		try {
			$pdo = get_db_pdo();
			$stmt = $pdo->query($sql);
			$row = $stmt->fetch();
			if ($row && isset($row[$fieldname])) {
				return $row[$fieldname];
			}
			return 0;
		} catch(Exception $e) {
			error_log("qryRow error: " . $e->getMessage() . " | SQL: " . $sql);
			return 0;
		}
	}
	
	function qryExec($sql)
	{
		try {
			$pdo = get_db_pdo();
			$pdo->exec($sql);
			return 1;
		} catch(Exception $e) {
			error_log("qryExec error: " . $e->getMessage() . " | SQL: " . $sql);
			return 0;
		}
	}

	function qryReport($Titulo, $sql, $SelItem)
	{
		try {
			$pdo = get_db_pdo();
			$stmt = $pdo->query($sql);
			$rows = $stmt->fetchAll();
			if (!$rows) {
				echo "<p>Nenhum registro encontrado.</p>";
				return 0;
			}
			echo "<table class='table table-striped table-bordered'>";
			echo "<thead><tr><th colspan='100%'><h4>{$Titulo}</h4></th></tr></thead><tbody>";
			foreach ($rows as $row) {
				echo "<tr>";
				foreach ($row as $col => $val) {
					echo "<td>" . htmlspecialchars((string)$val) . "</td>";
				}
				echo "</tr>";
			}
			echo "</tbody></table>";
			return count($rows);
		} catch(Exception $e) {
			echo "<p class='text-danger'>Erro no relatório: " . htmlspecialchars($e->getMessage()) . "</p>";
			return 0;
		}
	}

	// Funções de Controle de Dispositivos (compatíveis com os botões originais)
	function Ligar() 
	{
		qryExec("INSERT INTO devpar (iddevice, devparname, devvalue) VALUES (2, 'dev1', '1') ON CONFLICT DO NOTHING; UPDATE devpar SET devvalue = '1' WHERE iddevice = 2 AND devparname = 'dev1'");
		qryExec("INSERT INTO comandos_log (iddevice, comando, origem, resultado) VALUES (2, 'Ligar Irrigacao', 'web', 'OK')");
		echo "Ligou a irrigação!";
		exit;
	}

	function Desligar() 
	{
		qryExec("UPDATE devpar SET devvalue = '0' WHERE iddevice = 2 AND devparname = 'dev1'");
		qryExec("INSERT INTO comandos_log (iddevice, comando, origem, resultado) VALUES (2, 'Desligar Irrigacao', 'web', 'OK')");
		echo "Desligou a irrigação!";
		exit;
	}
	
	function Ligarluz() 
	{
		qryExec("INSERT INTO devpar (iddevice, devparname, devvalue) VALUES (1, 'dev1', '1') ON CONFLICT DO NOTHING; UPDATE devpar SET devvalue = '1' WHERE iddevice = 1 AND devparname = 'dev1'");
		qryExec("INSERT INTO comandos_log (iddevice, comando, origem, resultado) VALUES (1, 'Ligar Luz Sala', 'web', 'OK')");
		echo "Ligou a iluminação da Sala!";
		exit;
	}
	
	function Desligarluz() 
	{
		qryExec("UPDATE devpar SET devvalue = '0' WHERE iddevice = 1 AND devparname = 'dev1'");
		qryExec("INSERT INTO comandos_log (iddevice, comando, origem, resultado) VALUES (1, 'Desligar Luz Sala', 'web', 'OK')");
		echo "Desligou a iluminação da Sala!";
		exit;
	}
	
	function LigarluzLat() 
	{
		qryExec("INSERT INTO devpar (iddevice, devparname, devvalue) VALUES (2, 'dev5', '1') ON CONFLICT DO NOTHING; UPDATE devpar SET devvalue = '1' WHERE iddevice = 2 AND devparname = 'dev5'");
		echo "Ligou a iluminação lateral!";
		exit;
	}

	function DesligarluzLat() 
	{
		qryExec("UPDATE devpar SET devvalue = '0' WHERE iddevice = 2 AND devparname = 'dev5'");
		echo "Desligou a iluminação lateral!";
		exit;
	}

	// Captura de Telemetria de Sensores (PostgreSQL)
	function registrar_telemetria_sensor($iddevice, $sensor_nome, $valor, $unidade = '', $raw = '')
	{
		try {
			$pdo = get_db_pdo();
			$stmt = $pdo->prepare("INSERT INTO sensores_telemetria (iddevice, sensor_nome, valor_numerico, unidade, raw_data) VALUES (:dev, :nome, :val, :unid, :raw)");
			$stmt->execute([
				':dev' => $iddevice,
				':nome' => $sensor_nome,
				':val' => floatval($valor),
				':unid' => $unidade,
				':raw' => $raw
			]);
			return true;
		} catch (Exception $e) {
			error_log("Erro telemetria: " . $e->getMessage());
			return false;
		}
	}
?>
