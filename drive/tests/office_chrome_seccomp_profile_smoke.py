#!/usr/bin/env python3
"""Unit and policy tests for Chrome sandbox opt-in (no EC2/Docker needed)."""
import importlib.util
import json
import subprocess
import tempfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
source = root / "bin/office_chrome_seccomp_profile.py"
spec = importlib.util.spec_from_file_location("arcadecloud_chrome_seccomp", source)
assert spec and spec.loader
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)

with tempfile.TemporaryDirectory(prefix="arcadecloud-chrome-policy-test-") as temp:
    base = Path(temp) / "moby.json"
    target = Path(temp) / "trial.json"
    entries = [
        {"names": ["clone3"], "action": "SCMP_ACT_ERRNO", "errnoRet": 38},
        {"names": ["chroot"], "action": "SCMP_ACT_ALLOW",
         "includes": {"caps": ["CAP_SYS_CHROOT"]}},
        *({"names": [f"syscall_{i}"], "action": "SCMP_ACT_ALLOW"} for i in range(24)),
    ]
    original = {"defaultAction": "SCMP_ACT_ERRNO", "archMap": [],
                "syscalls": entries}
    base.write_text(json.dumps(original))
    builder.generate(base, target)
    generated = json.loads(target.read_text())
    assert generated["defaultAction"] == "SCMP_ACT_ERRNO"
    assert len(generated["syscalls"]) == len(entries) + 1
    assert generated["syscalls"][1:] == entries
    additional = generated["syscalls"][0]
    assert set(additional["names"]) == {"clone", "setns", "unshare"}
    assert additional["action"] == "SCMP_ACT_ALLOW"
    assert additional["args"] == []
    assert "mount" not in additional["names"]
    assert "ptrace" not in additional["names"]
    builder.generate(target, base)
    assert json.loads(base.read_text()) == generated, "Generation must be idempotent"

    invalid = {"defaultAction": "SCMP_ACT_ALLOW", "syscalls": entries}
    base.write_text(json.dumps(invalid))
    try:
        builder.generate(base, target)
    except ValueError:
        pass
    else:
        raise AssertionError("Allow-by-default must be rejected")

trial = (root / "bin/office_chrome_sandbox_trial.sh").read_text()
installer = (root / "bin/install_workstation_node.sh").read_text()
rollback = (root / "bin/office_chrome_sandbox_disable.sh").read_text()
launcher = (root / "docker/workstation/arcadecloud-chrome").read_text()
for file in ("office_chrome_sandbox_trial.sh", "install_workstation_node.sh",
             "office_chrome_sandbox_disable.sh"):
    completed = subprocess.run(["bash", "-n", str(root / "bin" / file)],
                               capture_output=True, text=True)
    assert completed.returncode == 0, (file, completed.stderr)
assert "office_chrome_seccomp_profile.py" in trial
assert '--cap-add=SYS_CHROOT' in trial
assert 'os.chroot("/proc/self/fdinfo/")' in trial
assert 'CHROME_RUNTIME_OPTS="--cap-add=SYS_CHROOT --security-opt seccomp=' in installer
assert 'CAP_SYS_CHROOT' in (root / "bin/office_chrome_seccomp_profile.py").read_text()
# El marcador de sandbox aprobado debe condicionar esta capacidad.
assert installer.index('CHROME_RUNTIME_OPTS="--cap-add=SYS_CHROOT') > installer.index('ACTUAL_CHROME_PROFILE_HASH')

assert "moby/profiles/4b8214fc4971e06172938a1f1554ccff6647ee80/seccomp/default.json" in trial
assert "docker run --rm --network none" in trial
assert "--security-opt=no-new-privileges:true" in trial
assert "--cap-drop=ALL" in trial
for capability in ("SETUID", "SETGID", "CHOWN", "DAC_OVERRIDE", "FOWNER"):
    assert f"--cap-add={capability}" in trial
assert "--cap-add=SYS_ADMIN" not in trial
assert "--privileged" not in trial
assert "--user 10001:10001" in trial
assert "--tmpfs /home/arcade:" in trial
assert " --volume " not in trial
assert " --mount " not in trial
assert "/usr/bin/google-chrome-stable" in trial
assert "--dump-dom about:blank" in trial
assert "exit \"$code\"" in trial
assert "if [[ \"$ENABLE_ON_PASS\" -eq 1 ]]" in trial
assert trial.index("ENSAYO APROBADO") < trial.index("if [[ \"$ENABLE_ON_PASS\" -eq 1 ]]")
assert "sha256sum" in trial and "chrome-sandbox-enabled" in trial
assert "install -d -m" not in trial
assert "systemctl restart" not in trial
assert "docker restart" not in trial
assert "--security-opt=no-new-privileges:true" in installer
assert "--cap-drop=ALL" in installer
assert 'ACTUAL_CHROME_PROFILE_HASH' in installer
assert 'ARCADECLOUD_CHROME_SANDBOX_ENABLED=1' in installer
assert "CHROME_SANDBOX_ARGS=(--no-sandbox)" in launcher
assert 'ARCADECLOUD_CHROME_SANDBOX_ENABLED:-0' in launcher
assert "rm -f /etc/arcadecloud-drive/chrome-sandbox-enabled" in rollback
print("OK: perfil Docker deny-by-default + tres syscalls Playwright.")
print("OK: ensayo real con capacidades de Office, sin red ni datos persistentes.")
print("OK: la activación exige Chrome funcional y conserva rollback y configuración EC2.")
