#!/usr/bin/env python3
"""Audio MP3 para noVNC. Solo accesible por el proxy Office autenticado.

El servidor escucha dentro de Docker en 6081. El puerto se publica únicamente
en el loopback del host, nunca en interfaces públicas. Cada conexión autorizada
desde el gateway crea una captura individual del monitor PipeWire de KDE.
"""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from subprocess import DEVNULL, PIPE, Popen, TimeoutExpired, run
import os
import re
import threading
from urllib.parse import urlsplit

PORT = 6081
MAX_STREAMS = 3  # KDE noVNC + XRDP/Guacamole + reconexión temporal
semaphore = threading.BoundedSemaphore(MAX_STREAMS)


def active_audio_monitor(env):
    """Captura la salida usada por aplicaciones, no un sink XRDP fijo.

    En una Workstation con KDE/noVNC y XFCE/XRDP simultáneos pueden
    coexistir varios sinks. Preferir un sink con reproducción activa y,
    en su ausencia, el sink predeterminado actual de PipeWire.
    """
    try:
        sinks_result = run(
            ["pactl", "list", "short", "sinks"], capture_output=True,
            text=True, timeout=3, env=env, check=True,
        )
        sinks = {}
        for line in sinks_result.stdout.splitlines():
            fields = line.split()
            if len(fields) > 1 and fields[0].isdigit():
                sinks[fields[0]] = fields[1]

        inputs_result = run(
            ["pactl", "list", "short", "sink-inputs"], capture_output=True,
            text=True, timeout=3, env=env, check=True,
        )
        active_sinks = []
        for line in inputs_result.stdout.splitlines():
            fields = line.split()
            if len(fields) > 1 and fields[1] in sinks:
                active_sinks.append(sinks[fields[1]])

        default_result = run(
            ["pactl", "get-default-sink"], capture_output=True,
            text=True, timeout=3, env=env, check=True,
        )
        default_sink = default_result.stdout.strip()

        # KDE/noVNC tiene un sink virtual dedicado; usarlo incluso cuando
        # Guacamole/XRDP está reproduciendo audio en otro sink.
        # Así abrir o cerrar Guacamole nunca cambia lo que oye noVNC.
        if "arcadecloud-novnc-sink" in sinks.values():
            return "arcadecloud-novnc-sink.monitor"

        # Compatibilidad con imágenes anteriores sin el nuevo sink virtual.
        for name in reversed(active_sinks):
            if re.fullmatch(r"[A-Za-z0-9_.-]{1,128}", name):
                return name + ".monitor"
        if default_sink in sinks.values() and re.fullmatch(
            r"[A-Za-z0-9_.-]{1,128}", default_sink
        ):
            return default_sink + ".monitor"
    except (OSError, TimeoutExpired, ValueError) as error:
        # Permitir fallback si pactl todavía está arrancando.
        pass
    except Exception:
        pass
    return "xrdp-sink.monitor"


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def do_GET(self):
        if urlsplit(self.path).path != "/__office_audio":
            self.send_error(404)
            return
        if not semaphore.acquire(blocking=False):
            self.send_error(429, "Ya hay una transmisión de audio activa")
            return

        proc = None
        try:
            env = dict(os.environ)
            env["XDG_RUNTIME_DIR"] = "/run/user/10001"
            env["PULSE_SERVER"] = "unix:/run/user/10001/pulse/native"
            proc = Popen(
                [
                    "/usr/bin/ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error",
                    "-f", "pulse", "-i", active_audio_monitor(env),
                    "-vn", "-ac", "2", "-ar", "44100",
                    "-c:a", "libmp3lame", "-b:a", "96k",
                    "-f", "mp3", "pipe:1",
                ],
                stdin=DEVNULL, stdout=PIPE, stderr=DEVNULL, env=env,
            )
            self.send_response(200)
            self.send_header("Content-Type", "audio/mpeg")
            self.send_header("Cache-Control", "no-store, no-transform")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Access-Control-Allow-Origin", "none")
            self.send_header("Connection", "close")
            self.end_headers()

            while True:
                block = proc.stdout.read(4096)
                if not block:
                    break
                self.wfile.write(block)
                self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError, ConnectionAbortedError):
            pass
        except OSError as error:
            self.log_error("Fallo de audio: %s", error)
        finally:
            if proc:
                if proc.stdout:
                    proc.stdout.close()
                if proc.poll() is None:
                    proc.terminate()
                    try:
                        proc.wait(timeout=2)
                    except TimeoutExpired:
                        proc.kill()
                        proc.wait(timeout=2)
            semaphore.release()

    def do_HEAD(self):
        self.send_error(405)

    def log_message(self, format, *args):
        # No imprimir cookies ni parámetros. Solo metadatos mínimos de servicio.
        if not format.startswith('"GET /__office_audio'):
            super().log_message(format, *args)


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
