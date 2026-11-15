#!/usr/bin/env python3
import sys
import json
import urllib.request

device_data = {
    "device_id": sys.argv[1] if len(sys.argv) > 1 else "tuya-sensor-192-168-2-3",
    "ip": sys.argv[2] if len(sys.argv) > 2 else "192.168.2.3",
    "nome": "Sensor Tuya Temperatura/Umidade",
    "comodo": "Sala",
    "mac_address": "2e:6b:ad:23:ec:eb",
    "tipo": "sensor_temperatura_umidade"
}

body = json.dumps(device_data).encode("utf-8")
req = urllib.request.Request(
    "http://127.0.0.1:8101/devices",
    data=body,
    headers={"Content-Type": "application/json"}
)
with urllib.request.urlopen(req) as resp:
    print(resp.read().decode("utf-8"))

