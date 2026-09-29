# Clusters e agentes Linux ARM

A página `index.php?grupo=DISPOSITIVOS&item=nodes` usa `api/arm_nodes.php`,
com autenticação da sessão do site. A consulta é somente leitura e não retorna
credenciais de dispositivos.

A lista reúne os registros legados de `arm_nodes` e os agentes atuais de
`dispositivos_cluster`. O `casa-node-agent.py` já envia `data.platform=linux-arm`,
`data.hostname`, `data.cpu`, `data.ram` e a capacidade `arm-agent` no heartbeat
universal, portanto não é necessário voltar à API legada nem reinstalar o agente.

A identificação considera plataforma/arquitetura ARM, tipos ARM e fabricantes
Raspberry Pi/Orange Pi ou capacidades ARM. Dispositivos comuns (celular, relé etc.)
não entram apenas por terem capacidade gateway. Um programa sem identificação ARM
precisa informar essa plataforma ou capacidade no registro/heartbeat.

Registros com o mesmo `device_id` aparecem uma vez; o registro atual é a fonte
autoritativa. Programas com IDs distintos no mesmo hostname permanecem separados.
Não há corte de 100 registros; a interface pagina a lista completa de 12 em 12.
Agentes sem contato recente e revogados continuam visíveis. Online indica último
contato em até 120 segundos, desde que não revogado ou explicitamente offline.
O botão Atualizar lista recarrega os dados. Horários exibidos são do servidor.

Testes: `.github/tests/test_arm_nodes.php` cobre união, classificação, estado,
telemetria e ausência de credenciais; com `JARVIS_DB_NAME=casa_test`, executa também
a consulta real no banco de CI. `test_arm_nodes_ui.js` cobre paginação, escape,
lista vazia, erro e nova tentativa. Ambos rodam no fluxo Integration Control Plane.
