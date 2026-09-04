#!/usr/bin/env python3
"""
medium_login.py — Save Medium session manually
Account: https://medium.com/@kanzariyapratik124

Run ONCE: python selenium/medium_login.py
Steps:
  1. Browser opens on Medium signin page
  2. Click 'Sign in with Google'
  3. Login with kanzariyapratik124@gmail.com
  4. After Medium home page — press ENTER here
"""
import os, sys, time
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager

EMAIL    = "kanzariyapratik124@gmail.com"
PASSWORD = "@DISHA12@"

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
PROFILE_DIR = os.path.join(SCRIPT_DIR, 'chrome_profile_medium')

# Clean lock files
for lf in [os.path.join(PROFILE_DIR,'Default','LOCK'),
           os.path.join(PROFILE_DIR,'SingletonLock')]:
    try:
        if os.path.exists(lf): os.remove(lf)
    except: pass
os.makedirs(PROFILE_DIR, exist_ok=True)

print("=" * 60)
print("  Medium.com — Login & Save Session")
print(f"  Account: https://medium.com/@kanzariyapratik124")
print("=" * 60)

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
opts.add_argument('--disable-extensions')

service = Service(ChromeDriverManager().install())
driver  = webdriver.Chrome(service=service, options=opts)
driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")

try:
    # Open Medium signin page
    print("\nOpening Medium signin page...")
    driver.get("https://medium.com/m/signin")
    time.sleep(6)
    print(f"URL: {driver.current_url}")
    print(f"Title: {driver.title}")

    # Handle Cloudflare
    if "just a moment" in driver.title.lower():
        print("Cloudflare challenge — waiting 10 seconds...")
        time.sleep(10)
        print(f"After wait: {driver.current_url}")

    # Check if already logged in
    driver.get("https://medium.com/@kanzariyapratik124")
    time.sleep(5)
    src = driver.page_source.lower()
    already = ("write" in src or "following" in src or "follow" in src)

    if already:
        print(f"[OK] Profile page accessible: {driver.current_url}")
    
    # Go to signin
    driver.get("https://medium.com/m/signin")
    time.sleep(5)
    print(f"\nSignin page: {driver.current_url}")

    print("\n" + "="*60)
    print("  ACTION NEEDED:")
    print("="*60)
    print("  Browser is open on Medium signin page")
    print("  Please do:")
    print("  1. Click 'Sign in with Google'")
    print(f"  2. Use: {EMAIL}")
    print("  3. Wait for Medium home page to load")
    print("  4. Come back here and press ENTER")
    print("="*60)
    input("\n>>> Press ENTER after you are logged in to Medium: ")

    # Verify login
    driver.get("https://medium.com/new-story")
    time.sleep(5)
    cur = driver.current_url
    print(f"\nAfter login check: {cur}")

    if "signin" in cur or "login" in cur:
        print("[WARNING] May not be logged in. URL still shows signin.")
        print("Please make sure you completed login in the browser.")
        input("Press ENTER again after confirming login: ")

    # Save cookies
    cookies = driver.get_cookies()
    print(f"\n[OK] {len(cookies)} cookies saved")
    print(f"[OK] Final URL: {cur}")
    print(f"[OK] Session saved to: {PROFILE_DIR}")
    print("[OK] Medium Auto Post is ready!")
    time.sleep(2)

except Exception as e:
    print(f"\n[ERROR] {e}")
    import traceback; traceback.print_exc()
finally:
    try: driver.quit()
    except: pass

print("\nDone. Now run: python medium_post.py to test posting.")
