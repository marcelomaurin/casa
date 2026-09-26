# Serviço Python do Agendador

Execute `python agendador_service.py` como serviço em segundo plano. Ele usa o
mesmo SQLite do desktop Lazarus, descobre equipamentos no `/24`, envia as
quatro linhas e o QR Code, executa agendamentos e grava histórico.

API local para a interface Lazarus:

```text
GET  http://127.0.0.1:8765/status
GET  http://127.0.0.1:8765/scan
POST http://127.0.0.1:8765/send
```

O serviço não abre interface gráfica. O Lazarus fica responsável por CRUD,
formulários e visualização. Configure `FATEC_AGENDADOR_DB`,
`FATEC_AGENDADOR_SUBNET` e `FATEC_AGENDADOR_API_PORT` antes de iniciar.
