#!/usr/bin/env bash
set -euo pipefail

DISPLAY_NUMBER="${DISPLAY_NUMBER:-1}"
VNC_GEOMETRY="${VNC_GEOMETRY:-1600x900}"
VNC_DEPTH="${VNC_DEPTH:-24}"
VNC_PASSWORD="${VNC_PASSWORD:-}"
DISPLAY=":${DISPLAY_NUMBER}"
export DISPLAY

if [[ -z "$VNC_PASSWORD" || "${#VNC_PASSWORD}" -lt 8 ]]; then
  echo "VNC_PASSWORD debe contener al menos 8 caracteres." >&2
  exit 64
fi

mkdir -p "$HOME/.vnc" /workspace
chmod 700 "$HOME/.vnc"
printf '%s\n' "$VNC_PASSWORD" | vncpasswd -f > "$HOME/.vnc/passwd"
chmod 600 "$HOME/.vnc/passwd"

cat > "$HOME/.vnc/xstartup" <<'EOF'
#!/bin/sh
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
exec dbus-launch --exit-with-session startxfce4
EOF
chmod 700 "$HOME/.vnc/xstartup"

cleanup() {
  vncserver -kill ":${DISPLAY_NUMBER}" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

vncserver ":${DISPLAY_NUMBER}" \
  -localhost yes \
  -geometry "$VNC_GEOMETRY" \
  -depth "$VNC_DEPTH" \
  -SecurityTypes VncAuth

VNC_PORT="$((5900 + DISPLAY_NUMBER))"
websockify --web=/usr/share/novnc/ 0.0.0.0:6080 "127.0.0.1:${VNC_PORT}" &
PROXY_PID=$!

sleep 3
kill -0 "$PROXY_PID" 2>/dev/null || {
  echo "noVNC/websockify no pudo iniciar." >&2
  exit 1
}

# Fase 1: abrir el centro de LibreOffice automáticamente una vez disponible XFCE.
# En fases posteriores el broker podrá abrir directamente el archivo solicitado.
nohup libreoffice --nologo --norestore >/tmp/libreoffice.log 2>&1 &

echo "ArcadeCloud Workstation lista en noVNC :6080."
wait "$PROXY_PID"
