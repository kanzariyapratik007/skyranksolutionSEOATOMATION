#!/usr/bin/env python3
"""
SkyRank Local Agent Engine (Zero-Dependency HTTP Server on 127.0.0.1:8989)
Runs on User's PC to bridge Web Portal commands to Local Selenium Browser Execution.
"""
import sys, json, os, subprocess, urllib.request, urllib.parse, re, threading, shutil
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler


PORT = 8989
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
POST_SCRIPT = os.path.join(SCRIPT_DIR, "pinterest_post.py")
CURRENT_TUNNEL_URL = None

GIF_1X1 = b'GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;'


def make_vertical_pinterest_image(input_path):
    try:
        from PIL import Image
        with Image.open(input_path) as im:
            im = im.convert('RGB')
            w, h = im.size
            target_w, target_h = 1000, 1500
            target_ratio = target_w / float(target_h) # 0.6667
            src_ratio = w / float(h)
            
            # Smart Center Crop to 2:3 aspect ratio (1000x1500)
            if src_ratio > target_ratio:
                # Source is wider than target ratio
                scaled_h = target_h
                scaled_w = int(w * (target_h / float(h)))
                resized = im.resize((scaled_w, scaled_h), Image.Resampling.LANCZOS if hasattr(Image, 'Resampling') else Image.LANCZOS)
                crop_left = (scaled_w - target_w) // 2
                cropped = resized.crop((crop_left, 0, crop_left + target_w, target_h))
            else:
                # Source is taller than target ratio
                scaled_w = target_w
                scaled_h = int(h * (target_w / float(w)))
                resized = im.resize((scaled_w, scaled_h), Image.Resampling.LANCZOS if hasattr(Image, 'Resampling') else Image.LANCZOS)
                crop_top = (scaled_h - target_h) // 2
                cropped = resized.crop((0, crop_top, target_w, crop_top + target_h))
                
            out_path = os.path.join(SCRIPT_DIR, "temp_local_upload.jpg")
            cropped.save(out_path, "JPEG", quality=95)
            print(f"[Agent] Smart Center-Cropped image ({w}x{h}) into HD 1000x1500 Pinterest image", flush=True)
            return out_path
    except Exception as e:
        print(f"[Agent] Note: Image smart-crop skip: {e}", flush=True)
    return input_path


