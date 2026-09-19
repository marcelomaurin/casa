#!/usr/bin/env python3
"""CASA/JARVIS - simulador de devices para testes do Control Plane.

Exemplos:
  python3 servicos/device_simulator.py --base-url https://host/api/v1 --device-id sim_watch_01 --token TOKEN --type watch
  python3 servicos/device_simulator.py --base-url https://host/api/v1 --device-id sim_tv_01 --token TOKEN --type tv --mode failure
  python3 servicos/device_simulator.py --base-url https://host/api/v1 --device-id sim_sensor_01 --token TOKEN --type sensor --mode timeout
"""

import argparse
import json
import random
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


def request_json(method, url, token, payload=None, timeout=15):
    data = None
    headers = {"Accept": "application/json", "X-Device-Token": token}
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as res:
            raw = res.read().decode("utf-8", errors="replace")
            return res.status, json.loads(raw) if raw else {}
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            body = json.loads(raw)
        except Exception:
            body = {"raw": raw}
        return exc.code, body


def capabilities(kind):
    return {
        "watch": ["display", "notification", "vibration", "battery"],
        "sensor": ["sensor", "temperature", "humidity", "presence"],
        "tv": ["power", "volume", "media"],
        "esp32": ["gpio", "wifi", "ble"],
        "android": ["notification", "voice", "gateway"],
    }.get(kind, ["generic"])


def heartbeat(base, device_id, token, kind):
    payload = {
        "device_id": device_id,
        "transport": "simulator",
        "health": "ok",
        "firmware_version": "sim-1.1",
        "protocol_version": "1.0",
        "manufacturer": "CASA Simulator",
        "model": kind,
        "battery": random.randint(55, 100) if kind in ("watch", "android") else None,
        "rssi": random.randint(-70, -35),
        "uptime_sec": int(time.monotonic()),
        "capabilities": capabilities(kind),
        "data": {"simulated": True, "kind": kind},
    }
    return request_json("POST", f"{base}/device.php?acao=heartbeat", token, payload)


def emit_event(base, device_id, token, event_type, data=None, correlation_id=None):
    return request_json("POST", f"{base}/device.php?acao=event", token, {
        "device_id": device_id,
        "type": event_type,
        "priority": "normal",
        "correlation_id": correlation_id,
        "data": data or {"simulated": True},
    })


def poll_commands(base, device_id, token):
    qs = urllib.parse.urlencode({"acao": "commands", "device_id": device_id, "limit": 10})
    return request_json("GET", f"{base}/device.php?{qs}", token)


def post_action(base, token, action, payload):
    return request_json("POST", f"{base}/device.php?acao={action}", token, payload)


def execute_command(base, device_id, token, command, mode, failure_rate):
    command_id = int(command["id"])
    if mode == "timeout":
        print(f"TIMEOUT command={command_id} sem ACK propositalmente")
        return

    post_action(base, token, "command_ack", {"device_id": device_id, "id": command_id})
    post_action(base, token, "command_start", {"device_id": device_id, "id": command_id})
    time.sleep(0.2)

    fail = mode == "failure" or (mode == "random" and random.random() < failure_rate)
    if fail:
        status, body = post_action(base, token, "command_result", {
            "device_id": device_id,
            "id": command_id,
            "status": "error",
            "error": "Falha simulada para teste de integracao",
            "result": {"simulated": True},
        })
        print(f"FAIL command={command_id} http={status} body={body}")
    else:
        status, body = post_action(base, token, "command_result", {
            "device_id": device_id,
            "id": command_id,
            "status": "success",
            "result": {"simulated": True, "command": command.get("comando"), "payload": command.get("payload")},
        })
        print(f"DONE command={command_id} http={status} body={body}")


def main():
    parser = argparse.ArgumentParser(description="Simulador de device CASA/JARVIS")
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--device-id", required=True)
    parser.add_argument("--token", required=True)
    parser.add_argument("--type", choices=["watch", "sensor", "tv", "esp32", "android", "generic"], default="generic")
    parser.add_argument("--interval", type=float, default=5.0)
    parser.add_argument("--mode", choices=["success", "failure", "timeout", "random"], default="success")
    parser.add_argument("--failure-rate", type=float, default=0.0)
    parser.add_argument("--event", default="", help="Tipo de evento a emitir apos heartbeat")
    parser.add_argument("--correlation-id", default="", help="Correlation ID opcional para eventos E2E")
    parser.add_argument("--disconnect-after-heartbeat", action="store_true", help="Envia heartbeat e encerra para simular desconexao")
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()

    base = args.base_url.rstrip("/")
    failure_rate = max(0.0, min(1.0, args.failure_rate))

    while True:
        status, body = heartbeat(base, args.device_id, args.token, args.type)
        print(f"HEARTBEAT http={status} body={body}")
        if status >= 400:
            return 2
        if args.event:
            e_status, e_body = emit_event(base, args.device_id, args.token, args.event, {"simulated": True, "value": True}, args.correlation_id or None)
            print(f"EVENT type={args.event} http={e_status} body={e_body}")
        if args.disconnect_after_heartbeat:
            print("DISCONNECT simulado apos heartbeat")
            return 0

        status, body = poll_commands(base, args.device_id, args.token)
        print(f"POLL http={status} commands={len(body.get('commands', [])) if isinstance(body, dict) else 0}")
        if status < 400 and isinstance(body, dict):
            for command in body.get("commands", []):
                execute_command(base, args.device_id, args.token, command, args.mode, failure_rate)

        if args.once:
            return 0
        time.sleep(max(1.0, args.interval))


if __name__ == "__main__":
    sys.exit(main())
