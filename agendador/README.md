# Agendador FATEC / ESP32 E-Paper

O projeto contém uma aplicação desktop em Lazarus/Free Pascal e o firmware
Arduino do LILYGO T5 V2.4. O display é dividido em duas áreas: QR Code no
terço esquerdo e quatro linhas de texto na área direita.

## Firmware

Pasta `firmware/lilygo_T5`.

O ESP32 começa em uma rede de configuração temporária:

```text
SSID: FATEC-EPD-<parte-do-MAC>
Senha: fatec1234
IP: 192.168.4.1
TCP: 8090
```

O protocolo de configuração usa JSON terminado por LF:

```json
{"op":"discover"}
{"op":"configure","name":"EPD-01","ssid":"MinhaRede","password":"senha-wifi","token":"token-atual","new_token":"token-atual"}
```

Depois de configurado, o ESP32 usa a rede recebida com DHCP e aceita somente
mensagens autenticadas:

```json
{"op":"set","token":"...","request_id":"uuid","lines":["linha 1","linha 2","linha 3","linha 4"],"qr":"https://exemplo"}
```

O `request_id` torna o envio idempotente. A mesma mensagem não é desenhada
novamente. O firmware persiste configuração e conteúdo no NVS, valida tamanho
das linhas, limita o QR Code a 53 bytes e rejeita caracteres não suportados.
O botão do T5 pressionado por 3 segundos reabre o AP temporário por 10 minutos.

## Serviço Python

Pasta `service`. Execute `python service/agendador_service.py` no computador
que ficará ligado. O serviço usa somente a biblioteca padrão do Python, cria
o mesmo banco SQLite do desktop e fica disponível apenas localmente em
`127.0.0.1:8765`.

Ele é responsável por:

- descoberta dos ESP32 na sub-rede configurada (`FATEC_AGENDADOR_SUBNET`);
- envio TCP autenticado na porta 8090;
- fila de envio, tentativas e histórico;
- execução dos agendamentos pendentes.

Endpoints locais: `GET /status`, `GET /scan` e `POST /send`.

## Agendador desktop

Pasta `desktop`. Abra `agendador.lpi` no Lazarus ou use o executável em
`desktop/bin/agendador.exe`.

O banco SQLite fica em `%LOCALAPPDATA%\\FatecAgendador\\agendador.sqlite3`.
As senhas são protegidas com Windows DPAPI e não ficam em texto puro no banco.

O Lazarus é a interface visual e o CRUD do operador. Ele compartilha o banco
com o serviço Python e apresenta os equipamentos, mensagens, agendamentos e
histórico. A comunicação operacional deve ser feita pelos endpoints locais do
serviço, deixando descoberta, envio e agendamento no processo Python.

Funções disponíveis na interface:

- cadastro, edição, exclusão e detalhes dos equipamentos;
- configuração inicial: conecta temporariamente no AP do ESP32, envia SSID,
  senha, nome e token, espera o DHCP e restaura a rede anterior do Windows;
- CRUD de mensagens com quatro linhas e QR Code;
- envio manual e criação de agendamentos através do serviço;
- histórico e atualização periódica do estado dos equipamentos.

O Windows WLAN API é usado para trocar temporariamente de rede. O programa
restaura a rede anterior mesmo quando a configuração falha; se essa restauração
falhar, o erro aparece no histórico e na barra de status.

## Testes

O executável foi compilado para Win64. Os testes locais verificam SQLite CRUD,
DPAPI, validação de mensagens/QR, agendamento, retries, recuperação após
reinício, fragmentação TCP e bloqueio quando o IP responde com outra identidade.
O simulador está em `desktop/test_integration.py`.

```text
desktop\\bin\\agendador.exe --self-test .\\test-results
python desktop\\test_integration.py .\\test-results
```

O firmware foi compilado com ESP32 core 2.0.14 e GxEPD 3.1.3. A gravação física
e o teste do painel ainda precisam ser feitos na placa.