def ensure_local_image(image_path, image_url, keyword):
    if image_path and os.path.exists(image_path) and os.path.getsize(image_path) > 500:
        return make_vertical_pinterest_image(image_path)

    if image_url:
        try:
            local_img = os.path.join(SCRIPT_DIR, "temp_local_upload.jpg")
            req = urllib.request.Request(image_url, headers={'User-Agent': 'Mozilla/5.0'})
            with urllib.request.urlopen(req, timeout=10) as response, open(local_img, 'wb') as out_file:
                shutil.copyfileobj(response, out_file)
            if os.path.exists(local_img) and os.path.getsize(local_img) > 500:
                print(f"[Agent] Downloaded image from URL ({os.path.getsize(local_img)} bytes)", flush=True)
                return make_vertical_pinterest_image(local_img)
        except Exception as e:
            print(f"[Agent] Warning: Could not download image URL: {e}", flush=True)

    # Generate fallback local marketing image with PIL
    try:
        from PIL import Image, ImageDraw
        img_path = os.path.join(SCRIPT_DIR, "temp_local_upload.jpg")
        img = Image.new('RGB', (1000, 1500), color=(15, 23, 42))
        draw = ImageDraw.Draw(img)
        draw.rectangle([50, 50, 950, 1450], outline=(59, 130, 246), width=8)
        draw.rectangle([100, 200, 900, 400], fill=(30, 58, 138))
        draw.rectangle([100, 500, 900, 1300], fill=(30, 41, 59))
        img.save(img_path, "JPEG", quality=90)
        print(f"[Agent] Generated fallback PIL marketing image at: {img_path}", flush=True)
        return img_path
    except Exception as e_pil:
        print(f"[Agent] PIL fallback image generation error: {e_pil}", flush=True)

    return image_path

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

    def _send_json_response(self, code, data):
        body = json.dumps(data).encode('utf-8')
        self.send_response(code)
        self._send_cors_headers()
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.send_header('Connection', 'close')
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self):
        self.send_response(204)
        self._send_cors_headers()
        self.send_header('Content-Length', '0')
        self.send_header('Connection', 'close')
        self.end_headers()

    def do_GET(self):
        if self.path.startswith('/health_img'):
            self.send_response(200)
            self._send_cors_headers()
            self.send_header('Content-Type', 'image/gif')
            self.send_header('Content-Length', str(len(GIF_1X1)))
            self.send_header('Cache-Control', 'no-cache, no-store, must-revalidate')
            self.send_header('Connection', 'close')
            self.end_headers()
            self.wfile.write(GIF_1X1)
        elif self.headers.get('Upgrade', '').lower() == 'websocket':
            key = self.headers.get('Sec-WebSocket-Key', '')
            if key:
                import hashlib, base64, time
                GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'
                accept_key = base64.b64encode(hashlib.sha1((key + GUID).encode()).digest()).decode()
                self.send_response(101, 'Switching Protocols')
                self.send_header('Upgrade', 'websocket')
                self.send_header('Connection', 'Upgrade')
                self.send_header('Sec-WebSocket-Accept', accept_key)
                self.end_headers()
                time.sleep(0.5)
                return
        elif self.path.startswith('/health_js'):
            body = "if(window.onSkyRankAgentReady) window.onSkyRankAgentReady();".encode('utf-8')
            self.send_response(200)
            self._send_cors_headers()
            self.send_header('Content-Type', 'application/javascript')
            self.send_header('Content-Length', str(len(body)))
            self.send_header('Connection', 'close')
            self.end_headers()
            self.wfile.write(body)
        elif self.path == '/health' or self.path == '/':
            res = {"status": "active", "agent": "SkyRank Local Engine", "port": PORT, "tunnel_url": CURRENT_TUNNEL_URL}
            self._send_json_response(200, res)
        elif self.path == '/tunnel':
            res = {"status": "active", "tunnel_url": CURRENT_TUNNEL_URL}
            self._send_json_response(200, res)
        else:
            self.send_response(404)
            self.send_header('Content-Length', '0')
            self.send_header('Connection', 'close')
            self.end_headers()

    def do_POST(self):
        if self.path == '/run_pin':
            content_length = int(self.headers.get('Content-Length', 0))
            post_data = self.rfile.read(content_length)
            
            payload = {}
            try:
                payload = json.loads(post_data.decode('utf-8'))
            except Exception:
                try:
                    parsed = urllib.parse.parse_qs(post_data.decode('utf-8'))
                    payload = {k: v[0] for k, v in parsed.items()}
                except Exception as e:
                    self._send_json_response(400, {"success": False, "error": f"Invalid payload: {e}"})
                    return

            email       = payload.get('email', '')
            password    = payload.get('password', '')
            keyword     = payload.get('keyword', 'SEO')
            target_site = payload.get('target_site', 'https://example.com')
            image_path  = payload.get('image_path', '')
            image_url   = payload.get('image_url', '')
            ai_title    = payload.get('ai_title', '')
            ai_content  = payload.get('ai_content', '')

            # Ensure local image path exists for upload
            image_path = ensure_local_image(image_path, image_url, keyword)


            print(f"[Agent] Executing Pinterest post for: {email}...", flush=True)

            python_exe = sys.executable or "python"
            cmd = [
                python_exe, POST_SCRIPT,
                email, password, keyword, target_site,
                image_path or "", ai_title or "", ai_content or ""
            ]

            is_form = 'application/x-www-form-urlencoded' in self.headers.get('Content-Type', '')

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

                if is_form:
                    html_body = f"""<!DOCTYPE html><html><body><script>
                    if (window.parent && window.parent.onLocalAgentPinResult) {{
                        window.parent.onLocalAgentPinResult({json.dumps(res_data)});
                    }}
                    </script></body></html>""".encode('utf-8')
                    self.send_response(200)
                    self._send_cors_headers()
                    self.send_header('Content-Type', 'text/html')
                    self.send_header('Content-Length', str(len(html_body)))
                    self.send_header('Connection', 'close')
                    self.end_headers()
                    self.wfile.write(html_body)
                else:
                    self._send_json_response(200, res_data)

            except subprocess.TimeoutExpired:
                err_payload = {"success": False, "error": "Execution timed out after 300 seconds"}
                if is_form:
                    html_body = f"<!DOCTYPE html><html><body><script>if(window.parent&&window.parent.onLocalAgentPinResult){{window.parent.onLocalAgentPinResult({json.dumps(err_payload)});}}</script></body></html>".encode('utf-8')
                    self.send_response(504)
                    self._send_cors_headers()
                    self.send_header('Content-Type', 'text/html')
                    self.send_header('Content-Length', str(len(html_body)))
                    self.end_headers()
                    self.wfile.write(html_body)
                else:
                    self._send_json_response(504, err_payload)

            except Exception as e:
                err_payload = {"success": False, "error": str(e)}
                if is_form:
                    html_body = f"<!DOCTYPE html><html><body><script>if(window.parent&&window.parent.onLocalAgentPinResult){{window.parent.onLocalAgentPinResult({json.dumps(err_payload)});}}</script></body></html>".encode('utf-8')
                    self.send_response(500)
                    self._send_cors_headers()
                    self.send_header('Content-Type', 'text/html')
                    self.send_header('Content-Length', str(len(html_body)))
                    self.end_headers()
                    self.wfile.write(html_body)
                else:
                    self._send_json_response(500, err_payload)
        else:
            self.send_response(404)
            self.send_header('Content-Length', '0')
            self.send_header('Connection', 'close')
            self.end_headers()

