#!/usr/bin/env python3
"""CASA/JARVIS - simulador simples de device para testes do Control Plane.

Usa apenas a biblioteca padrao. Exemplo:
  python3 servicos/device_simulator.py \
    --base-url https://casa.maurinsoft.com.br/api/v1 \
    --device-id sim_watch_01 \
    --token TOKEN_DO_DEVICE \
    --type watch
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


def heartbeat(base, device_id, token, kind):
    caps = {
        "watch": ["display", "notification", "vibration", "battery"],
        "sensor": ["sensor", "temperature", "humidity"],
        "tv": ["power", "volume", "media"],
        "esp32": ["gpio", "wifi", "ble"],
    }.get(kind, ["generic"])
    payload = {
        "device_id": device_id,
        "transport": "simulator",
        "health": "ok",
        "firmware_version": "sim-1.0",
        "protocol_version": "1.0",
        "manufacturer": "CASA Simulator",
        "model": kind,
        "battery": random.randint(55, 100) if kind == "watch" else None,
        "rssi": random.randint(-70, -35),
        "uptime_sec": int(time.monotonic()),
        "capabilities": caps,
        "data": {"simulated": True, "kind": kind},
    }
    return request_json("POST", f"{base}/device.php?acao=heartbeat", token, payload)


def poll_commands(base, device_id, token):
    qs = urllib.parse.urlencode({"acao": "commands", "device_id": device_id, "limit": 10})
    return request_json("GET", f"{base}/device.php?{qs}", token)


def post_action(base, token, action, payload):
    return request_json("POST", f"{base}/device.php?acao={action}", token, payload)


def execute_command(base, device_id, token, command, failure_rate):
    command_id = int(command["id"])
    post_action(base, token, "command_ack", {"device_id": device_id, "id": command_id})
    post_action(base, token, "command_start", {"device_id": device_id, "id": command_id})
    time.sleep(0.2)

    if random.random() < failure_rate:
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
            "result": {
                "simulated": True,
                "command": command.get("comando"),
                "payload": command.get("payload"),
            },
        })
        print(f"DONE command={command_id} http={status} body={body}")


def main():
    parser = argparse.ArgumentParser(description="Simulador de device CASA/JARVIS")
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--device-id", required=True)
    parser.add_argument("--token", required=True)
    parser.add_argument("--type", choices=["watch", "sensor", "tv", "esp32", "generic"], default="generic")
    parser.add_argument("--interval", type=float, default=5.0)
    parser.add_argument("--failure-rate", type=float, default=0.0)
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()

    base = args.base_url.rstrip("/")
    failure_rate = max(0.0, min(1.0, args.failure_rate))

    while True:
        status, body = heartbeat(base, args.device_id, args.token, args.type)
        print(f"HEARTBEAT http={status} body={body}")
        if status >= 400:
            return 2

        status, body = poll_commands(base, args.device_id, args.token)
        print(f"POLL http={status} commands={len(body.get('commands', [])) if isinstance(body, dict) else 0}")
        if status < 400 and isinstance(body, dict):
            for command in body.get("commands", []):
                execute_command(base, args.device_id, args.token, command, failure_rate)

        if args.once:
            return 0
        time.sleep(max(1.0, args.interval))


if __name__ == "__main__":
    sys.exit(main())
