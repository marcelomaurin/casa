# Voz e áudio

[Voltar ao catálogo](../README.md)

## [google-home-agent](google-home-agent/)

Descoberta e reprodução Google Cast, com registro/heartbeat específico Google Home. Consulte seu README. Destino existente: `/home/mmm/servicos/google-home-agent`.

## [google-home-bt](google-home-bt/)

Ponte Bluetooth bidirecional com Google Home / Google Assistant na porta 8094. Permite falar com o Google Home através de síntese de voz (Pocket-TTS), ouvir e transcrever o que o Google fala (Parakeet STT) e integrar o diálogo com a IA do Jarvis. Destino sugerido: `/opt/casa/servicos/google-home-bt`.

## [tts](tts/)

Síntese e clonagem de voz. Serviço auxiliar, sem heartbeat ARM próprio. Destino existente: `/home/mmm/servicos/tts`.

## [parakeet-stt](parakeet-stt/)

Reconhecimento de voz local (Speech-to-Text / ASR) com modelo Parakeet na porta 8092. Transcreve arquivos de áudio recebidos via API REST ou captura direta do microfone da residência. Destino sugerido: `/opt/casa/servicos/parakeet-stt`.

## [pocket-tts](pocket-tts/)

Síntese de voz leve (Text-to-Speech / TTS) com Pocket-TTS na porta 8093. Suporta geração rápida de fala, ajuste de pitch/velocidade, reprodução local nos alto-falantes e integração com o ecossistema CASA. Destino sugerido: `/opt/casa/servicos/pocket-tts`.
