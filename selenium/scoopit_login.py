#!/usr/bin/env python3
"""
scoopit_login.py  —  Run this ONCE to save your Scoop.it login session.

Steps:
  1. Run this script
  2. Chrome opens on scoop.it/login
  3. Login manually (email + password + 2FA if asked)
  4. Wait until you see your Scoop.it dashboard/topic page
  5. Come back here and press ENTER
  6. Session is saved — Auto Post will use it from now on
"""
import os, sys, time
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
PROFILE_DIR = os.path.join(SCRIPT_DIR, 'chrome_profile_scoopit')

# Clean up any leftover lock files
lock_file = os.path.join(PROFILE_DIR, 'Default', 'LOCK')
singleton = os.path.join(PROFILE_DIR, 'SingletonLock')
for lf in [lock_file, singleton]:
    try:
        if os.path.exists(lf):
            os.remove(lf)
    except: pass

os.makedirs(PROFILE_DIR, exist_ok=True)

print("=" * 60)
print("  Scoop.it — One-Time Login Setup")
print("=" * 60)
print(f"\nProfile folder: {PROFILE_DIR}\n")

opts = Options()
opts.add_argument('--no-sandbox')
opts.add_argument('--disable-dev-shm-usage')
opts.add_argument('--disable-blink-features=AutomationControlled')
opts.add_experimental_option('excludeSwitches', ['enable-automation'])
opts.add_experimental_option('useAutomationExtension', False)
opts.add_argument(f'--user-data-dir={PROFILE_DIR}')
opts.add_argument('--profile-directory=Default')
opts.add_argument('--window-size=1300,850')

service = Service(ChromeDriverManager().install())
driver  = webdriver.Chrome(service=service, options=opts)
driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")

print("Opening Scoop.it login page...")
print("1. Login with your email + password")
print("2. Complete 2FA if asked")
print("3. Wait until you see your Scoop.it topic/dashboard page")
print("4. Then come back here and press ENTER\n")

driver.get("https://www.scoop.it/login")

input(">>> Press ENTER ONLY after you are fully logged in: ")

# Verify session
cur  = driver.current_url
src  = driver.page_source.lower()
logged = ("log out" in src or "logout" in src or "sign out" in src
          or "/u/" in cur or "/topic/" in cur or "dashboard" in cur)

print(f"\nCurrent URL: {cur}")

if logged:
    print("[OK] Logged in confirmed!")

    # Navigate once more to make sure session cookies are flushed to disk
    driver.get("https://www.scoop.it/u/me")
    time.sleep(3)
    print(f"Profile URL: {driver.current_url}")

    # Get cookies count
    cookies = driver.get_cookies()
    print(f"[OK] {len(cookies)} cookies saved to profile")
    print("[OK] Session saved! You can now use Auto Post for Scoop.it.")
else:
    print("[WARNING] Could not confirm login. URL =", cur)
    print("If you completed login, try Auto Post anyway — session may still be saved.")

time.sleep(2)
driver.quit()
print("\nDone. Close this window.")
