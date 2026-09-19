# Device Registry do CASA

O `Device Registry` é a fonte única de verdade para dispositivos físicos e clientes conectados ao CASA.

## Fonte canônica

A identidade e o estado operacional ficam em:

```text
dispositivos_cluster
```

As capabilities normalizadas ficam em:

```text
device_capabilities
```

A coluna JSON `dispositivos_cluster.capabilities` é mantida apenas para compatibilidade de dados antigos e declaração inicial do device. Quando um device é lido pelo Registry, capabilities antigas podem ser normalizadas para `device_capabilities`.

## Responsabilidades

O Registry centraliza:

- `device_id` canônico;
- nome e tipo;
- status e health;
- online/offline calculado;
- transporte;
- IP local e observado;
- gateway;
- bateria e RSSI;
- fabricante/modelo/firmware/protocolo;
- localização;
- metadata;
- capabilities e risco.

## Roteamento

Todo device expõe uma estrutura de roteamento derivada do Registry:

```json
{
  "transport": "wifi",
  "gateway_device_id": null,
  "local_ip": "192.168.1.20",
  "observed_ip": "203.0.113.10"
}
```

Clientes e agentes não devem manter uma segunda fonte de verdade desses campos.

## Capabilities

Antes de uma ação física:

```text
Task / Rule / API
      ↓
Device Registry
      ↓
device existe?
      ↓
capability existe?
      ↓
capability habilitada?
      ↓
risk level
      ↓
Command Bus
```

Uma capability inexistente não é tratada como habilitada por padrão.

## Integrações que usam o Registry

- Control Plane;
- Task Engine;
- Rule Engine;
- Rule Scheduler;
- Provisionamento;
- Heartbeat/device API.

## Compatibilidade

As tabelas legadas `devices` e `devpar` podem continuar existindo enquanto módulos antigos forem migrados, mas não devem ser usadas para identidade, estado ou capability de novos devices do Control Plane.

Novos recursos devem usar `device_id` e o Device Registry.
