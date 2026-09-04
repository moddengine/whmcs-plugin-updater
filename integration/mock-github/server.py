import hashlib
import io
import json
import ssl
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


manifest = {
    "schema": 1,
    "package": "acme/playwright-fixture",
    "component": {"type": "addon", "name": "playwrightfixture"},
    "version": "1.1.0",
    "github": {
        "repository": "acme/playwright-fixture",
        "asset": "playwright-fixture-{version}.zip",
    },
    "requires": {
        "php_min": "8.3.0",
        "whmcs_min": "8.13.0",
        "whmcs_max_exclusive": "10.0.0",
    },
}
archive_buffer = io.BytesIO()
with zipfile.ZipFile(archive_buffer, "w", zipfile.ZIP_DEFLATED) as archive:
    archive.writestr("wrapper/not-installed.txt", "outside manifest root")
    archive.writestr("wrapper/addon/whmcs.update-manifest.json", json.dumps(manifest, indent=2))
    archive.writestr("wrapper/addon/version.txt", "1.1.0\n")
    archive.writestr("wrapper/addon/new.php", "<?php return 'updated';\n")
release_asset = archive_buffer.getvalue()
release_digest = hashlib.sha256(release_asset).hexdigest()


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/health":
            self.respond(b"ok\n", "text/plain")
            return
        if self.path.startswith("/repos/acme/playwright-fixture/releases?"):
            payload = [{
                "draft": False,
                "prerelease": False,
                "tag_name": "v1.1.0",
                "body": "Playwright demo: secure full-tree update from **1.0.0** to **1.1.0**.",
                "assets": [{
                    "id": 1,
                    "name": "playwright-fixture-1.1.0.zip",
                    "size": len(release_asset),
                    "digest": "sha256:" + release_digest,
                }],
            }]
            self.respond(json.dumps(payload).encode(), "application/json", {"ETag": '"demo-v1.1.0"'})
            return
        if self.path == "/repos/acme/playwright-fixture/releases/assets/1":
            self.respond(release_asset, "application/zip")
            return
        self.send_error(404)

    def respond(self, body, content_type, headers=None):
        self.send_response(200)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        for name, value in (headers or {}).items():
            self.send_header(name, value)
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format, *args):
        print(format % args, flush=True)


server = ThreadingHTTPServer(("0.0.0.0", 443), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain("/certs/server.crt", "/certs/server.key")
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()
