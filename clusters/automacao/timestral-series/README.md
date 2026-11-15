# Timestral-5M — Análise de Séries Temporais e Previsão

Daemon de inteligência para **Análise de Séries Temporais, Detecção de Anomalias e Previsão (Forecasting)** de telemetria residencial para o ecossistema **CASA / JARVIS**.

## Funcionalidades
- **API REST na porta 8091:**
  - `GET /health` / `GET /status`: estado operacional, modelo ativo e estatísticas de inferência.
  - `POST /forecast` (ou `/prever`): gera previsões futuras com limites de confiança superior/inferior e identificação de tendência (`alta`, `baixa`, `estavel`).
  - `POST /anomalies` (ou `/anomalias`): detecta picos anormais, quedas bruscas e desvios de comportamento com indicação de severidade (`baixa`, `media`, `critica`).
  - `POST /decompose` (ou `/decompor`): decompõe a série temporal em componentes de tendência, sazonalidade e resíduo.
- **Foco em IoT Residencial:** Ideal para séries de temperatura de sensores, umidade, consumo elétrico, telemetria de CPU/RAM de nós e estados de presença.
- **Segurança Reforçada:** Validação estrita de limites de payload (max 2MB), números finitos, sanitização de entrada e sem dependência de comandos de shell.

## Instalação e Execução

### Arquivos
- Daemon: `casa-timestral.py`
- Unidade systemd: `casa-timestral.service`
- Configuração: `/etc/casa/timestral.env` (baseado em `timestral.env.exemplo`)

### Iniciar Serviço
```bash
sudo systemctl enable casa-timestral.service
sudo systemctl start casa-timestral.service
sudo systemctl status casa-timestral.service
```

### Exemplo de Previsão
```bash
curl -X POST http://127.0.0.1:8091/forecast \
  -H "Content-Type: application/json" \
  -d '{"serie": [21.5, 21.8, 22.0, 22.3, 22.7, 23.0, 23.4], "horizonte": 4, "metrica": "temperatura"}'
```
