# Binários e Instaladores • Android Mobile (Casa Mobile)

Neste diretório ficam concentrados os instaladores e APKs compilados do **Casa Mobile** (`android/JarvisMobile`).

## Funcionalidades de Acesso via QR Code (v2.7.0+)

O Casa Mobile agora possui autenticação instantânea por leitura de QR Code:
1. Abra o site da CASA em `https://maurinsoft.com.br/casa/index.php?grupo=SISTEMA&item=api-sys`.
2. Role até a estação de QR Code (última operação da página).
3. No smartphone, abra o **Casa Mobile** e toque em **"LER QR CODE DA CASA"** (ou importe da galeria).
4. O app lê as credenciais, autentica no servidor via `/api/v1/` e libera acesso imediato à residência (Relés, Sensores, Voz e Dispositivos).

## Acesso Mobile Web (PWA - Sem Instalação)

Além do APK nativo, qualquer celular (Android ou iPhone/iOS) pode acessar diretamente o Web App em:
- URL: `https://maurinsoft.com.br/casa/mobile.php`
- Inclui câmera ao vivo para escanear o QR Code, feedback háptico e controle total dos relés e sensores da residência.

## Geração do APK

O APK debug é gerado automaticamente pelo GitHub Actions (`.github/workflows/build-jarvis-mobile.yml`) a cada push em `android/JarvisMobile/**` e disponibilizado como artefato e release:
- `CasaMobile-v2.7.0-debug.apk`
