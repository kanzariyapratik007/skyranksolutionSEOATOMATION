#!/usr/bin/env python3
"""
SkyRank Local Agent Engine (Zero-Dependency HTTP Server on 127.0.0.1:8989)
Runs on User's PC to bridge Web Portal commands to Local Selenium Browser Execution.
"""
import sys, json, os, subprocess, urllib.request
from http.server import HTTPServer, BaseHTTPRequestHandler

PORT = 8989
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
POST_SCRIPT = os.path.join(SCRIPT_DIR, "pinterest_post.py")

class AgentHandler(BaseHTTPRequestHandler):
    def _send_cors_headers(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type, Authorization')

    def do_OPTIONS(self):
        self.send_response(200)
        self._send_cors_headers()
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
                    tmp_img = os.path.join(SCRIPT_DIR, "tmp_downloaded_pin.jpg")
                    urllib.request.urlretrieve(image_url, tmp_img)
                    if os.path.exists(tmp_img) and os.path.getsize(tmp_img) > 100:
                        image_path = tmp_img
                except Exception as e_dl:
                    print(f"[Agent] Image download note: {e_dl}")

            cmd = [
                sys.executable, POST_SCRIPT,
                email, password, keyword, target_site,
                image_path, ai_title, ai_content
            ]

            print(f"[Agent] Executing Pinterest post for: {email}...")
            try:
                proc = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
                lines = proc.stdout.strip().split('\n')
                
                result_json = {"success": False, "error": "No valid result from post script"}
                for line in reversed(lines):
                    line_str = line.strip()
                    if line_str.startswith('{') and line_str.endswith('}'):
                        try:
                            parsed = json.loads(line_str)
                            if 'success' in parsed:
                                result_json = parsed
                                break
                        except Exception:
                            continue

                self.send_response(200)
                self._send_cors_headers()
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps(result_json).encode('utf-8'))
            except subprocess.TimeoutExpired:
                self.send_response(500)
                self._send_cors_headers()
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({"success": False, "error": "Execution timed out after 180 seconds"}).encode('utf-8'))
            except Exception as e_exec:
                self.send_response(500)
                self._send_cors_headers()
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({"success": False, "error": str(e_exec)}).encode('utf-8'))
        else:
            self.send_response(404)
            self.end_headers()

def run_server():
    server = HTTPServer(('127.0.0.1', PORT), AgentHandler)
    print(f"==================================================")
    print(f"  SkyRank Local Agent Engine Running")
    print(f"  Listening on: http://127.0.0.1:{PORT}")
    print(f"  Status: Ready for Web Portal Pairing")
    print(f"==================================================")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n[Agent] Stopping Local Agent...")
        server.server_close()

if __name__ == '__main__':
    run_server()
