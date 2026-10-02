# Versionamento — Casa Mobile

## Versão atual

- `versionName`: `2.8.1`
- `versionCode`: `281`
- pacote de desenvolvimento: `br.com.maurinsoft.jarvismobile.debug`
- pacote de produção: `br.com.maurinsoft.jarvismobile`

## Regra

O aplicativo segue versionamento semântico:

```text
MAJOR.MINOR.PATCH
```

Exemplos:

```text
2.1.0
2.1.1
2.2.0
3.0.0
```

O `versionCode` deve ser inteiro e sempre aumentar a cada APK distribuído.

Sugestão adotada:

```text
2.1.0 -> 210
2.1.1 -> 211
2.2.0 -> 220
3.0.0 -> 300
```

## APK de desenvolvimento

As builds automáticas atuais são `debug` e usam:

```text
applicationId = br.com.maurinsoft.jarvismobile.debug
```

Isto permite instalar a build de desenvolvimento mesmo que uma versão anterior do Casa Mobile tenha sido assinada por outra chave debug.

O APK gerado recebe nome versionado:

```text
CasaMobile-v2.6.9-debug.apk
```

O APK não é versionado no repositório. O GitHub Actions publica a build como artifact e atualiza a pre-release correspondente em GitHub Releases.

## APK de produção

A versão de produção deve usar:

```text
applicationId = br.com.maurinsoft.jarvismobile
```

E deve ser assinada sempre com o mesmo keystore privado.

O keystore e suas senhas **não podem ser armazenados no Git**. Devem ser fornecidos ao pipeline por GitHub Actions Secrets ou outro cofre de segredos.

## Erro “App não instalado”

Se uma build antiga utilizou o mesmo `applicationId` mas foi assinada com outra chave, o Android recusará a atualização.

Para as builds `debug` a partir da versão 2.1.0, esse problema é evitado pelo pacote `.debug` separado.

Se ainda houver conflito com uma instalação debug anterior, desinstale apenas o pacote de desenvolvimento anterior e instale novamente. Não é necessário remover uma futura versão oficial que utilize o pacote de produção.

## Antes de publicar uma nova versão

1. Atualizar `versionName`.
2. Incrementar `versionCode`.
3. Registrar mudanças relevantes.
4. Compilar pelo GitHub Actions.
5. Confirmar que o APK foi gerado.
6. Confirmar o nome/versionamento do APK.
7. Testar instalação limpa e atualização a partir da versão anterior usando a mesma assinatura.

## Histórico — 2.6.9

- Incremento de versão para nova distribuição do aplicativo Android.
- Documentação da versão atual sincronizada com o projeto Gradle.
- Nome exibido atualizado para Casa Mobile no Android, navegação, notificações e identificação do cliente.
- Identificador do pacote e prefixo das tags de atualização preservados para compatibilidade com instalações existentes.

## Histórico — 2.8.0

- Vínculo de instalação persistente e desvinculação explícita.
- Navegação inferior; lista geral de dispositivos; voz e texto na mesma conversa com histórico.
- Falhas HTTP não são tratadas como offline; fila revisada pelo usuário e prazo de cinco minutos.
- Atualização autorizada por `bin/versions.json`, checada em cada abertura e a cada hora com o serviço ativo.
- Download automático; hash, pacote, versão e assinatura validados antes do instalador Android.

## Instrução obrigatória para IA/robô

Toda nova versão deve atualizar também `bin/versions.json`. Consulte [MOBILE_ATUALIZACAO.md](../../docs/MOBILE_ATUALIZACAO.md) antes de compilar/publicar.
