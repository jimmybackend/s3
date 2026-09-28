#!/usr/bin/env bash
set -euo pipefail

DISPLAY_NUMBER="${DISPLAY_NUMBER:-1}"
VNC_GEOMETRY="${VNC_GEOMETRY:-1600x900}"
VNC_DEPTH="${VNC_DEPTH:-24}"
DISPLAY=":${DISPLAY_NUMBER}"
export DISPLAY

mkdir -p "$HOME/.vnc" /workspace
chmod 700 "$HOME/.vnc"

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

# VNC no maneja autenticación de usuario. Permanece encerrado dentro del
# contenedor/loopback; ArcadeCloud autoriza vnc.html + WebSocket en Nginx.
vncserver ":${DISPLAY_NUMBER}" \
  -localhost yes \
  -geometry "$VNC_GEOMETRY" \
  -depth "$VNC_DEPTH" \
  -SecurityTypes None

VNC_PORT="$((5900 + DISPLAY_NUMBER))"
websockify --web=/usr/share/novnc/ 0.0.0.0:6080 "127.0.0.1:${VNC_PORT}" &
PROXY_PID=$!

sleep 3
kill -0 "$PROXY_PID" 2>/dev/null || {
  echo "noVNC/websockify no pudo iniciar." >&2
  exit 1
}

nohup libreoffice --nologo --norestore >/tmp/libreoffice.log 2>&1 &

echo "ArcadeCloud Workstation lista en noVNC :6080."
wait "$PROXY_PID"
