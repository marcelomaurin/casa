# CASA - Gravafirmware (ESP32-CAM)

Grava o firmware `jarvis_espcam` na ESP32-CAM e a provisiona pela rede Wi-Fi **`espcam`** que a câmera cria quando está sem configuração. Depois, localiza a câmera na rede da casa e a registra no site CASA.
Especificação completa: [`docs/requisitos_ESPCAM.md`](../../docs/requisitos_ESPCAM.md).

## Arquivos

- `gravafirmware.lpi` / `gravafirmware.lpr`: projeto Lazarus
- `frmmain.pas` / `frmmain.lfm`: tela e fluxo
- `espcamprov.pas`: provisionamento (netsh, HTTP `/provision`, descoberta UDP, registro no site)
- Componente de gravação: `TAIFirmwareFlasher` da biblioteca CHATGPT (`pacote/AI Hardware/aifirmwareflasher.pas`, incluída pelo caminho de busca do `.lpi`)
- `gravafirmware.py`: versão Python **legada**, ainda no fluxo antigo de token por serial; não acompanha o provisionamento por `espcam`

## Requisitos

- Windows com adaptador Wi-Fi (para entrar na rede `espcam`)
- `libssl-1_1-x64.dll` e `libcrypto-1_1-x64.dll` ao lado do `gravafirmware.exe` (HTTPS com o site)
- `esptool` (detectado no Arduino15)
- Chave de API do site: **Sistema → Administração → Acesso Web & API → + INCLUIR CHAVE**. Informe no campo *Chave API* ou na variável de ambiente `CASA_API_KEY`

## Uso

1. Preencha **Redes Wi-Fi da casa** (uma por linha, `SSID;senha`) ou clique em **Carregar redes do site**.
2. Ligue IO0 ao GND e clique em **1. Gravar Firmware**.
3. Com **Provisionar após gravar** marcado, o app pede para tirar o jumper IO0 e apertar RST, aguarda 10 s e então:
   - procura a rede aberta `espcam` e conecta (sem senha);
   - `GET /provision` (lê device_id, MAC e token) e `POST /provision` (grava o token e as redes);
   - volta o PC para a rede Wi-Fi em que estava;
   - procura a câmera na rede da casa (broadcast UDP 32108; plano B: heartbeat no site);
   - registra a câmera no site com tipo, IP, porta e porta de stream.
4. **2. Provisionar (espcam)** refaz o provisionamento sem gravar o firmware. **3. Registrar no Site** reenvia só o registro.

O andamento aparece na faixa de etapa e no terminal, e é gravado em `gravafirmware.log`. **Cancelar** interrompe qualquer espera.
