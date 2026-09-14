# JARVIS Watch — LILYGO T-Watch 2020 V3

O firmware trata o T-Watch como smartwatch JARVIS com mostradores, launcher, voz, alarme, atividade, automação residencial, Wi-Fi de contingência e integração com celular/site.

## Hardware alvo

- LILYGO T-Watch 2020 V3
- ESP32 Dev Module / core ESP32 2.0.14
- TFT ST7789 240x240
- touch FT6336
- RTC PCF8563
- PMIC AXP202
- acelerômetro BMA423
- motor de vibração
- microfone PDM SPM1423
- Wi-Fi ESP32

O T-Watch 2020 V3 usado neste projeto não fornece câmera, GPS nem alto-falante de reprodução ao firmware. Câmera, GPS, vídeo e áudio de resposta usam o celular/site; o relógio oferece microfone, texto e vibração.

## Estabilidade / prevenção de travamentos

A revisão atual remove operações bloqueantes do caminho principal sempre que possível:

- scan de Wi-Fi assíncrono;
- conexão Wi-Fi iniciada sem aguardar em loop na tela de configuração;
- captura PDM processada em pequenas leituras no `loop()`;
- alarme por máquina de estados, sem sequência longa de `delay()`;
- timeouts curtos para HTTPS;
- validação de ponteiros de hardware antes de uso;
- botão físico com debounce;
- touch ignorado com tela apagada;
- botão físico também configurado como fonte de wake no modo de economia.

Não existe `try/catch` útil para a maior parte do firmware Arduino porque a configuração embarcada normalmente compila C++ sem exceções. O tratamento é feito por retorno de erro, timeout e máquinas de estado.

## Botão físico

O botão PEK/AXP202 é a chave principal da tela:

```text
pressionar -> apaga a tela
pressionar -> acende a tela
```

O firmware usa `AXP202_PEK_SHORTPRESS_IRQ`, conforme o mecanismo oficial da biblioteca do T-Watch. No modo ULTRA o botão também pode acordar o ESP32 do deep sleep.

## Navegação

```text
esquerda / direita  -> troca skin
para cima            -> abre aplicativos
para baixo           -> retorna
botão físico          -> tela ON/OFF
```

## Launcher

```text
VOZ     ALM     CAM     GPS
CASA    PASSOS  STATUS  CONFIG
```

## Wi-Fi no próprio relógio

Em `CONFIG -> WIFI`:

1. toque em `BUSCAR`;
2. o Watch faz scan sem congelar a UI;
3. mostra as redes por RSSI;
4. toque no SSID;
5. abre teclado touch;
6. `PAG` alterna minúsculas/maiúsculas/números e símbolos;
7. `APAGA` corrige a senha;
8. `SALVAR` grava a rede e inicia a associação Wi-Fi sem bloquear a interface.

Até cinco redes conhecidas podem ser armazenadas. Redes desconhecidas nunca são conectadas automaticamente.

## Voz / JARVIS

A captura de voz PDM não bloqueia mais a interface inteira por quase dois segundos. O estado é processado no loop.

A tela de voz permite escolher a saída da resposta:

- `CELULAR`: resposta falada no smartphone;
- `TEXTO`: somente texto/feedback visual;
- `AMBOS`: áudio no celular e texto no Watch.

Fluxo:

```text
Watch microfone -> celular -> STT -> JARVIS/IA -> celular/Watch
```

O T-Watch não reproduz voz diretamente porque não há saída de alto-falante disponível nesta configuração de hardware.

## Alarme

O alarme local usa RTC e funciona sem Internet. Agora possui seleção de padrão:

- `CURTO`;
- `DUPLO`;
- `URGENTE`;
- `CELULAR`.

Os três primeiros são padrões de vibração do próprio Watch. `CELULAR` solicita toque sonoro no telefone quando o transporte Watch <-> Android estiver ativo.

A vibração foi reescrita sem sequência bloqueante de `delay()`.

## Câmera e videochamada

A tela `CAMERA / VIDEO` oferece:

- `FOTOGRAFAR`: usa a câmera do celular;
- `VIDEO FAMILIA`: cria/inicia chamada no canal Família CASA.

Se o celular estiver conectado por BLE, o pedido é encaminhado ao Android. Se BLE estiver indisponível mas o Watch estiver conectado ao Wi-Fi e configurado com token próprio, ele cria a chamada diretamente em:

```text
/casa/api/v1/family.php?acao=call_start
```

Como o Watch não tem câmera, o vídeo sempre vem do celular/site.

## GPS

O GPS é fornecido pelo celular pareado. O Watch envia `gps_request` e exibe o resultado resumido quando o transporte estiver ativo.

## Economia de energia

Perfis:

- NORMAL
- ECO
- ULTRA

O modo ULTRA limita brilho, aumenta o intervalo do loop, apaga o display e pode entrar em deep sleep. O botão físico é fonte de wake-up; o movimento pelo BMA423 continua opcional.

## Canal Família e assistência

O site mantém:

```text
/casa/familia.php
/casa/api/v1/family.php
```

para presença, broadcast, assistência e sinalização de chamadas.

A camada de assistência continua separada em `jarvis_assistance.*`. Alertas de inatividade são alertas de verificação, não diagnóstico médico.

## Estado atual

| Recurso | Estado |
|---|---|
| Skins / swipe / launcher | Implementado |
| Botão físico tela ON/OFF | Implementado; validar hardware |
| Alarme não bloqueante | Implementado |
| Seleção de padrão do alarme | Implementado |
| Microfone PDM não bloqueante | Implementado; validar hardware |
| Saída de resposta voz celular/texto/ambos | Interface implementada |
| Busca Wi-Fi | Implementada assíncrona |
| Teclado de senha Wi-Fi | Implementado |
| Associação Wi-Fi após salvar | Implementada sem espera bloqueante |
| Videochamada Família | Pedido via BLE ou Wi-Fi/site implementado |
| Foto remota | UI implementada; depende do transporte Android |
| GPS | depende do transporte Android |
| BLE Watch -> Android | ainda precisa implementação GATT estável no Watch |
| Assistência/SOS | módulo disponível; integração completa ainda pendente |

> Esta revisão precisa ser compilada no ambiente ESP32 2.0.14 e validada no T-Watch físico. A prioridade do teste é: botão, touch, Wi-Fi, microfone e estabilidade por pelo menos 30 minutos.
