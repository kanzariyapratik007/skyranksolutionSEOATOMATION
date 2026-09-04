#!/usr/bin/env python3
"""
Medium Auto-Post via Selenium — Profile-based session
Usage: python medium_post.py <email> <password> <keyword> <target_site>
"""
import sys, json, time, os
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

email      = sys.argv[1] if len(sys.argv) > 1 else ""
import hashlib
email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest() if email else "default"

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
try:
    import pwd
    sys_user = pwd.getpwuid(os.getuid())[0]
except Exception:
    import getpass
    sys_user = getpass.getuser()
PROFILE_DIR = os.path.join(SCRIPT_DIR, f'chrome_profile_medium_{email_hash}_{sys_user}')

# Clean lock files in selenium profile
for root, dirs, files in os.walk(PROFILE_DIR):
    if root.replace(PROFILE_DIR, '').count(os.sep) > 2: continue
    for f in files:
        if f.lower() in ('lock', 'singletonlock', 'singletonsocket'):
            try: os.remove(os.path.join(root, f))
            except: pass
os.makedirs(PROFILE_DIR, exist_ok=True)

# Always use selenium profile (copy of system profile)
USE_SYSTEM_PROFILE = False
password   = sys.argv[2] if len(sys.argv) > 2 else ""
keyword    = sys.argv[3] if len(sys.argv) > 3 else "python training bangalore"
target_url = sys.argv[4] if len(sys.argv) > 4 else "https://learnmoretech.in"
ai_title   = sys.argv[5] if len(sys.argv) > 5 else ""
image_path = sys.argv[6] if len(sys.argv) > 6 else ""
# argv[7] = temp file path containing full content
_content_file = sys.argv[7] if len(sys.argv) > 7 else ""
if _content_file and os.path.exists(_content_file):
    try:
        with open(_content_file, 'r', encoding='utf-8') as _f:
            ai_content = _f.read()
        log(f"Medium: Content loaded from file ({len(ai_content)} chars)")
    except Exception as e:
        ai_content = ""
else:
    ai_content = ""

# Remove duplicate consecutive words from keyword
def clean_keyword(kw):
    words = kw.split()
    result, prev = [], ''
    for w in words:
        if w.lower() != prev.lower():
            result.append(w)
        prev = w
    return ' '.join(result)

keyword = clean_keyword(keyword)

title_text = ai_title if ai_title else f"Best {keyword.title()} - Complete Guide {time.strftime('%Y')}"
content_text = ai_content if ai_content else (
    f"Best {keyword} course at Learnmore Technologies.\n\n"
    f"Our comprehensive {keyword} program covers:\n\n"
    f"Expert trainers with industry experience\n"
    f"Hands-on live projects and real-world scenarios\n"
    f"Placement support and career guidance\n"
    f"Flexible batch timings\n\n"
    f"Why choose Learnmore Technologies?\n\n"
    f"We provide the best {keyword} with proven results. "
    f"Our students get placed in top companies.\n\n"
    f"Enroll now at: {target_url}\n\n"
    f"Visit us: {target_url}\n\n"
    f"#{keyword.replace(' ','').replace('Training','').replace('Course','').strip()} #Bangalore #Education #Career"
)

log("Medium: Starting...")

opts = Options()
opts.add_argument('--no-sandbox')
opts.add_argument('--disable-dev-shm-usage')
opts.add_argument('--disable-blink-features=AutomationControlled')
opts.add_experimental_option('excludeSwitches', ['enable-automation'])
opts.add_experimental_option('useAutomationExtension', False)
opts.add_argument(f'--user-data-dir={PROFILE_DIR}')
opts.add_argument('--profile-directory=Default')
log(f"Medium: Using selenium profile: {PROFILE_DIR}")
opts.add_argument('--window-size=1400,900')
opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')
opts.add_argument('--disable-extensions')

try:
    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    log("Medium: Browser ready")
except Exception as e:
    result(False, error=f"Browser failed: {e}"); sys.exit(1)

