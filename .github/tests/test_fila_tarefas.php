<?php
// Fila de tarefas (api/fila_tarefas.php): reserva, dependências, novas tentativas,
// reserva vencida, divisão em partes, posse da reserva e fechamento do plano.
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');

require __DIR__ . '/../../site/var/www/html/api/db.php';
require_once __DIR__ . '/../../site/var/www/html/api/task_engine.php';
require_once __DIR__ . '/../../site/var/www/html/api/fila_tarefas.php';
$pdo = get_db_pdo();
// Garante as migrações da fila mesmo que a cadeia automática tenha parado antes.
foreach (['1.33', '1.34'] as $v) casa_execute_schema_file($pdo, __DIR__ . "/../../site/var/www/html/api/migrations/{$v}.sql");

function falha(string $m): void { fwrite(STDERR, "FALHOU: {$m}\n"); exit(10); }
function igual($a, $b, string $m): void { if ($a !== $b) falha($m . ' (esperado ' . var_export($b, true) . ', veio ' . var_export($a, true) . ')'); }
function status(PDO $pdo, int $id): string { $s = $pdo->prepare("SELECT status FROM jarvis_tarefas WHERE id=:i"); $s->execute([':i' => $id]); return (string)$s->fetchColumn(); }
function plano_status(PDO $pdo, int $id): string { $s = $pdo->prepare("SELECT status FROM jarvis_planos WHERE id=:i"); $s->execute([':i' => $id]); return (string)$s->fetchColumn(); }
function nova(PDO $pdo, array $ctx, string $titulo, string $executor, ?int $dep = null, string $status = 'PENDENTE', ?string $quando = null, int $max = 3): int {
    $pdo->prepare("INSERT INTO jarvis_tarefas (id_plano,correlation_id,ordem,titulo,tipo,executor,fila,payload,depende_de,tarefa_pai_id,status,executar_em,max_tentativas)
        VALUES (:p,:c,1,:t,'IMEDIATA',:e,'geral',:pl,:d,:pai,:s,:x,:m)")->execute([
        ':p' => $ctx['id_plano'], ':c' => $ctx['correlation_id'], ':t' => $titulo, ':e' => $executor,
        ':pl' => json_encode(['comando' => $titulo]), ':d' => $dep, ':pai' => $ctx['id_tarefa_raiz'],
        ':s' => $status, ':x' => $quando, ':m' => $max,
    ]);
    return (int)$pdo->lastInsertId();
}

// Ambiente limpo para os executores do teste.
$pdo->exec("UPDATE jarvis_tarefas SET status='CANCELADA' WHERE executor LIKE 'teste\\_%' AND status NOT IN ('CONCLUIDA','ERRO','CANCELADA')");

$ctx = te_begin($pdo, 'Plano de teste da fila', 'TESTE', 'planejador');
te_complete($pdo, (int)$ctx['id_tarefa_raiz'], ['ok' => true]);
$plano = (int)$ctx['id_plano'];
$pdo->prepare("UPDATE jarvis_planos SET status='EM_EXECUCAO' WHERE id=:i")->execute([':i' => $plano]);

$A = nova($pdo, $ctx, 'A', 'teste_x');
$B = nova($pdo, $ctx, 'B depende de A', 'teste_x', $A, 'PENDENTE', null, 2);
$E = nova($pdo, $ctx, 'E depende de B', 'teste_y', $B);
$C = nova($pdo, $ctx, 'C', 'teste_y');
$D = nova($pdo, $ctx, 'D agendada', 'teste_y', null, 'AGENDADA', date('Y-m-d H:i:s', time() + 3600));

// 1. Só A sai: B espera A, D espera o horário.
$r = ft_reservar($pdo, 'w1', ['teste_x'], [], 5, 60);
igual(array_column($r, 'id'), [$A], 'w1 deveria receber só A');
igual(ft_reservar($pdo, 'w2', ['teste_x'], [], 5, 60), [], 'w2 não deveria receber nada');

// 2. Só quem reservou conclui.
try { ft_concluir($pdo, $A, 'w2', []); falha('w2 concluiu tarefa de w1'); } catch (FilaErro $e) { igual($e->http, 409, 'posse da reserva'); }
ft_concluir($pdo, $A, 'w1', ['texto' => 'resultado de A']);

// 3. B sai com o resultado de A como entrada.
$r = ft_reservar($pdo, 'w1', ['teste_x'], [], 5, 60);
igual(array_column($r, 'id'), [$B], 'B deveria sair após A');
igual($r[0]['entrada']['resultado']['texto'] ?? null, 'resultado de A', 'entrada de B');

