#!/usr/bin/env bash
set -euo pipefail

PURGE=0
[[ "${1:-}" == "--purge" ]] && PURGE=1
[[ "${EUID}" -eq 0 ]] || { echo "Ejecuta como root." >&2; exit 1; }

systemctl disable --now arcadecloud-workstation.service >/dev/null 2>&1 || true
rm -f /etc/systemd/system/arcadecloud-workstation.service
systemctl daemon-reload

if command -v docker >/dev/null 2>&1; then
  docker rm -f arcadecloud-workstation >/dev/null 2>&1 || true
  docker image rm arcadecloud/workstation:phase1 >/dev/null 2>&1 || true
fi

if [[ "$PURGE" -eq 1 ]]; then
  rm -rf /var/lib/arcadecloud-office
  rm -f /etc/arcadecloud-drive/workstation.env
fi

echo "ArcadeCloud Workstation retirada. Docker y el media worker no fueron desinstalados."
[[ "$PURGE" -eq 0 ]] && echo "Workspace y credenciales locales conservados; usa --purge sólo si deseas borrarlos."
