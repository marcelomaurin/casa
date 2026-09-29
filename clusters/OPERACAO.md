# Operação dos clusters

[Catálogo](README.md) · [Inventário](INVENTARIO.md) · [Migração](MIGRACAO.md)

## Conceitos

- Nó: computador Linux que executa um ou mais programas.
- Agente: programa que se identifica e se comunica com o site.
- Serviço auxiliar: programa usado por outros componentes, sem necessariamente enviar heartbeat próprio.
- Cluster, nesta pasta: agrupamento dos programas distribuídos do CASA por função. Não implica Kubernetes ou outro orquestrador.

## Escolha e preparação

Comece pelo agente ARM para conexão, heartbeat e comandos do nó. Acrescente os serviços necessários de automação, voz, visão, pesquisa e infraestrutura. Cada programa possui requisitos próprios; não há instalador único para todos.

1. Consulte o inventário e o guia da área.
2. Prepare as dependências do programa escolhido.
3. Configure a URL do CASA e as credenciais privadas.
4. Para agentes registrados, use identificador e token próprios por programa.
5. Instale apenas os serviços necessários ao nó.
6. Confira logs e, quando houver heartbeat, o último contato no site.

Não coloque credenciais reais no Git. A documentação descreve os componentes existentes; não certifica a compatibilidade de todos com qualquer placa ARM.

## Instalação do agente ARM

Na raiz do repositório, no computador Linux de destino:

```bash
sudo bash clusters/comunicacao/arm-agent/install_node.sh
```

O instalador usa apt e systemd, baixa os fontes atuais de master, copia para /opt/casa-node-agent e habilita/reinicia o serviço. Cria /etc/casa-node-agent.env somente se esse arquivo ainda não existir.

Em uma instalação nova, configure os valores de exemplo antes de considerar o agente operacional:

```bash
sudoedit /etc/casa-node-agent.env
sudo systemctl restart casa-node-agent
sudo systemctl status casa-node-agent --no-pager
sudo journalctl -u casa-node-agent -n 100 --no-pager
```

O agente requer cadastro/token previamente provisionados; o instalador não cadastra automaticamente o programa no site.

## Configuração do runtime ARM

| Variável | Função |
|---|---|
| CASA_BASE_URL | URL base; o agente acrescenta /api/v1/device.php |
| JARVIS_DEVICE_ID | Identificador próprio do programa |
| JARVIS_DEVICE_TOKEN | Token individual provisionado |
| JARVIS_CAPABILITIES | Capacidades disponíveis, separadas por vírgula |
| HEARTBEAT_INTERVAL | Intervalo entre sinais de presença, em segundos |
| COMMAND_POLL_INTERVAL | Intervalo de consulta de comandos |
| AGENT_PORT | Porta HTTP local de compatibilidade/diagnóstico |
| JARVIS_LOCAL_TTS_URL | Serviço TTS opcional |

JARVIS_MASTER_URL e JARVIS_API_BASE ainda aparecem no modelo do instalador, mas não são usados pelo Python atual para construir a URL. Declarar uma capacidade não implementa automaticamente seu adaptador físico.

## Verificação no site

Abra DISPOSITIVOS → Cluster ARM e clique em Atualizar lista.

- O agente ARM envia plataforma linux-arm, hostname, CPU e RAM.
- O avatar Raspberry Pi envia fabricante e heartbeat pela API v1.
- O mesmo device_id aparece uma vez; programas com IDs distintos permanecem separados.
- Online exige contato nos últimos 120 segundos, respeitando revogação e estado explicitamente offline.
- Google Home possui registro específico; TTS e outros auxiliares não entram automaticamente como agentes ARM individuais.

Se um agente não aparece, verifique cadastro, identificação ARM, token, URL, conectividade e logs. Se aparece offline, confira o último heartbeat.

A implementação da listagem está no Git. A última tentativa de deploy do painel foi bloqueada por falta da credencial SSH da Hostinger; confirme o workflow de publicação antes de esperar o novo comportamento em produção.

## Atualização

Atualize o Git primeiro. Compare os novos fontes com o destino executado pelo serviço, preserve configurações e ambiente virtual, copie a atualização necessária e reinicie apenas a unidade afetada. Consulte [Migração](MIGRACAO.md) para serviços que executam diretamente do checkout.
