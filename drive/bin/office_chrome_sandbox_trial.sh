#!/usr/bin/env bash
# Preflight no destructivo: sandbox real de Chrome, en contenedor descartable.
# NO modifica el systemd de Office, NO reinicia Workstation, NO instala
# seccomp=unconfined y NO elimina la política no-new-privileges.
set -euo pipefail

ENABLE_ON_PASS=0
case "${1:-}" in
  "") ;;
  --enable-on-pass) ENABLE_ON_PASS=1 ;;
  *) echo "Uso: sudo bash drive/bin/office_chrome_sandbox_trial.sh [--enable-on-pass]" >&2; exit 2 ;;
esac

if [[ "$EUID" -ne 0 ]]; then
  echo "Ejecuta: sudo bash drive/bin/office_chrome_sandbox_trial.sh"
  exit 2
fi
command -v docker >/dev/null || { echo "Falta Docker"; exit 2; }
command -v curl >/dev/null || { echo "Falta curl"; exit 2; }

image_name=arcadecloud/workstation:phase1
docker image inspect "$image_name" >/dev/null 2>&1 || {
  echo "No existe la imagen Workstation. Primero reconcilia Office."
  exit 2
}

temp_dir="$(mktemp -d /tmp/arcadecloud-chrome-sandbox.XXXXXX)"
trap 'rm -rf "$temp_dir"' EXIT

echo "=== DESCARGANDO PERFIL BASE OFICIAL MOBY ==="
# Sólo para la prueba descartable; no usar la versión remota directamente
# como política de producción sin fijarla y revisarla.
curl -fLsS --connect-timeout 8 --max-time 25 --retry 1 \
  https://raw.githubusercontent.com/moby/profiles/main/seccomp/default.json \
  -o "$temp_dir/default.json"

python3 - "$temp_dir/default.json" "$temp_dir/office-trial.json" <<'PY'
import json
import sys

src, dst = sys.argv[1:]
with open(src, encoding="utf-8") as stream:
    profile = json.load(stream)

if profile.get("defaultAction") != "SCMP_ACT_ERRNO":
    raise SystemExit("ERROR: el perfil de origen no aplica denegación por defecto")
rules = profile.get("syscalls", [])
if not isinstance(rules, list) or len(rules) < 20:
    raise SystemExit("ERROR: perfil seccomp de origen incompleto")
if not any("clone3" in r.get("names", []) and
           r.get("action") == "SCMP_ACT_ERRNO" for r in rules):
    raise SystemExit("ERROR: se esperaba un perfil con clone3 restringido")

# Prueba aislada: Chromium necesita namespaces de usuario y procesos.
# Conservamos el deny-by-default de Moby, sin SYS_ADMIN y sin unconfined.
# Para clone/unshare permitir solamente llamadas que incluyan NEWUSER o
# NEWPID (no habilitar mount, setns, bpf ni ptrace). Esta política es
# temporal y NO se instalará en Workstation sin prueba y revisión.
NAMESPACE_MASK = 0x7e020000
NEW_USER = 0x10000000
NEW_PID = 0x20000000
for name in ("clone", "unshare"):
    for flag in (NEW_USER, NEW_PID, NEW_USER | NEW_PID):
        rules.append({
            "names": [name],
            "action": "SCMP_ACT_ALLOW",
            "args": [{
                "index": 0,
                "value": NAMESPACE_MASK,
                "valueTwo": flag,
                "op": "SCMP_CMP_MASKED_EQ",
            }],
            "excludes": {"arches": ["s390", "s390x"]},
        })

with open(dst, "w", encoding="utf-8") as stream:
    json.dump(profile, stream, indent=2)
    stream.write("\n")
print("OK: ensayo seccomp derivado de Moby con excepciones restringidas a CLONE_NEWUSER")
PY

