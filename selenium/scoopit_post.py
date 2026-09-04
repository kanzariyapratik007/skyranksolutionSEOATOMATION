#!/usr/bin/env python3
"""
Scoop.it Auto-Post — Pinterest-style saved profile
Flow: Login → Topic page → PUBLISH dropdown → URL input → metadata fetch → Publish
Usage: python scoopit_post.py <email> <password> <keyword> <target_url>
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

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
PROFILE_DIR = os.path.join(SCRIPT_DIR, 'chrome_profile_scoopit')

for lf in [os.path.join(PROFILE_DIR,'Default','LOCK'),
           os.path.join(PROFILE_DIR,'SingletonLock')]:
    try:
        if os.path.exists(lf): os.remove(lf)
    except: pass
os.makedirs(PROFILE_DIR, exist_ok=True)

email      = sys.argv[1] if len(sys.argv) > 1 else "kanzariyapratik124@gmail.com"
password   = sys.argv[2] if len(sys.argv) > 2 else "@Disha12@"
keyword    = sys.argv[3] if len(sys.argv) > 3 else "python training bangalore"
target_url = sys.argv[4] if len(sys.argv) > 4 else "https://learnmoretech.in"

TOPIC_URL = "https://www.scoop.it/topic/lmt-by-pratik-kanzariya"

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
    opts.add_argument(f'--user-data-dir={PROFILE_DIR}')
    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    return driver

try:
    driver = get_driver()
    wait   = WebDriverWait(driver, 20)
    log("Scoop.it: Browser ready")

    # ── Step 1: Open topic page ───────────────────────────────
    log("Scoop.it: Opening topic page...")
    driver.get(TOPIC_URL)
    time.sleep(7)
    log(f"Scoop.it: URL = {driver.current_url}")

    # Login if needed
    if "login" in driver.current_url.lower():
        log("Scoop.it: Logging in...")
        # Accept cookies
        for csel in ["//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'OK, ACCEPT ALL')]"]:
            try:
                btn = WebDriverWait(driver,4).until(EC.element_to_be_clickable((By.XPATH,csel)))
                driver.execute_script("arguments[0].click();",btn); time.sleep(2); break
            except: continue

        # Email (type=text, not email — Scoop.it Angular form)
        for inp in driver.find_elements(By.CSS_SELECTOR,"input[type='text']"):
            if (inp.get_attribute('name') or '') == 'q': continue
            if inp.is_displayed():
                inp.click(); inp.clear(); time.sleep(0.3); inp.send_keys(email)
                log("Scoop.it: Email typed"); break

        # Password
        for pw in driver.find_elements(By.CSS_SELECTOR,"input[type='password']"):
            if pw.is_displayed():
                pw.click(); pw.clear(); time.sleep(0.3); pw.send_keys(password)
                log("Scoop.it: Password typed"); break

        # Login button
        for sel in ["//button[normalize-space(text())='Login']","button[type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
                driver.execute_script("arguments[0].click();",btn)
                log("Scoop.it: Login clicked"); break
            except: continue

        time.sleep(10)
        log(f"Scoop.it: Post-login = {driver.current_url}")
        if "login" in driver.current_url.lower():
            result(False, error="Scoop.it: Login failed. Run scoopit_login.py first.")
            driver.quit(); sys.exit(1)
        driver.get(TOPIC_URL)
        time.sleep(6)
    else:
        log("Scoop.it: Already logged in!")

    # ── Step 2: Click PUBLISH dropdown link ───────────────────
    log("Scoop.it: Clicking PUBLISH dropdown...")
    for sel in ["//a[contains(text(),'PUBLISH')]",
                "//a[normalize-space(text())='PUBLISH']",
                "a.dropdownArrow"]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
            if el.is_displayed():
                driver.execute_script("arguments[0].click();",el)
                log(f"Scoop.it: PUBLISH clicked: '{el.text.strip()}'")
                time.sleep(3); break
        except: continue

    # ── Step 3: Type URL in urlToResolve input ────────────────
    log(f"Scoop.it: Entering URL: {target_url}")
    for sel in ["input[name='urlToResolve']","input[placeholder*='link' i]","#urlChooserField"]:
        try:
            el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
            if el.is_displayed():
                el.click(); el.clear(); time.sleep(0.5)
                el.send_keys(target_url)
                log(f"Scoop.it: URL typed [{sel}]")
                time.sleep(0.5)

                # Click the arrow/button next to input or press Enter
                # Try sibling submit button
                parent = driver.execute_script("return arguments[0].parentElement;", el)
                if parent:
                    submit_btns = parent.find_elements(By.CSS_SELECTOR,"button,input[type='submit']") if hasattr(parent,'find_elements') else []
                    for sb in submit_btns:
                        try:
                            if sb.is_displayed():
                                driver.execute_script("arguments[0].click();",sb)
                                log("Scoop.it: Submit btn in parent clicked")
                                break
                        except: continue

                # Also try JS click on any adjacent button
                clicked = driver.execute_script("""
                    var inp = document.querySelector("input[name='urlToResolve']");
                    if (inp) {
                        var parent = inp.closest('form') || inp.parentElement;
                        var btn = parent ? parent.querySelector('button,input[type=submit]') : null;
                        if (btn) { btn.click(); return btn.textContent || 'btn clicked'; }
                    }
                    return null;
                """)
                if clicked:
                    log(f"Scoop.it: JS submit: {clicked}")

                # Press Enter as final fallback
                el.send_keys(Keys.RETURN)
                log("Scoop.it: Enter pressed on URL input")
                time.sleep(8)  # Wait for metadata fetch
                break
        except: continue

    driver.save_screenshot(os.path.join(SCRIPT_DIR,'scoopit_after_url.png'))

    # ── Step 4: Check what appeared ──────────────────────────
    btns = driver.find_elements(By.TAG_NAME,'button')
    visible = [b.text.strip() for b in btns if b.text.strip() and b.is_displayed()]
    log(f"Scoop.it: Buttons after URL = {visible[:15]}")

    inputs2 = driver.find_elements(By.TAG_NAME,'input')
    visible_inputs = [(i.get_attribute('name'),i.get_attribute('placeholder')) for i in inputs2 if i.is_displayed()]
    log(f"Scoop.it: Inputs after URL = {visible_inputs[:8]}")

    # ── Step 5: Click final Publish button ───────────────────
    for sel in [
        "//button[normalize-space(text())='Publish']",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'PUBLISH')]",
        "button.button-green","button[class*='btn-publish']",
    ]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver,10).until(EC.element_to_be_clickable((by,sel)))
            if btn.is_displayed() and btn.is_enabled():
                driver.execute_script("arguments[0].click();",btn)
                log(f"Scoop.it: Published! btn='{btn.text.strip()}'")
                time.sleep(5); break
        except: continue

    final = driver.current_url
    log(f"Scoop.it: Final URL = {final}")
    result(True, url=TOPIC_URL)

except Exception as e:
    log(f"Scoop.it: Error = {e}")
    result(False, error=str(e))
finally:
    try: driver.quit()
    except: pass
