#!/usr/bin/env bash
# Diagnóstico de salida y contenido real del audio noVNC.
# NO modifica usuarios, PipeWire, Nginx, contraseñas ni Workstation.
set -uo pipefail

if [[ "$EUID" -ne 0 ]]; then
  echo "Ejecuta con sudo."
  exit 2
fi
if ! docker ps --format '{{.Names}}' | grep -Fxq arcadecloud-workstation; then
  echo "La Workstation está apagada: abre Office desde ArcadeCloud OS."
  exit 2
fi

echo "=== PIPEWIRE Y SALIDAS ACTIVAS ==="
docker exec --user arcade arcadecloud-workstation bash -lc '
  export XDG_RUNTIME_DIR=/run/user/10001
  export PULSE_SERVER=unix:/run/user/10001/pulse/native
  echo "Sinks:"
  pactl list short sinks 2>&1 || true
  echo "Entradas que reproducen sonido:"
  pactl list short sink-inputs 2>&1 || true
  echo "Sink predeterminado:"
  pactl get-default-sink 2>&1 || true
'

echo "=== CANAL MP3 EN LOCALHOST ==="
temp_dir="$(mktemp -d /tmp/arcadecloud-office-audio.XXXXXX)"
trap 'rm -rf "$temp_dir"' EXIT
status="$(curl -sS --connect-timeout 3 --max-time 6 \
  -o "$temp_dir/stream.mp3" -w '%{http_code}' \
  http://127.0.0.1:6081/__office_audio 2>/dev/null || true)"
bytes="$(stat -c '%s' "$temp_dir/stream.mp3" 2>/dev/null || echo 0)"
printf 'HTTP %s - %s bytes\n' "${status:-000}" "$bytes"

if [[ "$status" == 429 ]]; then
  echo "AVISO: todas las transmisiones de sonido están ocupadas; cierra pestañas Office antiguas."
  exit 0
fi
if [[ "$status" != 200 || "$bytes" -lt 2048 ]]; then
  echo "FALLO: el flujo MP3 no produce suficientes datos."
  exit 1
fi

echo "=== VOLUMEN DECODIFICADO (NO SE GUARDA AUDIO) ==="
# Volumedetect sirve para distinguir un MP3 válido pero silencioso de un
# problema exclusivo del navegador. La prueba procesa el audio temporal.
docker_image="" # Compatibilidad con shellcheck: FFmpeg corre en host.
if ! command -v ffmpeg >/dev/null 2>&1; then
  echo "FFmpeg no está instalado en el host; el flujo MP3 sí produjo datos."
else
  ffmpeg -hide_banner -nostdin -loglevel info -y \
    -i "$temp_dir/stream.mp3" -vn -af volumedetect -f null - \
    2>&1 | grep -E 'mean_volume|max_volume|Invalid data|Error' || true
fi

echo "=== RESULTADO ==="
echo "Si mean_volume=-inf dB mientras reproduce sonido, el sink capturado está en silencio."
echo "Si hay volumen y noVNC no se escucha, falta revisar la reproducción/autorización web."
