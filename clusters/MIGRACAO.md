# Migração dos caminhos

[Catálogo](README.md) · [Operação](OPERACAO.md)

## Origem e destino no repositório

| Origem anterior | Destino atual |
|---|---|
| servicos/arm-agent | [clusters/comunicacao/arm-agent](comunicacao/arm-agent/) |
| servicos/casa-scheduler | [clusters/automacao/casa-scheduler](automacao/casa-scheduler/) |
| servicos/google-home-agent | [clusters/voz/google-home-agent](voz/google-home-agent/) |
| servicos/tts | [clusters/voz/tts](voz/tts/) |
| servicos/espcam | [clusters/visao/espcam](visao/espcam/) |
| yocto/raspberrypi-avatar-agent | [clusters/visao/raspberrypi-avatar-agent](visao/raspberrypi-avatar-agent/) |
| servicos/web-agent | [clusters/pesquisa/web-agent](pesquisa/web-agent/) |
| servicos/casa-tunnel | [clusters/infraestrutura/casa-tunnel](infraestrutura/casa-tunnel/) |
| servicos/runpod-agent | [clusters/infraestrutura/runpod-agent](infraestrutura/runpod-agent/) |

## Instalação independente do Git

Uma cópia instalada em /opt/ ou /home/mmm/servicos/ não é movida por git pull. Para atualizá-la, copie os fontes da nova pasta para o destino esperado pela unidade, preservando credenciais, permissões, ambientes virtuais, modelos e dados.

## Execução direta do checkout

Se ExecStart ou WorkingDirectory aponta para uma pasta antiga dentro do repositório, ajuste a unidade ou prepare uma cópia no destino esperado antes de reiniciar. Arquivos privados não versionados, como .env, não são migrados automaticamente pelo Git e precisam permanecer acessíveis ao programa.

## Ferramentas e links

- O instalador ARM atual baixa os fontes de clusters/comunicacao/arm-agent.
- Substitua cópias antigas do instalador antes de executá-las novamente.
- O guia Yocto usa clusters/visao/raspberrypi-avatar-agent.
- O CI e o teste de clientes API v1 acompanham os novos caminhos.
- O simulador de desenvolvimento permanece em servicos/device_simulator.py.

Esta reorganização não instala serviços, migra bancos, altera credenciais, reinicia equipamentos nem registra agentes no site.
