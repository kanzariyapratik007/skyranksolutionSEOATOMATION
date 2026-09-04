#!/usr/bin/env python3
"""
wakelet_login.py — Auto login (iframe-aware) + save session
Run ONCE: python selenium/wakelet_login.py
"""
import os, time
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
PROFILE_DIR = os.path.join(SCRIPT_DIR, 'chrome_profile_wakelet')

# Remove lock files
for lf in [os.path.join(PROFILE_DIR, 'Default', 'LOCK'),
           os.path.join(PROFILE_DIR, 'SingletonLock')]:
    try:
        if os.path.exists(lf): os.remove(lf)
    except: pass

os.makedirs(PROFILE_DIR, exist_ok=True)

print("=" * 60)
print("  Wakelet — Auto Login (iframe-aware)")
print("=" * 60)
print(f"Email: {EMAIL}\n")

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

def find_email_in_iframes():
    """Search for email input inside all iframes"""
    # First switch back to main
    driver.switch_to.default_content()
    frames = driver.find_elements(By.TAG_NAME, 'iframe')
    print(f"  Checking {len(frames)} iframes for email field...")
    for i, frame in enumerate(frames):
        try:
            driver.switch_to.frame(frame)
            src = frame.get_attribute('src') or ''
            inputs = driver.find_elements(By.TAG_NAME, 'input')
            if inputs:
                print(f"  iframe[{i}] src='{src[:60]}' has {len(inputs)} inputs")
                for inp in inputs:
                    itype = inp.get_attribute('type') or ''
                    iph   = inp.get_attribute('placeholder') or ''
                    print(f"    input: type={itype} placeholder='{iph}'")
                # Check if email input is here
                for sel in ["input[type='email']", "input[name='email']",
                            "input[placeholder*='mail' i]"]:
                    try:
                        el = driver.find_element(By.CSS_SELECTOR, sel)
                        if el.is_displayed():
                            print(f"  FOUND email in iframe[{i}]!")
                            return True, i
                    except: continue
            driver.switch_to.default_content()
        except Exception as e:
            driver.switch_to.default_content()
            continue
    driver.switch_to.default_content()
    return False, -1