// 4. Falha com nova tentativa: volta com espera; esgotando, vira ERRO e derruba E.
igual(ft_falhar($pdo, $B, 'w1', 'falha 1', true), 'PENDENTE', 'primeira falha repete');
igual(ft_reservar($pdo, 'w1', ['teste_x'], [], 5, 60), [], 'B está em espera');
$pdo->prepare("UPDATE jarvis_tarefas SET executar_em=NOW() WHERE id=:i")->execute([':i' => $B]);
$r = ft_reservar($pdo, 'w1', ['teste_x'], [], 5, 60);
igual(array_column($r, 'id'), [$B], 'B volta após a espera');
igual(ft_falhar($pdo, $B, 'w1', 'falha 2', true), 'ERRO', 'tentativas esgotadas');
igual(status($pdo, $E), 'ERRO', 'E deveria cair junto com B');

// 5. Reserva vencida volta para a fila.
$r = ft_reservar($pdo, 'w2', ['teste_y'], [], 1, 60);
igual(array_column($r, 'id'), [$C], 'w2 pega C');
$pdo->prepare("UPDATE jarvis_tarefas SET reservado_ate=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=:i")->execute([':i' => $C]);
$r = ft_reservar($pdo, 'w3', ['teste_y'], [], 1, 60);
igual(array_column($r, 'id'), [$C], 'C deveria voltar e ir para w3');
igual($r[0]['tentativa'], 2, 'segunda tentativa de C');

// 6. Liberar não gasta tentativa.
ft_liberar($pdo, $C, 'w3');
$r = ft_reservar($pdo, 'w3', ['teste_y'], [], 1, 60);
igual($r[0]['tentativa'], 2, 'liberar não conta tentativa');

// 7. Dividir C em duas partes encadeadas.
$partes = ft_dividir($pdo, $C, 'w3', [
    ['titulo' => 'C.1', 'executor' => 'teste_y', 'payload' => ['n' => 1]],
    ['titulo' => 'C.2', 'executor' => 'teste_y', 'payload' => ['n' => 2], 'depende_da_parte' => 1],
]);
igual(status($pdo, $C), 'AGUARDANDO_PARTES', 'C aguarda partes');
try { ft_dividir($pdo, $C, 'w3', [['titulo' => 'x']]); falha('dividiu tarefa sem reserva'); } catch (FilaErro $e) {}
try { ft_concluir($pdo, $partes[0], 'w3', []); falha('concluiu parte não reservada'); } catch (FilaErro $e) {}
$r = ft_reservar($pdo, 'w3', ['teste_y'], [], 5, 60);
igual(array_column($r, 'id'), [$partes[0]], 'só C.1 sai primeiro');
ft_concluir($pdo, $partes[0], 'w3', ['parte' => 1]);
$r = ft_reservar($pdo, 'w3', ['teste_y'], [], 5, 60);
igual(array_column($r, 'id'), [$partes[1]], 'C.2 sai depois de C.1');
igual($r[0]['entrada']['resultado']['parte'] ?? null, 1, 'C.2 recebe resultado de C.1');
ft_concluir($pdo, $partes[1], 'w3', ['parte' => 2]);
igual(status($pdo, $C), 'CONCLUIDA', 'C conclui com as partes');

// 8. Plano só fecha quando a agendada D terminar; com B em erro, fecha como ERRO.
igual(plano_status($pdo, $plano), 'EM_EXECUCAO', 'plano aberto enquanto D espera');
$pdo->prepare("UPDATE jarvis_tarefas SET executar_em=NOW() WHERE id=:i")->execute([':i' => $D]);
$r = ft_reservar($pdo, 'w2', ['teste_y'], [], 5, 60);
igual(array_column($r, 'id'), [$D], 'D sai no horário');
ft_concluir($pdo, $D, 'w2', ['ok' => true]);
igual(plano_status($pdo, $plano), 'ERRO', 'plano fecha como ERRO por causa de B');

// 9. Validações de entrada.
try { ft_lista_nomes(['ok', 'nome com espaço'], 'executores'); falha('aceitou nome inválido'); } catch (FilaErro $e) {}
try { ft_reservar($pdo, 'w1', [], [], 1, 60); falha('reservou sem executor'); } catch (FilaErro $e) {}

// 10. Registro de trabalhador.
ft_registrar_trabalhador($pdo, 'w1', ['teste_x', 'jarvis'], [], 'Cliente teste', ['host' => 'pi', 'versao' => '1.0']);
if (!in_array('teste_x', ft_executores_ativos($pdo), true)) falha('trabalhador ativo não listado');

echo "Fila de tarefas OK: dependências, nova tentativa, reserva vencida, liberar, partes, posse e fechamento do plano.\n";
