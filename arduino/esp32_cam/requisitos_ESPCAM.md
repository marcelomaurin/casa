# Especificação de Requisitos e Arquitetura: ESP32-CAM (CASA / JARVIS)

**Projeto:** Sistema Integrado de Câmeras e Visão Computacional Residencial (CASA)
**Atualizado em:** 25/10/2026
**Firmware:** `jarvis_espcam` 2.0.1
**Status:** implementado e compilado; validação de ponta a ponta em hardware pendente

---

## 1. Visão geral

A ESP32-CAM sai da gravação com a memória (NVS) **vazia**. Nesse estado ela cria a rede Wi-Fi **`espcam`** e espera ser configurada pelo aplicativo desktop **gravafirmware**. O app entra nessa rede, lê a câmera, grava o token e as redes Wi-Fi da casa, e a câmera reinicia. Daí em diante ela entra na rede da casa de **maior sinal**, publica o vídeo e aceita comandos **apenas de IPs da rede interna**. O gravafirmware então volta para a rede normal do PC, localiza a câmera e a registra na tabela de câmeras do site.

```
 GRAVAFIRMWARE (PC)                ESP32-CAM                        SITE CASA
 ------------------                ---------                        ---------
 1. grava firmware (IO0 no GND)
    usuario tira IO0 e aperta RST
                                   2. NVS vazia -> cria Wi-Fi
                                      "espcam" ABERTO (sem senha)
                                      IP 192.168.4.1
 3. aguarda 10 s, procura a rede
    espcam e conecta (netsh)
 4. GET  /provision  ------------>  identidade + token + redes
 5. POST /provision  ------------>  grava token + redes na NVS
                                    e reinicia
 6. volta o PC para a rede normal
 7. register_token (sem IP) ------------------------------------->  cadastra a camera
                                   8. escaneia, entra na rede da
                                      casa de MAIOR SINAL
                                   9. heartbeat (X-Camera-Token) ->  status online + IP
 10. broadcast UDP 32108
     "ESPCAM_DISCOVER"  -------->  responde device_id, IP, portas
 11. register_token com tipo, IP,
     porta e porta de stream  ------------------------------------>  atualiza a camera
```

---

## 2. Modos do firmware

### 2.1 Provisionamento (rede `espcam`)

- **Quando:** NVS sem nenhuma rede Wi-Fi gravada (firmware recém-gravado ou após `FACTORY_RESET`). Também quando a câmera, já configurada, passa **3 minutos** sem conseguir entrar em nenhuma rede da casa.
- **Rede:** SSID `espcam`, **aberta (sem senha)**, IP da câmera `192.168.4.1`.
- **Prazo:** sem prazo quando a câmera não tem configuração. No caso de fallback (sem rede há 3 min), o `espcam` fica aberto por **5 minutos**. Se ninguém estiver conectado ao fim desse prazo, a câmera reinicia e tenta as redes da casa de novo.
- **Endpoints (só neste modo):**

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/provision` | `device_id`, `mac`, `versao`, `modo`, `token` atual, `casa_url` e SSIDs gravados (sem senhas) |
| `POST` | `/provision` | Grava a configuração e reinicia em ~1,5 s |
| `GET` | `/scan` | Redes Wi-Fi visíveis pela câmera, com RSSI |
| `GET` | `/status` | Identidade e modo |

Corpo do `POST /provision`:

```json
{
  "token": "a1b2c3...(16 a 128 caracteres alfanuméricos)",
  "casa_url": "https://maurinsoft.com.br/casa",
  "nome": "Garagem",
  "local": "Externa",
  "wifis": [
    {"ssid": "rede_sala", "senha": "..."},
    {"ssid": "rede_quintal", "senha": "..."}
  ]
}
```

`wifis` precisa ter ao menos uma rede (até 12); os demais campos são opcionais. `casa_url`, se enviado, precisa ser `https://maurinsoft.com.br` (com ou sem caminho); qualquer outro valor recebe `400` e nada é gravado. O mesmo vale para `SET_CASA_URL` na serial e para um valor inválido já salvo na NVS, que é trocado pelo padrão no boot. O host aceito é definido em `CASA_ALLOWED_HOST` na compilação. O provisionamento é **livre**: quem estiver conectado ao `espcam` pode reconfigurar a câmera, inclusive no fallback (decisão de projeto; ver §5).

