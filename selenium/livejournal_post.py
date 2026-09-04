#!/usr/bin/env python3
"""
LiveJournal Auto-Post via Official XML-RPC API
Bypasses Chrome/Selenium browser automation to avoid WAF/CAPTCHAs, out-of-memory crashes, and Draft.js React state synchronization errors.
Usage: python livejournal_post.py <username> <password> <keyword> <target_url> <title> <image> <content_file>
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

try:
    if len(sys.argv) < 3:
        result(False, error="LiveJournal: Missing username or password arguments.")
        sys.exit(1)

    username   = sys.argv[1]
    password   = sys.argv[2]
    keyword    = sys.argv[3] if len(sys.argv) > 3 else ""
    target_url = sys.argv[4] if len(sys.argv) > 4 else ""
    ai_title   = sys.argv[5] if len(sys.argv) > 5 else ""
    image_path = sys.argv[6] if len(sys.argv) > 6 else ""
    _content_file = sys.argv[7] if len(sys.argv) > 7 else ""

    if _content_file and os.path.exists(_content_file):
        with open(_content_file, 'r', encoding='utf-8') as f:
            ai_content = f.read()
        log(f"LiveJournal: Content loaded from file ({len(ai_content)} chars)")
    else:
        ai_content = f"<p>This is a post about {keyword}. Learn more at <a href='{target_url}'>{target_url}</a>.</p>"

    # Ensure title is not empty
    if not ai_title:
        ai_title = f"Guide on {keyword}"

    log("LiveJournal: Connecting to XML-RPC server...")
    server = xmlrpc.client.ServerProxy("https://www.livejournal.com/interface/xmlrpc")

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
