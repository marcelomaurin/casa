"""Exemplo de extensão do casa-tarefas.

Copie para /opt/casa-tarefas/executores/, inclua o nome em CASA_TAREFAS_EXECUTORES e
reinicie o serviço. O planejador passa a enxergar este executor enquanto o daemon estiver ativo.

- EXECUTOR: nome usado em jarvis_tarefas.executor.
- executar(tarefa, ctx): devolve um dict com o resultado.
    tarefa["payload"]  dados da tarefa
    tarefa["entrada"]  resultado da tarefa da qual esta depende (ou None)
    ctx.renovar()      estende a reserva em tarefas longas
    ctx.log            registro do daemon
- Levante FalhaDefinitiva("motivo") quando não adiantar repetir,
  ou Dividir([{...}, {...}]) para quebrar a tarefa em partes.
"""

EXECUTOR = "eco"


def executar(tarefa, ctx):
    p = tarefa.get("payload") or {}
    partes = p.get("partes") if isinstance(p, dict) else None
    if partes:  # as partes recebem só "texto", então não se dividem de novo
        # Exemplo de divisão: cada item vira uma parte; a última depende da primeira.
        raise Dividir([
            {"titulo": f"Eco {i + 1}", "executor": "eco", "payload": {"texto": str(x)},
             **({"depende_da_parte": 1} if i > 0 else {})}
            for i, x in enumerate(partes)
        ])
    entrada = (tarefa.get("entrada") or {}).get("resultado")
    return {"eco": p.get("texto") if isinstance(p, dict) else p, "entrada": entrada}
