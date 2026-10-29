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

# KDE fija iconos en el panel mediante .desktop; que todos utilicen el mismo
# wrapper evita que el icono del Dock invoque un Chrome sin permisos Docker.
python3 /usr/local/bin/repair-chrome-launchers.py
# La Workstation aún no ha iniciado Chrome: limpiar bloqueos de otros
# contenedores antes de restaurar la sesión gráfica conservando el perfil.
python3 /usr/local/bin/arcadecloud-chrome-unlock.py

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

# KDE/noVNC y Guacamole/XRDP comparten el mismo UID y runtime.
# Evitar servidores PipeWire duplicados al abrir una segunda sesión, ya que
# eso puede reemplazar el socket Pulse y dejar a noVNC capturando silencio.
if ! pgrep -u "$(id -u)" -x pipewire >/dev/null 2>&1; then
    pipewire >/tmp/arcade-pipewire.log 2>&1 &
fi
sleep 1

if ! pgrep -u "$(id -u)" -x pipewire-pulse >/dev/null 2>&1; then
    pipewire-pulse >/tmp/arcade-pipewire-pulse.log 2>&1 &
fi
sleep 1

if ! pgrep -u "$(id -u)" -x wireplumber >/dev/null 2>&1; then
    wireplumber >/tmp/arcade-wireplumber.log 2>&1 &
fi
sleep 2

# KDE/noVNC necesita una salida de sonido propia aunque Guacamole no haya
# iniciado XRDP. El script es idempotente entre sesiones y conserva XRDP.
if /usr/local/bin/arcadecloud-audio-ensure-sink >/tmp/arcade-virtual-audio.log 2>&1; then
    # PULSE_SINK sólo se hereda por KDE/noVNC: no desviar el audio XRDP.
    if [ "${ARCADECLOUD_SESSION_TRANSPORT:-}" = "novnc" ]; then
        export PULSE_SINK=arcadecloud-web
    fi
else
    echo "[Office audio] No se pudo configurar el sink virtual; XRDP sigue disponible." >&2
fi

# Sólo las sesiones RDP (:10+) necesitan pipewire-module-xrdp.
# Antes se cargaba también desde VNC (:1) y la salida KDE dependía de
# conectarse a Guacamole. XRDP mantiene su audio al iniciar su sesión.
RDP_DISPLAY="${DISPLAY#:}"
RDP_DISPLAY="${RDP_DISPLAY%%.*}"
case "$RDP_DISPLAY" in
    ''|*[!0-9]*) ;;
    *)
        if [ "$RDP_DISPLAY" -ge 10 ]; then
            /usr/libexec/pipewire-module-xrdp/load_pw_modules.sh -l 3 \
              >/tmp/arcade-xrdp-audio.log 2>&1 &
        fi
        ;;
esac

# Selector de escritorio ArcadeCloud.
# XFCE continúa siendo el valor predeterminado.
if [ "${ARCADECLOUD_DESKTOP:-xfce}" = "plasma" ]; then
    export XDG_SESSION_TYPE=x11
    export XDG_CURRENT_DESKTOP=KDE
    export XDG_SESSION_DESKTOP=KDE
    export DESKTOP_SESSION=plasma
    export KDE_FULL_SESSION=true
    export QT_QPA_PLATFORM=xcb
    export LIBGL_ALWAYS_SOFTWARE=1
    export KWIN_COMPOSE=N

    if ! command -v startplasma-x11 >/dev/null 2>&1; then
        echo "ERROR: KDE Plasma X11 no está instalado." >&2
        exit 1
    fi

    # El único propietario legacy se autentica en office.esforzados.com.
    # KDE/noVNC no debe crear un segundo bloqueo PAM que, con
    # no-new-privileges, no logra leer las credenciales de /etc/shadow.
    # No se aplica a XRDP, otros transportes ni futuros contenedores por usuario.
    python3 /usr/local/bin/arcadecloud-kde-remote-sso.py \
      >/tmp/arcade-kde-remote-sso.log 2>&1

    echo "Iniciando KDE Plasma X11"
    exec startplasma-x11 >/tmp/arcade-plasma.log 2>&1
fi

startxfce4 >/tmp/arcade-xfce.log 2>&1 &
XFCE_PID=$!

# startxfce4 puede restaurar una sesión persistente sin xfce4-panel.
# Este intento usa el mismo DBUS_SESSION_BUS_ADDRESS creado por dbus-launch.
sleep 3
xfce4-panel >/tmp/arcade-xfce4-panel.log 2>&1 &

# En Guacamole (XRDP) XFCE usa el fondo elegido para KDE. No modificar
# el Dock, los lanzadores ni el escritorio KDE de noVNC.
sleep 2
/usr/local/bin/arcadecloud-xfce-wallpaper >/tmp/arcade-xfce-wallpaper.log 2>&1 || true

wait "$XFCE_PID"
XFCESTART
chmod 700 /home/arcade/.local/bin/arcadecloud-start-xfce
chown arcade:arcade /home/arcade/.local/bin/arcadecloud-start-xfce