echo "=== CONTENEDOR DESCARTABLE, SIN RED NI VOLUMENES REALES ==="
# Nunca montar /home/arcade real ni /workspace. La prueba sólo lee la imagen.
# Mantener no-new-privileges, sin CAP_SYS_ADMIN, sin host networking.
docker run --rm --network none \
  --security-opt=no-new-privileges:true \
  --security-opt="seccomp=$temp_dir/office-trial.json" \
  --cap-drop=ALL --user 10001:10001 --shm-size=512m \
  --tmpfs /tmp:rw,nosuid,nodev,mode=1777 \
  --tmpfs /home/arcade:rw,nosuid,nodev,uid=10001,gid=10001,mode=0700 \
  --entrypoint /bin/bash "$image_name" -lc '
    set -e
    export HOME=/home/arcade
    export XDG_RUNTIME_DIR=/tmp/arcade-chrome-runtime
    mkdir -p "$XDG_RUNTIME_DIR"
    chmod 700 "$XDG_RUNTIME_DIR"
    echo "=== USER NAMESPACES ==="
    if ! unshare --user --map-root-user /usr/bin/true; then
      echo "NO APTO: el host o su política sigue bloqueando user namespaces"
      exit 25
    fi
    echo "OK: user namespaces permitidos para arcade"
    echo "=== REQUISITO: USER + PID NAMESPACES ==="
    if unshare --user --map-root-user --pid --fork /usr/bin/true 2>/dev/null; then
      echo "OK: user+pid namespaces disponibles."
    else
      echo "AVISO: user+pid namespaces no funcionan juntos con la política actual."
    fi
    echo "=== CHROME SIN --no-sandbox ==="
    # El rootfs de prueba ahora es temporal y escribible, como Office real.
    # Evitar grep -q sobre stdout vivo porque puede cerrar la tubería antes
    # de que Chrome finalice y generar diagnósticos SIGPIPE falsos.
    ulimit -c 0 || true
    status=0
    timeout 25s google-chrome-stable \
         --headless=new --disable-gpu --disable-dev-shm-usage \
         --no-first-run --no-default-browser-check \
         --user-data-dir=/tmp/arcade-chrome-test \
         --dump-dom about:blank \
         >/tmp/chrome-dom.html 2>/tmp/chrome-test.log || status=$?
    if [ "$status" -ne 0 ] || ! grep -q "<html" /tmp/chrome-dom.html; then
      echo "NO APTO: Chrome no completó el arranque con sandbox (exit=$status)"
      tail -n 28 /tmp/chrome-test.log || true
      exit 26
    fi
    echo "OK: Chrome arrancó sin --no-sandbox en el contenedor descartable"
  '

echo "=== RESULTADO ==="
echo "PRUEBA APROBADA: Chrome inició en el contenedor descartable con sandbox."

if [[ "$ENABLE_ON_PASS" -eq 1 ]]; then
  echo "=== INSTALANDO PERFIL REVISADO (NO REINICIA OFFICE) ==="
  profile=/etc/arcadecloud-drive/chrome-seccomp.json
  marker=/etc/arcadecloud-drive/chrome-sandbox-enabled
  if [[ ! -d /etc/arcadecloud-drive ]]; then
    echo "ERROR: falta el directorio existente de configuración de ArcadeCloud." >&2
    exit 3
  fi
  install -o root -g root -m 0644 "$temp_dir/office-trial.json" "$profile"
  hash="$(sha256sum "$profile" | awk '{print $1}')"
  temporary_marker="$(mktemp /etc/arcadecloud-drive/.chrome-sandbox-enabled.XXXXXXXX)"
  trap 'rm -f "$temporary_marker"; rm -rf "$temp_dir"' EXIT
  printf '%s\n' "$hash" > "$temporary_marker"
  chown root:root "$temporary_marker"
  chmod 0600 "$temporary_marker"
  mv -f "$temporary_marker" "$marker"
  echo "OK: perfil seccomp comprobado y guardado, con SHA-256 de integridad."
  echo "PENDIENTE: sudo bash drive/bin/install_workstation_node.sh --reconcile"
  echo "El sandbox se aplicará al siguiente reinicio de Workstation, no ahora."
else
  echo "Workstation, Guacamole y archivos de usuario NO se modificaron."
fi