### 2.2 Estação (rede da casa)

- No boot, a câmera escaneia o ambiente, cruza com as redes gravadas e **conecta na de maior RSSI**. Se nenhuma aparecer no scan, tenta todas em sequência.
- Se a conexão cair, tenta de novo a cada 25 s. Depois de 3 min sem rede, volta ao modo provisionamento (§2.1).
- **Acesso:** somente clientes com IP interno: `10.0.0.0/8`, `172.16.0.0/12` e `192.168.0.0/16`. Os demais recebem `403`. **Não há token no acesso local.**

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/stream` | MJPEG (porta 80) |
| `GET` | `/capture` | Foto JPEG |
| `GET` | `/status` | Identidade, IP, RSSI e survey de Wi-Fi |
| `GET` | `/wifi_survey` | RSSI de cada rede da casa (`?refresh=1` força novo scan) |
| `POST` | `/config/wifis` | Substitui a lista de redes (usado pelo daemon); vale na próxima reconexão |
| `GET` | `/flash/on`, `/flash/off` | LED de flash |

- **Descoberta UDP:** porta **32108**. A câmera responde a um datagrama `ESPCAM_DISCOVER` vindo de IP interno com:

```json
{"type":"esp32cam","device_id":"esp32cam-0CAFC4","mac":"24:6F:28:0C:AF:C4","versao":"2.0.0",
 "modo":"estacao","ip":"192.168.2.57","porta":80,"porta_stream":80,
 "stream":"/stream","capture":"/capture","ssid":"rede_sala","rssi":-58}