cat > /home/arcade/.xsession <<'XSESSION'
#!/bin/sh
export ARCADECLOUD_SESSION_TRANSPORT=xrdp
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
export ARCADECLOUD_SESSION_TRANSPORT=novnc
export HOME=/home/arcade
export XDG_RUNTIME_DIR=/run/user/10001
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
exec dbus-launch --exit-with-session /home/arcade/.local/bin/arcadecloud-start-xfce
VNC
chmod 700 /home/arcade/.vnc/xstartup
chown -R arcade:arcade /home/arcade/.vnc

# Agrega el control de audio al navegador sin tocar las preferencias KDE.
python3 - <<'PY'
from pathlib import Path
import re
page = Path("/usr/share/novnc/vnc.html")
if page.exists():
    content = page.read_text(encoding="utf-8")
    marker = '<script src="/arcadecloud-audio.js?v=office-audio-bootstrap-20261029"></script>'
    prior = re.compile(
        r'<script\\s+[^>]*src=["\\']/arcadecloud-audio\\.js(?:\\?[^"\\']*)?["\\'][^>]*>\\s*</script>',
        re.IGNORECASE,
    )
    # Reemplazar una única referencia anterior y evitar duplicar controles.
    updated, count = prior.subn(marker, content, count=1)
    if not count:
        updated, count = re.subn(
            r'</body\\s*>', marker + "\\n</body>", content, count=1,
            flags=re.IGNORECASE,
        )
    if count and updated != content:
        page.write_text(updated, encoding="utf-8")
    elif not count:
        print("AVISO: noVNC no incluye cierre de body; Office gateway cargará el audio.", flush=True)
PY

dbus-daemon --system --fork 2>/dev/null || true

# Guacamole y noVNC son independientes. Arrancar PipeWire + monitor virtual
# antes de abrir VNC; previamente sólo se iniciaba dentro del xstartup,
# que puede no ejecutarse si TigerVNC encuentra un script persistente viejo.
echo "===== PREPARANDO AUDIO WEB SIN GUACAMOLE ====="
if ! su -s /bin/bash arcade -c '/usr/local/bin/arcadecloud-audio-bootstrap' \
    >/tmp/arcade-audio-bootstrap.log 2>&1; then
  echo "AVISO: audio noVNC sin preparar, ver /tmp/arcade-audio-bootstrap.log" >&2
fi

cleanup() {
  [[ -n "${NOVNC_PID:-}" ]] && kill "$NOVNC_PID" >/dev/null 2>&1 || true
  [[ -n "${AUDIO_PID:-}" ]] && kill "$AUDIO_PID" >/dev/null 2>&1 || true
  [[ -n "${XRDP_PID:-}" ]] && kill "$XRDP_PID" >/dev/null 2>&1 || true
  [[ -n "${SES_PID:-}" ]] && kill "$SES_PID" >/dev/null 2>&1 || true
  su -s /bin/bash arcade -c "vncserver -kill :${DISPLAY_NUMBER}" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

# Ubuntu 24.04 dentro de Docker no ejecuta systemd-tmpfiles al arrancar.
# XRDP 0.9.x necesita su socketdir en /run (tmpfs del contenedor), o los
# intentos de reconectar desde Guacamole fallan con g_create_path(...).
# 1777 + sticky bit es el modo requerido por el protocolo de XRDP 0.9.x;
# nunca tocar /run/user/10001 ni las configuraciones persistentes de KDE.
install -d -o root -g root -m 0755 /run/xrdp
install -d -o root -g root -m 1777 /run/xrdp/sockdir

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
  -xstartup /home/arcade/.vnc/xstartup \
  -SecurityTypes None
"

VNC_PORT="$((5900 + DISPLAY_NUMBER))"
websockify \
  --web=/usr/share/novnc/ \
  0.0.0.0:6080 \
  "127.0.0.1:${VNC_PORT}" &
NOVNC_PID=$!

# Esperar proceso KDE, no sólo websockify: un puerto abierto no demuestra
# que el escritorio esté listo. No detener Workstation si KDE tarda demasiado.
if [[ "${ARCADECLOUD_DESKTOP:-xfce}" == "plasma" ]]; then
  for attempt in $(seq 1 25); do
    if pgrep -u arcade -x plasmashell >/dev/null 2>&1; then
      echo "KDE Plasma listo sin abrir Guacamole (intento $attempt)."
      break
    fi
    if [[ "$attempt" -eq 25 ]]; then
      echo "AVISO: KDE aún no está listo; revisar /tmp/arcade-plasma.log y ~/.vnc/*.log" >&2
    fi
    sleep 1
  done
fi

echo "===== ARRANCANDO AUDIO WEB PRIVADO ====="
su -s /bin/bash arcade -c 'exec python3 /usr/local/bin/arcadecloud-audio-server' \
  >/tmp/arcade-audio-web.log 2>&1 &
AUDIO_PID=$!

sleep 3
kill -0 "$NOVNC_PID" 2>/dev/null || {
  echo "noVNC/websockify no pudo iniciar." >&2
  exit 1
}
kill -0 "$XRDP_PID" 2>/dev/null || {
  echo "XRDP no pudo iniciar." >&2
  exit 1
}

kill -0 "$AUDIO_PID" 2>/dev/null || echo "AVISO: servidor de audio web no iniciado."
echo "ArcadeCloud Workstation lista."
echo "noVNC :6080"
echo "XRDP  :3389"

wait -n "$NOVNC_PID" "$XRDP_PID" "$SES_PID"
