#!/usr/bin/env python3
"""Build a Chromium sandbox trial profile from the current Moby seccomp default.

Preserves every Docker default denial/allow rule, adding ONLY the three
user-namespace-related syscall allowances recommended by Playwright.
No mount, bpf, ptrace, CAP_SYS_ADMIN, privileged or seccomp=unconfined.

The generated profile MUST be validated in a throwaway container running
Chrome without --no-sandbox before it can ever be installed in Workstation.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

ALLOW = ("clone", "setns", "unshare")


def generate(source: Path, output: Path) -> None:
    with source.open(encoding="utf-8") as stream:
        profile = json.load(stream)
    if profile.get("defaultAction") != "SCMP_ACT_ERRNO":
        raise ValueError("La política Moby ya no aplica denegación por defecto")
    rules = profile.get("syscalls")
    if not isinstance(rules, list) or len(rules) < 20:
        raise ValueError("La política Moby tiene un conjunto inesperado de reglas")
    if not any(
        "clone3" in r.get("names", ()) and r.get("action") == "SCMP_ACT_ERRNO"
        for r in rules
    ):
        raise ValueError("No se detectó fallback ENOSYS/EPERM esperado para clone3")
    if not any(
        "chroot" in r.get("names", ()) and
        r.get("action") == "SCMP_ACT_ALLOW" and
        "CAP_SYS_CHROOT" in r.get("includes", {}).get("caps", ())
        for r in rules
    ):
        raise ValueError(
            "Moby seccomp no contiene la regla chroot controlada por CAP_SYS_CHROOT"
        )
    # chroot sólo se permitirá en el contenedor que haya superado el
    # ensayo Chrome y cuyo perfil esté fijado por SHA-256. No se concede
    # SYS_CHROOT al resto de la Workstation por defecto.
    # Restore a single allow for the 3 relevant syscalls even if the
    # original profile has cap-dependent or arg-filtered rules. Do not
    # remove any existing rules or broaden any other syscall.
    extra = {
        "comment": "Chromium user namespace sandbox (Playwright recommended syscalls)",
        "names": list(ALLOW),
        "action": "SCMP_ACT_ALLOW",
        "args": [],
        "includes": {},
        "excludes": {},
    }
    if not any(
        r.get("action") == "SCMP_ACT_ALLOW"
        and not r.get("args")
        and set(ALLOW).issubset(set(r.get("names", ())))
        and not r.get("includes") and not r.get("excludes")
        for r in rules
    ):
        rules.insert(0, extra)
    output.write_text(json.dumps(profile, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Uso: office_chrome_seccomp_profile.py <moby.json> <trial.json>")
    try:
        generate(Path(sys.argv[1]), Path(sys.argv[2]))
    except (ValueError, KeyError, OSError) as error:
        raise SystemExit(f"ERROR: perfil seccomp incompatible: {error}")
    print("OK: seccomp Moby deny-by-default + clone, unshare y setns (sin SYS_ADMIN).")
