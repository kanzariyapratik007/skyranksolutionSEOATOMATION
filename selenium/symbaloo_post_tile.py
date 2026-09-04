#!/usr/bin/env python3
"""
Symbaloo — Add tile to Digital Marketing Service mix
Flow: Open mix → Double-click empty cell → tileSearchInput appears
      → Type URL → Enter → Tile appears → Done
"""
import os, sys, time, json
script_dir = os.path.dirname(os.path.abspath(__file__))
try:
    import pwd
    sys_user = pwd.getpwuid(os.getuid())[0]
except Exception:
    import getpass
    sys_user = getpass.getuser()
app_tmp_dir = os.path.join(script_dir, f'tmp_dir_{sys_user}')
try:
    os.makedirs(app_tmp_dir, exist_ok=True)
except Exception:
    pass
os.environ['HOME'] = app_tmp_dir
os.environ['WDM_LOG'] = '0'
os.environ['WDM_DIR'] = os.path.join(app_tmp_dir, '.wdm')
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

# Args: email password keyword target_url
email      = sys.argv[1] if len(sys.argv) > 1 else "kanzariyapratik124@gmail.com"
password   = sys.argv[2] if len(sys.argv) > 2 else "@DISHA12@"
keyword    = sys.argv[3] if len(sys.argv) > 3 else "python training bangalore"
target_url = sys.argv[4] if len(sys.argv) > 4 else "https://learnmoretech.in/"
custom_mix_url = sys.argv[5] if len(sys.argv) > 5 else ""
ai_description = sys.argv[6] if len(sys.argv) > 6 else ""

import hashlib
email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest()
try:
    import pwd
    sys_user = pwd.getpwuid(os.getuid())[0]
except Exception:
    import getpass
    sys_user = getpass.getuser()
PROFILE_DIR = os.path.join(SCRIPT_DIR, f'chrome_profile_symbaloo_{email_hash}_{sys_user}')

for lf in [os.path.join(PROFILE_DIR,'Default','LOCK'),
           os.path.join(PROFILE_DIR,'SingletonLock')]:
    try:
        if os.path.exists(lf): os.remove(lf)
    except: pass

MIX_URL = "https://www.symbaloo.com/home/mix/13ePQXNM4g"

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error=''):
    print(json.dumps({"success": success, "url": url, "error": error}), flush=True)

def close_consent_modal(driver):
    try:
        for sel in [
            "//button[contains(., 'CONFIRM')]",
            "//button[contains(., 'Confirm')]",
            "//button[contains(., 'AGREE')]",
            "//button[contains(., 'Agree')]",
            "#accept-btn",
            ".qc-cmp2-summary-buttons button",
            "button[class*='confirm']",
            "button[class*='accept']"
        ]:
            try:
                agree = WebDriverWait(driver, 3).until(EC.element_to_be_clickable((By.XPATH, sel) if sel.startswith('/') else (By.CSS_SELECTOR, sel)))
                agree.click()
                log(f"Symbaloo: Cookie Consent Accepted via {sel}.")
                time.sleep(3)
                return True
            except:
                continue
    except Exception as e:
        log(f"Symbaloo: Consent check error: {e}")
    return False

def close_adblock_modal(driver):
    try:
        # Check if adblock text is present before trying to close
        body_text = ""
        try:
            body_text = driver.find_element(By.TAG_NAME, 'body').text
        except: pass

        if "ad-block" not in body_text.lower() and "adblock" not in body_text.lower():
            return False

        log("Symbaloo: Adblock warning detected, closing...")
        for sel in [
            "button[aria-label*='Close' i]",
            "button[class*='close' i]",
            "[class*='close-btn' i]",
            "[class*='CloseBtn']",
            "span[class*='close' i]"
        ]:
            try:
                close_btn = driver.find_elements(By.CSS_SELECTOR, sel)
                for cb in close_btn:
                    if cb.is_displayed():
                        cb.click()
                        log(f"Symbaloo: Closed adblock popup via {sel}")
                        time.sleep(2)
                        return True
            except:
                continue
        try:
            driver.find_element(By.TAG_NAME, 'body').send_keys(Keys.ESCAPE)
            log("Symbaloo: Pressed Escape to close popup")
            time.sleep(1)
            return True
        except: pass
    except Exception as e:
        log(f"Symbaloo: Adblock close error: {e}")
    return False

opts = Options()
if sys.platform != "win32":
    opts.add_argument('--headless=new')
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

