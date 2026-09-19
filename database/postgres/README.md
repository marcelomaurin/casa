# PostgreSQL

PostgreSQL é reservado a serviços especializados que justifiquem armazenamento separado, por exemplo indexação/RAG, análise de dados ou workloads locais específicos.

## legacy/

`legacy/` contém schemas históricos anteriores à consolidação MySQL. Eles:

- não são usados pelo instalador do site;
- não são fonte de verdade;
- não devem receber novas features do Control Plane;
- não devem conter senhas ou usuários bootstrap em texto claro.

Se um serviço novo usar PostgreSQL, crie um subdiretório próprio, por exemplo `database/postgres/rag/`, com README indicando proprietário, processo de migration e conexão.
