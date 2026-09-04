#!/usr/bin/env python3
"""
Padlet Auto-Post — Pinterest-style saved profile approach
Board: https://padlet.com/kanzariyapratik124/lmt-wb7faycbn66hp2z5
Usage: python padlet_post.py <email> <password> <keyword> <target_url>
"""
import sys, json, time, os
os.environ['WDM_LOG'] = '0'
try:
    import pwd
    sys_user = pwd.getpwuid(os.getuid())[0]
except Exception:
    import getpass
    sys_user = getpass.getuser()
os.environ['WDM_DIR'] = f'/tmp/.wdm_{sys_user}'
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.action_chains import ActionChains
from webdriver_manager.chrome import ChromeDriverManager

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error=''):
    print(json.dumps({"success": success, "url": url, "error": error}), flush=True)

email      = sys.argv[1] if len(sys.argv) > 1 else "kanzariyapratik124@gmail.com"
import hashlib
email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest() if email else "default"

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
try:
    import pwd
    sys_user = pwd.getpwuid(os.getuid())[0]
except Exception:
    import getpass
    sys_user = getpass.getuser()
PROFILE_DIR = os.path.join(SCRIPT_DIR, f'chrome_profile_padlet_{email_hash}_{sys_user}')

# Pinterest-style lock cleanup
for lf in [os.path.join(PROFILE_DIR,'Default','LOCK'),
           os.path.join(PROFILE_DIR,'SingletonLock')]:
    try:
        if os.path.exists(lf): os.remove(lf)
    except: pass
os.makedirs(PROFILE_DIR, exist_ok=True)
password   = sys.argv[2] if len(sys.argv) > 2 else "@disha12@"
keyword    = sys.argv[3] if len(sys.argv) > 3 else "python training bangalore"
target_url = sys.argv[4] if len(sys.argv) > 4 else "https://learnmoretech.in"

BOARD_URL = "https://padlet.com/kanzariyapratik124/lmt-wb7faycbn66hp2z5"

# Pinterest-style driver with saved profile
def get_driver():
    opts = Options()
    if sys.platform != "win32":
        opts.add_argument('--headless=new')
    opts.add_argument('--no-sandbox')
    opts.add_argument('--disable-dev-shm-usage')
    opts.add_argument('--disable-blink-features=AutomationControlled')
    opts.add_experimental_option('excludeSwitches', ['enable-automation'])
    opts.add_experimental_option('useAutomationExtension', False)
    opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')
    opts.add_argument('--window-size=1400,900')
    opts.add_argument(f'--user-data-dir={PROFILE_DIR}')  # Persistent profile
    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    return driver