try:
    # ── Step 1: Open new-story page ───────────────────────────
    log("Medium: Opening new-story page...")
    driver.get("https://medium.com/new-story")
    time.sleep(8)
    log(f"Medium: URL = {driver.current_url}")

    # If redirected to signin — try to login
    if "signin" in driver.current_url or "m/signin" in driver.current_url:
        log("Medium: Not logged in — attempting login...")

        # Click Sign in with Google
        try:
            google_link = WebDriverWait(driver, 8).until(
                EC.element_to_be_clickable((By.XPATH, "//a[contains(@href,'connect/google')]")))
            driver.execute_script("arguments[0].click();", google_link)
            log("Medium: Google sign-in clicked")
            time.sleep(8)
        except Exception as e:
            log(f"Medium: Google link error: {e}")
            # Try direct auth URL
            auth_url = ("https://medium.com/m/connect/google?state=google-%7Chttps%3A%2F%2Fmedium.com"
                       "%2Fnew-story%3Fsource%3Dlogin--------------------------------------------%7Clogin"
                       "%7Cremember_me&source=login--------------------------------------------")
            driver.get(auth_url)
            time.sleep(8)

        # Check if Google auth page
        if "accounts.google.com" in driver.current_url:
            log("Medium: Google auth page — filling credentials...")
            try:
                em = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((By.CSS_SELECTOR, "input[type='email']")))
                em.click(); em.clear(); em.send_keys(email)
                nb = driver.find_element(By.CSS_SELECTOR, "#identifierNext button, [id='identifierNext']")
                driver.execute_script("arguments[0].click();", nb)
                time.sleep(4)
                pw = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((By.CSS_SELECTOR, "input[type='password']")))
                pw.click(); pw.clear(); pw.send_keys(password)
                pb = driver.find_element(By.CSS_SELECTOR, "#passwordNext button, [id='passwordNext']")
                driver.execute_script("arguments[0].click();", pb)
                time.sleep(10)
                log(f"Medium: After Google auth = {driver.current_url}")
            except Exception as e:
                log(f"Medium: Google auth error: {e}")

        # Navigate to new-story after login
        driver.get("https://medium.com/new-story")
        time.sleep(8)
        log(f"Medium: After login URL = {driver.current_url}")

    if "signin" in driver.current_url or "login" in driver.current_url:
        result(False, error="Medium: Login failed. Run medium_login.py first to save session manually.")
        driver.quit(); sys.exit(1)

    log(f"Medium: Editor page = {driver.current_url}")
    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'medium_editor.png'))

    # ── Step 2: Fill Title ────────────────────────────────────
    # Medium uses contenteditable h3 for title
    title_filled = False
    for sel in [
        "h3[data-testid='editorTitle']",
        "h3[contenteditable='true']",
        "[data-testid='editorTitle']",
        "h3.graf--title",
        "div.postField--title h3",
        "h3[placeholder*='Title']",
        "h3",
    ]:
        try:
            el = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
            if el.is_displayed():
                driver.execute_script("arguments[0].click();", el)
                time.sleep(0.5)
                el.send_keys(title_text)
                log(f"Medium: Title typed [{sel}]")
                title_filled = True
                break
        except: continue

    if not title_filled:
        # Try pressing Enter first, then find title area
        try:
            body = driver.find_element(By.TAG_NAME, 'body')
            body.click(); time.sleep(0.5)
            body.send_keys(title_text)
            log("Medium: Title typed via body")
            title_filled = True
        except: pass

    # ── Step 2b: Upload image if provided ────────────────────
    if image_path and os.path.exists(image_path):
        log(f"Medium: Uploading image: {image_path}")
        try:
            driver.find_element(By.TAG_NAME, 'body').send_keys(Keys.RETURN)
            time.sleep(1)
            # Try image upload via file input
            file_inputs = driver.find_elements(By.CSS_SELECTOR, "input[type='file']")
            for fi in file_inputs:
                try:
                    driver.execute_script("arguments[0].style.display='block';", fi)
                    fi.send_keys(os.path.abspath(image_path))
                    log("Medium: Image uploaded via file input")
                    time.sleep(4)
                    break
                except: continue
        except Exception as e:
            log(f"Medium: Image upload skipped: {e}")

    # Press Enter to move to content area
    try:
        driver.find_element(By.TAG_NAME, 'body').send_keys(Keys.RETURN)
        time.sleep(0.5)
    except: pass

    # ── Step 3: Fill Content ──────────────────────────────────
    for sel in [
        "p.graf--p[contenteditable='true']",
        "[contenteditable='true']:not(h3):not(h1):not(h2)",
        "div.ProseMirror p",
        ".notranslate[contenteditable='true']",
        "[data-testid='editorBody']",
        "div[role='textbox']",
    ]:
        try:
            els = driver.find_elements(By.CSS_SELECTOR, sel)
            # Get the last/bottom contenteditable (content area, not title)
            for el in reversed(els):
                if el.is_displayed() and el != driver.find_elements(By.CSS_SELECTOR, 'h3')[-1] if driver.find_elements(By.CSS_SELECTOR, 'h3') else True:
                    driver.execute_script("arguments[0].click();", el)
                    time.sleep(0.5)
                    # Type content line by line
                    for line in content_text.split('\n'):
                        el.send_keys(line)
                        el.send_keys(Keys.RETURN)
                        time.sleep(0.05)
                    log(f"Medium: Content typed [{sel}]")
                    break
            else: continue
            break
        except: continue

    time.sleep(2)

    # ── Step 4: Publish ───────────────────────────────────────
    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'medium_before_publish.png'))

    # Click Publish button
    published = False
    for sel in [
        "//button[normalize-space(text())='Publish']",
        "//button[contains(text(),'Publish')]",
        "[data-testid='publishButton']",
        "button[data-action='publish']",
    ]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((by, sel)))
            if btn.is_displayed():
                driver.execute_script("arguments[0].click();", btn)
                log(f"Medium: Publish clicked: '{btn.text.strip()}'")
                time.sleep(4)
                published = True
                break
        except: continue

    # Confirm publish dialog
    if published:
        for sel in [
            "//button[contains(text(),'Publish now')]",
            "//button[contains(text(),'Publish story')]",
            "//button[contains(text(),'Confirm')]",
            "[data-testid='publishNow']",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((by, sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();", btn)
                    log(f"Medium: Confirm publish: '{btn.text.strip()}'")
                    time.sleep(6)
                    break
            except: continue

    # ── Step 5: Get URL ───────────────────────────────────────
    time.sleep(5)
    final = driver.current_url
    log(f"Medium: Final URL = {final}")
    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'medium_published.png'))

    if "medium.com" in final and "new-story" not in final:
        result(True, url=final)
    elif published:
        result(True, url="https://medium.com/me/stories/public")
    else:
        result(False, error="Medium: Could not publish. Login with medium_login.py first.")

except Exception as e:
    log(f"Medium: Error = {e}")
    result(False, error=str(e))
finally:
    try: driver.quit()
    except: pass
