#!/usr/bin/env python3
"""
4Shared Auto-Upload PDF via Selenium — Pinterest-style saved profile
Usage: python fourshared_upload.py <email> <password> <pdf_path> <keyword>
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
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from webdriver_manager.chrome import ChromeDriverManager

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error=''):
    print(json.dumps({"success": success, "url": url, "error": error}), flush=True)

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
PROFILE_DIR = os.path.join(SCRIPT_DIR, 'chrome_profile_4shared')

for lf in [os.path.join(PROFILE_DIR,'Default','LOCK'),
           os.path.join(PROFILE_DIR,'SingletonLock')]:
    try:
        if os.path.exists(lf): os.remove(lf)
    except: pass
os.makedirs(PROFILE_DIR, exist_ok=True)

email    = sys.argv[1] if len(sys.argv) > 1 else "kanzariyapratik124@gmail.com"
password = sys.argv[2] if len(sys.argv) > 2 else "@disha12@"
pdf_path = sys.argv[3] if len(sys.argv) > 3 else ""
keyword  = sys.argv[4] if len(sys.argv) > 4 else "python training bangalore"

if not pdf_path or not os.path.exists(pdf_path):
    result(False, error=f"4Shared: PDF not found: {pdf_path}")
    sys.exit(1)

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
    # No profile — fresh session every time (avoids lock/corruption issues)
    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    return driver

try:
    driver = get_driver()
    wait   = WebDriverWait(driver, 20)
    log("4Shared: Browser ready")

    # ── Step 1: Open 4Shared ──────────────────────────────────
    driver.get("https://www.4shared.com/")
    time.sleep(6)
    log(f"4Shared: URL = {driver.current_url}")

    src = driver.page_source.lower()
    not_logged = ("sign in" in src and "logout" not in src and "log out" not in src)

    # Always try to login fresh (4Shared session is unreliable in profile)
    log("4Shared: Logging in...")
    driver.get("https://www.4shared.com/login.jsp")
    time.sleep(5)

    # Always login fresh for 4Shared
    log("4Shared: Logging in...")
    driver.get("https://www.4shared.com/login.jsp")
    time.sleep(6)
    log(f"4Shared: Login page = {driver.current_url}")

    src2 = driver.page_source.lower()
    if "logout" in src2 or "account/home" in driver.current_url.lower():
        log("4Shared: Already logged in!")
    else:
        # Debug inputs
        all_inputs = driver.find_elements(By.TAG_NAME, 'input')
        for inp in all_inputs:
            if inp.is_displayed():
                log(f"  Input: type={inp.get_attribute('type')} name={inp.get_attribute('name')} id={inp.get_attribute('id')}")

        # Email — 4Shared uses name='login'
        for sel in ["input[name='login']","input[id='login']","input[type='email']",
                    "input[placeholder*='email' i]","input[placeholder*='login' i]"]:
            try:
                el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); time.sleep(0.5)
                    el.send_keys(email)
                    log(f"4Shared: Email typed [{sel}] = {el.get_attribute('value')}"); break
            except: continue

        # Password
        for sel in ["input[name='password']","input[id='password']","input[type='password']"]:
            try:
                el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); time.sleep(0.5)
                    el.send_keys(password)
                    log(f"4Shared: Password typed, len={len(el.get_attribute('value') or '')}"); break
            except: continue

        time.sleep(0.5)

        # Submit — 4Shared uses input[type='submit'] with value='Login'
        for sel in ["input[type='submit']","button[type='submit']",
                    "//input[@value='Login']","//input[@type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    log(f"4Shared: Clicking submit: '{btn.get_attribute('value') or btn.text}'")
                    driver.execute_script("arguments[0].click();",btn)
                    log("4Shared: Login submitted"); break
            except: continue

        time.sleep(10)
        log(f"4Shared: Post-login = {driver.current_url}")

    # ── Step 2: Go to account home and upload ────────────────
    log("4Shared: Going to account home...")
    driver.get("https://www.4shared.com/account/home.jsp")
    time.sleep(8)
    log(f"4Shared: Account = {driver.current_url}")

    # Debug — show all visible buttons and links
    all_btns = driver.find_elements(By.TAG_NAME, 'button')
    all_links = driver.find_elements(By.TAG_NAME, 'a')
    visible_btns = [b.text.strip() for b in all_btns if b.text.strip() and b.is_displayed()]
    visible_links = [(l.text.strip(), l.get_attribute('href') or '') for l in all_links
                     if l.text.strip() and l.is_displayed() and 'upload' in (l.text.strip() + (l.get_attribute('href') or '')).lower()]
    log(f"4Shared: Buttons = {visible_btns[:10]}")
    log(f"4Shared: Upload links = {visible_links[:5]}")

    # Try JS upload via direct file input
    # 4Shared uses Angular — look for file input
    file_inputs = driver.find_elements(By.CSS_SELECTOR, "input[type='file']")
    log(f"4Shared: File inputs = {len(file_inputs)}")
    if file_inputs:
        for fi in file_inputs:
            try:
                fi.send_keys(os.path.abspath(pdf_path))
                log(f"4Shared: PDF sent to file input directly")
                time.sleep(12)
                break
            except Exception as fe:
                log(f"4Shared: file input error: {fe}")

    driver.save_screenshot(os.path.join(SCRIPT_DIR,'4shared_account.png'))

    # ── Step 3: Click Upload button ───────────────────────────
    for sel in [
        "//button[contains(text(),'Upload')]","//a[contains(text(),'Upload')]",
        "[class*='upload']","[id*='upload']","[data-action*='upload']",
        "//button[contains(text(),'Add')]",
    ]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
            if el.is_displayed():
                driver.execute_script("arguments[0].click();",el)
                log(f"4Shared: Upload btn clicked: '{el.text.strip()}'")
                time.sleep(3); break
        except: continue

    # ── Step 4: Send file to file input ───────────────────────
    for sel in ["input[type='file']","input[accept*='pdf']","input[accept*='*']"]:
        try:
            el = driver.find_element(By.CSS_SELECTOR,sel)
            el.send_keys(os.path.abspath(pdf_path))
            log(f"4Shared: File sent: {os.path.basename(pdf_path)}")
            time.sleep(10)  # Wait for upload
            break
        except: continue

    # ── Step 5: Get share link ────────────────────────────────
    driver.save_screenshot(os.path.join(SCRIPT_DIR,'4shared_uploaded.png'))

    # Look for success notification or share link
    share_url = ""
    for sel in [
        "a[href*='4shared.com/file']","a[href*='4shared.com/doc']",
        "[class*='share-link']","[class*='file-link']",
    ]:
        try:
            el = WebDriverWait(driver,10).until(EC.presence_of_element_located((By.CSS_SELECTOR,sel)))
            href = el.get_attribute('href') or ''
            if '4shared.com' in href:
                share_url = href
                log(f"4Shared: Share URL = {share_url}")
                break
        except: continue

    if not share_url:
        share_url = f"https://www.4shared.com/web/myFiles"

    log(f"4Shared: Final URL = {driver.current_url}")
    result(True, url=share_url if share_url else "https://www.4shared.com/web/myFiles")

except Exception as e:
    log(f"4Shared: Error = {e}")
    result(False, error=str(e))
finally:
    try: driver.quit()
    except: pass
