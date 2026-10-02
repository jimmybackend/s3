#!/usr/bin/env bash
set -euo pipefail
umask 0007

DISPLAY_NUMBER="${DISPLAY_NUMBER:-1}"
VNC_GEOMETRY="${VNC_GEOMETRY:-1600x900}"
VNC_DEPTH="${VNC_DEPTH:-24}"

export HOME=/home/arcade

PHP_SHARED_GID="${ARCADECLOUD_PHP_GID:-}"
if [[ "$PHP_SHARED_GID" =~ ^[0-9]+$ ]]; then
  PHP_SHARED_GROUP="$(getent group "$PHP_SHARED_GID" | cut -d: -f1 || true)"
  if [[ -z "$PHP_SHARED_GROUP" ]]; then
    PHP_SHARED_GROUP="arcadecloud-php"
    groupadd --gid "$PHP_SHARED_GID" "$PHP_SHARED_GROUP"
  fi
  usermod -aG "$PHP_SHARED_GROUP" arcade
fi

mkdir -p \
  /home/arcade/.vnc \
  /home/arcade/.config/xfce4 \
  /home/arcade/.config/pipewire \
  /home/arcade/.local/state \
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

cat > /home/arcade/.xsession <<'XSESSION'
#!/bin/sh

export XDG_SESSION_TYPE=x11
export XDG_CURRENT_DESKTOP=XFCE
export XDG_SESSION_DESKTOP=xfce
export XDG_RUNTIME_DIR=/run/user/10001
export PIPEWIRE_RUNTIME_DIR=/run/user/10001

mkdir -p "$XDG_RUNTIME_DIR"
chmod 700 "$XDG_RUNTIME_DIR"

exec dbus-launch --exit-with-session /bin/sh -c '
    export XDG_SESSION_TYPE=x11
    export XDG_CURRENT_DESKTOP=XFCE
    export XDG_SESSION_DESKTOP=xfce
    export XDG_RUNTIME_DIR=/run/user/10001
    export PIPEWIRE_RUNTIME_DIR=/run/user/10001

    pipewire >/tmp/arcade-pipewire.log 2>&1 &
    sleep 1

    pipewire-pulse >/tmp/arcade-pipewire-pulse.log 2>&1 &
    sleep 1

    wireplumber >/tmp/arcade-wireplumber.log 2>&1 &
    sleep 2

    /usr/libexec/pipewire-module-xrdp/load_pw_modules.sh -l 3 \
      >/tmp/arcade-xrdp-audio.log 2>&1 &

    exec startxfce4
'
XSESSION

chmod 700 /home/arcade/.xsession
chown arcade:arcade /home/arcade/.xsession

cat > /home/arcade/.config/xfce4/helpers.rc <<'XFCE'
WebBrowser=arcadecloud-chrome
XFCE
chown -R arcade:arcade /home/arcade/.config

cat > /home/arcade/.vnc/xstartup <<'VNC'
#!/bin/sh
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
exec dbus-launch --exit-with-session startxfce4
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
