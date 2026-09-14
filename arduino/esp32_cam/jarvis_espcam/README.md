# ESP32-CAM — JARVIS CASA

Firmware para **AI-Thinker ESP32-CAM / OV2640** integrado à arquitetura distribuída CASA.

## Papel do equipamento

A ESP32-CAM é configurada pelo **JARVIS Mobile via Bluetooth Low Energy** e, depois do provisionamento, trabalha normalmente por **Wi-Fi**.

```text
PRIMEIRO SETUP
Celular JARVIS Mobile
        │
        │ BLE
        ▼
ESP32-CAM
        │
        ├─ SSID
        ├─ senha Wi-Fi
        ├─ URL CASA
        ├─ token individual
        ├─ nome
        └─ localização
        │
        ▼
      NVS
        │
     reinicia
        │
        ▼
OPERAÇÃO NORMAL POR WI-FI
```

O firmware-fonte não contém SSID, senha Wi-Fi, token mestre, senha de banco nem chave RunPod.

## Área Novos Devices no celular

O Android possui a área `NewDevicesActivity` e o provisionador `EspCamProvisioner`.

Fluxo esperado:

1. Abra **Novos Devices** no JARVIS Mobile.
2. Toque em **PROCURAR ESP32-CAM POR BLUETOOTH**.
3. Uma câmera nova aparece como `JARVIS-CAM-xxxxxx`.
4. Selecione a câmera.
5. Informe nome, localização, SSID e senha Wi-Fi.
6. O celular, autenticado na CASA, cria uma identidade para a câmera em `/api/v1/provision.php`.
7. A CASA devolve um **token individual exclusivo daquela câmera**.
8. O celular grava as informações na ESP32-CAM por BLE.
9. A câmera testa o Wi-Fi, grava a configuração em NVS e reinicia.
10. A operação diária passa a usar Wi-Fi.

O token do celular não é copiado para a ESP32-CAM.

## Comportamento do BLE

O BLE fica disponível quando:

- a câmera ainda não possui configuração válida; ou
- a configuração Wi-Fi gravada falha no boot.

Quando a câmera consegue iniciar normalmente por Wi-Fi, o BLE não é necessário para a operação diária.

## Serviço BLE

Service UUID:

`7a5b0001-78fc-4b97-9f0f-9e9f5a31b401`

| Função | UUID | Acesso |
|---|---|---|
| SSID Wi-Fi | `7a5b0002-78fc-4b97-9f0f-9e9f5a31b401` | Write |
| Senha Wi-Fi | `7a5b0003-78fc-4b97-9f0f-9e9f5a31b401` | Write |
| Aplicar | `7a5b0006-78fc-4b97-9f0f-9e9f5a31b401` | Write |
| Status | `7a5b0007-78fc-4b97-9f0f-9e9f5a31b401` | Read / Notify |
| URL CASA | `7a5b0008-78fc-4b97-9f0f-9e9f5a31b401` | Write |
| Token do device | `7a5b0009-78fc-4b97-9f0f-9e9f5a31b401` | Write |
| Nome | `7a5b000a-78fc-4b97-9f0f-9e9f5a31b401` | Write |
| Localização | `7a5b000b-78fc-4b97-9f0f-9e9f5a31b401` | Write |

Para concluir, o celular escreve `APPLY` na característica de aplicação.

## Configuração persistida

Namespace NVS:

`jarviscam`

Valores persistidos:

- `ssid`
- `wifi_pass`
- `casa_url`
- `token`
- `name`
- `location`
- `configured`

## Recursos da câmera

Após conexão Wi-Fi:

- MJPEG: `/stream`
- foto: `/capture`
- flash: `/flash/on`
- flash: `/flash/off`
- status: `/status`
- heartbeat para CASA

O endereço IP é adquirido pela rede Wi-Fi. A CASA deve usar o cadastro/heartbeat para conhecer o estado do equipamento.

## API de provisionamento

O celular usa:

`POST /casa/api/v1/provision.php?acao=create`

Essa operação exige um token com `mobile.write`.

A API cria registro em `dispositivos_cluster` e devolve uma credencial exclusiva para o novo equipamento. O token é transmitido ao device durante o provisionamento BLE.

## Segurança

- nunca reutilizar token do celular na câmera;
- nunca usar token mestre em firmware;
- nunca gravar senha de banco ou chave RunPod;
- Wi-Fi e token ficam em NVS do equipamento;
- BLE é usado para setup/recovery, Wi-Fi para operação normal;
- URL CASA deve usar HTTPS.

A implementação HTTPS usa `WiFiClientSecure`. Na revisão atual ainda existe `setInsecure()` como etapa de transição de desenvolvimento. Antes de produção, instalar o CA correspondente e usar validação de certificado.

## Arduino IDE

Configuração típica:

- placa: **AI Thinker ESP32-CAM**;
- PSRAM habilitada;
- CPU 240 MHz;
- GPIO 0 em GND apenas durante gravação.

Depois do upload, retire GPIO 0 do GND e reinicie. Se ainda não houver configuração válida, a placa entra automaticamente no modo BLE `JARVIS-CAM-xxxxxx`.

## Teste recomendado

1. apagar NVS/flash da ESP32-CAM;
2. ligar a câmera;
3. verificar anúncio `JARVIS-CAM-xxxxxx`;
4. abrir Novos Devices no celular;
5. selecionar câmera;
6. informar Wi-Fi;
7. provisionar;
8. observar reinício;
9. confirmar IP no serial;
10. testar `/status`, `/capture` e `/stream`;
11. confirmar heartbeat no CASA.
