-- CASA / JARVIS - Migration 1.30
-- Registro forcado no banco de dados para os equipamentos existentes do cluster
-- Elimina a necessidade de IPs chumbados no codigo e centraliza informacoes no banco.

INSERT INTO dispositivos_cluster (
    device_id, nome, tipo, manufacturer, model, localizacao, ip_address, local_ip, porta, status, firmware_version, capabilities, metadata
) VALUES
(
    'raspberry-pi-local', 'Raspberry Pi 4B (Hub Principal)', 'linux-arm', 'Raspberry Pi Foundation', 'Raspberry Pi 4 Model B Rev 1.2', 'Hub Central / Servidor Local', '192.168.2.13', '192.168.2.13', 8080, 'online', 'v2.7.0 (#6f4db51)',
    '["arm-agent","linux-arm","hub","hardware-gateway","serial","rs485","scheduler","tts","cluster-site","ssh-agent"]',
    '{"hostname":"raspberrypi","arch":"aarch64","platform":"Debian 12 (bookworm)","role":"hub","version":"v2.7.0 (#6f4db51)","commit":"6f4db51"}'
),
(
    'raspberry-pi-node-13', 'Raspberry Pi Node 13', 'linux-arm', 'Raspberry Pi Foundation', 'Raspberry Pi 4 Model B Rev 1.2', 'Rack Servidores', '192.168.2.13', '192.168.2.13', 8080, 'online', 'v2.7.0 (#6f4db51)',
    '["arm-agent","linux-arm","cluster-site","secundario"]',
    '{"hostname":"raspberrypi","arch":"aarch64","platform":"Debian 12 (bookworm)","role":"secundario","version":"v2.7.0 (#6f4db51)","commit":"6f4db51"}'
),
(
    'cubieboard-arm-07', 'Cubieboard 2 ARMv7', 'linux-arm', 'Cubietech', 'Cubieboard 2 (Allwinner A20)', 'Gateway Automacao / Serial', '192.168.2.7', '192.168.2.7', 8080, 'online', 'v2.7.0 (#6f4db51)',
    '["arm-agent","linux-arm","hardware-gateway","serial","rs485","cluster-site"]',
    '{"hostname":"cubieboard","arch":"armv7l","platform":"Linaro 14.04 (armv7l)","role":"gateway","version":"v2.7.0 (#6f4db51)","commit":"6f4db51"}'
),
(
    'raspberry-pi-node-08', 'Raspberry Pi Node 08', 'linux-arm', 'Raspberry Pi Foundation', 'Raspberry Pi 3 Model B+', 'Quadro de Automacao / Sensores', '192.168.2.8', '192.168.2.8', 8080, 'online', 'v2.7.0 (#6f4db51)',
    '["arm-agent","linux-arm","reles","sensores","cluster-site"]',
    '{"hostname":"raspberrypi-08","arch":"aarch64","platform":"Linux ARM","role":"periferico","version":"v2.7.0 (#6f4db51)","commit":"6f4db51"}'
),
(
    'esp32-cam-01', 'ESP32-CAM Portão', 'esp32', 'Ai-Thinker', 'ESP32-CAM OV2640', 'Portão Social', '192.168.2.50', '192.168.2.50', 80, 'online', '1.0.0',
    '["camera","streaming","flash","capture"]',
    '{"resolution":"VGA","stream_path":"/stream","capture_path":"/capture","flash_path":"/flash/on"}'
)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    tipo = VALUES(tipo),
    manufacturer = VALUES(manufacturer),
    model = VALUES(model),
    localizacao = VALUES(localizacao),
    ip_address = VALUES(ip_address),
    local_ip = VALUES(local_ip),
    porta = VALUES(porta),
    status = VALUES(status),
    firmware_version = VALUES(firmware_version),
    capabilities = VALUES(capabilities),
    metadata = VALUES(metadata),
    ultimo_heartbeat = CURRENT_TIMESTAMP;

INSERT INTO arm_nodes (
    device_id, hostname, ip_address, papel, status, cpu_info, ram_info, capabilities, ultimo_ping
) VALUES
(
    'raspberry-pi-local', 'raspberrypi', '192.168.2.13', 'Raspberry Pi 4B (Hub Principal)', 'online', '4 núcleos / carga 0.1 / 44.5 °C', '980 MB / 3790 MB',
    '["arm-agent","linux-arm","hub","hardware-gateway","serial","rs485","scheduler","tts","cluster-site","ssh-agent"]', CURRENT_TIMESTAMP
),
(
    'raspberry-pi-node-13', 'raspberrypi', '192.168.2.13', 'Raspberry Pi Node 13', 'online', '4 núcleos / carga 0.1 / 44.5 °C', '980 MB / 3790 MB',
    '["arm-agent","linux-arm","cluster-site","secundario"]', CURRENT_TIMESTAMP
),
(
    'cubieboard-arm-07', 'cubieboard', '192.168.2.7', 'Cubieboard 2 ARMv7', 'online', '2 núcleos / carga 1.0 / 41.2 °C', '420 MB / 998 MB',
    '["arm-agent","linux-arm","hardware-gateway","serial","rs485","cluster-site"]', CURRENT_TIMESTAMP
),
(
    'raspberry-pi-node-08', 'raspberrypi-08', '192.168.2.8', 'Raspberry Pi Node 08', 'online', '4 núcleos / carga 0.05 / 42.0 °C', '350 MB / 920 MB',
    '["arm-agent","linux-arm","reles","sensores","cluster-site"]', CURRENT_TIMESTAMP
)
ON DUPLICATE KEY UPDATE
    hostname = VALUES(hostname),
    ip_address = VALUES(ip_address),
    papel = VALUES(papel),
    status = VALUES(status),
    cpu_info = VALUES(cpu_info),
    ram_info = VALUES(ram_info),
    capabilities = VALUES(capabilities),
    ultimo_ping = CURRENT_TIMESTAMP;
