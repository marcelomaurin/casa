# Casa Mobile: atualização autorizada e orientação para o robô

## Regra obrigatória para novas versões

Sempre que o robô/IA alterar e distribuir o Casa Mobile:

1. Incrementar `versionName` e `versionCode` em `android/JarvisMobile/app/build.gradle.kts`. Nunca reutilizar um versionCode distribuído.
2. Atualizar a entrada correspondente em **`bin/versions.json`** no mesmo commit, preservando os outros componentes.
3. Informar `version`, `version_code`, `package_id` e `apk_url` do APK novo. Definir `enabled: false` e `sha256: ""` enquanto ele não estiver publicado.
4. Executar testes e compilar o APK. Publicar na release indicada por `apk_url`.
5. Calcular o SHA-256 do APK publicado, preencher `sha256` e só então colocar `enabled: true`.
6. Conferir que o download existe e que pacote, versionCode e assinatura correspondem à instalação anterior. Não mandar o usuário desinstalar para contornar conflito de assinatura: isso apagaria o vínculo e os dados.
7. Atualizar `VERSIONAMENTO.md` e documentar as mudanças. Manter a mesma chave de assinatura em todas as atualizações do mesmo pacote.

O workflow `build-jarvis-mobile.yml` testa, compila e publica o APK debug. Depois preenche o hash e ativa **somente** a versão que já está indicada no JSON, se os números continuarem correspondendo ao build. Uma indicação alterada durante o build não é sobrescrita. Se o push final falhar por atualização concorrente da master, conferir o manifesto e concluir essa etapa antes de anunciar atualização disponível.

O canal `casa-mobile-debug` usa `br.com.maurinsoft.jarvismobile.debug`; o canal de produção `casa-mobile` usa `br.com.maurinsoft.jarvismobile` e requer APK assinado com a chave de produção. Não misturar os canais. A chave debug atual depende do cache do Actions; se esse cache for perdido, a assinatura pode mudar. Antes de distribuir, validar a assinatura e manter uma chave durável nos Secrets para produção. Nunca versionar chaves privadas.

## O que o aplicativo faz

- A cada criação da Activity principal (inclusive antes do login), consulta `https://raw.githubusercontent.com/marcelomaurin/casa/master/bin/versions.json` em segundo plano, sem bloquear a abertura.
- Com o serviço de conexão ativo, consulta novamente no máximo uma vez por hora.
- Usa exclusivamente a entrada autorizada do seu canal. Uma release ou um commit novo sem indicação no JSON não inicia atualização.
- Compara o `version_code` indicado com o instalado. Igual ou menor: não instala. Maior e `enabled: true`: baixa o APK do endereço indicado, dentro das releases do próprio projeto.
- Notifica quando o download termina. Sem permissão de notificações, o usuário pode acessar Ajustes > Instalar atualização baixada.
- Antes de abrir o instalador, confere SHA-256, nome do pacote, versionCode e assinatura. Um APK de outro aplicativo, corrompido, antigo ou assinado com outra chave é bloqueado.
- Se necessário, abre o ajuste Android “Permitir desta fonte”. Depois o usuário retorna e toca em “Validar e instalar”.
- O instalador do Android apresenta a confirmação. A instalação substitui o app, sem desinstalá-lo e preservando os dados locais. Não existe shell Linux no fluxo do celular.
- Falhas de consulta ou download não apagam configuração. Downloads malsucedidos podem ser tentados novamente; o APK concluído é mantido para retomar uma instalação cancelada.

## Vínculo QR e uso

O QR Code é provisionamento inicial, não uma etapa de cada abertura. O app salva URL, credencial e identidade local; fecha/reabre sem novo QR. O token do dispositivo permanece protegido pelo Android Keystore. O fechamento de telas não desfaz o vínculo. Apenas Ajustes > Desvincular este celular, com confirmação, remove a credencial local e permite novo QR. Revogação no servidor exige regularizar o acesso; offline não exige parear novamente.

Atualizar por cima com a mesma assinatura preserva a configuração. Desinstalar, limpar dados, trocar entre pacote debug e produção ou restaurar dados em outro aparelho não preserva a chave Keystore; nessas situações um novo vínculo pode ser necessário. O backup foi desabilitado para não restaurar uma credencial criptografada sem sua chave.

## Teste no aparelho

1. Instalar o APK como atualização do pacote debug existente, sem desinstalar.
2. Parear se ainda não houver vínculo; fechar pelo Android, reabrir e conferir que o QR não reaparece.
3. Repetir sem rede e após reiniciar o celular; conferir que o vínculo continua.
4. Abrir Casa, JARVIS, Dispositivos e Ajustes; testar voz/texto e histórico.
5. Em uma publicação posterior, indicar versionCode maior no JSON. Conferir download, autorização do Android e atualização preservando o vínculo.
6. Conferir que JSON desabilitado, versão igual/menor, pacote errado, hash errado e assinatura diferente não permitem instalar.
7. Comandos de envio não confirmado ficam para revisão manual; a reconexão não deve executá-los sozinha. Só reenviar após revisar o risco de duplicação; comandos com mais de cinco minutos são descartados.

A atualização do mobile não muda as regras dos integradores Linux/Raspberry.