try:
    driver = get_driver()
    wait   = WebDriverWait(driver, 20)
    log("Padlet: Browser ready")

    # ── Step 1: Open board ────────────────────────────────────
    log("Padlet: Opening board...")
    driver.get(BOARD_URL)
    time.sleep(8)
    log(f"Padlet: URL = {driver.current_url}")

    # Check if logged in as owner — not guest
    src = driver.page_source.lower()
    is_owner = ("sign up" not in src or
                driver.execute_script("""
                    var btns = document.querySelectorAll('button');
                    for (var b of btns) { 
                        if ((b.innerText||'').indexOf('Post') !== -1 && b.className.indexOf('add') !== -1) return true;
                    }
                    return false;
                """))

    # Check for "Go to sign up" = not logged in
    not_logged = "go to sign up" in src

    if not_logged:
        log("Padlet: Not logged in — logging in...")
        driver.get("https://padlet.com/auth/login")
        time.sleep(6)
        log(f"Padlet: Login page = {driver.current_url}")

        # Email
        for sel in ["input[type='email']","input[name='email']","input[placeholder*='mail' i]",
                    "[data-testid='email-input']"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); time.sleep(0.3); el.send_keys(email)
                    log(f"Padlet: Email typed [{sel}]"); break
            except: continue

        # Next/Continue if needed
        for sel in ["//button[contains(text(),'Continue')]","//button[contains(text(),'Next')]",
                    "button[type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,4).until(EC.element_to_be_clickable((by,sel)))
                pw_els = driver.find_elements(By.CSS_SELECTOR,"input[type='password']")
                if pw_els and pw_els[0].is_displayed(): break
                driver.execute_script("arguments[0].click();",btn)
                time.sleep(3); break
            except: continue

        # Password
        for sel in ["input[type='password']","input[name='password']"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); time.sleep(0.3); el.send_keys(password)
                    log("Padlet: Password typed"); break
            except: continue

        # Submit
        for sel in ["button[type='submit']",
                    "//button[contains(text(),'Log in')]","//button[contains(text(),'Sign in')]",
                    "//button[contains(text(),'Continue')]"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log(f"Padlet: Login submitted: '{btn.text.strip()}'"); break
            except: continue

        time.sleep(8)
        log(f"Padlet: Post-login = {driver.current_url}")

        # Go to board
        driver.get(BOARD_URL)
        time.sleep(8)
        log(f"Padlet: Board after login = {driver.current_url}")
    else:
        log("Padlet: Already logged in!")

    # ── Step 2: Click "Post" button ───────────────────────────
    log("Padlet: Clicking Post button...")
    time.sleep(3)

    post_clicked = False

    # JS approach — find Post button by text
    try:
        clicked = driver.execute_script("""
            var btns = document.querySelectorAll('button');
            for (var b of btns) {
                var t = (b.innerText || b.textContent || '').trim();
                var cls = b.className || '';
                if (t === 'Post' && cls.indexOf('add') !== -1) {
                    b.click(); return 'post-add-' + t;
                }
            }
            // Fallback: any button with text 'Post'
            for (var b of btns) {
                var t = (b.innerText || b.textContent || '').trim();
                if (t === 'Post') {
                    b.click(); return 'post-' + t;
                }
            }
            return null;
        """)
        if clicked:
            log(f"Padlet: JS Post clicked: {clicked}")
            post_clicked = True
            time.sleep(5)
    except Exception as e:
        log(f"Padlet: JS click error: {e}")

    if not post_clicked:
        for sel in ["//button[normalize-space(text())='Post']",
                    "[aria-label='Add post']","[data-testid='add-post-button']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
                if el.is_displayed():
                    driver.execute_script("arguments[0].click();",el)
                    log(f"Padlet: Post btn clicked [{sel}]")
                    post_clicked = True; time.sleep(5); break
            except: continue

    # Debug buttons after click
    btns = driver.find_elements(By.TAG_NAME,'button')
    visible = [b.text.strip() for b in btns if b.text.strip() and b.is_displayed()]
    log(f"Padlet: Buttons after Post click = {visible[:12]}")

    # ── Step 3: Fill Title ────────────────────────────────────
    title_text = f"Best {keyword.title()} Training {time.strftime('%Y')}"
    for sel in ["[data-testid='post-title']","[placeholder*='Title' i]",
                "input[placeholder*='Title' i]","textarea[placeholder*='Title' i]"]:
        try:
            el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
            if el.is_displayed():
                el.click(); el.clear(); time.sleep(0.3)
                el.send_keys(title_text)
                log(f"Padlet: Title typed [{sel}]"); break
        except: continue

    # ── Step 4: Fill Body ─────────────────────────────────────
    body_text = (f"Best {keyword} training at Learnmore Technologies. "
                 f"Expert trainers, live projects, placement support. "
                 f"Enroll: {target_url}")
    for sel in ["[data-testid='post-body']","textarea[placeholder*='Write' i]",
                "[contenteditable='true']","textarea"]:
        try:
            el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
            if el.is_displayed():
                el.click(); time.sleep(0.3); el.send_keys(body_text)
                log(f"Padlet: Body typed [{sel}]"); break
        except: continue

    # ── Step 5: Add Link ──────────────────────────────────────
    for sel in ["input[placeholder*='url' i]","input[type='url']","input[placeholder*='http' i]"]:
        try:
            el = driver.find_element(By.CSS_SELECTOR,sel)
            if el.is_displayed():
                el.click(); el.clear(); el.send_keys(target_url)
                log("Padlet: Link typed"); break
        except: continue

    time.sleep(1)

    # ── Step 6: Click Publish ─────────────────────────────────
    for sel in ["//button[normalize-space(text())='Publish']",
                "//button[normalize-space(text())='Post']",
                "//button[normalize-space(text())='Done']",
                "[data-testid='post-publish-button']"]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver,5).until(EC.element_to_be_clickable((by,sel)))
            if btn.is_displayed() and btn.is_enabled():
                driver.execute_script("arguments[0].click();",btn)
                log(f"Padlet: Publish clicked: '{btn.text.strip()}'")
                time.sleep(3); break
        except: continue

    final = driver.current_url
    log(f"Padlet: Done! URL = {final}")
    result(True, url=BOARD_URL)

except Exception as e:
    log(f"Padlet: Error = {e}")
    result(False, error=str(e))
finally:
    try: driver.quit()
    except: pass
