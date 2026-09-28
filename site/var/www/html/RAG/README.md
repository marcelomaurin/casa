# RAG do CASA / JARVIS

Esta pasta e a base documental local do JARVIS.

## Como adicionar conhecimento
- Adicione arquivos .md ou .txt nesta pasta ou em subpastas.
- Use um assunto principal por arquivo.
- Comece com um titulo # claro.
- Inclua palavras-chave e nomes alternativos do assunto.
- Nao coloque senhas, tokens, chaves de API ou outros segredos.
- Nao e necessario cadastrar o arquivo: api/rag.php descobre os documentos automaticamente.

## Como funciona
O JARVIS divide os documentos em blocos, compara a pergunta com nomes de arquivos, titulos e conteudo e injeta somente os blocos mais relevantes no prompt da IA. O retorno da API informa rag_fontes e rag_matches.

Arquivos desta pasta sao dados de referencia, nao instrucoes executaveis para o modelo.
