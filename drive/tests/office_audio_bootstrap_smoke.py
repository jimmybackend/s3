#!/usr/bin/env python3
"""Regresión: el audio noVNC debe cargarse aunque su HTML esté cacheado."""
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
gateway = (root / "office-gateway.php").read_text(encoding="utf-8")
entry = (root / "docker/workstation/entrypoint.sh").read_text(encoding="utf-8")
audio = (root / "docker/workstation/arcadecloud-audio.js").read_text(encoding="utf-8")
marker = "office-audio-bootstrap-20261029"

assert "window.__arcadecloudOfficeAudioDiagnostics = () =>" in gateway
assert "frame.addEventListener('load', ensureNoVncAudio)" in gateway
assert "script.onerror = () =>" in gateway
assert "script.src = '/arcadecloud-audio.js?v=" + marker + "'" in gateway
assert "child.__arcadecloudAudioInstalled === true" in gateway
assert "if (isNoVncFrame)" in gateway
assert "window.__arcadecloudOfficeAudioDiagnostics = () =>" not in audio
assert "window.__arcadecloudAudioDiagnostics = () =>" in audio
assert "parent.__arcadecloudOfficeAudioDiagnostics =" in audio

start = "python3 - <<'PY'\n"
end = "\nPY\n"
assert start in entry
program = entry.split(start, 1)[1].split(end, 1)[0]
compile(program, "<vnc-script-patcher>", "exec")

def verify_html(html):
    with tempfile.TemporaryDirectory(prefix="office-vnc-audio-test-") as directory:
        fake = Path(directory) / "vnc.html"
        fake.write_text(html, encoding="utf-8")
        isolated = program.replace("/usr/share/novnc/vnc.html", str(fake))
        assert isolated != program
        run = subprocess.run(["python3", "-c", isolated], capture_output=True, text=True)
        assert run.returncode == 0, run.stderr
        after_first = fake.read_text(encoding="utf-8")
        assert marker in after_first, after_first
        assert after_first.count("/arcadecloud-audio.js") == 1, after_first
        run_again = subprocess.run(["python3", "-c", isolated], capture_output=True, text=True)
        assert run_again.returncode == 0, run_again.stderr
        assert fake.read_text(encoding="utf-8") == after_first
        return after_first

verify_html("<html><body><p>noVNC</p></body></html>")
verify_html("<html><body><script src=\"/arcadecloud-audio.js\"></script></body></html>")
verify_html("<html><BODY><p>noVNC</p></BODY></html>")
verify_html("<html><body><script src=\"/arcadecloud-audio.js?v=old\"></script></body></html>")
print("OK: noVNC recibe un único script de audio versionado y persistente.")
print("OK: gateway ofrece estado de audio aunque noVNC cargue HTML de caché.")