```

- **Heartbeat:** a cada 30 s, `GET {casa_url}/api/v1/camera.php?acao=heartbeat&device_id=...&ip=...&mac=...&rssi=...` com o cabeçalho `X-Camera-Token`. Esse endpoint v1 só aceita o heartbeat, com rate limit de 10 por minuto, e repassa ao `camera_discovery.php`. Câmeras com firmware 2.0.0 continuam usando `camera_discovery.php?acao=heartbeat`, que segue aceito. O HTTPS é validado contra as raízes Let's Encrypt (`casa_ca.h`). Sem token gravado, a câmera não envia heartbeat.

### 2.3 Serial (diagnóstico e recuperação, 115200 baud)

Mensagens de andamento, uma por linha: `CAM_STAGE:BOOT`, `CONFIG_OK`, `CAMERA_OK`/`CAMERA_ERR`, `AP_ESPCAM`, `WIFI_SCAN`, `WIFI_FOUND`, `WIFI_BEST`, `WIFI_CONNECTING`, `WIFI_OK`, `WIFI_FAIL`, `PROVISIONED`, `DISCOVERY_UDP`, `RESTART`, além de `CAM_READY` quando a câmera aceita comandos.

| Comando | Efeito |
|---|---|
| `PING` | Responde `CAM_READY:VER=...:DEVICE_ID=...:MODO=...` |
| `STATUS` | Modo, IP, RSSI e quantidade de redes |
| `GET_TOKEN` / `INFO` | Identidade e token (`CAM_SECRET:...`) |
| `SET_TOKEN:<token>` | Grava o token |
| `SET_WIFI:<ssid>:<senha>` | Adiciona/atualiza uma rede e reinicia |
| `FACTORY_RESET` | Apaga a NVS e reinicia (volta ao `espcam`) |
| `RESTART`, `FLASH:ON`, `FLASH:OFF` | — |

### 2.4 Memória (NVS, namespace `jarviscam`)

`token`, `house_wifis` (JSON com SSID e senha), `casa_url`, `device_id` (derivado do MAC de fábrica), `name`, `location`. Nada disso vem no código-fonte: o firmware não contém redes nem senhas.

---

## 3. Aplicativo desktop `gravafirmware`

Projeto Lazarus em `desktop/gravafirmware` (`frmmain.pas` + `espcamprov.pas`). A gravação usa o componente `TAIFirmwareFlasher` da biblioteca CHATGPT.

1. **Gravar firmware:** `jarvis_espcam.ino.merged.bin` em `0x0` (IO0 ligado ao GND). A gravação apaga a NVS.
2. Com **"Provisionar após gravar"** marcado, o app pede para tirar o jumper IO0 e apertar RST, **aguarda 10 s** e segue sozinho. Também dá para disparar pelo botão **2. Provisionar (espcam)**.
3. Cria o perfil Wi-Fi aberto `espcam` no Windows (`netsh wlan add profile`, modo manual) e conecta (`netsh wlan connect`), tentando por até 90 s.
4. `GET /provision` preenche device_id e MAC.
5. Token: usa o do campo; se estiver vazio, o que a câmera já tem; se também estiver vazio, gera um novo.
6. `POST /provision` com o token e as redes da caixa *Redes Wi-Fi da casa* (uma por linha, `SSID;senha`). O botão **Carregar redes do site** preenche a caixa a partir de `get_house_wifis`.
7. Desconecta do `espcam` e reconecta o PC na rede Wi-Fi em que estava. Se o PC estava só no cabo, apenas desconecta.
8. Registra a câmera no site sem IP, para o site aceitar o heartbeat dela.
9. Procura a câmera na rede da casa por broadcast UDP (porta 32108), por até 120 s. O plano B é o IP informado pelo heartbeat (`acao=listar`).
10. Registra a câmera na tabela de câmeras do site com **tipo, IP, porta de comandos e porta de stream**.

Todo passo aparece na faixa de etapa e no terminal, e é gravado em `gravafirmware.log` ao lado do exe. O botão **Cancelar** interrompe qualquer espera.

Requisitos do PC: Windows com Wi-Fi, chave de API do site CASA (campo *Chave API* ou variável `CASA_API_KEY`) e as DLLs do OpenSSL 1.1 x64 (`libssl-1_1-x64.dll` e `libcrypto-1_1-x64.dll`) ao lado do exe. Sem essas DLLs, o HTTPS do Free Pascal falha com "Could not initialize OpenSSL library".

A chave de API é criada no site em **Sistema → Administração → Acesso Web & API → + INCLUIR CHAVE**.

---

## 4. Site CASA (`api/camera_discovery.php`)

Todas as ações exigem sessão do painel ou chave de API (`X-API-Key` / `Authorization: Bearer`), inclusive `register_token`, `listar`, `get_house_wifis` e `get_snapshot`. A única exceção é o `heartbeat` da câmera, autenticado pelo token dela (`X-Camera-Token`), que tem de bater com o token registrado. O `listar` não devolve o token das câmeras, e erros internos respondem só uma mensagem genérica (o detalhe vai para o `error_log`). A tabela `redes_wifi_casa` começa vazia: não há mais redes padrão.

| Método | Ação | Origem | Descrição |
|---|---|---|---|
| `POST` | `register_token` | gravafirmware | Cria ou atualiza a câmera em `dispositivos_cluster`. Campos: `device_id`, `token`, `mac`, `ip`, `tipo` (padrão `espcam`), `porta` (padrão 80), `porta_stream` (padrão = `porta`). Grava `metadata.token`, `web_port`, `stream_port`, `stream_url` e `snapshot_url` |
| `GET` | `heartbeat` | Câmera | Atualiza `ultimo_heartbeat`, IP, MAC e RSSI. Firmware 2.0.1 chega por `/api/v1/camera.php` |
| `GET` | `listar` | Web / gravafirmware | Câmeras com status dinâmico (online se o heartbeat tiver ≤ 90 s) |
| `GET` | `get_tokens` | Daemon | Tokens registrados (somente do banco) |
| `GET` | `get_house_wifis` | Daemon / gravafirmware / Web | Redes Wi-Fi da casa (com senha) |
| `POST` | `save_house_wifi`, `delete_house_wifi` | Web | Cadastro das redes da casa |
| `POST` | `report_wifi_scan` | Daemon | Grava o survey de sinal (`camera_wifi_survey`) |
| `GET` | `get_wifi_survey` | Web | Histórico do survey |
| `POST` | `upload_snapshot` | Daemon | Recebe o frame; os arquivos ficam protegidos por `.htaccess` |
| `GET` | `get_snapshot` | Web / mobile | Último frame da câmera |

O cofre de tokens fica **somente no banco** (`dispositivos_cluster.metadata.token`). O antigo `data/camera_tokens.json` dentro do webroot não é mais usado; se ainda existir no servidor, apague.

---

## 5. Daemon de visão (`clusters/visao/espcam/espcam_daemon.py`)

- Lê `CASA_SERVER_URL` e `CASA_API_KEY` do ambiente e envia a chave em `X-API-Key` em todas as chamadas ao site.
- A cada ciclo (8 s), varre a sub-rede (`/status`), captura `/capture` das câmeras conhecidas e publica o frame (`upload_snapshot`).
- A cada 60 s, sincroniza as redes da casa do site, repassa a lista para as câmeras (`/config/wifis`) e envia o survey de sinal (`report_wifi_scan`).
- **Limitação atual:** a regra "apenas um daemon ativo por residência (Active/Standby)" **não está implementada**: todo daemon iniciado atua como ativo.

---

## 6. Segurança: decisões e consequências

| Decisão | Consequência |
|---|---|
| Acesso local à câmera só por IP interno, sem token | Qualquer aparelho conectado ao Wi-Fi da casa (inclusive visitantes) vê o stream e aciona o flash. Separe uma rede de convidados se isso for um problema. |
| `espcam` aberto (sem senha) e provisionamento livre | Quem estiver ao alcance com a câmera em modo `espcam` (recém-gravada ou há 3 min sem rede) pode ler o token e reconfigurá-la. O tráfego do provisionamento, incluindo as senhas das redes da casa, passa sem criptografia e pode ser capturado por quem estiver perto. Derrubar o roteador por 3 min é suficiente para abrir essa janela. |
| Token só identifica a câmera no site | O heartbeat de uma câmera só é aceito se o token bater com o registrado; câmeras não registradas recebem `401`. Só quem tem chave de API ou sessão consegue registrar ou trocar o token. |
| `casa_url` fixa em HTTPS no host do site | Mesmo quem reconfigurar a câmera pelo `espcam` não consegue desviar o heartbeat (e o token) para outro servidor; o certificado é validado contra `casa_ca.h`. Se o site trocar de autoridade certificadora, atualize `casa_ca.h` e regrave o firmware. |
| Redes e senhas apenas na NVS | O firmware-fonte não contém credenciais. As senhas antigas que estavam no código continuam no histórico do git: troque-as. |

---

## 7. Banco de dados

```sql
CREATE TABLE IF NOT EXISTS redes_wifi_casa (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ssid VARCHAR(64) NOT NULL,
  senha VARCHAR(64) NOT NULL,
  prioridade INT NOT NULL DEFAULT 1,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_redes_wifi_ssid (ssid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS camera_wifi_survey (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id VARCHAR(120) NOT NULL,
  ip_address VARCHAR(45) NULL,
  wifi_conectado VARCHAR(64) NULL,
  rssi_conectado INT NULL,
  redes_detectadas JSON NOT NULL,
  coletado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cws_device (device_id, coletado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

A câmera fica em `dispositivos_cluster` (`tipo = 'espcam'`). IP, MAC, RSSI e `ultimo_heartbeat` ficam em colunas; token, portas e URLs, em `metadata`.

---

## 8. Histórico

- **2.0.1 (25/10/2026):** HTTPS do heartbeat passa a validar o certificado (`setCACert`, antes `setInsecure`); `casa_url` restrita a `https://CASA_ALLOWED_HOST`; heartbeat mantido durante o `/stream` e enviado para `/api/v1/camera.php`; `/status` sem scan de Wi-Fi bloqueante (o survey atualizado fica em `/wifi_survey`). No site, `camera_discovery.php` passou a exigir autenticação em todas as ações exceto o heartbeat, deixou de expor tokens e detalhes de erro, e perdeu as redes Wi-Fi padrão; o `espcam_provision.php` legado foi removido.
- **2.0.0 (19/10/2026):** provisionamento pela rede `espcam` (AP) com `GET`/`POST /provision`; NVS vazia após a gravação; conexão na rede de maior sinal; acesso local por IP interno; fallback para o `espcam` após 3 min sem rede; descoberta UDP; registro no site com tipo, IP e portas. O gravafirmware passou a fazer o fluxo completo: gravar, provisionar, voltar à rede, localizar e registrar.
- **1.5.0:** protocolo serial com `CAM_STAGE`/`CAM_READY`/`PING` (substituído pelo provisionamento Wi-Fi; os comandos seriais ficaram para diagnóstico).
- **1.4.0:** heartbeat autenticado por token com HTTPS validado; atendimento serial durante a conexão Wi-Fi; correções de `/capture` e do stream bloqueante.
- **1.0.0 a 1.3.0:** gravafirmware (Lazarus/Python), token por serial, cofre de tokens, status dinâmico por heartbeat, survey de Wi-Fi.
