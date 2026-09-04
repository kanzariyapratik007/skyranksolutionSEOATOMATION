#!/usr/bin/env python3
"""
login_all_platforms.py — Auto login & save sessions for all 6 platforms
Run: python selenium/login_all_platforms.py

Platforms: Minds, Dribbble, Symbaloo, Penzu, Plurk, Linktree
After running, Auto Post will use saved sessions automatically.
"""
import os, time, sys
os.environ['WDM_LOG'] = '0'
os.environ['WDM_DIR'] = '/tmp/.wdm'
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

# ── Credentials ───────────────────────────────────────────────
EMAIL    = "kanzariyapratik124@gmail.com"
PASSWORD = "@DISHA12@"

# ── Platform configs ──────────────────────────────────────────
PLATFORMS = [
    {
        'name':      'Minds.com',
        'key':       'minds',
        'login_url': 'https://www.minds.com/login',
        'check_url': 'https://www.minds.com/',
        'success_signals': ['logout', 'newsfeed', 'compose'],
    },
    {
        'name':      'Dribbble',
        'key':       'dribbble',
        'login_url': 'https://dribbble.com/session/new',
        'check_url': 'https://dribbble.com/',
        'success_signals': ['log out', 'logout', 'shots', 'account'],
    },
    {
        'name':      'Symbaloo',
        'key':       'symbaloo',
        'login_url': 'https://www.symbaloo.com/login',
        'check_url': 'https://www.symbaloo.com/home/mix/',
        'success_signals': ['logout', 'log out', 'mix', 'webmix'],
    },
    {
        'name':      'Penzu',
        'key':       'penzu',
        'login_url': 'https://penzu.com/login',
        'check_url': 'https://penzu.com/app',
        'success_signals': ['logout', 'log out', 'new entry', 'journal'],
    },
    {
        'name':      'Plurk',
        'key':       'plurk',
        'login_url': 'https://www.plurk.com/login',
        'check_url': 'https://www.plurk.com/',
        'success_signals': ['logout', 'log out', 'timeline', 'newsfeed'],
        'username_field': "input[name='nick'], input[id='nick']",  # Plurk uses nick not email
    },
    {
        'name':      'Linktree',
        'key':       'linktree',
        'login_url': 'https://linktr.ee/login',
        'check_url': 'https://linktr.ee/admin',
        'success_signals': ['logout', 'log out', 'add link', 'admin'],
    },
]

def get_driver(profile_key):
    profile_dir = os.path.join(SCRIPT_DIR, f'chrome_profile_{profile_key}')
    # Remove lock files
    for lf in [os.path.join(profile_dir,'Default','LOCK'), os.path.join(profile_dir,'SingletonLock')]:
        try:
            if os.path.exists(lf): os.remove(lf)
        except: pass
    os.makedirs(profile_dir, exist_ok=True)

    opts = Options()
    if sys.platform != "win32":
        opts.add_argument('--headless=new')
    opts.add_argument('--no-sandbox')
    opts.add_argument('--disable-dev-shm-usage')
    opts.add_argument('--disable-blink-features=AutomationControlled')
    opts.add_experimental_option('excludeSwitches', ['enable-automation'])
    opts.add_experimental_option('useAutomationExtension', False)
    opts.add_argument(f'--user-data-dir={profile_dir}')
    opts.add_argument('--profile-directory=Default')
    opts.add_argument('--window-size=1400,900')
    opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')

    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    return driver

