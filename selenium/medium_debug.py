#!/usr/bin/env python3
"""Check Medium login state and new-story page"""
import os, time
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
PROFILE_DIR = os.path.join(SCRIPT_DIR, 'chrome_profile_medium')
for lf in [os.path.join(PROFILE_DIR,'Default','LOCK'), os.path.join(PROFILE_DIR,'SingletonLock')]:
    try:
        if os.path.exists(lf): os.remove(lf)
    except: pass

opts = Options()
opts.add_argument('--no-sandbox')
opts.add_argument('--disable-dev-shm-usage')
opts.add_argument('--disable-blink-features=AutomationControlled')
opts.add_experimental_option('excludeSwitches', ['enable-automation'])
opts.add_experimental_option('useAutomationExtension', False)
opts.add_argument(f'--user-data-dir={PROFILE_DIR}')
opts.add_argument('--profile-directory=Default')
opts.add_argument('--window-size=1400,900')
opts.add_argument('--remote-debugging-port=9223')

driver = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=opts)
driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")

# Check if logged in
driver.get("https://medium.com/")
time.sleep(6)
print(f"URL: {driver.current_url}")
print(f"Title: {driver.title}")

src = driver.page_source.lower()
print(f"Logged in signals:")
print(f"  'sign out' in page: {'sign out' in src}")
print(f"  'write' in page: {'write' in src}")
print(f"  'your profile' in page: {'your profile' in src}")

# Try new-story page
driver.get("https://medium.com/new-story")
time.sleep(6)
print(f"\nnew-story URL: {driver.current_url}")
print(f"Title: {driver.title}")
driver.save_screenshot(os.path.join(SCRIPT_DIR, 'medium_newstory.png'))
print("Screenshot: medium_newstory.png")

# Check for editor
src2 = driver.page_source.lower()
print(f"\nEditor signals:")
print(f"  'editor' in src: {'editor' in src2}")
print(f"  'write' in src: {'write' in src2}")
print(f"  'title' in src: {'title' in src2}")

# All contenteditable
ces = driver.find_elements(By.CSS_SELECTOR, "[contenteditable]")
print(f"\nContenteditable ({len(ces)}):")
for c in ces:
    if c.is_displayed():
        cls = (c.get_attribute('class') or '')[:60]
        ph  = c.get_attribute('placeholder') or c.get_attribute('data-placeholder') or ''
        print(f"  tag={c.tag_name} ph='{ph}' class='{cls}'")

# Buttons
btns = driver.find_elements(By.TAG_NAME, 'button')
print(f"\nButtons ({len(btns)}):")
for b in btns:
    if b.is_displayed() and b.text.strip():
        print(f"  '{b.text.strip()[:40]}'")

# Sign in links
for l in driver.find_elements(By.TAG_NAME, 'a'):
    txt = l.text.strip().lower()
    if 'sign in' in txt or 'sign up' in txt or 'log in' in txt:
        print(f"  Sign link: '{l.text.strip()}' => {l.get_attribute('href')}")

driver.quit()
print("Done.")
