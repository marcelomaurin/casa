# ESP32-CAM — Módulo de Visualização & Detecção JARVIS

Este projeto implementa o firmware para **AI-Thinker ESP32-CAM** (sensor OV2640), integrando streaming de vídeo, captura de fotos, flash LED, telemetria e provisionamento seguro de Wi-Fi via Bluetooth Low Energy (BLE).

---

## 1. Recursos Implementados

- **Streaming de Vídeo MJPEG**: endpoint `/stream`.
- **Captura de Snapshot**: endpoint `/capture`.
- **Controle de Flash LED**: endpoints `/flash/on` e `/flash/off` (GPIO 4).
- **Alerta de Movimento com Upload**: integração com `/api/agente_externo.php?acao=upload_espcam&movimento=1`.
- **Segurança com Hardware Token**: comunicação com o JARVIS usando `X-Device-Token`.
- **Telemetria Contínua**: RAM livre e RSSI enviados periodicamente ao cluster.
- **Provisionamento Wi-Fi por BLE**.
- **Persistência de SSID e senha na NVS do ESP32**.
- **Reconfiguração protegida por usuário e senha do JARVIS**.

---

## 2. Provisionamento Bluetooth

O firmware não possui mais SSID e senha Wi-Fi fixos no código-fonte.

Ao iniciar, a ESP32-CAM lê a configuração salva na NVS.

### Primeira configuração

Quando a câmera nunca foi configurada:

1. A ESP32-CAM anuncia um dispositivo BLE com nome semelhante a:

   `JARVIS-CAM-A1B2C3`

2. O aplicativo/configurador conecta ao dispositivo.
3. Envia o SSID da rede Wi-Fi.
4. Envia a senha da rede Wi-Fi.
5. Escreve `APPLY` na característica de confirmação.
6. A ESP32-CAM tenta conectar à rede informada.
7. Se a conexão funcionar, SSID e senha são gravados na NVS.
8. A câmera reinicia e passa a operar normalmente.

Na primeira configuração não é necessário informar usuário e senha do JARVIS.

### Reconfiguração

Se a câmera já possuir uma configuração gravada, a alteração do Wi-Fi exige:

- novo SSID;
- nova senha Wi-Fi;
- usuário ou e-mail de um usuário ativo do JARVIS;
- senha desse usuário.

Fluxo:

1. O configurador envia a nova rede e as credenciais do usuário via BLE.
2. A ESP32-CAM tenta conectar à nova rede sem gravá-la definitivamente.
3. Pela nova conexão, consulta:

   `POST /api/espcam_provision.php`

4. O servidor valida o usuário na tabela `usuarios`.
5. Somente se a autenticação for aceita a nova configuração é persistida na NVS.
6. Em caso de falha, a configuração anterior é preservada e a câmera tenta retornar à rede anterior.

Isso impede que alguém próximo à câmera altere o Wi-Fi apenas por possuir acesso ao Bluetooth.

---

## 3. Serviço BLE

Service UUID:

`7a5b0001-78fc-4b97-9f0f-9e9f5a31b401`

Características:

| Função | UUID | Acesso |
|---|---|---|
| SSID Wi-Fi | `7a5b0002-78fc-4b97-9f0f-9e9f5a31b401` | Write |
| Senha Wi-Fi | `7a5b0003-78fc-4b97-9f0f-9e9f5a31b401` | Write |
| Usuário JARVIS | `7a5b0004-78fc-4b97-9f0f-9e9f5a31b401` | Write |
| Senha JARVIS | `7a5b0005-78fc-4b97-9f0f-9e9f5a31b401` | Write |
| Aplicar configuração | `7a5b0006-78fc-4b97-9f0f-9e9f5a31b401` | Write |
| Status | `7a5b0007-78fc-4b97-9f0f-9e9f5a31b401` | Read / Notify |

Para aplicar a configuração, escreva `APPLY`, `SALVAR` ou `1` na característica de confirmação.

A característica de status devolve JSON, por exemplo:

```json
{
  "status": "AGUARDANDO_CONFIGURACAO",
  "mensagem": "Informe SSID e senha Wi-Fi.",
  "configurado": false,
  "wifi_conectado": false
}
```

Possíveis estados incluem:

- `AGUARDANDO_CONFIGURACAO`
- `CONFIGURADO`
- `TESTANDO_WIFI`
- `WIFI_INVALIDO`
- `AUTENTICACAO_NECESSARIA`
- `AUTENTICANDO`
- `NAO_AUTORIZADO`
- `RECONFIGURADO`
- `WIFI_OFFLINE`

---

## 4. Como gravar na placa — Arduino IDE

1. Instale o suporte às placas ESP32 no Gerenciador de Placas.
2. Selecione **AI Thinker ESP32-CAM**.
3. Use uma versão do pacote ESP32 que disponibilize as bibliotecas BLE e `Preferences`.
4. Parâmetros sugeridos:
   - **CPU Frequency**: `240MHz (WiFi/BT)`
   - **Flash Frequency**: `80MHz`
   - **Flash Mode**: `QIO`
   - **Partition Scheme**: `Huge APP (3MB No OTA/1MB SPIFFS)`
5. No código, ajuste apenas os parâmetros próprios do JARVIS, como `jarvis_server`, `device_name` e o token do dispositivo.
6. Conecte `GPIO 0` ao `GND` para entrar em modo de gravação e pressione Reset.
7. Após o upload, desconecte `GPIO 0` do `GND` e reinicie a placa.
8. Faça a primeira configuração do Wi-Fi pelo Bluetooth.

---

## 5. Endpoint de autorização

Arquivo:

`site/var/www/html/api/espcam_provision.php`

Exemplo de requisição interna feita pela câmera:

```json
{
  "usuario": "usuario_jarvis",
  "senha": "senha_do_usuario",
  "dispositivo": "ESP32-CAM Entrada"
}
```

Resposta autorizada:

```json
{
  "ok": true,
  "usuario": "usuario_jarvis",
  "perfil": "admin"
}
```

O endpoint possui limitação de tentativas por IP e registra sucessos e falhas no `comandos_log`.

> Importante: para uma implantação definitiva, recomenda-se que a comunicação ESP32-CAM → JARVIS use HTTPS, principalmente porque a autorização de reconfiguração envolve credenciais de usuário.
