#!/usr/bin/env python3
import os, time
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
PROFILE_DIR = os.path.join(SCRIPT_DIR, 'chrome_profile_4shared')
for lf in [os.path.join(PROFILE_DIR,'Default','LOCK'), os.path.join(PROFILE_DIR,'SingletonLock')]:
    try:
        if os.path.exists(lf): os.remove(lf)
    except: pass
os.makedirs(PROFILE_DIR, exist_ok=True)

opts = Options()
opts.add_argument('--no-sandbox')
opts.add_argument('--disable-dev-shm-usage')
opts.add_argument('--disable-blink-features=AutomationControlled')
opts.add_experimental_option('excludeSwitches', ['enable-automation'])
opts.add_experimental_option('useAutomationExtension', False)
opts.add_argument(f'--user-data-dir={PROFILE_DIR}')
opts.add_argument('--window-size=1400,900')

driver = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=opts)
driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")

driver.get("https://www.4shared.com/login.jsp")
time.sleep(5)
print(f"URL: {driver.current_url}")
print(f"Title: {driver.title}")
driver.save_screenshot(os.path.join(SCRIPT_DIR, '4shared_login.png'))
print("Screenshot: 4shared_login.png")

inputs = driver.find_elements(By.TAG_NAME, 'input')
print(f"\nInputs ({len(inputs)}):")
for inp in inputs:
    if inp.is_displayed():
        print(f"  type={inp.get_attribute('type')} name={inp.get_attribute('name')} id={inp.get_attribute('id')} ph='{inp.get_attribute('placeholder')}'")

btns = driver.find_elements(By.TAG_NAME, 'button')
print(f"\nButtons ({len(btns)}):")
for b in btns:
    if b.is_displayed() and b.text.strip():
        print(f"  '{b.text.strip()[:30]}' type={b.get_attribute('type')}")

submit_inputs = driver.find_elements(By.CSS_SELECTOR, "input[type='submit']")
print(f"\nSubmit inputs ({len(submit_inputs)}):")
for s in submit_inputs:
    print(f"  value='{s.get_attribute('value')}' displayed={s.is_displayed()}")

driver.quit()
print("Done.")
