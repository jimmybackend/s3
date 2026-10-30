#!/usr/bin/env python3
"""Chrome sandbox regression for KDE/noVNC and XRDP/Guacamole.

The wrapper is exercised against a mock browser in temporary HOME, with an
isolated simulated root-owned marker. Does not require Docker/EC2/Chrome or
touch any real user profile.
"""
from pathlib import Path
from tempfile import TemporaryDirectory
import os
import subprocess

root = Path(__file__).resolve().parents[1]
launcher = (root / "docker/workstation/arcadecloud-chrome").read_text()
entrypoint = (root / "docker/workstation/entrypoint.sh").read_text()
installer = (root / "bin/install_workstation_node.sh").read_text()

real_marker = "/run/arcadecloud-office/chrome-sandbox-enabled"
assert f"CHROME_SANDBOX_MARKER={real_marker}" in launcher
assert '[[ -f "$CHROME_SANDBOX_MARKER" ]]' in launcher
assert 'ARCADECLOUD_CHROME_SANDBOX_ENABLED:-0' not in launcher
assert 'ARCADECLOUD_CHROME_SANDBOX_ENABLED:-0' in entrypoint
assert f"CHROME_SANDBOX_RUNTIME_MARKER=$CHROME_SANDBOX_RUNTIME_DIR/chrome-sandbox-enabled" in entrypoint
assert 'install -d -o root -g root -m 0755 "$CHROME_SANDBOX_RUNTIME_DIR"' in entrypoint
assert 'install -o root -g root -m 0444 /dev/null "$CHROME_SANDBOX_RUNTIME_MARKER"' in entrypoint
assert 'rm -f "$CHROME_SANDBOX_RUNTIME_MARKER"' in entrypoint
assert 'CHROME_RUNTIME_OPTS="--cap-add=SYS_CHROOT --security-opt seccomp=' in installer
assert 'ARCADECLOUD_CHROME_SANDBOX_ENABLED=1' in installer
assert installer.index('CHROME_RUNTIME_OPTS="--cap-add=SYS_CHROOT') > installer.index('ACTUAL_CHROME_PROFILE_HASH')
assert '--security-opt=no-new-privileges:true' in installer
assert 'seccomp=unconfined' not in installer
assert '--privileged' not in installer
assert 'CHROME_SANDBOX_ARGS=(--no-sandbox)' in launcher
assert 'CHROME_SANDBOX_ARGS=()' in launcher
assert 'arcadecloud-chrome-rdp' in launcher

for name in ("docker/workstation/arcadecloud-chrome",
             "docker/workstation/entrypoint.sh",
             "bin/install_workstation_node.sh"):
    result = subprocess.run(["bash", "-n", str(root / name)], capture_output=True, text=True)
    assert result.returncode == 0, (name, result.stderr)

with TemporaryDirectory(prefix="office-chrome-marker-smoke-") as tmp:
    directory = Path(tmp)
    marker = directory / "chrome-sandbox-enabled"
    home = directory / "arcade-home"
    home.mkdir()
    mock = directory / "mock-chrome.sh"
    # Only these two transformations are made in the temporary test COPY.
    # The committed production wrapper is never modified by the test.
    simulated = launcher.replace(real_marker, str(marker))
    simulated = simulated.replace(
        "exec /usr/bin/google-chrome-stable", "exec /bin/echo"
    ).replace(
        "python3 /usr/local/bin/arcadecloud-chrome-unlock.py", ":"
    )
    mock.write_text(simulated)

    def run(display: str, enabled_env: str = "0") -> str:
        env = dict(os.environ, HOME=str(home), DISPLAY=display,
                   ARCADECLOUD_CHROME_SANDBOX_ENABLED=enabled_env)
        result = subprocess.run(["bash", str(mock), "about:blank"],
                                capture_output=True, text=True, env=env)
        assert result.returncode == 0, result.stderr
        return result.stdout

    # No marker: original compatibility mode is unchanged.
    assert "--no-sandbox" in run(":10")
    # Simulate RDP session dropping Docker env: with valid marker it MUST
    # still launch securely without --no-sandbox.
    marker.touch()
    rdp = run(":10", enabled_env="0")
    assert "--no-sandbox" not in rdp, rdp
    assert f"--user-data-dir={home}/.config/arcadecloud-chrome-rdp" in rdp
    kde = run(":1", enabled_env="0")
    assert "--no-sandbox" not in kde, kde
    assert "--user-data-dir=" not in kde
    # A spoofed session env without root's marker must NOT change policy.
    marker.unlink()
    assert "--no-sandbox" in run(":10", enabled_env="1")
    assert "--no-sandbox" in run(":1", enabled_env="1")

print("OK: root-owned runtime marker controls Chrome sandbox even if XRDP drops Docker env.")
print("OK: no marker retains prior compatibility; marker enables sandbox in KDE and Guacamole.")
print("OK: distinct Chrome profiles, seccomp hash guard, Docker protections and persistence retained.")
