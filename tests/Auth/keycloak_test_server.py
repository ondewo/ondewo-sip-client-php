"""A scripted stand-in for the Keycloak token endpoint, for tests/Auth/CurlKeycloakTokenTransportTest.php.

argv[1] is a JSON object {"cert": path, "key": path}; it serves plain HTTP on one port and HTTPS with
that certificate on another, both on 127.0.0.1.

Control endpoints (both ports share one state):
  POST /__script    body = JSON list of {"status": int, "body": str, "sleep": float}; replaces the
                    queue of answers and forgets the recorded requests.
  GET  /__requests  JSON list of every other request since: {"method", "path", "content_type",
                    "form": [[name, value], ...]} (form fields in the order they were sent).
Every other request is recorded and answered with the next scripted entry (500 once the queue is
empty).

Prints one JSON line {"http": port, "https": port} once both listen, then runs until stdin closes.
"""

import json
import ssl
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Dict, List
from urllib.parse import parse_qsl

_LOCK = threading.Lock()
_QUEUE: List[Dict[str, Any]] = []
_REQUESTS: List[Dict[str, Any]] = []


class _Handler(BaseHTTPRequestHandler):
    def log_message(self, format: str, *args: Any) -> None:  # noqa: A002 - BaseHTTPRequestHandler's signature
        pass

    def _send(self, status: int, body: str) -> None:
        payload = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def _body(self) -> str:
        length = int(self.headers.get("Content-Length", "0"))
        return self.rfile.read(length).decode("utf-8")

    def do_GET(self) -> None:  # noqa: N802 - BaseHTTPRequestHandler's naming
        if self.path == "/__requests":
            with _LOCK:
                self._send(200, json.dumps(_REQUESTS))
            return
        self._send(404, "{}")

    def do_POST(self) -> None:  # noqa: N802 - BaseHTTPRequestHandler's naming
        body = self._body()
        if self.path == "/__script":
            with _LOCK:
                _QUEUE[:] = json.loads(body)
                _REQUESTS.clear()
            self._send(204, "")
            return
        with _LOCK:
            _REQUESTS.append(
                {
                    "method": "POST",
                    "path": self.path,
                    "content_type": self.headers.get("Content-Type", ""),
                    "form": parse_qsl(body, keep_blank_values=True),
                }
            )
            entry = _QUEUE.pop(0) if _QUEUE else {"status": 500, "body": '{"error":"script exhausted"}'}
        time.sleep(float(entry.get("sleep", 0.0)))
        self._send(int(entry["status"]), str(entry.get("body", "")))


def main() -> None:
    spec = json.loads(sys.argv[1])
    plain = ThreadingHTTPServer(("127.0.0.1", 0), _Handler)
    secure = ThreadingHTTPServer(("127.0.0.1", 0), _Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(spec["cert"], spec["key"])
    secure.socket = context.wrap_socket(secure.socket, server_side=True)
    for server in (plain, secure):
        server.daemon_threads = True
        threading.Thread(target=server.serve_forever, daemon=True).start()
    print(json.dumps({"http": plain.server_address[1], "https": secure.server_address[1]}), flush=True)
    sys.stdin.read()


if __name__ == "__main__":
    main()
