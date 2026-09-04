#!/usr/bin/env python3
"""
Site123 Auto Blog Post via Selenium
Usage: python site123_add_post.py <email> <password> <keyword> <target_url>
"""
import sys, json, time, os
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.keys import Keys
from webdriver_manager.chrome import ChromeDriverManager

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error=''):
    print(json.dumps({"success": success, "url": url, "error": error}), flush=True)

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
PROFILE_DIR = os.path.join(SCRIPT_DIR, 'chrome_profile_site123')

# Clean lock files
for root, dirs, files in os.walk(PROFILE_DIR):
    if root.replace(PROFILE_DIR, '').count(os.sep) > 2: continue
    for f in files:
        if f.lower() in ('lock', 'singletonlock', 'singletonsocket'):
            try: os.remove(os.path.join(root, f))
            except: pass
os.makedirs(PROFILE_DIR, exist_ok=True)

email      = sys.argv[1] if len(sys.argv) > 1 else "kanzariyapratik124@gmail.com"
password   = sys.argv[2] if len(sys.argv) > 2 else "@DISHA12@"
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
        log(f"Site123: Content loaded from file ({len(ai_content)} chars)")
    except Exception as e:
        ai_content = ""
else:
    ai_content = ""

ADD_POST_URL = "https://app.site123.com/versions/2/wizard/modules/blog/addItem.php?w=12200919&moduleID=6a30edf702ebc"
BLOG_URL     = "https://app.site123.com/blog?w=12200919"

log("Site123: Starting...")

opts = Options()
opts.add_argument('--headless=new')
opts.add_argument('--disable-gpu')
opts.add_argument('--no-sandbox')
opts.add_argument('--disable-dev-shm-usage')
opts.add_argument('--disable-blink-features=AutomationControlled')
opts.add_experimental_option('excludeSwitches', ['enable-automation'])
opts.add_experimental_option('useAutomationExtension', False)
opts.add_argument(f'--user-data-dir={PROFILE_DIR}')
opts.add_argument('--profile-directory=Default')
opts.add_argument('--window-size=1400,900')
opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')

try:
    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    log("Site123: Browser ready")
except Exception as e:
    result(False, error=f"Browser failed: {e}")
    sys.exit(1)

try:
    # ── Step 1: Open Add Post page ────────────────────────────
    log("Site123: Opening add post URL...")
    driver.get(ADD_POST_URL)
    time.sleep(7)
    log(f"Site123: URL = {driver.current_url}")

    # Login if redirected
    if 'login' in driver.current_url.lower():
        log("Site123: Logging in...")
        driver.get("https://app.site123.com/manager/login/login.php?l=en")
        time.sleep(5)
        try:
            u = driver.find_element(By.CSS_SELECTOR, "input[name='username']")
            u.click(); u.clear(); u.send_keys(email)
        except: pass
        try:
            p = driver.find_element(By.CSS_SELECTOR, "input[name='password']")
            p.click(); p.clear(); p.send_keys(password)
        except: pass
        try:
            btn = driver.find_element(By.XPATH, "//button[normalize-space(text())='Login']")
            driver.execute_script("arguments[0].click();", btn)
            time.sleep(8)
        except: pass
        log(f"Site123: After login = {driver.current_url}")
        driver.get(ADD_POST_URL)
        time.sleep(7)

    log(f"Site123: Page title = {driver.title}")

    # ── Step 2: Switch to form iframe ────────────────────────
    frames = driver.find_elements(By.TAG_NAME, 'iframe')
    form_frame = None
    for f in frames:
        src = f.get_attribute('src') or ''
        if 'addItem' in src or '12200919' in src:
            form_frame = f; break
    if not form_frame and frames:
        form_frame = frames[0]

    if form_frame:
        driver.switch_to.frame(form_frame)
        log("Site123: Switched to form iframe")
        time.sleep(2)

    # ── Step 3: Fill Title (textarea[name='title']) ───────────
    title_text = ai_title if ai_title else f"Best {keyword.title()} - Complete Guide {time.strftime('%Y')}"
    for sel in ["textarea[name='title']", "input[name='title']", "input[type='text']"]:
        try:
            el = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
            if el.is_displayed():
                el.click(); el.clear(); time.sleep(0.3)
                el.send_keys(title_text)
                log(f"Site123: Title typed: {title_text}")
                break
        except: continue

    # ── Step 3b: Upload featured image if provided ───────────
    if image_path and os.path.exists(image_path):
        log(f"Site123: Uploading image: {image_path}")
        for img_sel in [
            "input[type='file'][accept*='image']",
            "input[type='file']",
            "[class*='upload'] input[type='file']",
        ]:
            try:
                fi = driver.find_element(By.CSS_SELECTOR, img_sel)
                driver.execute_script("arguments[0].style.display='block';", fi)
                fi.send_keys(os.path.abspath(image_path))
                log("Site123: Image uploaded")
                time.sleep(3)
                break
            except: continue
    content_text = ai_content if ai_content else (
        f"Best {keyword} at Learnmore Technologies — Complete Guide {time.strftime('%Y')}\n\n"
        f"Are you looking for the best {keyword} in Bangalore? Learnmore Technologies offers industry-leading {keyword} programs with expert trainers and 100% placement support.\n\n"
        f"Why Learn {keyword}?\n"
        f"- High salary potential: ₹4–20 LPA\n"
        f"- Huge job demand across India and globally\n"
        f"- Industry-recognised certification\n"
        f"- Hands-on live projects with real data\n"
        f"- Flexible batch timings — weekday and weekend\n"
        f"- 100% placement assistance included\n\n"
        f"What You Will Learn:\n"
        f"Our comprehensive {keyword} program covers fundamentals to advanced topics, ensuring you are job-ready within 60–90 days.\n\n"
        f"Enroll now at: {target_url}\n\n"
        f"Why Choose Learnmore Technologies?\n"
        f"Expert trainers with 10+ years experience, small batches for personal attention, and proven placement results. Visit us at Kalyan Nagar, Bangalore.\n\n"
        f"Start your {keyword} journey today: {target_url}"
    )
    for sel in ["div.fr-element", "div.fr-view", ".fr-element.fr-view",
                "[contenteditable='true']", ".ql-editor", ".ProseMirror", "textarea"]:
        try:
            el = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
            if el.is_displayed():
                el.click(); time.sleep(0.5)
                el.send_keys(content_text[:800])
                log(f"Site123: Content typed [{sel}]")
                break
        except: continue

    # ── Step 5: Tags ──────────────────────────────────────────
    for sel in ["input[placeholder='Tags']", "input[name='tags']"]:
        try:
            el = driver.find_element(By.CSS_SELECTOR, sel)
            if el.is_displayed():
                el.click(); el.send_keys(keyword.replace(' ', ','))
                break
        except: continue

    time.sleep(1)

    # ── Step 6: Save ──────────────────────────────────────────
    for sel in [
        "//button[normalize-space(text())='Save']",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SAVE')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'PUBLISH')]",
        "button[type='submit']",
    ]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((by, sel)))
            if btn.is_displayed() and btn.is_enabled():
                log(f"Site123: Save: '{btn.text.strip()}'")
                driver.execute_script("arguments[0].click();", btn)
                time.sleep(6); break
        except: continue

    try: driver.switch_to.default_content()
    except: pass

    log(f"Site123: Done! URL = {driver.current_url}")
    result(True, url=BLOG_URL)

except Exception as e:
    log(f"Site123: Error = {e}")
    result(False, error=str(e))
finally:
    try: driver.quit()
    except: pass
