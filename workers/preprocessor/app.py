"""Pré-processador de imagens do PostFlow (Python + rembg + Pillow).

Etapa 1: só o esqueleto do serviço e o /health usado pelo job de teste.
Etapa 4: remoção de fundo (u2net_human_seg), versões branca/preta dos logos,
efeitos com Pillow e redução para no máximo 1080 px.
"""

import json
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class Handler(BaseHTTPRequestHandler):
    def _json(self, status, body):
        payload = json.dumps(body, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self):
        if self.path == "/health":
            return self._json(200, {"status": "ok", "servico": "preprocessor"})
        return self._json(404, {"erro": "Rota não encontrada."})

    def do_POST(self):
        if self.path == "/process":
            return self._json(501, {"erro": "Pré-processamento entra na etapa 4."})
        return self._json(404, {"erro": "Rota não encontrada."})

    def log_message(self, fmt, *args):
        print(f"[preprocessor] {self.address_string()} {fmt % args}", flush=True)


def main():
    port = int(os.environ.get("PORT", "3002"))
    server = ThreadingHTTPServer(("0.0.0.0", port), Handler)
    print(f"[preprocessor] ouvindo na porta {port}", flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
