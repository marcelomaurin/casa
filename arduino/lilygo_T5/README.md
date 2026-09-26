# FATEC-RP — LILYGO T5 V2.4

Sketch Arduino para o painel **GDEM0213B74** da imagem fornecida.
Dependencias: **GxEPD by Jean-Marc Zingg** (nao GxEPD2), Adafruit GFX Library
e Adafruit BusIO. O repositorio LilyGo-T5-Epaper-Series e uma colecao de
exemplos; nao substitui por si so a instalacao da biblioteca.

No Arduino IDE, abra `lilygo_T5.ino`, selecione ESP32 Dev Module, flash 4 MB,
PSRAM desabilitada e a porta USB da placa. Bibliotecas alternativas para
outros paineis nao sao intercambiaveis com o driver GDEM0213B74.

Pinos: SPI SCK 18, MOSI 23, CS 5, DC 17, RST 16, BUSY 4; alimentacao GPIO12 HIGH.
GPIO17 e DC, nao MISO. Tela horizontal com duas linhas centralizadas.

Inicialmente: `FATEC-RP` / `Equipamento de teste`.

Conecte-se ao Wi-Fi `FATEC-RP-TESTE`, senha `fatec1234`.
Abra uma conexao **TCP** a `192.168.4.1:8090` (nao HTTP/navegador).
Envie cada comando terminado por LF, CR ou CRLF:

```text
TEXT1=FATEC-RP
TEXT2=Equipamento de teste
```

`TEXT1=[Mensagem]` tambem e aceito e remove os colchetes externos.
`TEXT2=` apaga a segunda linha. Comandos sao sensiveis a maiusculas.
Resposta `OK` significa comando aceito; a atualizacao completa do e-paper
ocorre depois, podendo levar alguns segundos. A atualizacao do display e
bloqueante; o TCP usa buffers durante esse intervalo. Evite envio continuo
de comandos sem aguardar resposta. Ate quatro clientes; conexoes ociosas
sao fechadas apos 60 s. Mensagens nao sao salvas apos desligar.

Maximo 38 caracteres por linha. Fonte maior ate 19 caracteres, menor acima.
Acentos portugueses sao convertidos para letras sem acento; outros caracteres
fora de ASCII retornam erro. Linhas maiores sao rejeitadas sem alterar a tela.

Teste: ligar, conferir os dois textos, conectar ao AP, enviar TEXT1 e TEXT2,
verificar OK e a tela, testar comando invalido e desconectar/reconectar.
O hardware precisa de validacao fisica apos gravacao.

Fontes: https://github.com/ZinggJM/GxEPD e
https://github.com/Xinyuan-LilyGO/LilyGo-T5-Epaper-Series
