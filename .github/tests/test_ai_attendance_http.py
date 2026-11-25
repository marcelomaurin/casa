#!/usr/bin/env python3
"""Test the real PHP HTTP adapter against a declared local model-response fixture."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import shutil
import subprocess
import threading

ROOT = Path(__file__).resolve().parents[2]


class Fixture(BaseHTTPRequestHandler):
    errors = []
    requests = []

    def log_message(self, *args):
        pass

    def do_POST(self):
        try:
            self.requests.append(self.path)
            assert self.headers.get('X-API-Key') == 'fixture-internal-token'
            body = json.loads(self.rfile.read(int(self.headers.get('Content-Length', 0))))
            assert body['comando'] == 'E na cozinha?' and body['origem'] == 'SIP'
            assert body['historico_mensagens'][0]['content'] == 'Na sala, vinte graus.'
            assert body['session_id'] == 'http-fixture-call'
            code = 200
            response = json.dumps({'status': 'sucesso', 'resposta': 'Na cozinha, **vinte e dois graus**.',
                                   'provedor': 'fixture HTTP'}, ensure_ascii=False).encode()
            if self.path == '/unavailable':
                code, response = 503, b'{}'
            elif self.path == '/invalid':
                response = b'not-json'
            elif self.path == '/too-large':
                response = b'x' * (2 * 1024 * 1024 + 1)
            elif self.path == '/redirect':
                code, response = 302, b''
            self.send_response(code)
            if code == 302:
                self.send_header('Location', '/must-not-follow')
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(response)))
            self.end_headers()
            try:
                self.wfile.write(response)
            except (BrokenPipeError, ConnectionResetError):
                pass
        except Exception as exc:
            self.errors.append(repr(exc))
            self.send_error(500)


def main():
    php = os.getenv('PHP_BIN') or shutil.which('php')
    if not php:
        raise RuntimeError('PHP CLI with curl is required')
    command = [php]
    if os.getenv('PHP_CURL_EXTENSION'):
        command.extend(['-n', '-d', 'extension=' + os.environ['PHP_CURL_EXTENSION']])
    server = ThreadingHTTPServer(('127.0.0.1', 0), Fixture)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        subprocess.run(command + [str(ROOT / '.github/tests/test_ai_attendance_http.php'),
                                  f'http://127.0.0.1:{server.server_port}'], check=True, timeout=15)
        assert not Fixture.errors, Fixture.errors
        assert '/must-not-follow' not in Fixture.requests
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2)


if __name__ == '__main__':
    main()
