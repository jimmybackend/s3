#!/usr/bin/env bash
# Auditoría de requisitos para Chrome sandbox sin relajar las protecciones EC2.
# Este script es SOLO lectura: no cambia el perfil seccomp ni reinicia Chrome.
set -euo pipefail

CONTAINER=arcadecloud-workstation

if ! docker ps --format '{{.Names}}' | grep -Fxq "$CONTAINER"; then
  echo "AVISO: Workstation está apagada. Abre Office antes de comprobar Chromium."
  exit 2
fi

echo "=== AISLAMIENTO ACTUAL DE WORKSTATION ==="
docker inspect -f 'SecurityOpt: {{json .HostConfig.SecurityOpt}}' "$CONTAINER"
docker inspect -f 'CapDrop: {{json .HostConfig.CapDrop}}' "$CONTAINER"
docker inspect -f 'CapAdd: {{json .HostConfig.CapAdd}}' "$CONTAINER"

echo "=== PRUEBA NO DESTRUCTIVA DE USER NAMESPACES (arcade) ==="
docker exec --user arcade "$CONTAINER" bash -lc '
  if ! command -v unshare >/dev/null 2>&1; then
    echo "SIN DIAGNOSTICO: falta utilidad unshare (util-linux)"
    exit 0
  fi
  if unshare --user --map-root-user true >/dev/null 2>&1; then
    echo "OK: user namespace básico permitido; falta verificar Chrome sandbox en prueba controlada."
  else
    echo "BLOQUEADO: kernel/seccomp/LSM impide crear user namespaces dentro de Docker."
    echo "Con esta política no debemos eliminar --no-sandbox del lanzador."
  fi
'

echo "=== RESULTADO ==="
echo "No se modificó Docker, Chrome ni la instancia EC2."
