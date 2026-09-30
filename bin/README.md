# CASA / JARVIS — Diretório Central de Binários e Instaladores

Este diretório concentra os artefatos compilados, instaladores e pacotes distribuíveis do projeto CASA / JARVIS.

## Estrutura de subdiretórios

| Diretório | Finalidade | Tipos de arquivo |
|---|---|---|
| [`android/`](android/) | Aplicativos Android para smartphone / tablet (Casa Mobile) | `.apk` |
| [`android-tv/`](android-tv/) | Aplicativo para Android TV (JARVIS TV) | `.apk` |
| [`linux/`](linux/) | Pacotes e binários compilados para Linux (AMD64 / ARM) | `.deb`, `.tar.gz`, binários ELF |
| [`firmware/`](firmware/) | Firmwares compilados para IoT / microcontroladores | `.bin`, `.hex` |
| [`windows/`](windows/) | Ferramentas e instaladores para ambiente Windows | `.exe`, `.msi`, `.zip` |

## Observações

- Os arquivos neste diretório servem para distribuição, testes locais e publicação de releases.
- Os builds automatizados de CI/CD (GitHub Actions) geram artefatos correspondentes para cada versão do projeto.
