#!/usr/bin/env python3
"""
LiveJournal Auto-Post via Playwright CLI Wrapper (Engine: XML-RPC)
Uses LiveJournal's officially supported XML-RPC API under the hood to bypass anti-scraping
WAF/Cloudflare blocks (which return HTTP 403 on browser-automated post submissions).
"""
import sys
import json
import os
import xmlrpc.client
import hashlib
from datetime import datetime

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error=''):
    print(json.dumps({"success": success, "url": url, "error": error}), flush=True)

def livejournal_post(username, password, keyword, target_url, ai_title, image_path, content_file):
    # Load content from file
    if content_file and os.path.exists(content_file):
        with open(content_file, 'r', encoding='utf-8') as f:
            ai_content = f.read()
        log(f"LiveJournal: Content loaded from file ({len(ai_content)} chars)")
    else:
        ai_content = f"<p>Learn more about {keyword} here. Visit <a href='{target_url}'>{target_url}</a>.</p>"

    # Prepend image if provided
    if image_path and image_path.startswith("http"):
        ai_content = f'<p><img src="{image_path}" alt="{keyword}" style="max-width:100%;" /></p>\n' + ai_content

    # Ensure title is not empty
    if not ai_title:
        ai_title = f"Guide on {keyword}"

    try:
        import ssl
        ctx = ssl._create_unverified_context()
        transport = xmlrpc.client.SafeTransport(context=ctx)
        log("LiveJournal: Connecting to XML-RPC server...")
        server = xmlrpc.client.ServerProxy("https://www.livejournal.com/interface/xmlrpc", transport=transport)

        log("LiveJournal: Requesting authentication challenge...")
        challenge_data = server.LJ.XMLRPC.getchallenge()
        challenge = challenge_data.get('challenge')
        if not challenge:
            raise Exception("No challenge string returned by LiveJournal server proxy.")

        password_hash = hashlib.md5(password.encode('utf-8')).hexdigest()
        auth_response = hashlib.md5((challenge + password_hash).encode('utf-8')).hexdigest()

        now = datetime.now()

        params = {
            "username": username,
            "auth_method": "challenge",
            "auth_challenge": challenge,
            "auth_response": auth_response,
            "ver": "1",
            "event": ai_content,
            "subject": ai_title,
            "year": now.year,
            "mon": now.month,
            "day": now.day,
            "hour": now.hour,
            "min": now.minute,
            "props": {
                "opt_preformatted": True,
                "taglist": keyword,
            }
        }

        log(f"LiveJournal: Submitting post event '{ai_title}' via XML-RPC API...")
        resp = server.LJ.XMLRPC.postevent(params)
        
        post_url = resp.get('url')
        if post_url:
            log(f"LiveJournal: Post published successfully at {post_url}")
            result(True, url=post_url)
        else:
            raise Exception(f"XML-RPC response did not contain post URL. Response: {resp}")

    except Exception as e:
        log(f"LiveJournal: Error = {e}")
        result(False, error=str(e))

if __name__ == "__main__":
    if len(sys.argv) < 8:
        result(False, error="Usage: livejournal_post_playwright.py <username> <password> <keyword> <target_url> <title> <image_path> <content_file>")
        sys.exit(1)
        
    livejournal_post(
        sys.argv[1],
        sys.argv[2],
        sys.argv[3],
        sys.argv[4],
        sys.argv[5],
        sys.argv[6],
        sys.argv[7]
    )
