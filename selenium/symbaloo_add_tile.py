#!/usr/bin/env python3
"""Full Symbaloo add tile flow — double-click empty cell → fill URL → save"""
import os, time
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.action_chains import ActionChains
from selenium.webdriver.common.keys import Keys
from webdriver_manager.chrome import ChromeDriverManager

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
PROFILE_DIR = os.path.join(SCRIPT_DIR, 'chrome_profile_symbaloo')
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

driver = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=opts)
driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
driver.get("https://www.symbaloo.com/home/mix/home-lbqt")
time.sleep(7)
print(f"URL: {driver.current_url}")

TARGET_URL = "https://learnmoretech.in"
TITLE      = "Best Python Training Bangalore"

# Step 1: Find and double-click an empty cell
print("\nStep 1: Finding empty cell...")
cells = driver.find_elements(By.CSS_SELECTOR, "[id^='gridEmptyCell']")
print(f"Empty cells found: {len(cells)}")

clicked = False
for cell in cells:
    if cell.is_displayed():
        print(f"  Double-clicking: id={cell.get_attribute('id')[:60]}")
        driver.execute_script("arguments[0].scrollIntoView({block:'center'});", cell)
        time.sleep(0.5)
        ActionChains(driver).double_click(cell).perform()
        print("  Double-clicked!")
        time.sleep(4)
        driver.save_screenshot(os.path.join(SCRIPT_DIR, 'symbaloo3_after_dbl.png'))
        clicked = True
        break

if not clicked:
    print("No empty cell found!")
    driver.quit()
    exit()

# Step 2: Check what appeared (modal/form)
print("\nStep 2: Checking modal/form...")
inputs = driver.find_elements(By.TAG_NAME, 'input')
print(f"Inputs ({len(inputs)}):")
for inp in inputs:
    if inp.is_displayed():
        print(f"  type={inp.get_attribute('type')} placeholder='{inp.get_attribute('placeholder')}' name='{inp.get_attribute('name')}' id='{inp.get_attribute('id')}'")

btns = driver.find_elements(By.TAG_NAME, 'button')
print(f"\nButtons ({len(btns)}):")
for b in btns:
    txt = b.text.strip()
    if txt and b.is_displayed():
        print(f"  '{txt}' class='{(b.get_attribute('class') or '')[:50]}'")

modals = driver.find_elements(By.CSS_SELECTOR, "[role='dialog'],[class*='modal'],[class*='Modal'],[class*='dialog'],[class*='Dialog']")
print(f"\nModals: {len(modals)}")
for m in modals:
    if m.is_displayed():
        print(f"  class='{(m.get_attribute('class') or '')[:60]}'")
        print(f"  text: {m.text[:200]}")

# Step 3: Fill URL
print("\nStep 3: Filling URL...")
url_filled = False
for sel in ["input[placeholder*='url' i]","input[placeholder*='URL' i]","input[placeholder*='http' i]",
            "input[placeholder*='website' i]","input[placeholder*='address' i]",
            "input[type='url']","input[name='url']","input[id*='url']"]:
    try:
        el = WebDriverWait(driver,5).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
        if el.is_displayed():
            el.click(); el.clear(); time.sleep(0.3)
            el.send_keys(TARGET_URL)
            print(f"  URL typed: [{sel}]")
            url_filled = True
            break
    except: continue

if not url_filled:
    # Try all visible text/url inputs
    for inp in inputs:
        if inp.is_displayed() and inp.get_attribute('type') in ('text','url',''):
            inp.click(); inp.clear()
            inp.send_keys(TARGET_URL)
            print(f"  URL typed in fallback input")
            url_filled = True
            break

# Press Tab/Enter to trigger URL fetch
if url_filled:
    time.sleep(0.5)
    driver.find_element(By.TAG_NAME,'body').send_keys(Keys.TAB)
    time.sleep(3)
    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'symbaloo4_url_typed.png'))
    print("  After URL: screenshot saved")

    # Check for title field
    inputs2 = driver.find_elements(By.TAG_NAME,'input')
    for inp in inputs2:
        if inp.is_displayed():
            ph = inp.get_attribute('placeholder') or ''
            if 'title' in ph.lower() or 'name' in ph.lower() or 'label' in ph.lower():
                inp.click(); inp.clear()
                inp.send_keys(TITLE)
                print(f"  Title typed: '{TITLE}'")
                break

    # Save button
    for sel in ["//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SAVE')]",
                "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ADD')]",
                "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'OK')]",
                "button[type='submit']"]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver,5).until(EC.element_to_be_clickable((by,sel)))
            if btn.is_displayed():
                driver.execute_script("arguments[0].click();",btn)
                print(f"  Saved! btn='{btn.text.strip()}'")
                time.sleep(4)
                break
        except: continue

driver.save_screenshot(os.path.join(SCRIPT_DIR, 'symbaloo5_final.png'))

# Check if tile appeared
links = driver.find_elements(By.TAG_NAME,'a')
for l in links:
    href = l.get_attribute('href') or ''
    if 'learnmore' in href.lower():
        print(f"\n✅ TILE SAVED! href={href}")

print(f"\nFinal URL: {driver.current_url}")
driver.quit()
print("Done.")
