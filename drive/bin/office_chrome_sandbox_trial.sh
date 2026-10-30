#!/usr/bin/env bash
# Chrome native sandbox preflight in an isolated, disposable container.
# Does not read home profiles, mount projects, change Workstation or restart EC2.
set -euo pipefail
umask 077

ENABLE_ON_PASS=0
case "$#" in
  0) ;;
  1) if [[ "$1" == "--enable-on-pass" ]]; then ENABLE_ON_PASS=1;
     else echo "Uso: sudo bash drive/bin/office_chrome_sandbox_trial.sh [--enable-on-pass]" >&2; exit 2; fi ;;
  *) echo "Demasiados argumentos." >&2; exit 2 ;;
esac

if [[ "$EUID" -ne 0 ]]; then
  echo "Ejecuta con sudo desde /var/www/arcadecloud-drive" >&2
  exit 2
fi
for cmd in curl docker python3 timeout grep sha256sum install mktemp; do
  command -v "$cmd" >/dev/null || { echo "Falta $cmd" >&2; exit 2; }
done
image_name=arcadecloud/workstation:phase1
docker image inspect "$image_name" >/dev/null 2>&1 || {
  echo "Falta imagen Office; ejecuta install_workstation_node.sh --reconcile." >&2
  exit 2
}
SCRIPT_ROOT="$(cd -- "$(dirname -- "$0")" && pwd)"
temp_dir="$(mktemp -d /tmp/arcadecloud-chrome-canary.XXXXXXXX)"
trap 'rm -rf "$temp_dir"' EXIT

echo "=== 1. PERFIL SECCOMP BASADO EN LA POLÍTICA DE MOBY ==="
# Playwright recomienda clone, unshare y setns para Chromium.
# Se añade SOLO esa excepción; se conserva el deny-by-default.
curl -fLsS --connect-timeout 8 --max-time 25 --retry 1 \
  https://raw.githubusercontent.com/moby/profiles/4b8214fc4971e06172938a1f1554ccff6647ee80/seccomp/default.json \
  -o "$temp_dir/default.json" || {
  echo "No se pudo descargar seccomp oficial; Office no se modificó." >&2
  exit 4
}
python3 "$SCRIPT_ROOT/office_chrome_seccomp_profile.py" \
  "$temp_dir/default.json" "$temp_dir/office-trial.json"

echo "=== 2. PRUEBA DESCARTABLE, REPRODUCIENDO LAS CAPACIDADES DE OFFICE ==="
echo "Sin red, documentos, home real, SYS_ADMIN ni acceso a S3."
if docker run --rm --network none --pids-limit 256 \
  --security-opt=no-new-privileges:true \
  --security-opt="seccomp=$temp_dir/office-trial.json" \
  --cap-drop=ALL \
  --cap-add=SETUID --cap-add=SETGID --cap-add=CHOWN \
  --cap-add=DAC_OVERRIDE --cap-add=FOWNER \
  --user 10001:10001 --memory=5632m --cpus=3.25 --shm-size=1g \
  --tmpfs /tmp:rw,nosuid,nodev,mode=1777 \
  --tmpfs /home/arcade:rw,nosuid,nodev,uid=10001,gid=10001,mode=0700 \
  --entrypoint /bin/bash "$image_name" -lc '
    set -eu
    export HOME=/home/arcade
    export XDG_RUNTIME_DIR=/tmp/arcade-chrome
    mkdir -p "$XDG_RUNTIME_DIR"
    chmod 700 "$XDG_RUNTIME_DIR"
    echo "--- user namespace ---"
    unshare --user --map-root-user /usr/bin/true || {
      echo "ERROR: user namespace no disponible."
      exit 21
    }
    echo "--- user + PID namespace ---"
    if unshare --user --map-root-user --pid --fork /usr/bin/true 2>/tmp/userpid.err; then
      echo "OK: user + PID namespaces"
    else
      echo "AVISO: user + PID namespaces no disponibles."
      cat /tmp/userpid.err || true
    fi
    echo "--- Google Chrome con sandbox nativo ---"
    ulimit -c 0 || true
    status=0
    timeout 35s /usr/bin/google-chrome-stable \
      --headless=new --disable-gpu \
      --no-first-run --no-default-browser-check \
      --user-data-dir=/tmp/chrome-sandbox-validation \
      --enable-logging=stderr \
      --dump-dom about:blank \
      >/tmp/chrome-output.html 2>/tmp/chrome-errors.log || status=$?
    if [ "$status" -ne 0 ] || ! grep -qi "<html" /tmp/chrome-output.html; then
      echo "NO APTO: Chrome con sandbox no arrancó (exit=$status)."
      echo "--- Últimos errores de Chrome ---"
      tail -n 45 /tmp/chrome-errors.log || true
      exit 26
    fi
    echo "OK: Chrome inició SIN --no-sandbox."
  '; then
  echo "=== 3. CHROME CON SANDBOX: ENSAYO APROBADO ==="
else
  code=$?
  echo "=== ENSAYO FALLIDO (código=$code) ==="
  echo "Chrome, KDE, audio, Guacamole y datos permanecen sin cambios."
  exit "$code"
fi

if [[ "$ENABLE_ON_PASS" -eq 1 ]]; then
  echo "=== 4. GUARDAR SÓLO PERFIL COMPROBADO, SIN REINICIAR ==="
  directory=/etc/arcadecloud-drive
  profile="$directory/chrome-seccomp.json"
  marker="$directory/chrome-sandbox-enabled"
  if [[ ! -d "$directory" ]]; then
    echo "Falta directorio Office; no se cambian sus permisos." >&2
    exit 3
  fi
  temp_profile="$(mktemp "$directory/.chrome-seccomp.XXXXXXXX")"
  temp_marker="$(mktemp "$directory/.chrome-sandbox-marker.XXXXXXXX")"
  cleanup_stage() { rm -f "$temp_profile" "$temp_marker"; }
  trap 'cleanup_stage; rm -rf "$temp_dir"' EXIT
  install -o root -g root -m 0644 "$temp_dir/office-trial.json" "$temp_profile"
  expected_hash="$(sha256sum "$temp_profile" | awk '{print $1}')"
  printf '%s\n' "$expected_hash" > "$temp_marker"
  chown root:root "$temp_marker"
  chmod 0600 "$temp_marker"
  mv -f "$temp_profile" "$profile"
  mv -f "$temp_marker" "$marker"
  echo "OK: política Chrome revisada, persistente y fijada por SHA-256."
  echo "Siguiente paso: reconciliar Office y comprobar Chrome GUI tras reinicio de Workstation."
else
  echo "Perfil NO instalado: ejecutar --enable-on-pass sólo cuando se desee activarlo."
fi
