# ESP32-CAM — JARVIS CASA

Firmware `jarvis_espcam` (versão 2.0.1) para **AI-Thinker ESP32-CAM / OV2640**.
A especificação completa está em [`docs/requisitos_ESPCAM.md`](../../../docs/requisitos_ESPCAM.md).

## Como funciona

```text
gravar firmware ──► NVS vazia ──► cria Wi-Fi ABERTO "espcam" (sem senha, 192.168.4.1)
                                        │
                     gravafirmware: GET/POST /provision (token + redes da casa)
                                        │
                                    reinicia
                                        │
                 escaneia e entra na rede da casa de MAIOR SINAL
                                        │
       /stream e /capture na porta 80, apenas para IPs internos;
       heartbeat ao site; responde à descoberta UDP 32108
                                        │
         sem rede por 3 min ──► reabre o "espcam" por 5 min ──► reinicia
```

- **Provisionamento:** `GET /provision` devolve identidade, token e redes gravadas. `POST /provision` com `{"token": "...", "wifis": [{"ssid": "...", "senha": "..."}]}` grava tudo e reinicia.
- **Operação:** `/stream`, `/capture`, `/status`, `/wifi_survey`, `/config/wifis` (POST), `/flash/on`, `/flash/off`. Clientes fora de `10.x`, `172.16-31.x` e `192.168.x` recebem `403`. Não há token no acesso local.
- **Descoberta:** um datagrama UDP `ESPCAM_DISCOVER` na porta 32108 recebe como resposta um JSON com `device_id`, `ip`, `porta` e `porta_stream`.
- **Site:** heartbeat a cada 30 s em `/api/v1/camera.php` com `X-Camera-Token`, por HTTPS validado contra as raízes Let's Encrypt (`casa_ca.h`). A `casa_url` só aceita `https://maurinsoft.com.br` (constante `CASA_ALLOWED_HOST`), então o token não pode ser desviado para outro servidor.
- **Serial (115200):** mensagens `CAM_STAGE:*` e comandos `PING`, `STATUS`, `GET_TOKEN`, `SET_TOKEN:<t>`, `SET_WIFI:<ssid>:<senha>`, `FACTORY_RESET` e `RESTART`.

O código-fonte não contém redes, senhas nem token: tudo fica na NVS (namespace `jarviscam`).

## Compilar

Placa **AI Thinker ESP32-CAM** (core esp32 3.x), PSRAM habilitada:

```bash
arduino-cli compile --fqbn esp32:esp32:esp32cam --build-path build .
```

O gravafirmware usa `build/jarvis_espcam.ino.merged.bin` (gravado em `0x0`; a gravação apaga a NVS).

## Gravar e provisionar

1. Ligue IO0 ao GND, alimente a placa com 5 V e conecte o adaptador serial (TX→U0R, RX→U0T, GND comum).
2. No gravafirmware, preencha as redes da casa (ou clique em *Carregar redes do site*) e a Chave API. Clique em **1. Gravar Firmware**.
3. Quando o app pedir, tire o jumper IO0 e aperte RST. O app espera 10 s, conecta no `espcam`, envia a configuração, volta para a rede normal, localiza a câmera e a registra no site.

Para reconfigurar uma câmera já instalada, envie `FACTORY_RESET` pela serial ou desligue as redes dela por 3 minutos para o `espcam` reabrir.

## Teste recomendado

1. Grave o firmware e confirme na serial `CAM_STAGE:AP_ESPCAM:SSID=espcam`.
2. Provisione pelo gravafirmware.
3. Confirme na serial `CAM_STAGE:WIFI_BEST` e `CAM_STAGE:WIFI_OK` com o IP.
4. A partir de um PC da rede, teste `http://<ip>/status`, `/capture` e `/stream`.
5. Confirme `[HEARTBEAT] HTTP 200` na serial e a câmera online no site (Segurança → Câmeras & visão).
