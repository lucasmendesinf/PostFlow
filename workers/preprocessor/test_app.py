import json
import threading
import unittest
import urllib.request
from http.server import ThreadingHTTPServer

from app import Handler


class HealthTest(unittest.TestCase):
    def test_health_responde_ok(self):
        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            url = f"http://127.0.0.1:{server.server_address[1]}/health"
            with urllib.request.urlopen(url) as res:
                self.assertEqual(res.status, 200)
                self.assertEqual(json.load(res), {"status": "ok", "servico": "preprocessor"})
        finally:
            server.shutdown()
            server.server_close()


if __name__ == "__main__":
    unittest.main()