try:
    print("Opening wakelet.com/sign-in...")
    driver.get("https://wakelet.com/sign-in")
    time.sleep(6)
    print(f"URL: {driver.current_url}")

    # Check if already logged in
    src = driver.page_source.lower()
    already = ("logout" in src or "sign out" in src or
               "/home" in driver.current_url or "feed" in driver.current_url)

    if already:
        print("[OK] Already logged in!")
    else:
        # Step 1: Click Login button to open modal
        print("Clicking Login button...")
        for sel in [
            "//button[normalize-space(text())='Login']",
            "//button[contains(text(),'Login')]",
        ]:
            try:
                btn = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((By.XPATH, sel)))
                driver.execute_script("arguments[0].click();", btn)
                print(f"  Clicked: '{btn.text}'")
                time.sleep(4)
                break
            except: continue

        driver.save_screenshot(os.path.join(SCRIPT_DIR, 'wakelet_step1.png'))

        # Step 2: Check iframes for email form
        found, frame_idx = find_email_in_iframes()

        if found:
            # We are already inside the correct iframe
            print(f"  Using iframe[{frame_idx}] for login form")

            # Type email
            for sel in ["input[type='email']", "input[name='email']",
                        "input[placeholder*='mail' i]"]:
                try:
                    el = driver.find_element(By.CSS_SELECTOR, sel)
                    if el.is_displayed():
                        el.click(); el.clear(); time.sleep(0.3)
                        el.send_keys(EMAIL)
                        print(f"  Email typed in iframe")
                        break
                except: continue

            # Click Next/Continue if needed
            for sel in ["button[type='submit']",
                        "//button[contains(text(),'Next')]",
                        "//button[contains(text(),'Continue')]"]:
                try:
                    by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                    btn = driver.find_element(by, sel)
                    if btn.is_displayed():
                        driver.execute_script("arguments[0].click();", btn)
                        print(f"  Next clicked: '{btn.text}'")
                        time.sleep(3)
                        break
                except: continue

            # Type password
            for sel in ["input[type='password']", "input[name='password']"]:
                try:
                    el = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
                    if el.is_displayed():
                        el.click(); el.clear(); time.sleep(0.3)
                        el.send_keys(PASSWORD)
                        print(f"  Password typed in iframe")
                        break
                except: continue

            # Submit
            for sel in ["button[type='submit']",
                        "//button[contains(text(),'Sign in')]",
                        "//button[contains(text(),'Log in')]",
                        "//button[contains(text(),'Login')]"]:
                try:
                    by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                    btn = driver.find_element(by, sel)
                    if btn.is_displayed():
                        driver.execute_script("arguments[0].click();", btn)
                        print(f"  Submit clicked: '{btn.text}'")
                        break
                except: continue

            driver.switch_to.default_content()
            time.sleep(8)
            print(f"After submit URL: {driver.current_url}")

        else:
            # No iframe found — try Auth0 / direct login URL
            print("  Email not in iframes. Trying direct auth URL...")
            driver.switch_to.default_content()

            # Try Wakelet's auth endpoint directly
            for auth_url in [
                "https://wakelet.com/sign-in?mode=email",
                "https://wakelet.com/login",
                "https://auth.wakelet.com/",
            ]:
                driver.get(auth_url)
                time.sleep(5)
                print(f"  Trying: {driver.current_url}")

                # Check for email input
                inputs = driver.find_elements(By.CSS_SELECTOR,
                    "input[type='email'], input[name='email'], input[placeholder*='mail' i]")
                if inputs and inputs[0].is_displayed():
                    inputs[0].click(); inputs[0].clear()
                    inputs[0].send_keys(EMAIL)
                    print(f"  Email typed at {auth_url}")

                    # Next
                    for sel in ["button[type='submit']",
                                "//button[contains(text(),'Next')]",
                                "//button[contains(text(),'Continue')]"]:
                        try:
                            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                            btn = WebDriverWait(driver, 4).until(EC.element_to_be_clickable((by, sel)))
                            driver.execute_script("arguments[0].click();", btn)
                            time.sleep(3); break
                        except: continue

                    # Password
                    pw = driver.find_elements(By.CSS_SELECTOR, "input[type='password']")
                    if pw and pw[0].is_displayed():
                        pw[0].click(); pw[0].clear()
                        pw[0].send_keys(PASSWORD)
                        print("  Password typed")

                        for sel in ["button[type='submit']",
                                    "//button[contains(text(),'Sign')]",
                                    "//button[contains(text(),'Log')]"]:
                            try:
                                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                                btn = WebDriverWait(driver, 4).until(EC.element_to_be_clickable((by, sel)))
                                driver.execute_script("arguments[0].click();", btn)
                                print(f"  Submitted"); time.sleep(8); break
                            except: continue
                        break

            print(f"After attempts URL: {driver.current_url}")

        # Final verify
        cur = driver.current_url
        src = driver.page_source.lower()
        logged = ("sign-in" not in cur and "login" not in cur and
                  "wakelet.com" in cur and
                  ("logout" in src or "sign out" in src or "create" in src))

        if not logged:
            print("\n[MANUAL NEEDED] Auto-login could not complete.")
            print("Wakelet uses Google/Apple login — browser is open.")
            print(f"Please login manually with: {EMAIL}")
            input("\n>>> Press ENTER after Wakelet home page is visible: ")

    # Save session
    driver.get("https://wakelet.com/home")
    time.sleep(4)
    cookies = driver.get_cookies()
    print(f"\n[OK] {len(cookies)} cookies saved")
    print(f"[OK] Final URL: {driver.current_url}")
    print("[OK] Session saved to chrome_profile_wakelet/")
    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'wakelet_loggedin.png'))
    print("Screenshot: wakelet_loggedin.png")
    time.sleep(2)

except Exception as e:
    print(f"\n[ERROR] {e}")
    import traceback; traceback.print_exc()
finally:
    try: driver.quit()
    except: pass

print("\nDone.")
