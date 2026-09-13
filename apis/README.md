# JARVIS Residencial - API REST Segura v1

API HTTP/REST de alta performance, criptografada via HTTPS e protegida por arquitetura Zero-Trust, Rate Limiting e Firewall Anti-Intrusão.

## Autenticação

Todas as rotas exigem o envio do token no cabeçalho HTTP:
```http
Authorization: Bearer <MASTER_API_KEY>
```

A chave mestre pode ser visualizada, copiada ou rotacionada na aba **Acesso Web & API Segura** do painel JARVIS Web HUD.

## Endpoints Disponíveis

| Método | Endpoint | Descrição |
|---|---|---|
| `GET` | `/api/v1/status` | Telemetria resumida da residência, nós do cluster e status do túnel HTTPS. |
| `POST` | `/api/v1/comando` | Executa comandos em linguagem natural através do ecossistema Multi-IA (llama.cpp local ou RunPod GPU). |
| `GET` | `/api/v1/dispositivos` | Lista todos os dispositivos cadastrados, parâmetros e dispositivos IoT. |
| `POST` | `/api/v1/dispositivos/acionar` | Aciona relés específicos (`iddevice`, `parametro`, `valor`). |
| `GET` | `/api/v1/sensores` | Últimas leituras de sensores ambientais (temperatura, umidade, telemetria). |
| `GET` | `/api/v1/clima` | Consulta em tempo real das condições climáticas e previsão para automação de irrigação. |
| `GET` | `/api/v1/camera/snapshot` | Captura fotográfica instantânea das câmeras ESP32-CAM autorizadas. |

## Exemplos de Uso

### 1. Obter Status da Casa (cURL)
```bash
curl -X GET "https://sua-url.trycloudflare.com/api/v1/status" \
  -H "Authorization: Bearer jarvis_sec_v1_..."
```

### 2. Enviar Comando de Automação para o JARVIS (cURL)
```bash
curl -X POST "https://sua-url.trycloudflare.com/api/v1/comando" \
  -H "Authorization: Bearer jarvis_sec_v1_..." \
  -H "Content-Type: application/json" \
  -d '{"comando": "Ligue a irrigação da piscina", "ia_mode": "auto"}'
```

### 3. Acionar Relé Diretamente (Python)
```python
from jarvis_client import JarvisClient

jarvis = JarvisClient("https://sua-url.trycloudflare.com", "jarvis_sec_v1_...")
jarvis.acionar_dispositivo(iddevice=1, parametro="dev1", valor="1")
```
