#!/usr/bin/env bash
set -euo pipefail
umask 0007

DISPLAY_NUMBER="${DISPLAY_NUMBER:-1}"
VNC_GEOMETRY="${VNC_GEOMETRY:-1600x900}"
VNC_DEPTH="${VNC_DEPTH:-24}"

export HOME=/home/arcade
export TZ="${TZ:-America/Mexico_City}"

if [[ -e "/usr/share/zoneinfo/$TZ" ]]; then
  ln -snf "/usr/share/zoneinfo/$TZ" /etc/localtime
  printf '%s\n' "$TZ" > /etc/timezone
fi

mkdir -p \
  /home/arcade/.vnc \
  /home/arcade/.config/xfce4 \
  /home/arcade/.config/pipewire \
  /home/arcade/.local/state \
  /home/arcade/.local/bin \
  /home/arcade/Projects \
  /home/arcade/Downloads \
  /workspace \
  /run/dbus \
  /run/user/10001

chown -R arcade:arcade /home/arcade
chown arcade:arcade /run/user/10001
chmod 700 /run/user/10001

if [[ -n "${ARCADECLOUD_RDP_PASSWORD:-}" ]]; then
  echo "arcade:${ARCADECLOUD_RDP_PASSWORD}" | chpasswd
fi

cat > /home/arcade/.local/bin/arcadecloud-start-xfce <<'XFCESTART'
#!/bin/sh
set -eu

export XDG_SESSION_TYPE=x11
export XDG_CURRENT_DESKTOP=XFCE
export XDG_SESSION_DESKTOP=xfce
export XDG_RUNTIME_DIR=/run/user/10001
export PIPEWIRE_RUNTIME_DIR=/run/user/10001

mkdir -p "$XDG_RUNTIME_DIR" "$HOME/.cache/sessions"
chmod 700 "$XDG_RUNTIME_DIR"

# No restaurar una sesión XFCE donde el panel haya quedado cerrado.
# La configuración visual vive en ~/.config/xfce4 y no se elimina.
find "$HOME/.cache/sessions" -maxdepth 1 -type f -name 'xfce4-session-*' -delete 2>/dev/null || true

pipewire >/tmp/arcade-pipewire.log 2>&1 &
sleep 1

pipewire-pulse >/tmp/arcade-pipewire-pulse.log 2>&1 &
sleep 1

wireplumber >/tmp/arcade-wireplumber.log 2>&1 &
sleep 2

/usr/libexec/pipewire-module-xrdp/load_pw_modules.sh -l 3 \
  >/tmp/arcade-xrdp-audio.log 2>&1 &

startxfce4 >/tmp/arcade-xfce.log 2>&1 &
XFCE_PID=$!

# startxfce4 puede restaurar una sesión persistente sin xfce4-panel.
# Este intento usa el mismo DBUS_SESSION_BUS_ADDRESS creado por dbus-launch.
sleep 3
xfce4-panel >/tmp/arcade-xfce4-panel.log 2>&1 &

wait "$XFCE_PID"
XFCESTART
chmod 700 /home/arcade/.local/bin/arcadecloud-start-xfce
chown arcade:arcade /home/arcade/.local/bin/arcadecloud-start-xfce

cat > /home/arcade/.xsession <<'XSESSION'
#!/bin/sh
export HOME=/home/arcade
export XDG_RUNTIME_DIR=/run/user/10001
unset SESSION_MANAGER
exec dbus-launch --exit-with-session /home/arcade/.local/bin/arcadecloud-start-xfce
XSESSION

chmod 700 /home/arcade/.xsession
chown arcade:arcade /home/arcade/.xsession

cat > /home/arcade/.config/xfce4/helpers.rc <<'XFCE'
WebBrowser=arcadecloud-chrome
XFCE
chown -R arcade:arcade /home/arcade/.config

cat > /home/arcade/.vnc/xstartup <<'VNC'
#!/bin/sh
export HOME=/home/arcade
export XDG_RUNTIME_DIR=/run/user/10001
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
exec dbus-launch --exit-with-session /home/arcade/.local/bin/arcadecloud-start-xfce
VNC
chmod 700 /home/arcade/.vnc/xstartup
chown -R arcade:arcade /home/arcade/.vnc

dbus-daemon --system --fork 2>/dev/null || true

cleanup() {
  [[ -n "${NOVNC_PID:-}" ]] && kill "$NOVNC_PID" >/dev/null 2>&1 || true
  [[ -n "${XRDP_PID:-}" ]] && kill "$XRDP_PID" >/dev/null 2>&1 || true
  [[ -n "${SES_PID:-}" ]] && kill "$SES_PID" >/dev/null 2>&1 || true
  su -s /bin/bash arcade -c "vncserver -kill :${DISPLAY_NUMBER}" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

echo "===== ARRANCANDO XRDP ====="
/usr/sbin/xrdp-sesman --nodaemon >/tmp/xrdp-sesman.log 2>&1 &
SES_PID=$!
/usr/sbin/xrdp --nodaemon >/tmp/xrdp.log 2>&1 &
XRDP_PID=$!

sleep 2

echo "===== ARRANCANDO VNC ====="
su -s /bin/bash arcade -c "
export HOME=/home/arcade
vncserver :${DISPLAY_NUMBER} \
  -localhost yes \
  -geometry '${VNC_GEOMETRY}' \
  -depth '${VNC_DEPTH}' \
  -SecurityTypes None
"

VNC_PORT="$((5900 + DISPLAY_NUMBER))"
websockify \
  --web=/usr/share/novnc/ \
  0.0.0.0:6080 \
  "127.0.0.1:${VNC_PORT}" &
NOVNC_PID=$!

sleep 3
kill -0 "$NOVNC_PID" 2>/dev/null || {
  echo "noVNC/websockify no pudo iniciar." >&2
  exit 1
}
kill -0 "$XRDP_PID" 2>/dev/null || {
  echo "XRDP no pudo iniciar." >&2
  exit 1
}

echo "ArcadeCloud Workstation lista."
echo "noVNC :6080"
echo "XRDP  :3389"

wait -n "$NOVNC_PID" "$XRDP_PID" "$SES_PID"
