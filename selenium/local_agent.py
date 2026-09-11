#!/usr/bin/env python3
"""
SkyRank Local Agent Engine (Zero-Dependency HTTP Server on 127.0.0.1:8989)
Runs on User's PC to bridge Web Portal commands to Local Selenium Browser Execution.
"""
import sys, json, os, subprocess, urllib.request
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler

PORT = 8989
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
POST_SCRIPT = os.path.join(SCRIPT_DIR, "pinterest_post.py")

class AgentHandler(BaseHTTPRequestHandler):
    def address_string(self):
        return self.client_address[0]

    def log_message(self, format, *args):
        print(f"[Agent] {self.address_string()} - {format%args}", flush=True)

    def _send_cors_headers(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
        self.send_header('Access-Control-Allow-Private-Network', 'true')

    def do_OPTIONS(self):
        self.send_response(204)
        self._send_cors_headers()
        self.send_header('Content-Length', '0')
        self.end_headers()

    def do_GET(self):
        if self.path == '/health' or self.path == '/':
            self.send_response(200)
            self._send_cors_headers()
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            res = {"status": "active", "agent": "SkyRank Local Engine", "port": PORT}
            self.wfile.write(json.dumps(res).encode('utf-8'))
        else:
            self.send_response(404)
            self.end_headers()

    def do_POST(self):
        if self.path == '/run_pin':
            content_length = int(self.headers.get('Content-Length', 0))
            post_data = self.rfile.read(content_length)
            
            try:
                payload = json.loads(post_data.decode('utf-8'))
            except Exception as e:
                self.send_response(400)
                self._send_cors_headers()
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({"success": False, "error": f"Invalid JSON: {e}"}).encode('utf-8'))
                return

            email       = payload.get('email', '')
            password    = payload.get('password', '')
            keyword     = payload.get('keyword', 'SEO')
            target_site = payload.get('target_site', 'https://example.com')
            image_path  = payload.get('image_path', '')
            image_url   = payload.get('image_url', '')
            ai_title    = payload.get('ai_title', '')
            ai_content  = payload.get('ai_content', '')

            # Download remote image URL if provided
            if not image_path and image_url:
                try:
                    local_img = os.path.join(SCRIPT_DIR, "temp_local_upload.jpg")
                    urllib.request.urlretrieve(image_url, local_img)
                    image_path = local_img
                except Exception as e:
                    print(f"[Agent] Warning: Could not download image URL: {e}", flush=True)

            print(f"[Agent] Executing Pinterest post for: {email}...", flush=True)

            python_exe = sys.executable or "python"
            cmd = [
                python_exe, POST_SCRIPT,
                email, password, keyword, target_site,
                image_path or "", ai_title or "", ai_content or ""
            ]

            try:
                proc = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
                stdout = proc.stdout or ""
                stderr = proc.stderr or ""
                
                # Extract json result line
                last_line = ""
                for line in stdout.splitlines():
                    line_str = line.strip()
                    if line_str.startswith("{") and "success" in line_str:
                        last_line = line_str

                if last_line:
                    res_data = json.loads(last_line)
                else:
                    res_data = {"success": False, "error": f"Process exited without result JSON. Stderr: {stderr[:200]}"}

                self.send_response(200)
                self._send_cors_headers()
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps(res_data).encode('utf-8'))

            except subprocess.TimeoutExpired:
                self.send_response(504)
                self._send_cors_headers()
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({"success": False, "error": "Execution timed out after 300 seconds"}).encode('utf-8'))

            except Exception as e:
                self.send_response(500)
                self._send_cors_headers()
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({"success": False, "error": str(e)}).encode('utf-8'))
        else:
            self.send_response(404)
            self.end_headers()

def run_server():
    server = ThreadingHTTPServer(('0.0.0.0', PORT), AgentHandler)
    print(f"==================================================", flush=True)
    print(f"  SkyRank Local Agent Engine Running", flush=True)
    print(f"  Listening on: http://127.0.0.1:{PORT}", flush=True)
    print(f"  Status: Ready for Web Portal Pairing", flush=True)
    print(f"==================================================", flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n[Agent] Stopping Local Agent...", flush=True)
        server.server_close()

if __name__ == '__main__':
    run_server()
