#!/bin/sh
set +e

ENV_FILE="/etc/casa-avatar.env"
[ -f "$ENV_FILE" ] && . "$ENV_FILE"

BASE="${CASA_BASE_URL:-https://casa.maurinsoft.com.br}"
CAPTURE="${AUDIO_CAPTURE_DEVICE:-default}"
PLAYBACK="${AUDIO_PLAYBACK_DEVICE:-default}"
RGB="${KINECT_RGB_FILE:-/tmp/casa-kinect-rgb.ppm}"
DEPTH="${KINECT_DEPTH_FILE:-/tmp/casa-kinect-depth.pgm}"

ok(){ printf "[ OK ] %s\n" "$1"; }
fail(){ printf "[FAIL] %s\n" "$1"; }

echo "CASA Avatar Agent - diagnóstico"
echo "================================"

if command -v lsusb >/dev/null 2>&1 && lsusb | grep -Eqi 'Microsoft|Xbox|Kinect|045e'; then
  ok "Kinect/Microsoft detectado no USB"
else
  fail "Kinect não detectado no USB"
fi

if kinect-snapshot "$RGB" "$DEPTH" >/tmp/kinect-diag.log 2>&1; then
  ok "Captura RGB/depth"
else
  fail "Captura RGB/depth: $(tail -1 /tmp/kinect-diag.log 2>/dev/null)"
fi

if arecord -l >/tmp/arecord-list.txt 2>&1; then
  ok "ALSA possui dispositivos de captura"
else
  fail "Nenhum dispositivo ALSA de captura"
fi

if arecord -q -D "$CAPTURE" -f S16_LE -r 16000 -c 1 -d 2 /tmp/avatar-mic-test.wav >/tmp/mic-diag.log 2>&1; then
  ok "Microfone: $CAPTURE"
else
  fail "Microfone: $CAPTURE"
fi

if command -v speaker-test >/dev/null 2>&1; then
  if speaker-test -D "$PLAYBACK" -t sine -f 700 -l 1 >/tmp/speaker-diag.log 2>&1; then
    ok "Speaker: $PLAYBACK"
  else
    fail "Speaker: $PLAYBACK"
  fi
fi

if curl -fsS --max-time 10 "$BASE/api/v1/health.php" >/tmp/casa-health.json 2>&1; then
  ok "Servidor CASA acessível: $BASE"
else
  fail "Servidor CASA inacessível: $BASE"
fi

if [ -n "${CASA_API_TOKEN:-}" ]; then
  if curl -fsS --max-time 15 -H "Authorization: Bearer $CASA_API_TOKEN" "$BASE/api/v1/status" >/tmp/casa-auth.json 2>&1; then
    ok "CASA_API_TOKEN autenticado"
  else
    fail "CASA_API_TOKEN rejeitado ou rota indisponível"
  fi
else
  fail "CASA_API_TOKEN não configurado"
fi

if [ -n "${CASA_DEVICE_TOKEN:-}" ]; then
  ok "CASA_DEVICE_TOKEN configurado"
else
  fail "CASA_DEVICE_TOKEN não configurado"
fi

echo
echo "Arquivos de diagnóstico: /tmp/*-diag.log /tmp/casa-*.json"
