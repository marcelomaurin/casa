# Diagnóstico real de daemons no COMPUTER

O site interpreta consultas sobre daemons, estado da fila e planos pendentes antes de chamar o modelo de IA. O retorno vem da telemetria autenticada dos nós Linux e do banco de tarefas.

## Fluxo de execução

1. O casa-node-agent.py consulta no Linux a lista real de systemd units running e o estado das unidades críticas.
2. O agente lê até cinco mensagens de erro recentes do journalctl para casa-tarefas.service (últimos 30 minutos), oculta tokens, senhas e URLs, e identifica falta de permissão.
3. O heartbeat HTTPS autenticado envia daemon_diagnostics em data para /api/v1/device.php?acao=heartbeat; o site armazena em dispositivos_cluster.metadata.
4. A API do COMPUTER reconhece frases como 'Liste os daemons rodando', 'Qual o status do daemon?' e 'Por que o Plano 178 está pendente?' e consulta diretamente banco, trabalhadores e journal já recebido.
5. A pergunta continua sendo registrada para rastreabilidade pelo Task Engine, mas a consulta diagnóstica não cria uma execução remota desnecessária e é concluída imediatamente.

## Validade e limites

- Um nó com heartbeat há mais de 40 segundos não autoriza afirmações sobre processos atualmente ativos.
- Trabalhadores da fila com heartbeat há mais de 300 segundos são marcados como sem contato recente, o que não prova necessariamente que o daemon parou.
- Nó antigo ou sem systemctl: inventário indisponível; não se afirma que todos os processos estão desligados.
- Usuário do node-agent sem acesso ao journal: campo logs_status=sem_acesso; nenhuma linha é inventada.
- Só são coletados serviços systemd, não todos os processos arbitrários. O inventário é limitado a 100 nomes por nó.
- O journal transmitido tem apenas erros recentes do casa-tarefas.service, com redação de credenciais. Não é um terminal remoto.

## Implantação nos equipamentos

É necessário publicar as alterações do site e atualizar o agente nos nós Linux. O instalador oficial clusters/comunicacao/arm-agent/install_node.sh baixa a versão atualizada após integração na master e preserva /etc/casa-node-agent.env. O agente precisa de um token individual válido.

Comandos de verificação executados no próprio nó, após instalação:

    sudo systemctl restart casa-node-agent.service
    sudo systemctl status casa-node-agent.service --no-pager
    sudo journalctl -u casa-node-agent.service -n 30 --no-pager
    sudo systemctl status casa-tarefas.service --no-pager
    sudo journalctl -u casa-tarefas.service -p err -n 20 --no-pager

Não há como concluir o estado real do daemon a partir do código GitHub sem telemetria recente do equipamento.

## Regressões

    python3 .github/tests/test_node_daemon_telemetry.py
    php .github/tests/test_computer_daemon_diagnostics.php