def start_cloudflared():
    cloudflared_exe = os.path.join(SCRIPT_DIR, "cloudflared.exe")
    if not os.path.exists(cloudflared_exe):
        # try PATH
        import shutil
        cloudflared_exe = shutil.which("cloudflared") or "cloudflared"

    try:
        print("[Agent] Starting Cloudflare HTTPS Tunnel...", flush=True)
        proc = subprocess.Popen(
            [cloudflared_exe, "tunnel", "--url", f"http://127.0.0.1:{PORT}"],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            bufsize=1
        )
        
        def monitor_tunnel():
            global CURRENT_TUNNEL_URL
            for line in proc.stdout:
                line_clean = line.strip()
                match = re.search(r'https://[a-zA-Z0-9-]+\.trycloudflare\.com', line_clean)
                if match and not CURRENT_TUNNEL_URL:
                    CURRENT_TUNNEL_URL = match.group(0)
                    print("\n==================================================", flush=True)
                    print("  [SECURE HTTPS TUNNEL CREATED!]", flush=True)
                    print(f"  [HTTPS URL]: {CURRENT_TUNNEL_URL}", flush=True)
                    print("  -> AUTO-REGISTERING TUNNEL TO AWS WEB PORTAL...", flush=True)
                    print("==================================================\n", flush=True)
                    try:
                        req_url = f"http://52.55.247.39/submission-manager.php?action=save_tunnel_url&url={CURRENT_TUNNEL_URL}"
                        urllib.request.urlopen(req_url, timeout=5)
                        print("  [AUTO-REGISTERED TUNNEL TO AWS PORTAL SUCCESS!]", flush=True)
                    except Exception as ex_t:
                        print(f"  [Auto-Register Tunnel note]: {ex_t}", flush=True)

        t = threading.Thread(target=monitor_tunnel, daemon=True)
        t.start()
        return proc
    except Exception as e:
        print(f"[Agent] Note: Cloudflare tunnel start skipped: {e}", flush=True)
        return None

def run_server():
    cf_proc = start_cloudflared()
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
        if cf_proc:
            cf_proc.terminate()
        server.server_close()

if __name__ == '__main__':
    run_server()

