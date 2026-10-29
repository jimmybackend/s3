#!/usr/bin/env bash
# Preflight no destructivo: sandbox real de Chrome, en contenedor descartable.
# NO modifica el systemd de Office, NO reinicia Workstation, NO instala
# seccomp=unconfined y NO elimina la política no-new-privileges.
set -euo pipefail

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

# Docker utiliza máscara de espacios de nombres 0x7e020000. Añadir sólo
# syscalls con el bit CLONE_NEWUSER=0x10000000 en las banderas; conservar
# las restantes limitaciones y todas las reglas del perfil oficial.
NAMESPACE_MASK = 0x7e020000
NEW_USER = 0x10000000
for name in ("clone", "unshare"):
    rules.append({
        "names": [name],
        "action": "SCMP_ACT_ALLOW",
        "args": [{
            "index": 0,
            "value": NAMESPACE_MASK,
            "valueTwo": NEW_USER,
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
  --cap-drop=ALL --user 10001:10001 --read-only \
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
    echo "=== CHROME SIN --no-sandbox ==="
    if ! timeout 25s google-chrome-stable \
         --headless=new --disable-gpu --disable-dev-shm-usage \
         --no-first-run --no-default-browser-check \
         --user-data-dir=/tmp/arcade-chrome-test \
         --dump-dom about:blank 2>/tmp/chrome-test.log |
         grep -q "<html"; then
      echo "NO APTO: Chrome no completó el arranque con sandbox"
      tail -n 18 /tmp/chrome-test.log || true
      exit 26
    fi
    echo "OK: Chrome arrancó sin --no-sandbox en el contenedor descartable"
  '

echo "=== RESULTADO ==="
echo "PRUEBA APROBADA: se puede preparar una política revisada sin abrir permisos generales."
echo "Workstation, Guacamole y archivos de usuario NO se modificaron."
