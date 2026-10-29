#!/usr/bin/env python3
"""Audio MP3 para noVNC. Solo accesible por el proxy Office autenticado.

El servidor escucha dentro de Docker en 6081. El puerto se publica únicamente
en el loopback del host, nunca en interfaces públicas. Cada conexión autorizada
desde el gateway crea una captura individual del monitor PipeWire de KDE.
"""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from subprocess import DEVNULL, PIPE, Popen, TimeoutExpired
import os
import threading
from urllib.parse import urlsplit

PORT = 6081
MAX_STREAMS = 3  # KDE noVNC + XRDP/Guacamole + reconexión temporal
semaphore = threading.BoundedSemaphore(MAX_STREAMS)


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
                    "-f", "pulse", "-i", "xrdp-sink.monitor",
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
