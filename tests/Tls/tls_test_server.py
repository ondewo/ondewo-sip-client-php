"""TLS gRPC servers for tests/Tls/MutualTlsHandshakeTest.php (PHP has no gRPC server).

argv[1] is a JSON object of server name -> {"cert": path, "key": path, "client_ca": path or null}.
Each server registers no service, so every RPC that completes the TLS handshake is answered
UNIMPLEMENTED: that status proves the handshake, a failed handshake is UNAVAILABLE on the client.
A server with "client_ca" requires a client certificate issued by that CA (mutual TLS).

Prints one JSON line {name: port} once every server listens, then runs until stdin closes.
"""

import json
import sys
from concurrent import futures

import grpc


def _read(path: str) -> bytes:
    with open(path, "rb") as handle:
        return handle.read()


def main() -> None:
    spec = json.loads(sys.argv[1])
    servers = []
    ports = {}
    for name, files in spec.items():
        client_ca = files.get("client_ca")
        credentials = grpc.ssl_server_credentials(
            [(_read(files["key"]), _read(files["cert"]))],
            root_certificates=_read(client_ca) if client_ca else None,
            require_client_auth=bool(client_ca),
        )
        server = grpc.server(futures.ThreadPoolExecutor(max_workers=2))
        # localhost binds 127.0.0.1 and, where the host has it, ::1 on the same port.
        ports[name] = server.add_secure_port("localhost:0", credentials)
        server.start()
        servers.append(server)
    print(json.dumps(ports), flush=True)
    sys.stdin.read()
    for server in servers:
        server.stop(None)


if __name__ == "__main__":
    main()
