# Atualização dos integradores CASA (casa-cluster-update)

[Passo a passo: instalação, configuração e diagnóstico](INSTALACAO.md).

O serviço roda em cada Raspberry/nó Linux e **busca os fontes direto do Git** (`https://github.com/marcelomaurin/casa`, branch `master`):

1. A cada ~2 minutos (timer systemd) consulta a API central (`api/v1/updates.php?acao=policy`) para saber a branch e se o painel pediu uma atualização forçada. Se a API estiver fora do ar, segue com a branch do `config.cfg`.
2. Compara o commit mais recente da branch (`git ls-remote`) com o commit instalado.
3. Havendo commit novo (ou pedido forçado), atualiza um cache Git local em `/var/lib/casa-cluster-update/repo.git` (bare, raso, sem baixar blobs desnecessários) e, para cada integrador **já instalado neste nó**, copia somente os arquivos de código alterados.
4. Reinicia os serviços que estavam ativos e informa o resultado à API (`acao=node_report`).

O painel **Segurança › Atualizações** mostra, por nó, o commit instalado comparado ao último commit da master no GitHub, o resultado por integrador, erros e permite **forçar** a atualização de um nó ou de todos. Forçar reinstala todos os arquivos e reinicia os serviços ativos na próxima consulta do nó (até ~2 min).

## Proteções

- Só arquivos `.py .html .css .js .sh` dentro da pasta do integrador; configuração e dados (`.cfg .env .ini .json .db .sqlite .onnx .wav`), `tests/`, `install.sh` nunca são instalados nem sobrescritos.
- Todos os arquivos do integrador são baixados e validados antes de tocar na instalação: Python passa por `compile`, shell por `bash -n`. Com erro de sintaxe o integrador fica na versão anterior e o nó tenta de novo na próxima execução.
- Substituição atômica, com backup e restauração automática se a instalação ou o reinício do serviço falhar.
- Destinos não podem sair da pasta do integrador nem seguir links simbólicos. Bloqueio contra execuções concorrentes.
- Serviços inativos não são iniciados. Unidades systemd, dependências (`pip`, `apt`) e binários não são alterados: exigem instalação explícita.

Estado do nó em `/var/lib/casa-cluster-update/state.json` (lido também pelo agente ARM e pelo portal do cluster).

```bash
sudo casa-cluster-update --check     # compara instalado x master, sem alterar nada
sudo casa-cluster-update --apply     # atualiza se houver commit novo ou pedido do painel
sudo casa-cluster-update --force     # reinstala tudo e reinicia os serviços ativos
sudo casa-cluster-update --status
systemctl list-timers casa-cluster-update.timer
python3 -m unittest discover -s tests -v
```

> As releases manuais (`acao=register/activate`, manifestos) da versão 1.x não são mais usadas pelos nós. Os endpoints continuam na API apenas por compatibilidade.
