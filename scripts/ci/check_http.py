#!/usr/bin/env python3
"""Exercise the real public front controller through a local PHP server."""
from __future__ import annotations

import http.client
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time


def check_server(root: Path, environment: dict[str, str]) -> None:
    with socket.socket() as reservation:
        reservation.bind(("127.0.0.1", 0))
        port = reservation.getsockname()[1]
    with tempfile.TemporaryFile() as log:
        server = subprocess.Popen(
            ["php", "-d", "variables_order=GPCS", "-S", f"127.0.0.1:{port}", "-t", "public", "public/index.php"],
            cwd=root, env=environment, stdout=log, stderr=log,
        )
        try:
            for _ in range(40):
                if server.poll() is not None:
                    raise RuntimeError("The local PHP server stopped before the HTTP test")
                connection = http.client.HTTPConnection("127.0.0.1", port, timeout=2)
                try:
                    connection.request("GET", "/foundation-unknown-route?probe=%3Cscript%3Eprobe%3C/script%3E", headers={"Accept": "text/html"})
                    response = connection.getresponse()
                    body = response.read(100_000).decode("utf-8")
                    if response.status != 404:
                        log.seek(0)
                        diagnostic = log.read().decode("utf-8", errors="replace")
                        for key in ("APP_SECRET", "DATABASE_URL", "REDIS_URL"):
                            value = environment.get(key)
                            if value:
                                diagnostic = diagnostic.replace(value, "[redacted]")
                        raise RuntimeError(f"Expected HTTP 404, received {response.status}; server log: {diagnostic[-4000:]}")
                    if "Anfrage nicht verfügbar" not in body:
                        log.seek(0)
                        diagnostic = body[:1200] + "\n" + log.read().decode("utf-8", errors="replace")[-2500:]
                        for key in ("APP_SECRET", "DATABASE_URL", "REDIS_URL"):
                            value = environment.get(key)
                            if value:
                                diagnostic = diagnostic.replace(value, "[redacted]")
                        raise RuntimeError(f"The neutral Twig error page was not rendered: {diagnostic}")
                    if any(marker in body for marker in ("<script>", "Stack Trace", "DATABASE_URL", "APP_SECRET")):
                        raise RuntimeError("The HTTP error response contains unsafe details")
                    return
                except (ConnectionError, OSError):
                    time.sleep(0.25)
                finally:
                    connection.close()
            raise RuntimeError("The HTTP bootstrap did not become ready within the test window")
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)


def main() -> None:
    root = Path(__file__).resolve().parents[2]
    check_server(root, dict(os.environ, APP_ENV="prod", APP_DEBUG="0"))
    defaults = dict(os.environ)
    defaults.pop("APP_ENV", None)
    defaults.pop("APP_DEBUG", None)
    check_server(root, defaults)
    print("HTTP bootstrap passed: explicit production environment and safe defaults, including variables_order=GPCS; neutral Twig 404 responses.")


if __name__ == "__main__":
    main()