try:
    log("Symbaloo: Navigating to login page...")
    driver.get("https://www.symbaloo.com/login")
    time.sleep(5)

    # Accept GDPR if visible
    close_consent_modal(driver)

    # Check if we are still on login page
    if "login" in driver.current_url.lower():
        log("Symbaloo: Logging in...")
        try:
            WebDriverWait(driver, 10).until(EC.element_to_be_clickable((By.CSS_SELECTOR, "input[type='email'], input[name='email'], #email")))
        except: pass
        
        for sel in ["input[type='email']","input[name='email']","#email"]:
            try:
                el = driver.find_element(By.CSS_SELECTOR, sel)
                el.click(); el.clear(); el.send_keys(email); break
            except: continue
        for sel in ["input[type='password']","#password"]:
            try:
                el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                el.click(); el.clear(); el.send_keys(password); break
            except: continue
        for sel in ["button[type='submit']","#login-button"]:
            try:
                btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                driver.execute_script("arguments[0].click();",btn); break
            except: continue
        time.sleep(10)
    else:
        log("Symbaloo: Already logged in!")

    # Now go to the target mix URL (defaults to user's home page if custom_mix_url is not provided)
    target_mix = custom_mix_url if (custom_mix_url and "symbaloo.com" in custom_mix_url) else "https://www.symbaloo.com/"
    log(f"Symbaloo: Navigating to mix = {target_mix}")
    driver.get(target_mix)
    time.sleep(10)
    close_consent_modal(driver)
    close_adblock_modal(driver)
    log(f"Symbaloo: URL = {driver.current_url}")

    log(f"Symbaloo: Mix loaded = {driver.current_url}")

    # Check if we need to enter Edit mode
    try:
        edit_clicked = False
        for btn in driver.find_elements(By.TAG_NAME, 'button'):
            txt = btn.text.strip()
            if 'Edit Webmix' in txt or 'Edit webmix' in txt:
                driver.execute_script("arguments[0].click();", btn)
                log("Symbaloo: Clicked 'Edit Webmix' to enter edit mode")
                time.sleep(4)
                edit_clicked = True
                break
        if not edit_clicked:
            for el in driver.find_elements(By.XPATH, "//*[contains(text(), 'Edit Webmix') or contains(text(), 'Edit webmix')]"):
                try:
                    if el.is_displayed():
                        driver.execute_script("arguments[0].click();", el)
                        log("Symbaloo: Clicked 'Edit Webmix' element to enter edit mode")
                        time.sleep(4)
                        break
                except: continue
    except Exception as e:
        log(f"Symbaloo: Edit mode click error: {e}")

    # Find empty cells
    cells = driver.find_elements(By.CSS_SELECTOR, "[id^='gridEmptyCell']")
    log(f"Symbaloo: Empty cells = {len(cells)}")

    if not cells:
        result(False, error="Symbaloo: No empty cells in this mix")
        exit()

    # Try up to 5 empty cells to open the tile addition sidebar
    tile_input = None
    max_cells_to_try = min(len(cells), 5)

    for idx in range(max_cells_to_try):
        cell = cells[idx]
        log(f"Symbaloo: Trying empty cell #{idx+1}...")
        try:
            driver.execute_script("arguments[0].scrollIntoView({block:'center'});", cell)
            time.sleep(0.5)
        except: continue

        # 1. Single click via JS
        try:
            driver.execute_script("arguments[0].click();", cell)
            time.sleep(0.5)
        except: pass

        # 2. Double click via JS
        try:
            driver.execute_script("""
                var target = arguments[0];
                var clickEvent = document.createEvent('MouseEvents');
                clickEvent.initEvent('dblclick', true, true);
                target.dispatchEvent(clickEvent);
            """, cell)
            time.sleep(1)
        except: pass

        # 3. Native selenium click
        try:
            cell.click()
            time.sleep(2)
        except: pass

        close_adblock_modal(driver)

        # Check if tileSearchInput is visible
        for sel in [
            "#tileSearchInput",
            "input[placeholder*='URL' i]",
            "input[placeholder*='url' i]",
            "input[placeholder*='search query' i]",
            "input[placeholder*='Enter a URL' i]",
        ]:
            try:
                el = driver.find_element(By.CSS_SELECTOR, sel)
                if el.is_displayed():
                    tile_input = el
                    log(f"Symbaloo: Found tileSearchInput [{sel}] on cell #{idx+1}!")
                    break
            except: continue

        if tile_input:
            break

    if not tile_input:
        driver.save_screenshot(os.path.join(SCRIPT_DIR, 'symbaloo_error.png'))
        result(False, error="Symbaloo: tileSearchInput not found after trying multiple empty cells")
        exit()

    # Type target URL
    try:
        tile_input.click()
    except Exception as e:
        log(f"Symbaloo: Standard click on input failed ({e}), trying JS click")
        try:
            driver.execute_script("arguments[0].click();", tile_input)
        except: pass
    tile_input.clear()
    time.sleep(0.3)
    tile_input.send_keys(target_url)
    log(f"Symbaloo: URL typed = {target_url}")
    time.sleep(1)

    # Press Enter to search/add — re-find element to avoid stale
    try:
        tile_input.send_keys(Keys.RETURN)
        log("Symbaloo: Enter pressed on input")
    except:
        # Element went stale — find again and press Enter
        try:
            el2 = driver.find_element(By.ID, 'tileSearchInput')
            el2.send_keys(Keys.RETURN)
            log("Symbaloo: Enter pressed on re-found input")
        except:
            # Just press Enter on body
            driver.find_element(By.TAG_NAME,'body').send_keys(Keys.RETURN)
            log("Symbaloo: Enter pressed on body")
    time.sleep(6)

    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'symbaloo_tile_added.png'))

    # After Enter, wait then find fresh buttons
    time.sleep(6)
    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'symbaloo_tile_added.png'))

    # Fresh find — page may have re-rendered
    def fresh_buttons():
        try:
            return driver.find_elements(By.TAG_NAME, 'button')
        except:
            return []

    btns = fresh_buttons()
    btn_texts = []
    for b in btns:
        try:
            if b.is_displayed() and b.text.strip():
                btn_texts.append(b.text.strip())
        except: continue
    log(f"Symbaloo: Buttons after search = {btn_texts[:15]}")

    # Click result items first
    added = False
    for sel in [
        "[class*='result']", "[class*='searchResult']",
        "[class*='suggestion']", "[class*='tileResult']",
    ]:
        try:
            els = driver.find_elements(By.CSS_SELECTOR, sel)
            for el in els:
                try:
                    if el.is_displayed():
                        driver.execute_script("arguments[0].click();", el)
                        log(f"Symbaloo: Clicked result [{sel}]")
                        time.sleep(3); added = True; break
                except: continue
            if added: break
        except: continue

    if not added:
        log("Symbaloo: No result button — tile auto-added on Enter")

    # Now look for "Edit Tile" — fill URL, name, description
    time.sleep(2)
    for btn in fresh_buttons():
        try:
            txt = btn.text.strip()
            if txt == 'Edit Tile' and btn.is_displayed():
                driver.execute_script("arguments[0].click();", btn)
                log("Symbaloo: Edit Tile clicked")
                time.sleep(4)

                # Fill Tile URL (name='url')
                for inp in driver.find_elements(By.CSS_SELECTOR, "input[name='url']"):
                    try:
                        if inp.is_displayed():
                            inp.click(); inp.clear(); time.sleep(0.2)
                            inp.send_keys(target_url)
                            log("Symbaloo: URL = " + target_url); break
                    except: continue

                # Fill Tile name (name='name')
                tile_title = "Best " + keyword.title() + " Training"
                for inp in driver.find_elements(By.CSS_SELECTOR, "input[name='name']"):
                    try:
                        if inp.is_displayed():
                            inp.click(); inp.clear(); time.sleep(0.2)
                            inp.send_keys(tile_title)
                            log("Symbaloo: Name = " + tile_title); break
                    except: continue

                # Fill Tile description (textarea)
                if ai_description:
                    tile_desc = ai_description
                else:
                    tile_desc = ("Best " + keyword + " training at Learnmore Technologies. "
                                 "Expert trainers, live projects, placement support. "
                                 "Enroll: " + target_url)
                for ta in driver.find_elements(By.TAG_NAME, 'textarea'):
                    try:
                        if ta.is_displayed():
                            ta.click(); ta.clear(); time.sleep(0.2)
                            ta.send_keys(tile_desc[:300])
                            log("Symbaloo: Description filled"); break
                    except: continue
                break
        except: continue

    # Click "Finish editing Webmix"
    time.sleep(2)
    for btn in fresh_buttons():
        try:
            txt = btn.text.strip()
            if 'Finish' in txt or 'finish' in txt.lower():
                driver.execute_script("arguments[0].click();", btn)
                log(f"Symbaloo: Clicked '{txt}'")
                time.sleep(4); break
        except: continue

    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'symbaloo_tile_final.png'))
    log(f"Symbaloo: Final URL = {driver.current_url}")
    result(True, url=driver.current_url)

except Exception as e:
    result(False, error=str(e))
finally:
    try: driver.quit()
    except: pass
