# Binários e Instaladores • Android Mobile (Casa Mobile)

Neste diretório ficam concentrados os instaladores e APKs compilados do **Casa Mobile** (`android/JarvisMobile`).

## Pareamento por QR Code (v2.7.1+)

O celular é pareado com um QR Code de uso único:
1. No site da CASA, abra **Segurança › Acessos pessoais** (`index.php?grupo=SEGURANÇA&item=acessos`) e clique em **Gerar QR Code de acesso**.
2. No smartphone, abra o **Casa Mobile** e toque em **"LER QR CODE DA CASA"** (ou importe a imagem da galeria).
3. O app troca o código por uma credencial própria do celular (`/api/v1/auth.php`, `acao=pair`), guardada no Android Keystore.
4. O QR vale 10 minutos e só pode ser usado uma vez. O celular aparece em **Celulares pareados**, onde o acesso pode ser revogado.

QR Codes com chave de API (Sistema › Acesso Web & API) continuam aceitos por compatibilidade.

## Acesso Mobile Web (PWA - Sem Instalação)

Além do APK nativo, qualquer celular (Android ou iPhone/iOS) pode acessar diretamente o Web App em:
- URL: `https://maurinsoft.com.br/casa/mobile.php`
- Inclui câmera ao vivo para escanear o QR Code, feedback háptico e controle total dos relés e sensores da residência.

## Geração do APK

O APK debug é gerado automaticamente pelo GitHub Actions (`.github/workflows/build-jarvis-mobile.yml`) a cada push em `android/JarvisMobile/**` e disponibilizado como artefato e release:
- `CasaMobile-v2.8.0-debug.apk`
