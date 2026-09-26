"""Local simulator: does not scan the LAN or change Wi-Fi."""
import json
import pathlib
import socketserver
import subprocess
import threading
import time
import sys

received = []

class Simulator(socketserver.StreamRequestHandler):
    def handle(self):
        request = json.loads(self.rfile.readline(8192))
        if request.get("op") == "invalid_test":
            response = {"ok": False, "error": "auth"}
        else:
            response = {"ok": True, "product": "FATEC_EPD", "protocol": 2,
                        "id": "SIM-01", "name": "SIMULADOR"}
            if request.get("op") == "set":
                assert request["token"] == "01234567890123456789012345678901"
                assert len(request["lines"]) == 4
                assert request["request_id"] == "sim-test"
                assert request["qr"] == "https://example.com"
                received.append(request)
                response.update(lines=request["lines"], qr=request["qr"], request_id=request["request_id"])
        wire = (json.dumps(response) + "\n").encode()
        for start in range(0, len(wire), 17):
            self.wfile.write(wire[start:start+17])
            self.wfile.flush()
            time.sleep(.002)

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True

def main():
    base = pathlib.Path(__file__).resolve().parent
    output = pathlib.Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else base / "test-results"
    output.mkdir(parents=True, exist_ok=True)
    with Server(("127.0.0.1", 8090), Simulator) as server:
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        result = subprocess.run([str(base / "bin" / "agendador.exe"), "--protocol-test", str(output)], timeout=120)
        server.shutdown()
    report = (output / "result.txt").read_text(encoding="utf-8")
    print(report)
    assert result.returncode == 0, "Pascal integration tests failed"
    assert len(received) == 1, "Unexpected duplicate send / device identity not enforced"
    print("PASS: simulator received exactly one authenticated message with four lines and QR")

if __name__ == "__main__":
    main()