def do_login(driver, platform, email, password):
    """Auto login flow — handles most platforms"""
    name      = platform['name']
    login_url = platform['login_url']
    username_sel = platform.get('username_field', "input[type='email'], input[name='email'], input[name='username'], input[name='nick']")

    print(f"\n  Opening {login_url}...")
    driver.get(login_url)
    time.sleep(5)

    # Accept cookie consent
    for csel in [
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ACCEPT ALL')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ACCEPT')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'OK')]",
        "#onetrust-accept-btn-handler",
    ]:
        try:
            btn = WebDriverWait(driver, 3).until(EC.element_to_be_clickable((By.XPATH, csel)))
            if btn.is_displayed():
                driver.execute_script("arguments[0].click();", btn)
                time.sleep(2); break
        except: continue

    # Fill email/username
    for sel in username_sel.split(', '):
        sel = sel.strip()
        try:
            el = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
            if el.is_displayed():
                el.click(); el.clear(); time.sleep(0.3); el.send_keys(email)
                print(f"  Email/username typed [{sel}]"); break
        except: continue

    # Click Next if needed
    for sel in ["button[type='submit']", "//button[contains(text(),'Next')]", "//button[contains(text(),'Continue')]"]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver, 4).until(EC.element_to_be_clickable((by, sel)))
            pw = driver.find_elements(By.CSS_SELECTOR, "input[type='password']")
            if pw and pw[0].is_displayed(): break  # password already visible
            driver.execute_script("arguments[0].click();", btn)
            time.sleep(3); break
        except: continue

    # Fill password
    for sel in ["input[type='password']", "input[name='password']", "input[name='pwd']"]:
        try:
            el = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
            if el.is_displayed():
                el.click(); el.clear(); time.sleep(0.3); el.send_keys(password)
                print(f"  Password typed [{sel}]"); break
        except: continue

    # Submit
    for sel in [
        "button[type='submit']", "input[type='submit']",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SIGN IN')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'LOG IN')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'LOGIN')]",
    ]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((by, sel)))
            if btn.is_displayed():
                driver.execute_script("arguments[0].click();", btn)
                print(f"  Submit clicked: '{btn.text.strip()[:30]}'"); break
        except: continue

    time.sleep(8)
    return driver.current_url

def check_logged_in(driver, platform):
    src = driver.page_source.lower()
    for signal in platform['success_signals']:
        if signal in src or signal in driver.current_url.lower():
            return True
    return False

def login_platform(platform):
    name = platform['name']
    key  = platform['key']
    print(f"\n{'='*55}")
    print(f"  {name}")
    print(f"{'='*55}")

    driver = get_driver(key)
    try:
        # Check check_url first
        print(f"  Checking if already logged in...")
        driver.get(platform['check_url'])
        time.sleep(5)

        if check_logged_in(driver, platform):
            print(f"  [OK] Already logged in at {driver.current_url}")
            cookies = driver.get_cookies()
            print(f"  [OK] {len(cookies)} cookies saved")
            return True

        print(f"  Not logged in. Attempting auto-login...")
        url_after = do_login(driver, platform, EMAIL, PASSWORD)
        print(f"  After login URL: {url_after}")

        # Navigate to check URL to verify
        driver.get(platform['check_url'])
        time.sleep(4)

        if check_logged_in(driver, platform):
            cookies = driver.get_cookies()
            print(f"  [OK] Login successful! {len(cookies)} cookies saved")
            print(f"  [OK] Profile: chrome_profile_{key}/")
            return True
        else:
            print(f"  [MANUAL] Auto-login may have failed.")
            print(f"  Please login manually in the browser window.")
            print(f"  Email: {EMAIL}  Password: {PASSWORD}")
            input(f"  >>> Press ENTER after {name} login is complete: ")

            driver.get(platform['check_url'])
            time.sleep(3)
            cookies = driver.get_cookies()
            print(f"  [OK] Manual login done. {len(cookies)} cookies saved.")
            return True

    except Exception as e:
        print(f"  [ERROR] {e}")
        return False
    finally:
        try: driver.quit()
        except: pass

# ── Main ─────────────────────────────────────────────────────
if __name__ == "__main__":
    # Check if specific platform requested
    target = sys.argv[1].lower() if len(sys.argv) > 1 else 'all'

    print("=" * 55)
    print("  Multi-Platform Auto Login")
    print(f"  Email: {EMAIL}")
    print("=" * 55)

    results = {}
    for platform in PLATFORMS:
        if target != 'all' and target != platform['key']:
            continue
        ok = login_platform(platform)
        results[platform['name']] = 'OK' if ok else 'FAILED'

    print("\n" + "=" * 55)
    print("  SUMMARY")
    print("=" * 55)
    for name, status in results.items():
        print(f"  {status:8} {name}")
    print("\nAll sessions saved! Auto Post is ready.")
