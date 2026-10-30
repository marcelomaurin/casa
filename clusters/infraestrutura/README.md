# Infraestrutura e recursos remotos

[Voltar ao catálogo](../README.md)

## [casa-cluster-monitor](casa-cluster-monitor/)

Monitoramento local dos integradores e serviços do nó. Destino existente: `/opt/casa/cluster-monitor`.

## [casa-cluster-update](casa-cluster-update/)

Atualizador automático dos integradores a partir do Git, reportando para `/api/v1/updates.php`. Destino existente: `/opt/casa/cluster-update`.

## [casa-ssh-agent](casa-ssh-agent/)

Controlador e agente SSH para gerenciamento de nós do cluster. Destino existente: `/opt/casa/ssh-agent`.

## [casa-tunnel](casa-tunnel/)

Gerenciamento de túnel de conectividade. Destino existente: `/opt/casa-tunnel`.

## [runpod-agent](runpod-agent/)

Gerenciamento de pods GPU RunPod e SSH remoto. Destino existente: `/opt/casa/servicos/runpod-agent`. Não é um motor de inferência local ARM.
