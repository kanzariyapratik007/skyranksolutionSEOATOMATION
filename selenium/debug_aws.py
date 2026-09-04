#!/usr/bin/env python3
import sys
import os
import time
import requests
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.keys import Keys
from webdriver_manager.chrome import ChromeDriverManager

username = sys.argv[1] if len(sys.argv) > 1 else ""
password = sys.argv[2] if len(sys.argv) > 2 else ""
keyword = sys.argv[3] if len(sys.argv) > 3 else "technical SEO audit consultant"
target_url = sys.argv[4] if len(sys.argv) > 4 else "https://skyranksolution.livejournal.com/"

title_text = f"Unlock {keyword} Guide {time.strftime('%Y')}"
content_text = f"<p>This is a test blog post for {keyword}. Visit us at <a href='{target_url}'>SkyRank Solution</a> for details.</p>"

def log(msg):
    print(f"[DEBUG LOG] {msg}", flush=True)

def api_login(user, pwd):
    session = requests.Session()
    session.headers.update({
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    })
    challenge_url = "https://www.livejournal.com/interface/flat"
    try:
        r = session.post(challenge_url, data={"mode": "getchallenge"}, timeout=30)
        lines = [line.strip() for line in r.text.split("\n") if line.strip()]
        data = {}
        for i in range(0, len(lines) - 1, 2):
            data[lines[i]] = lines[i+1]
        chal = data.get("challenge")
        if not chal:
            return None, "No challenge returned"
            
        import hashlib
        pw_hash = hashlib.md5(pwd.encode('utf-8')).hexdigest()
        auth_response = hashlib.md5((chal + pw_hash).encode('utf-8')).hexdigest()
        
        r2 = session.post(challenge_url, data={
            "mode": "sessiongenerate",
            "user": user,
            "auth_method": "challenge",
            "auth_challenge": chal,
            "auth_response": auth_response,
            "clientversion": "Python-Autopost"
        }, timeout=30)
        lines2 = [line.strip() for line in r2.text.split("\n") if line.strip()]
        data2 = {}
        for i in range(0, len(lines2) - 1, 2):
            data2[lines2[i]] = lines2[i+1]
            
        if data2.get("success") == "OK":
            return data2.get("ljsession"), None
        return None, data2.get("errmsg", "Unknown error")
    except Exception as e:
        return None, str(e)

# Start
SCRATCH_DIR = "/var/www/html/scratch"
os.makedirs(SCRATCH_DIR, exist_ok=True)

log(f"Starting debug post. User: {username}")
ljsession_val, err = api_login(username, password)
if err:
    log(f"API Login Failed: {err}")
    sys.exit(1)

log("API Login success!")

opts = Options()
opts.add_argument('--headless=new')
opts.add_argument('--no-sandbox')
opts.add_argument('--disable-dev-shm-usage')
opts.add_argument('--window-size=1400,900')

driver = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=opts)

try:
    log("Navigating to robots.txt to set domain...")
    driver.get("https://www.livejournal.com/robots.txt")
    time.sleep(2)
    
    log("Injecting cookie...")
    driver.add_cookie({
        "name": "ljsession",
        "value": ljsession_val,
        "domain": ".livejournal.com",
        "path": "/"
    })
    
    log("Navigating to m.livejournal.com to settle...")
    driver.get("https://m.livejournal.com/")
    time.sleep(3)
    
    log("Navigating to editor page...")
    driver.get("https://www.livejournal.com/update.bml")
    time.sleep(8)
    driver.save_screenshot(f"{SCRATCH_DIR}/01_editor_loaded.png")
    
    # 1. Fill title
    log("Filling title...")
    title_el = WebDriverWait(driver, 10).until(
        EC.presence_of_element_located((By.CSS_SELECTOR, "textarea[placeholder='Title']"))
    )
    title_el.click()
    title_el.clear()
    title_el.send_keys(title_text)
    time.sleep(1)
    driver.save_screenshot(f"{SCRATCH_DIR}/02_title_filled.png")
    
    # 2. Fill content
    log("Filling content...")
    content_el = WebDriverWait(driver, 10).until(
        EC.presence_of_element_located((By.CSS_SELECTOR, ".public-DraftEditor-content"))
    )
    content_el.click()
    time.sleep(0.5)
    
    # Paste via ClipboardEvent
    driver.execute_script("""
        var el = arguments[0];
        var html = arguments[1];
        var dt = new DataTransfer();
        dt.setData('text/html', html);
        dt.setData('text/plain', el.innerText);
        var event = new ClipboardEvent('paste', {
            clipboardData: dt,
            bubbles: true,
            cancelable: true
        });
        el.dispatchEvent(event);
    """, content_el, content_text)
    
    # Sync React state
    content_el.send_keys(" ")
    time.sleep(0.2)
    content_el.send_keys(Keys.BACKSPACE)
    time.sleep(2)
    driver.save_screenshot(f"{SCRATCH_DIR}/03_content_filled.png")
    
    # 3. Click "Tune in and publish"
    log("Clicking first publish button...")
    clicked_text = driver.execute_script("""
        var btns = document.querySelectorAll('button');
        for (var i = 0; i < btns.length; i++) {
            var t = btns[i].innerText || btns[i].textContent || '';
            if (t.toLowerCase().indexOf('publish') !== -1 || t.toLowerCase().indexOf('tune') !== -1) {
                btns[i].click();
                return t;
            }
        }
        return null;
    """)
    log(f"Clicked button text: {clicked_text}")
    time.sleep(4)
    driver.save_screenshot(f"{SCRATCH_DIR}/04_after_publish_click.png")
    
    # 4. Handle confirm popup
    log("Waiting for confirm popup button...")
    confirm_btn = WebDriverWait(driver, 10).until(
        EC.presence_of_element_located((By.CSS_SELECTOR, ".js--submit-post"))
    )
    
    is_disabled = confirm_btn.get_attribute("disabled") or "disabled" in (confirm_btn.get_attribute("class") or "")
    log(f"Confirm button class: {confirm_btn.get_attribute('class')}")
    log(f"Confirm button disabled state: {is_disabled}")
    
    # Click natively
    confirm_btn.click()
    log("Native click performed on confirm button.")
    time.sleep(6)
    driver.save_screenshot(f"{SCRATCH_DIR}/05_after_confirm_click.png")
    
    log(f"Final URL: {driver.current_url}")
    
except Exception as e:
    log(f"Error occurred: {e}")
finally:
    driver.quit()
