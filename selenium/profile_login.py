#!/usr/bin/env python3
"""
profile_login.py — One-time login helper for any platform.
Saves Chrome session to chrome_profile_<platform>/ folder.

Usage:
  python selenium/profile_login.py <platform> <email> <password>

Supported platforms:
  minds, dribbble, symbaloo, penzu, plurk, linktree

Examples:
  python selenium/profile_login.py minds kanzariyapratik124@gmail.com @DISHA12@
  python selenium/profile_login.py plurk kanzariyapratik124@gmail.com @DISHA12@
"""
import os, sys, time
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager

PLATFORM_URLS = {
    'minds':     ('https://www.minds.com/login',       'minds.com/newsfeed'),
    'dribbble':  ('https://dribbble.com/session/new',  'dribbble.com'),
    'symbaloo':  ('https://www.symbaloo.com/home/mix/login', 'symbaloo.com'),
    'penzu':     ('https://penzu.com/login',           'penzu.com'),
    'plurk':     ('https://www.plurk.com/',            'plurk.com'),
    'linktree':  ('https://linktr.ee/login',           'linktr.ee'),
    'medium':    ('https://medium.com/m/signin',       'medium.com'),
    'scoopit':   ('https://www.scoop.it/login',        'scoop.it'),
}

if len(sys.argv) < 4:
    print(__doc__)
    sys.exit(1)

platform = sys.argv[1].lower()
email    = sys.argv[2]
password = sys.argv[3]

if platform not in PLATFORM_URLS:
    print(f"Unknown platform: {platform}")
    print(f"Supported: {', '.join(PLATFORM_URLS.keys())}")
    sys.exit(1)

login_url, success_domain = PLATFORM_URLS[platform]

import hashlib
email_hash  = hashlib.md5(email.lower().encode('utf-8')).hexdigest() if email else 'default'
SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
PROFILE_DIR = os.path.join(SCRIPT_DIR, f'chrome_profile_{platform}_{email_hash}')

# Remove lock files
for lf in [os.path.join(PROFILE_DIR, 'Default', 'LOCK'),
           os.path.join(PROFILE_DIR, 'SingletonLock')]:
    try:
        if os.path.exists(lf): os.remove(lf)
    except: pass

os.makedirs(PROFILE_DIR, exist_ok=True)

print("=" * 60)
print(f"  {platform.title()} — Auto Login & Save Session")
print("=" * 60)
print(f"Email   : {email}")
print(f"Profile : {PROFILE_DIR}\n")

opts = Options()
opts.add_argument('--no-sandbox')
opts.add_argument('--disable-dev-shm-usage')
opts.add_argument('--disable-blink-features=AutomationControlled')
opts.add_experimental_option('excludeSwitches', ['enable-automation'])
opts.add_experimental_option('useAutomationExtension', False)
opts.add_argument(f'--user-data-dir={PROFILE_DIR}')
opts.add_argument('--profile-directory=Default')
opts.add_argument('--window-size=1400,900')
opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')

service = Service(ChromeDriverManager().install())
driver  = webdriver.Chrome(service=service, options=opts)
driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
wait = WebDriverWait(driver, 20)

def click(selectors):
    for sel in selectors:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver, 5).until(EC.element_to_be_clickable((by, sel)))
            if btn.is_displayed():
                driver.execute_script("arguments[0].click();", btn)
                return True
        except: continue
    return False

def fill(selectors, value):
    for sel in selectors:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            el = WebDriverWait(driver, 5).until(EC.element_to_be_clickable((by, sel)))
            if el.is_displayed():
                el.click(); el.clear(); time.sleep(0.3)
                el.send_keys(value)
                return True
        except: continue
    return False

try:
    print(f"Opening {login_url}...")
    driver.get(login_url)
    time.sleep(5)

    # Check already logged in
    src = driver.page_source.lower()
    cur = driver.current_url
    already = ('login' not in cur.lower() and 'sign' not in cur.lower() and
               success_domain.split('/')[0] in cur)

    if already:
        print("[OK] Already logged in!")
    else:
        print("Attempting auto-login...")

        # Fill email
        fill([
            "input[type='email']", "input[name='email']",
            "input[id*='email']", "input[placeholder*='mail' i]",
            "input[name='username']", "input[id*='username']",
            "input[type='text']",
        ], email)
        time.sleep(0.5)

        # Some sites need Next click before password appears
        click([
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'NEXT')]",
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'CONTINUE')]",
        ])
        time.sleep(2)

        # Fill password
        fill([
            "input[type='password']", "input[name='password']",
            "input[id*='password']",
        ], password)
        time.sleep(0.5)

        # Submit
        click([
            "button[type='submit']",
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'LOG IN')]",
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SIGN IN')]",
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'LOGIN')]",
            "//input[@type='submit']",
        ])
        time.sleep(8)
        print(f"Post-login URL: {driver.current_url}")

        # Verify
        src2 = driver.page_source.lower()
        cur2 = driver.current_url
        logged = (('login' not in cur2.lower() and 'sign' not in cur2.lower()) or
                  'logout' in src2 or 'sign out' in src2 or 'dashboard' in src2)

        if not logged:
            print(f"\n[MANUAL NEEDED] Auto-login failed for {platform}.")
            print(f"Browser is open. Please login manually with:")
            print(f"  Email   : {email}")
            print(f"  Password: {password}")
            input(f"\n>>> Press ENTER after you are logged into {platform}: ")

    # Confirm + save
    cookies = driver.get_cookies()
    print(f"\n[OK] {len(cookies)} cookies saved")
    print(f"[OK] URL: {driver.current_url}")
    print(f"[OK] Session saved to chrome_profile_{platform}_{email_hash}/")
    print(f"[OK] {platform.title()} Auto Post is ready!")

    driver.save_screenshot(os.path.join(SCRIPT_DIR, f'{platform}_loggedin.png'))
    time.sleep(2)

except Exception as e:
    print(f"\n[ERROR] {e}")
    import traceback; traceback.print_exc()
finally:
    try: driver.quit()
    except: pass

print("\nDone.")
