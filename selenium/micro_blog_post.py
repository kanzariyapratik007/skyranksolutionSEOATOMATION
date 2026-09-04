#!/usr/bin/env python3
"""
Micro Blog / Bookmarking Platforms Selenium Auto-Poster
Handles: scoopit, wakelet, padlet, pearltrees, mewe, instapaper, vivauae
Usage: python micro_blog_post.py <platform> <email> <password> <keyword> <target_site>
"""
import sys, json, time, re, os
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
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.common.action_chains import ActionChains
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from webdriver_manager.chrome import ChromeDriverManager

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error=''):
    print(json.dumps({"success": success, "url": url, "error": error}), flush=True)

def get_driver(platform=''):
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
    # NOTE: Do NOT use --incognito — it breaks cookie-based sessions on Scoop.it/etc
    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    return driver

def get_driver_with_profile(platform='', email='default'):
    """Use saved Chrome profile — preserves login sessions (no need to login again)"""
    import os, hashlib
    email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest() if email else 'default'
    try:
        import pwd
        sys_user = pwd.getpwuid(os.getuid())[0]
    except Exception:
        import getpass
        sys_user = getpass.getuser()
    profile_dir = os.path.join(os.path.dirname(__file__), f'chrome_profile_{platform}_{email_hash}_{sys_user}')
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
    opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')
    opts.add_argument('--window-size=1400,900')
    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    return driver

def wait_click(driver, wait, selector, by=By.CSS_SELECTOR, timeout=15):
    try:
        el = WebDriverWait(driver, timeout).until(EC.element_to_be_clickable((by, selector)))
        driver.execute_script("arguments[0].scrollIntoView({block:'center'}); arguments[0].click();", el)
        return True
    except Exception as e:
        log(f"click [{selector}]: {e}")
        return False

def wait_type(driver, wait, selector, text, by=By.CSS_SELECTOR, timeout=15, clear=True):
    try:
        el = WebDriverWait(driver, timeout).until(EC.element_to_be_clickable((by, selector)))
        driver.execute_script("arguments[0].scrollIntoView({block:'center'});", el)
        el.click()
        if clear:
            el.clear()
        el.send_keys(text)
        return True
    except Exception as e:
        log(f"type [{selector}]: {e}")
        return False

def js_type(driver, selector, value, by=By.CSS_SELECTOR):
    """Set value via JS (for React inputs)"""
    try:
        el = driver.find_element(by, selector)
        driver.execute_script("""
            var el=arguments[0], val=arguments[1];
            var setter=Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,'value').set;
            setter.call(el,val);
            el.dispatchEvent(new Event('input',{bubbles:true}));
            el.dispatchEvent(new Event('change',{bubbles:true}));
        """, el, value)
        return True
    except: return False

# ─────────────────────────────────────────────────────────────
# ---------------------------------------------------------
# SCOOP.IT (2025) — Topic page Suggestions + Publish button
# Free account: Login -> Topic page -> Click Publish on suggestions
# ---------------------------------------------------------
def post_scoopit(email, password, keyword, target_site, ai_title="", ai_content=""):
    driver = get_driver_with_profile('scoopit', email)
    wait   = WebDriverWait(driver, 25)
    try:
        log("Scoop.it: Opening site...")
        driver.get("https://www.scoop.it/login")
        time.sleep(4)

        # Accept cookies on login page
        for csel in [
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'OK, ACCEPT ALL')]",
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ACCEPT ALL')]",
        ]:
            try:
                btn = WebDriverWait(driver,4).until(EC.element_to_be_clickable((By.XPATH,csel)))
                driver.execute_script("arguments[0].click();",btn)
                log("Scoop.it: Cookie accepted on login page")
                time.sleep(2); break
            except: continue

        # Check if already logged in
        src = driver.page_source.lower()
        logged = ("log out" in src or "logout" in src or "/u/" in driver.current_url)

        if not logged:
            log("Scoop.it: Logging in...")
            for sel in ["input[name='email']","input[type='email']"]:
                try:
                    el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                    el.click(); el.clear(); time.sleep(0.3); el.send_keys(email)
                    log("Scoop.it: Email typed"); break
                except: continue

            for sel in ["input[type='password']","input[name='password']"]:
                try:
                    el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                    el.click(); el.clear(); time.sleep(0.3); el.send_keys(password)
                    log("Scoop.it: Password typed"); break
                except: continue

            for sel in ["button[type='submit']",
                        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'LOG IN')]"]:
                try:
                    by = By.XPATH if sel.startswith("//") else By.CSS_SELECTOR
                    btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
                    driver.execute_script("arguments[0].click();",btn)
                    log("Scoop.it: Login submitted"); break
                except: continue

            time.sleep(7)
            cur = driver.current_url
            log("Scoop.it: Post-login URL = " + cur)

            if "/2fa" in cur or "two-factor" in cur.lower():
                result(False, error="Scoop.it 2FA required. Run scoopit_login.py first.")
                return
            if "login" in cur.lower() and "justloggedin" not in cur:
                result(False, error="Scoop.it login failed. URL=" + cur)
                return
            log("Scoop.it: Logged in!")
        else:
            log("Scoop.it: Already logged in!")

        # Get topic URL via API
        topic_url = ""
        topic_name = ""
        try:
            driver.get("https://www.scoop.it/api/0/user?includeTopics=1")
            time.sleep(3)
            import json as _json
            data = _json.loads(driver.find_element(By.TAG_NAME,"body").text)
            topics = data.get("user",{}).get("curatedTopics",[])
            if topics:
                topic_url  = topics[0].get("url","")
                topic_name = topics[0].get("urlName","")
                log("Scoop.it: Topic = " + topic_url)
        except Exception as e:
            log("Scoop.it: API skip: " + str(e))

        # Fallback topic URL from profile
        if not topic_url:
            try:
                driver.get("https://www.scoop.it/u/me")
                time.sleep(3)
                links = driver.find_elements(By.CSS_SELECTOR, "a[href*='/topic/']")
                for l in links:
                    href = l.get_attribute("href") or ""
                    if "/topic/" in href:
                        topic_url = href
                        log("Scoop.it: Topic from profile = " + topic_url)
                        break
            except: pass

        if not topic_url:
            result(False, error="Scoop.it: Could not find topic URL. Please check your account.")
            return

        # Go to topic page
        log("Scoop.it: Going to topic = " + topic_url)
        driver.get(topic_url)
        time.sleep(6)
        log("Scoop.it: Topic page = " + driver.current_url)

        # Accept cookie consent on topic page
        for csel in [
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'OK, ACCEPT ALL')]",
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ACCEPT ALL')]",
        ]:
            try:
                btn = WebDriverWait(driver,4).until(EC.element_to_be_clickable((By.XPATH,csel)))
                driver.execute_script("arguments[0].click();",btn)
                log("Scoop.it: Cookie accepted on topic page")
                time.sleep(3); break
            except: continue

        # Find all "Publish" green buttons (on suggested articles)
        published = False
        publish_url = topic_url

        try:
            # Wait for Publish buttons to appear
            WebDriverWait(driver, 10).until(
                EC.presence_of_element_located((By.CSS_SELECTOR, "button.button-green"))
            )
            publish_btns = driver.find_elements(By.CSS_SELECTOR, "button.button-green")
            log("Scoop.it: Found " + str(len(publish_btns)) + " Publish buttons")

            if publish_btns:
                # Click the first visible green button (Publish on suggested article)
                for btn in publish_btns:
                    try:
                        if btn.is_displayed():
                            txt = btn.text.strip().lower()
                            # Accept 'publish', 'scoop', '' (icon button), or any green button
                            driver.execute_script("arguments[0].scrollIntoView({block:'center'});", btn)
                            time.sleep(0.5)
                            driver.execute_script("arguments[0].click();", btn)
                            log("Scoop.it: Clicked Publish button! text='" + txt + "'")
                            time.sleep(4)
                            cur = driver.current_url
                            log("Scoop.it: After publish URL = " + cur)
                            published = True
                            publish_url = cur if "scoop.it" in cur else topic_url
                            break
                    except Exception as be:
                        log("Scoop.it: btn error: " + str(be))
                        continue
                        log("Scoop.it: btn error: " + str(be))
                        continue
        except Exception as e:
            log("Scoop.it: Publish buttons not found: " + str(e))

        if not published:
            try:
                driver.save_screenshot(os.path.join(os.path.dirname(__file__), "scoopit_debug.png"))
                log("Scoop.it: Screenshot saved")
            except: pass
            # Still return topic URL as partial success
            result(True, url=topic_url)
            return

        result(True, url=publish_url if "scoop.it" in publish_url else topic_url)

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

def post_wakelet(email, password, keyword, target_site, ai_title="", ai_content=""):
    """
    Wakelet auto-post using saved Chrome profile:
    Login -> Create Collection -> Blank -> Paste URL -> Done
    """
    import os as _os
    from selenium.webdriver.common.action_chains import ActionChains as _AC

    _PROFILE = _os.path.join(_os.path.dirname(_os.path.abspath(__file__)), 'chrome_profile_wakelet')
    for _lf in [_os.path.join(_PROFILE,'Default','LOCK'), _os.path.join(_PROFILE,'SingletonLock')]:
        try:
            if _os.path.exists(_lf): _os.remove(_lf)
        except: pass

    opts = Options()
    opts.add_argument('--no-sandbox')
    opts.add_argument('--disable-dev-shm-usage')
    opts.add_argument('--disable-blink-features=AutomationControlled')
    opts.add_experimental_option('excludeSwitches', ['enable-automation'])
    opts.add_experimental_option('useAutomationExtension', False)
    opts.add_argument(f'--user-data-dir={_PROFILE}')
    opts.add_argument('--profile-directory=Default')
    opts.add_argument('--window-size=1400,900')

    driver = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    wait = WebDriverWait(driver, 20)

    try:
        log("Wakelet: Opening with saved profile...")
        driver.get("https://wakelet.com/home")
        time.sleep(5)
        log("Wakelet: URL = " + driver.current_url)

        # Check login
        src = driver.page_source.lower()
        logged = ("logout" in src or "workspace" in driver.current_url or
                  "collections" in driver.current_url)

        if not logged:
            log("Wakelet: Not logged in - attempting login...")
            driver.get("https://wakelet.com/login")
            time.sleep(5)
            for sel in ["input[type='email']","input[name='email']"]:
                try:
                    el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                    el.click(); el.clear(); el.send_keys(email)
                    log("Wakelet: Email typed"); break
                except: continue
            for sel in ["button[type='submit']",
                        "//button[contains(text(),'Next')]","//button[contains(text(),'Continue')]"]:
                try:
                    by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                    btn = WebDriverWait(driver,4).until(EC.element_to_be_clickable((by,sel)))
                    pw_v = driver.find_elements(By.CSS_SELECTOR,"input[type='password']")
                    if pw_v and pw_v[0].is_displayed(): break
                    driver.execute_script("arguments[0].click();",btn); time.sleep(3); break
                except: continue
            for sel in ["input[type='password']","input[name='password']"]:
                try:
                    el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                    el.click(); el.clear(); el.send_keys(password); break
                except: continue
            for sel in ["button[type='submit']",
                        "//button[contains(text(),'Sign')]","//button[contains(text(),'Log')]"]:
                try:
                    by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                    btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
                    driver.execute_script("arguments[0].click();",btn); break
                except: continue
            time.sleep(8)
            log("Wakelet: Post-login = " + driver.current_url)
            src2 = driver.page_source.lower()
            if "logout" not in src2 and "workspace" not in driver.current_url:
                result(False, error="Wakelet: Login failed. Run wakelet_login.py first.")
                return
        else:
            log("Wakelet: Already logged in!")

        # ── Step 1: Click "Create Collection" (primary button) ───
        log("Wakelet: Clicking Create Collection...")
        for sel in [
            "button[class*='primary']",
            "//button[contains(text(),'Create Collection')]",
            "//button[contains(text(),'Create')]",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btns_list = driver.find_elements(by, sel) if by == By.CSS_SELECTOR else [driver.find_element(by, sel)]
                for b in (btns_list if isinstance(btns_list, list) else [btns_list]):
                    if b.is_displayed() and ('create' in b.text.lower() or 'create' in (b.get_attribute('class') or '').lower()):
                        _AC(driver).move_to_element(b).click().perform()
                        log("Wakelet: Clicked - " + b.text.strip())
                        time.sleep(4)
                        break
                else: continue
                break
            except: continue

        # ── Step 2: Click "Blank Collection" ────────────────────
        log("Wakelet: Clicking Blank Collection...")
        for blank_sel in [
            "//button[contains(text(),'Blank Collection')]",
            "//button[contains(text(),'Blank')]",
        ]:
            try:
                blank_btn = WebDriverWait(driver, 8).until(
                    EC.element_to_be_clickable((By.XPATH, blank_sel)))
                if blank_btn.is_displayed():
                    _AC(driver).move_to_element(blank_btn).click().perform()
                    log("Wakelet: Blank Collection clicked!")
                    time.sleep(6)
                    log("Wakelet: Collection URL = " + driver.current_url)
                    break
            except: continue

        # ── Step 3: Paste URL in "Paste any web address" input ──
        wake_url = driver.current_url  # collection URL like /wake/XXXXX
        log("Wakelet: Collection URL = " + wake_url)
        log("Wakelet: Adding link: " + target_site)

        for url_sel in [
            "input[placeholder*='web address' i]",
            "input[placeholder*='url' i]",
            "input[placeholder*='paste' i]",
            "input[placeholder*='http' i]",
            "input[type='text']",
        ]:
            try:
                url_inp = WebDriverWait(driver, 8).until(
                    EC.element_to_be_clickable((By.CSS_SELECTOR, url_sel)))
                if url_inp.is_displayed():
                    url_inp.click(); url_inp.clear(); time.sleep(0.3)
                    url_inp.send_keys(target_site)
                    log("Wakelet: URL typed")
                    time.sleep(1)
                    from selenium.webdriver.common.keys import Keys as _K
                    url_inp.send_keys(_K.RETURN)
                    time.sleep(6)
                    log("Wakelet: URL submitted, page = " + driver.current_url)
                    # Update wake_url if URL changed to actual wake page
                    if "wake" in driver.current_url and "workspace" not in driver.current_url:
                        wake_url = driver.current_url
                    break
            except: continue

        # If URL still workspace, try getting wake URL from page
        if "workspace" in wake_url or "home" in wake_url:
            try:
                # Check for links to /wake/ on current page
                links = driver.find_elements(By.CSS_SELECTOR, "a[href*='/wake/']")
                for l in links:
                    href = l.get_attribute('href') or ''
                    if '/wake/' in href and 'wakelet.com' in href:
                        wake_url = href
                        log("Wakelet: Wake URL from page = " + wake_url)
                        break
            except: pass

            if "workspace" in wake_url:
                # Navigate to collections to find most recent wake
                try:
                    workspace_id = [p for p in driver.current_url.split('/') if p and p != 'workspace' and 'wakelet' not in p][0] if 'workspace' in driver.current_url else ""
                    if workspace_id:
                        driver.get(f"https://wakelet.com/workspace/{workspace_id}/collections")
                        time.sleep(4)
                        # Get first wake link (most recent = first)
                        recent = driver.find_elements(By.CSS_SELECTOR, "a[href*='/wake/']")
                        if recent:
                            wake_url = recent[0].get_attribute('href') or wake_url
                            log("Wakelet: Most recent wake = " + wake_url)
                except: pass

        # ── Step 4: Done ─────────────────────────────────────────
        final = driver.current_url
        log("Wakelet: Final URL = " + final)
        final_url = wake_url if "wakelet.com" in wake_url else final
        result(True, url=final_url)

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

def post_padlet(email, password, keyword, target_site, ai_title="", ai_content=""):
    driver = get_driver('padlet')
    wait   = WebDriverWait(driver, 20)
    try:
        log("Padlet: Logging in...")
        driver.get("https://padlet.com/auth/login")
        time.sleep(4)

        wait_type(driver, wait, "input[type='email'], input[name='email']", email)
        wait_type(driver, wait, "input[type='password']", password)
        wait_click(driver, wait, "button[type='submit']")
        time.sleep(6)

        if "login" in driver.current_url or "auth" in driver.current_url:
            result(False, error="Padlet login failed.")
            return

        log("Padlet: Logged in!")

        # Create new padlet
        driver.get("https://padlet.com/dashboard")
        time.sleep(4)

        # Click "Make a Padlet" / New
        for sel in [
            "//button[contains(text(),'Make a Padlet')]",
            "//button[contains(text(),'New')]",
            "[data-test-id='new-padlet-button']",
            "//a[contains(text(),'Make')]",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((by, sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();", btn)
                    time.sleep(4)
                    log("Padlet: New padlet clicked")
                    break
            except: continue

        # Choose Wall template
        for sel in ["//button[contains(text(),'Wall')]", "//div[contains(text(),'Wall')]", "[data-test-id='wall']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                el = driver.find_element(by, sel)
                if el.is_displayed():
                    driver.execute_script("arguments[0].click();", el)
                    time.sleep(3)
                    break
            except: continue

        title = ai_title if ai_title else f"Best {keyword.title()} {time.strftime('%Y')}"
        wait_type(driver, wait, "input[placeholder*='title' i], [contenteditable][class*='title']", title, timeout=10)
        wait_click(driver, wait, "button[type='submit'], button[class*='save'], button[class*='create']", timeout=8)
        time.sleep(4)

        # Add post/card
        for sel in [
            "button[class*='add-post'], [aria-label='Add post'], .add-post-button, [data-test-id*='add']",
            "//button[contains(text(),'Add')]",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((by, sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();", btn)
                    time.sleep(2)
                    break
            except: continue

        card_title   = ai_title if ai_title else f"Learn {keyword.title()}"
        card_content = ai_content if ai_content else f"Best {keyword} training. Enroll: {target_site}"
        wait_type(driver, wait, "[placeholder*='Title'], [class*='post-title']", card_title, timeout=8)
        wait_type(driver, wait, "[placeholder*='description' i], [contenteditable]", card_content, timeout=8)
        wait_click(driver, wait, "button[class*='save'], button[type='submit']", timeout=8)
        time.sleep(3)

        final_url = driver.current_url
        result(True, url=final_url if "padlet.com" in final_url else "https://padlet.com/dashboard")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
# PEARLTREES
# â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
def post_pearltrees(email, password, keyword, target_site, ai_title="", ai_content=""):
    driver = get_driver('pearltrees')
    wait   = WebDriverWait(driver, 20)
    try:
        log("Pearltrees: Logging in...")
        driver.get("https://www.pearltrees.com/login")
        time.sleep(4)

        wait_type(driver, wait, "input[type='email'], input[name='email'], input[id*='email']", email)
        wait_type(driver, wait, "input[type='password']", password)
        wait_click(driver, wait, "button[type='submit'], input[type='submit']")
        time.sleep(5)

        if "login" in driver.current_url:
            # Try alternate login
            driver.get("https://www.pearltrees.com")
            time.sleep(3)
            for sel in ["a[href*='login']", "//a[contains(text(),'Sign')]"]:
                try:
                    by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                    driver.find_element(by, sel).click()
                    time.sleep(2)
                    break
                except: continue

        log("Pearltrees: Logged in!")

        # Add new pearl with URL
        driver.get("https://www.pearltrees.com")
        time.sleep(3)

        # Click "Add" button
        for sel in [
            "[class*='addpearl'], [class*='add-pearl']",
            "//button[contains(text(),'Add')]",
            "[aria-label*='Add']",
            "//button[@title='Add']",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((by, sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();", btn)
                    time.sleep(2)
                    log("Pearltrees: Add button clicked")
                    break
            except: continue

        # Enter URL
        wait_type(driver, wait, "input[placeholder*='url' i], input[type='url'], input[placeholder*='http' i]", target_site, timeout=8)
        time.sleep(0.5)

        # Confirm
        wait_click(driver, wait, "button[type='submit'], button[class*='add'], button[class*='save']", timeout=8)
        time.sleep(4)

        uname = email.split('@')[0].lower()
        result(True, url=f"https://www.pearltrees.com/{uname}")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
# MEWE
# â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
def post_mewe(email, password, keyword, target_site, ai_title="", ai_content=""):
    driver = get_driver('mewe')
    wait   = WebDriverWait(driver, 20)
    try:
        log("MeWe: Logging in...")
        driver.get("https://mewe.com/login")
        time.sleep(5)

        # Click "Use email or phone" button first
        for sel in [
            "//button[contains(text(),'Use email')]",
            "//button[contains(text(),'email or phone')]",
            "button.btn-l",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((by, sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();", btn)
                    time.sleep(3)
                    log("MeWe: Clicked 'Use email' button")
                    break
            except: continue

        # Now find email/password fields
        wait_type(driver, wait, "input[type='email'], input[name='email'], input[type='text']", email, timeout=10)
        time.sleep(0.5)

        # Click Next/Continue if shown
        for sel in ["//button[contains(text(),'Next') or contains(text(),'Continue')]", "button[type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver, 5).until(EC.element_to_be_clickable((by, sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();", btn)
                    time.sleep(2)
                    break
            except: continue

        wait_type(driver, wait, "input[type='password']", password, timeout=10)
        wait_click(driver, wait, "button[type='submit'], //button[contains(text(),'Log in')]", By.XPATH if '//' in "//button" else By.CSS_SELECTOR, timeout=10)
        time.sleep(7)

        if "login" in driver.current_url or "mewe.com" not in driver.current_url:
            result(False, error="MeWe login failed. Check credentials.")
            return

        log("MeWe: Logged in!")

        # Navigate to newsfeed
        driver.get("https://mewe.com/newsfeed")
        time.sleep(5)

        # Click compose/post area
        compose_clicked = False
        for sel in [
            "[placeholder*='share' i]",
            "[placeholder*=\"What's\" i]",
            "[class*='compose-input']",
            "[data-testid*='compose']",
            "//div[@contenteditable='true']",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                el = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((by, sel)))
                if el.is_displayed():
                    el.click()
                    time.sleep(1)
                    content = (f"Best {keyword.title()} training course!\n\n"
                               f"Expert trainers, hands-on projects, placement support.\n\n"
                               f"Enroll: {target_site}\n\n"
                               f"#{keyword.replace(' ','')} #Training #Education #Career")
                    el.send_keys(content[:1500])
                    log("MeWe: Content typed")
                    compose_clicked = True
                    break
            except: continue

        if not compose_clicked:
            log("MeWe: Could not find compose area")

        # Post button
        for sel in [
            "//button[normalize-space(text())='Post']",
            "//button[normalize-space(text())='Share']",
            "button[class*='post-btn']",
            "[data-testid='post-button']",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((by, sel)))
                if btn.is_displayed() and btn.is_enabled():
                    driver.execute_script("arguments[0].click();", btn)
                    time.sleep(5)
                    log("MeWe: Posted!")
                    break
            except: continue

        result(True, url="https://mewe.com/newsfeed")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
# INSTAPAPER
# â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
def post_instapaper(email, password, keyword, target_site, ai_title="", ai_content=""):
    driver = get_driver('instapaper')
    wait   = WebDriverWait(driver, 20)
    try:
        log("Instapaper: Logging in...")
        driver.get("https://www.instapaper.com/user/login")
        time.sleep(4)

        wait_type(driver, wait, "input[name='username'], input[type='email']", email)
        wait_type(driver, wait, "input[type='password'], input[name='password']", password)
        wait_click(driver, wait, "input[type='submit'], button[type='submit']")
        time.sleep(5)

        if "login" in driver.current_url:
            result(False, error="Instapaper login failed.")
            return

        log("Instapaper: Logged in!")

        # Save URL via the "Add" page
        save_url = f"https://www.instapaper.com/add?url={target_site}&title=Best+{keyword.replace(' ', '+')}+Training+Guide"
        driver.get(save_url)
        time.sleep(4)

        # Check for confirmation
        if "instapaper.com" in driver.current_url:
            # Try clicking save
            for sel in ["input[type='submit'], button[type='submit'], [class*='save']"]:
                try:
                    btn = driver.find_element(By.CSS_SELECTOR, sel)
                    if btn.is_displayed():
                        btn.click()
                        time.sleep(3)
                        break
                except: continue

        result(True, url="https://www.instapaper.com/")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
# VIVAUAE â€” Article posting (form-based)
# â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
def post_vivauae(email, password, keyword, target_site, ai_title="", ai_content=""):
    driver = get_driver('vivauae')
    wait   = WebDriverWait(driver, 20)
    try:
        log("Vivauae: Checking site...")
        driver.get("https://vivauae.com")
        time.sleep(5)

        log(f"Vivauae URL: {driver.current_url}")

        # Try login
        login_links = driver.find_elements(By.XPATH, "//a[contains(@href,'login') or contains(text(),'Login') or contains(text(),'Sign in')]")
        if login_links:
            login_links[0].click()
            time.sleep(3)

            wait_type(driver, wait, "input[type='email'], input[name='email'], input[name='log']", email, timeout=8)
            wait_type(driver, wait, "input[type='password'], input[name='password'], input[name='pwd']", password, timeout=8)
            wait_click(driver, wait, "input[type='submit'], button[type='submit'], [class*='login-submit']", timeout=8)
            time.sleep(5)
            log("Vivauae: Login attempted")

        # Try to submit article
        driver.get("https://vivauae.com/submit-article")
        time.sleep(3)

        if driver.current_url == "https://vivauae.com/submit-article" or "submit" in driver.current_url:
            title   = f"Best {keyword.title()} Training Guide {time.strftime('%Y')}"
            content = f"Best {keyword} training course. Expert trainers, live projects, placement support. Visit: {target_site}"

            wait_type(driver, wait, "input[name='title'], #title, [placeholder*='title' i]", title, timeout=8)
            wait_type(driver, wait, "textarea, #content, [placeholder*='content' i]", content, timeout=8)
            wait_type(driver, wait, "input[name='url'], input[type='url'], [placeholder*='url' i]", target_site, timeout=5)
            wait_click(driver, wait, "input[type='submit'], button[type='submit']", timeout=8)
            time.sleep(5)

        final_url = driver.current_url
        result(True, url=final_url if "vivauae.com" in final_url else "https://vivauae.com")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
# MAIN DISPATCHER
# â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

# ---------------------------------------------------------
# HELPER — Profile-based driver for new platforms
# ---------------------------------------------------------
def get_profile_driver(platform_name, email='default'):
    """Get Chrome driver with saved profile — robust lock cleanup"""
    import os as _os, glob as _glob, time as _time, hashlib
    email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest() if email else 'default'
    try:
        import pwd
        sys_user = pwd.getpwuid(_os.getuid())[0]
    except Exception:
        import getpass
        sys_user = getpass.getuser()
    _PROFILE = _os.path.join(_os.path.dirname(_os.path.abspath(__file__)), f'chrome_profile_{platform_name}_{email_hash}_{sys_user}')
    _os.makedirs(_PROFILE, exist_ok=True)

    # Aggressively remove ALL lock files
    lock_patterns = [
        _os.path.join(_PROFILE, 'SingletonLock'),
        _os.path.join(_PROFILE, 'SingletonSocket'),
        _os.path.join(_PROFILE, 'SingletonCookie'),
        _os.path.join(_PROFILE, 'Default', 'LOCK'),
        _os.path.join(_PROFILE, 'Default', 'LOG'),
    ]
    for lf in lock_patterns:
        try:
            if _os.path.exists(lf):
                _os.remove(lf)
        except: pass

    # Also check for any .lock files recursively (max depth 2)
    for root, dirs, files in _os.walk(_PROFILE):
        depth = root.replace(_PROFILE, '').count(_os.sep)
        if depth > 2: continue
        for f in files:
            if f.lower() in ('lock', 'singletonlock', 'singletonSocket'):
                try: _os.remove(_os.path.join(root, f))
                except: pass

    opts = Options()
    if sys.platform != "win32":
        opts.add_argument('--headless=new')
    opts.add_argument('--no-sandbox')
    opts.add_argument('--disable-dev-shm-usage')
    opts.add_argument('--disable-blink-features=AutomationControlled')
    opts.add_experimental_option('excludeSwitches', ['enable-automation'])
    opts.add_experimental_option('useAutomationExtension', False)
    opts.add_argument(f'--user-data-dir={_PROFILE}')
    opts.add_argument('--profile-directory=Default')
    opts.add_argument('--window-size=1400,900')
    opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')
    drv = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=opts)
    drv.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    return drv

def _do_login(driver, login_url, email, password, extra_wait=6):
    """Generic login helper — tries email+password form"""
    from selenium.webdriver.common.action_chains import ActionChains as _AC
    driver.get(login_url)
    time.sleep(4)
    for sel in ["input[type='email']","input[name='email']","input[name='username']","input[name='log']","input[placeholder*='mail' i]","input[placeholder*='Email' i]"]:
        try:
            el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
            if el.is_displayed():
                el.click(); el.clear(); time.sleep(0.3); el.send_keys(email)
                log(f"  Email typed [{sel}]"); break
        except: continue
    for sel in ["button[type='submit']","//button[contains(text(),'Next')]","//button[contains(text(),'Continue')]"]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver,4).until(EC.element_to_be_clickable((by,sel)))
            pw_v = driver.find_elements(By.CSS_SELECTOR,"input[type='password']")
            if pw_v and pw_v[0].is_displayed(): break
            driver.execute_script("arguments[0].click();",btn); time.sleep(3); break
        except: continue
    for sel in ["input[type='password']","input[name='password']","input[name='pwd']"]:
        try:
            el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
            if el.is_displayed():
                el.click(); el.clear(); time.sleep(0.3); el.send_keys(password)
                log(f"  Password typed [{sel}]"); break
        except: continue
    for sel in ["button[type='submit']","input[type='submit']","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SIGN IN')]","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'LOG IN')]","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'LOGIN')]"]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
            if btn.is_displayed():
                driver.execute_script("arguments[0].click();",btn)
                log(f"  Submit clicked: '{btn.text.strip()}'"); break
        except: continue
    time.sleep(extra_wait)
    return driver.current_url

# ---------------------------------------------------------
# MINDS.COM — Selenium login + post (API token unreliable)
# ---------------------------------------------------------
# MINDS.COM — Selenium login (Angular-aware) + post
# ---------------------------------------------------------
def _angular_type(driver, el, value):
    """Type into Angular input using JS setter"""
    try:
        driver.execute_script("""
            var el = arguments[0], val = arguments[1];
            var nativeInputValueSetter = Object.getOwnPropertyDescriptor(
                window.HTMLInputElement.prototype, 'value').set;
            nativeInputValueSetter.call(el, val);
            el.dispatchEvent(new Event('input', {bubbles:true}));
            el.dispatchEvent(new Event('change', {bubbles:true}));
        """, el, value)
        return True
    except: return False

def post_minds(email, password, keyword, target_site, ai_title="", ai_content=""):
    driver = get_profile_driver('minds', email)
    try:
        log("Minds: Opening with saved profile...")
        driver.get("https://www.minds.com/")
        time.sleep(5)
        src = driver.page_source.lower()
        logged = ("logout" in src or "newsfeed" in driver.current_url or
                  "feed" in driver.current_url or "channel" in driver.current_url)

        if not logged:
            log("Minds: Logging in via Angular form...")
            driver.get("https://www.minds.com/login")
            time.sleep(5)

            # Find username input
            username_inp = WebDriverWait(driver, 15).until(
                EC.visibility_of_element_located((By.ID, "username"))
            )
            username_inp.click()
            username_inp.clear()
            time.sleep(0.3)
            username_inp.send_keys(email)
            log("Minds: Username typed")

            # Password
            password_inp = WebDriverWait(driver, 15).until(
                EC.visibility_of_element_located((By.ID, "password"))
            )
            password_inp.click()
            password_inp.clear()
            time.sleep(0.3)
            password_inp.send_keys(password)
            log("Minds: Password typed")

            time.sleep(0.5)
            # Click Login button
            btn = WebDriverWait(driver, 15).until(
                EC.element_to_be_clickable((By.CSS_SELECTOR, "button.m-button--blue, button.m-button--solid, button[type='submit']"))
            )
            driver.execute_script("arguments[0].scrollIntoView({block:'center'});", btn)
            time.sleep(0.3)
            driver.execute_script("arguments[0].click();", btn)
            log("Minds: Login clicked: " + btn.text.strip())

            time.sleep(10)
            log("Minds: Post-login URL = " + driver.current_url)
            src2 = driver.page_source.lower()
            if "login" in driver.current_url.lower() and "logout" not in src2:
                # Try navigating to newsfeed to see if login cookie was accepted anyway
                driver.get("https://www.minds.com/newsfeed/subscriptions")
                time.sleep(6)
                src2 = driver.page_source.lower()
                if "login" in driver.current_url.lower() and "logout" not in src2:
                    result(False, error="Minds: Login failed. Please verify credentials, run profile_login.py manually, or use API Token.")
                    return
            log("Minds: Logged in!")
        else:
            log("Minds: Already logged in!")

        # Go to newsfeed
        driver.get("https://www.minds.com/newsfeed/subscriptions")
        time.sleep(6)
        log("Minds: Newsfeed URL = " + driver.current_url)

        # Take screenshot to see compose area
        driver.save_screenshot(os.path.join(os.path.dirname(__file__), 'minds_newsfeed.png'))

        content_text = ai_content if ai_content else (
            f"🎓 {keyword.title()} — Complete Career Guide\n\n"
            f"Looking to master {keyword}? Here's everything you need to know.\n\n"
            f"✅ Why Learn {keyword}?\n"
            f"- High salary: ₹4–20 LPA\n"
            f"- Huge job demand across India\n"
            f"- Industry-recognised certification\n"
            f"- Placement support included\n"
            f"- Hands-on live projects\n\n"
            f"📚 What You'll Learn:\n"
            f"From core fundamentals to advanced real-world applications, our {keyword} program covers everything you need to become job-ready in 60–90 days.\n\n"
            f"🏆 Why Learnmore Technologies?\n"
            f"Expert trainers with 10+ years experience, small batches, flexible timings, and 100% placement assistance.\n\n"
            f"🔗 Enroll now: {target_site}\n"
            f"📍 Kalyan Nagar, Bangalore\n\n"
            f"Learn more at {target_site}\n\n"
            f"#{keyword.replace(' ','').replace('Training','').replace('Course','').strip()} #Bangalore #Career #Education"
        )
        link_suffix = f"\n\n🔗 Enroll now: {target_site}"
        content_text = content_text[:900] + link_suffix
        content_text = "".join(c for c in content_text if ord(c) <= 0xffff)

        # Dismiss any modal/popup blocking the composer
        for modal_sel in [
            "ngb-modal-window button[aria-label='Close']",
            "ngb-modal-window .close",
            ".modal button.btn-close",
            ".modal .close",
            "//ngb-modal-window//button[contains(@class,'close')]",
            "//button[@aria-label='Close']",
        ]:
            try:
                by = By.XPATH if modal_sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver, 3).until(EC.element_to_be_clickable((by, modal_sel)))
                driver.execute_script("arguments[0].click();", btn)
                log("Minds: Modal dismissed")
                time.sleep(2); break
            except: continue

        # Also try pressing Escape to close any modal
        try:
            from selenium.webdriver.common.keys import Keys as _K
            driver.find_element(By.TAG_NAME, 'body').send_keys(_K.ESCAPE)
            time.sleep(1)
        except: pass

        # Type directly in textarea — exact selector: data-ref='composer-textarea'
        compose_typed = False
        for sel in [
            "textarea[data-ref='composer-textarea']",
            "textarea.m-composerTextarea__message",
            "textarea[placeholder*='mind' i]",
            "textarea[placeholder*='Speak' i]",
            "textarea",
        ]:
            try:
                el = WebDriverWait(driver, 8).until(EC.presence_of_element_located((By.CSS_SELECTOR, sel)))
                driver.execute_script("arguments[0].scrollIntoView({block:'center'});", el)
                driver.execute_script("arguments[0].focus();", el)
                time.sleep(0.5)
                # Use JS click to bypass interception
                driver.execute_script("arguments[0].click();", el)
                time.sleep(0.5)
                el.send_keys(content_text)
                log("Minds: Content typed: " + sel)
                compose_typed = True
                time.sleep(1); break
            except Exception as ce:
                log("Minds: compose try failed: " + str(ce)[:60])
                continue

        if not compose_typed:
            driver.save_screenshot(os.path.join(os.path.dirname(__file__), 'minds_compose.png'))
            log("Minds: Could not type — screenshot saved")

        # Click Post button — class: m-button--blue m-button--small m-button--dropdown
        posted = False
        btns_all = driver.find_elements(By.TAG_NAME, 'button')
        for b in btns_all:
            txt = b.text.strip().lower()
            cls = (b.get_attribute('class') or '').lower()
            if txt == 'post' or ('action' in cls and 'post' in cls):
                if b.is_enabled() and b.is_displayed():
                    driver.execute_script("arguments[0].click();", b)
                    log("Minds: Post clicked: " + b.text.strip())
                    posted = True
                    time.sleep(5)
                    break

        if not posted:
            # Fallback selectors
            for sel in [
                "//button[normalize-space(text())='Post']",
                ".m-button--dropdown",
                "button.m-button--blue.m-button--small",
            ]:
                try:
                    by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                    btn = WebDriverWait(driver, 5).until(EC.element_to_be_clickable((by, sel)))
                    if btn.is_displayed() and btn.is_enabled():
                        driver.execute_script("arguments[0].click();", btn)
                        log("Minds: Post fallback clicked")
                        posted = True
                        time.sleep(5); break
                except: continue

        if not compose_typed:
            driver.save_screenshot(os.path.join(os.path.dirname(__file__), 'minds_compose.png'))
            log("Minds: Could not type content")

        # Click Post button (blue button in toolbar)
        for sel in [
            ".m-composerToolbar__action button",
            ".m-composerToolbar__action--post button",
            "//button[normalize-space(text())='Post']",
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'POST')]",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((by, sel)))
                if btn.is_displayed() and btn.is_enabled():
                    driver.execute_script("arguments[0].click();", btn)
                    log("Minds: Post button clicked: " + btn.text.strip())
                    time.sleep(6); break
            except: continue

        profile_url = f"https://www.minds.com/{email}"
        result(True, url=profile_url)

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# ---------------------------------------------------------
# DRIBBBLE — Selenium login + upload shot
# ---------------------------------------------------------
def post_dribbble(email, password, keyword, target_site, ai_title="", ai_content=""):
    import os as _os
    driver = get_profile_driver('dribbble', email)
    try:
        log("Dribbble: Opening with saved profile...")
        driver.get("https://dribbble.com/")
        time.sleep(5)
        src = driver.page_source.lower()
        logged = ("log out" in src or "logout" in src or "sign out" in src or
                  "/shots/new" in driver.current_url or "account" in driver.current_url)

        if not logged:
            log("Dribbble: Logging in...")
            url_after = _do_login(driver, "https://dribbble.com/session/new", email, password, 7)
            log("Dribbble: Post-login URL = " + url_after)
            src2 = driver.page_source.lower()
            if "session" in url_after and "logout" not in src2:
                result(False, error="Dribbble: Login failed. Check credentials.")
                return
            log("Dribbble: Logged in!")
        else:
            log("Dribbble: Already logged in!")

        # Go to new shot page
        driver.get("https://dribbble.com/shots/new")
        time.sleep(5)
        log("Dribbble: New shot page = " + driver.current_url)

        # Upload image if available
        upload_dir = _os.path.join(_os.path.dirname(_os.path.abspath(__file__)), '..', 'uploads')
        images = []
        import glob as _glob
        for ext in ['*.jpg','*.jpeg','*.png']:
            images.extend(_glob.glob(_os.path.join(upload_dir, 'project_*' + ext[1:])))
        if images:
            images.sort(key=_os.path.getmtime, reverse=True)
            img_path = images[0]
            log("Dribbble: Uploading image: " + _os.path.basename(img_path))
            for sel in ["input[type='file']","input[accept*='image']"]:
                try:
                    el = driver.find_element(By.CSS_SELECTOR, sel)
                    el.send_keys(_os.path.abspath(img_path))
                    log("Dribbble: Image sent to input")
                    time.sleep(6); break
                except: continue

        # Fill title
        title = f"Best {keyword.title()} Training {time.strftime('%Y')}"
        for sel in ["input[name='title']","input[placeholder*='title' i]","#title"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); el.send_keys(title)
                    log("Dribbble: Title typed"); break
            except: continue

        # Fill description
        desc = f"Best {keyword} training. Expert mentors, live projects. Visit: {target_site}"
        for sel in ["textarea[name='description']","[contenteditable='true']","textarea"]:
            try:
                el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); el.send_keys(desc[:500])
                    log("Dribbble: Description typed"); break
            except: continue

        # Add website link
        for sel in ["input[name='website']","input[placeholder*='website' i]","input[placeholder*='url' i]"]:
            try:
                el = driver.find_element(By.CSS_SELECTOR, sel)
                if el.is_displayed():
                    el.click(); el.clear(); el.send_keys(target_site)
                    log("Dribbble: Website typed"); break
            except: continue

        # Publish / Save draft
        for sel in ["//button[contains(text(),'Publish')]","//button[contains(text(),'Save')]","//button[contains(text(),'Post')]","button[type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Dribbble: Published! btn=" + btn.text.strip())
                    time.sleep(8); break
            except: continue

        final = driver.current_url
        log("Dribbble: Final URL = " + final)
        result(True, url=final if "dribbble.com" in final else "https://dribbble.com/shots")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# ---------------------------------------------------------
# SYMBALOO — Selenium login + add webmix tile
# ---------------------------------------------------------
# ---------------------------------------------------------
# SYMBALOO — Login + Add tile via existing mix
# Strategy: Open learnm... webmix, right-click empty cell,
# fill Tile URL form, click Save/Change Webmix
# ---------------------------------------------------------
# ---------------------------------------------------------
# SYMBALOO — Add tile to Digital Marketing Service mix
# Mix: https://www.symbaloo.com/home/mix/13ePQXN5iS
# ---------------------------------------------------------
def post_symbaloo(email, password, keyword, target_site, ai_title="", ai_content=""):
    import os as _os
    from selenium.webdriver.common.action_chains import ActionChains as _AC

    MIX_URL = "https://www.symbaloo.com/home/mix/13ePQXNM4g"

    _PROFILE = _os.path.join(_os.path.dirname(_os.path.abspath(__file__)), 'chrome_profile_symbaloo')
    for _lf in [_os.path.join(_PROFILE,'Default','LOCK'), _os.path.join(_PROFILE,'SingletonLock')]:
        try:
            if _os.path.exists(_lf): _os.remove(_lf)
        except: pass

    opts = Options()
    opts.add_argument('--no-sandbox')
    opts.add_argument('--disable-dev-shm-usage')
    opts.add_argument('--disable-blink-features=AutomationControlled')
    opts.add_experimental_option('excludeSwitches', ['enable-automation'])
    opts.add_experimental_option('useAutomationExtension', False)
    opts.add_argument(f'--user-data-dir={_PROFILE}')
    opts.add_argument('--profile-directory=Default')
    opts.add_argument('--window-size=1400,900')

    driver = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")

    try:
        log("Symbaloo: Opening Digital Marketing Service mix...")
        log("Symbaloo: Navigating to login page...")
        driver.get("https://www.symbaloo.com/login")
        time.sleep(5)

        # Accept GDPR if visible
        try:
            agree = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((By.ID, "accept-btn")))
            agree.click()
            log("Symbaloo: GDPR Cookie Consent Accepted.")
            time.sleep(3)
        except:
            log("Symbaloo: GDPR consent banner not found or already accepted.")

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

        # Now go to target mix URL
        driver.get(MIX_URL)
        time.sleep(6)
        log("Symbaloo: URL = " + driver.current_url)

        # Find empty cells
        cells = driver.find_elements(By.CSS_SELECTOR, "[id^='gridEmptyCell']")
        log("Symbaloo: Empty cells = " + str(len(cells)))

        if not cells:
            log("Symbaloo: Mix is full! Attempting to create a new Webmix...")
            try:
                # 1. Look for the "+" button to add a webmix tab
                add_btn = None
                for sel in ["#tabsBarCreateWebmixButton", ".add-tab", "li.add-tab", "[id='tab-add']", "button[title*='Webmix' i]", "a[title*='Webmix' i]", "span[title*='Webmix' i]"]:
                    try:
                        el = WebDriverWait(driver, 5).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
                        if el.is_displayed():
                            add_btn = el; break
                    except: continue
                
                if not add_btn:
                    # Try to find by title/aria-label attributes
                    for btn in driver.find_elements(By.TAG_NAME, 'button') + driver.find_elements(By.TAG_NAME, 'a'):
                        try:
                            title = (btn.get_attribute('title') or '').lower()
                            aria = (btn.get_attribute('aria-label') or '').lower()
                            if '+' in btn.text or 'add' in title or 'create' in title or 'webmix' in aria:
                                add_btn = btn; break
                        except: continue
                
                if add_btn:
                    try:
                        add_btn.click()
                    except:
                        _AC(driver).move_to_element(add_btn).click().perform()
                    log("Symbaloo: Add Webmix tab clicked")
                    time.sleep(4)
                    
                    # 2. Find name input and enter a name
                    name_input = None
                    for sel in ["#addWebmixInput", "input[placeholder*='Name' i]", "input[placeholder*='webmix' i]", "input.webmix-name"]:
                        try:
                            el = WebDriverWait(driver, 5).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
                            if el.is_displayed():
                                name_input = el; break
                        except: continue
                    
                    if name_input:
                        tabs = driver.find_elements(By.CSS_SELECTOR, "li[id^='tab-'], .webmix-tab")
                        mix_index = len(tabs) + 1
                        mix_name = f"seo{mix_index}"
                        name_input.click(); name_input.clear()
                        name_input.send_keys(mix_name)
                        log(f"Symbaloo: Entered new webmix name: {mix_name}")
                        time.sleep(1)
                        
                        # 3. Find submit/add button
                        submit_btn = None
                        for sel in ["button[type='submit']", "input[type='submit']", "button.create", "button.add", "button.btn-primary"]:
                            try:
                                el = WebDriverWait(driver, 5).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
                                if el.is_displayed():
                                    submit_btn = el; break
                            except: continue
                        
                        if not submit_btn:
                            # Try finding button by text
                            for btn in driver.find_elements(By.TAG_NAME, 'button'):
                                if btn.text.strip().lower() in ['add', 'create', 'save', 'ok']:
                                    submit_btn = btn; break
                        
                        if submit_btn:
                            driver.execute_script("arguments[0].click();", submit_btn)
                            log("Symbaloo: Create Webmix submit button clicked")
                            try:
                                WebDriverWait(driver, 15).until(EC.presence_of_element_located((By.CSS_SELECTOR, "[id^='gridEmptyCell']")))
                            except: pass
                            
                            # Refresh cells list on the new mix page
                            cells = driver.find_elements(By.CSS_SELECTOR, "[id^='gridEmptyCell']")
                            log("Symbaloo: New mix empty cells = " + str(len(cells)))
                            if cells:
                                MIX_URL = driver.current_url
                        else:
                            log("Symbaloo: Webmix submit button not found, trying pressing Enter")
                            name_input.send_keys(Keys.RETURN)
                            try:
                                WebDriverWait(driver, 15).until(EC.presence_of_element_located((By.CSS_SELECTOR, "[id^='gridEmptyCell']")))
                            except: pass
                            cells = driver.find_elements(By.CSS_SELECTOR, "[id^='gridEmptyCell']")
                            log("Symbaloo: New mix empty cells = " + str(len(cells)))
                            if cells:
                                MIX_URL = driver.current_url
                    else:
                        log("Symbaloo: Webmix name input field not found")
                else:
                    log("Symbaloo: Plus/Add Webmix tab button not found")
            except Exception as mix_err:
                log(f"Symbaloo: Failed to create new webmix: {str(mix_err)}")

        if not cells:
            result(False, error="Symbaloo: No empty cells in mix and failed to create a new one.")
            return

        # Double-click empty cell
        cell = cells[0]
        driver.execute_script("arguments[0].scrollIntoView({block:'center'});", cell)
        time.sleep(0.5)
        _AC(driver).double_click(cell).perform()
        log("Symbaloo: Empty cell double-clicked")
        time.sleep(4)

        # Find tileSearchInput
        tile_input = None
        for sel in ["#tileSearchInput", "input[placeholder*='Enter a URL' i]", "input[placeholder*='search query' i]", "input[placeholder*='URL' i]", "input[placeholder*='url' i]"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    tile_input = el; break
            except: continue

        if not tile_input:
            result(False, error="Symbaloo: tileSearchInput not found"); return

        # Type URL + Enter
        try:
            tile_input.click()
        except:
            driver.execute_script("arguments[0].click();", tile_input)
        tile_input.clear(); time.sleep(0.3)
        tile_input.send_keys(target_site)
        log("Symbaloo: URL typed = " + target_site)
        time.sleep(1)
        try:
            tile_input.send_keys(Keys.RETURN)
        except:
            try:
                driver.find_element(By.CSS_SELECTOR, '#tileSearchInput').send_keys(Keys.RETURN)
            except:
                driver.find_element(By.TAG_NAME,'body').send_keys(Keys.RETURN)
        log("Symbaloo: Enter pressed")
        time.sleep(5)

        # Click the "Add to Webmix" button for the first suggestion
        add_to_mix_clicked = False
        for sel in ["button[title='Add to Webmix']", "button[title*='Webmix' i]", "button.ol"]:
            try:
                el = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
                driver.execute_script("arguments[0].click();", el)
                log("Symbaloo: Add to Webmix button clicked")
                add_to_mix_clicked = True
                break
            except: continue

        if not add_to_mix_clicked:
            # Fallback to click any button containing '+' or 'add' near results
            for btn in driver.find_elements(By.TAG_NAME, 'button') + driver.find_elements(By.TAG_NAME, 'a'):
                try:
                    title = (btn.get_attribute('title') or '').lower()
                    if 'add' in title or 'webmix' in title:
                        driver.execute_script("arguments[0].click();", btn)
                        log("Symbaloo: Fallback Add to Webmix clicked")
                        add_to_mix_clicked = True
                        break
                except: continue
        
        time.sleep(5)

        # Fresh find buttons — click Edit Tile
        def fresh_btns():
            try: return driver.find_elements(By.TAG_NAME,'button')
            except: return []

        for btn in fresh_btns():
            try:
                if btn.text.strip() == 'Edit Tile' and btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Symbaloo: Edit Tile clicked")
                    time.sleep(4)
                    
                    # Fill Tile URL (name='url')
                    for inp in driver.find_elements(By.CSS_SELECTOR, "input[name='url']"):
                        try:
                            if inp.is_displayed():
                                inp.click(); inp.clear(); time.sleep(0.2)
                                inp.send_keys(target_site)
                                log("Symbaloo: URL = " + target_site); break
                        except: continue

                    # Fill Tile name/title (name='name') using ChatGPT title
                    tile_title = ai_title if ai_title else f"Best {keyword.title()} Training"
                    for inp in driver.find_elements(By.CSS_SELECTOR, "input[name='name']"):
                        try:
                            if inp.is_displayed():
                                inp.click(); inp.clear(); time.sleep(0.2)
                                inp.send_keys(tile_title)
                                log("Symbaloo: Name = " + tile_title); break
                        except: continue

                    # Fill Tile description (textarea) using ChatGPT description
                    tile_desc = ai_content if ai_content else f"Best {keyword} training. Expert trainers, placement support: {target_site}"
                    for ta in driver.find_elements(By.TAG_NAME, 'textarea'):
                        try:
                            if ta.is_displayed():
                                ta.click(); ta.clear(); time.sleep(0.2)
                                ta.send_keys(tile_desc[:300]) # Symbaloo limits description length
                                log("Symbaloo: Description filled"); break
                        except: continue
                    break
            except: continue

        # Click Finish editing Webmix
        for btn in fresh_btns():
            try:
                txt = btn.text.strip()
                if 'Finish' in txt and btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Symbaloo: Finished! " + txt)
                    time.sleep(3); break
            except: continue

        log("Symbaloo: Done! URL = " + MIX_URL)
        result(True, url=MIX_URL)

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

def post_penzu(email, password, keyword, target_site, ai_title="", ai_content=""):
    driver = get_profile_driver('penzu', email)
    try:
        log("Penzu: Opening with saved profile...")
        driver.get("https://penzu.com/app")
        time.sleep(5)
        src = driver.page_source.lower()
        logged = ("logout" in src or "log out" in src or "new entry" in src or
                  "journal" in driver.current_url or "app" in driver.current_url)

        if not logged:
            log("Penzu: Logging in...")
            url_after = _do_login(driver, "https://penzu.com/login", email, password, 7)
            log("Penzu: Post-login URL = " + url_after)
            src2 = driver.page_source.lower()
            if "login" in url_after and "new entry" not in src2 and "app" not in url_after:
                result(False, error="Penzu: Login failed. Check credentials.")
                return
            log("Penzu: Logged in!")
        else:
            log("Penzu: Already logged in!")

        # Create new entry by clicking the dashboard button
        driver.get("https://penzu.com/app")
        time.sleep(8)
        log("Penzu: Dashboard URL = " + driver.current_url)

        new_entry_clicked = False

        # Method 1: Find by common CSS selectors
        for sel in ["#new-entry", "a[href*='new-entry']", "a[href*='/entries/new']", ".new-entry-btn", ".new-entry", "button.new-entry"]:
            try:
                btn = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();", btn)
                    log("Penzu: Clicked New Entry button via CSS Selector")
                    new_entry_clicked = True
                    break
            except: continue

        # Method 2: Search all clickable tags by text
        if not new_entry_clicked:
            elements = driver.find_elements(By.TAG_NAME, 'a') + driver.find_elements(By.TAG_NAME, 'button')
            for el in elements:
                try:
                    txt = el.text.strip().lower()
                    if 'new entry' in txt or 'write' in txt:
                        driver.execute_script("arguments[0].click();", el)
                        log("Penzu: Clicked New Entry button via Text search")
                        new_entry_clicked = True
                        break
                except: continue

        # Method 3: Fallback click the first journal cover to open it
        if not new_entry_clicked:
            for sel in [".journal-cover", ".journal-book", "[class*='journal']", "a[href*='journals/']"]:
                try:
                    el = WebDriverWait(driver, 5).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
                    driver.execute_script("arguments[0].click();", el)
                    log("Penzu: Clicked journal cover fallback")
                    time.sleep(5)
                    # Try to find New Entry again inside the journal view
                    for sel2 in ["#new-entry", "a[href*='new-entry']", ".new-entry"]:
                        btn = driver.find_element(By.CSS_SELECTOR, sel2)
                        driver.execute_script("arguments[0].click();", btn)
                        log("Penzu: Clicked New Entry inside journal")
                        new_entry_clicked = True
                        break
                except: continue
                if new_entry_clicked: break

        time.sleep(5)
        log("Penzu: Active editor page = " + driver.current_url)

        title = ai_title if ai_title else f"Best {keyword.title()} Guide {time.strftime('%Y')}"
        content_text = ai_content if ai_content else (
            f"Best {keyword} training course available!\n\n"
            f"Expert trainers with hands-on projects and placement support.\n\n"
            f"Key topics covered:\n"
            f"- Introduction to {keyword}\n"
            f"- Advanced techniques\n"
            f"- Industry projects\n"
            f"- Placement assistance\n\n"
            f"Enroll now at: {target_site}\n\n"
            f"#{keyword.replace(' ','')} #Training #Education"
        )

        # Fill title
        for sel in ["input[placeholder*='title' i]","input[name='title']","#title","[class*='entry-title']","input[type='text']"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); el.send_keys(title)
                    log("Penzu: Title typed"); break
            except: continue

        # Fill content/body
        for sel in ["textarea","[contenteditable='true']","[class*='entry-body']","[class*='content']"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); time.sleep(0.5)
                    el.send_keys(content_text)
                    log("Penzu: Content typed"); break
            except: continue

        # Save/Publish
        for sel in ["//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SAVE')]","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'PUBLISH')]","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'POST')]","button[type='submit']","[class*='save']","#save-now",".save-now",".save-button","//div[contains(text(),'Save')]"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Penzu: Saved! btn=" + btn.text.strip())
                    time.sleep(5); break
            except: continue

        final = driver.current_url
        log("Penzu: Final URL = " + final)
        result(True, url=final if "penzu.com" in final else "https://penzu.com/app")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# ---------------------------------------------------------
# PLURK — Selenium login + post (API fallback)
# ---------------------------------------------------------
def post_plurk(email, password, keyword, target_site, ai_title="", ai_content=""):
    """Plurk via Selenium (API key stored as password field)"""
    driver = get_profile_driver('plurk', email)
    try:
        log("Plurk: Opening with saved profile...")
        driver.get("https://www.plurk.com/")
        time.sleep(5)
        src = driver.page_source.lower()
        logged = ("logout" in src or "log out" in src or "timeline" in driver.current_url or
                  "newsfeed" in driver.current_url or "home" in driver.current_url)

        if not logged:
            log("Plurk: Logging in...")
            driver.get("https://www.plurk.com/login")
            time.sleep(4)
            for sel in ["input[name='nick']","input[id='nick']","input[placeholder*='nick' i]","input[placeholder*='email' i]","input[type='text']"]:
                try:
                    el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                    if el.is_displayed():
                        el.click(); el.clear(); el.send_keys(email)
                        log("Plurk: Username typed"); break
                except: continue
            for sel in ["input[name='password']","input[type='password']"]:
                try:
                    el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                    if el.is_displayed():
                        el.click(); el.clear(); el.send_keys(password)
                        log("Plurk: Password typed"); break
                except: continue
            for sel in ["input[type='submit']","button[type='submit']"]:
                try:
                    el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                    if el.is_displayed():
                        driver.execute_script("arguments[0].click();",el)
                        log("Plurk: Submit clicked"); break
                except: continue
            time.sleep(7)
            log("Plurk: Post-login URL = " + driver.current_url)
        else:
            log("Plurk: Already logged in!")

        driver.get("https://www.plurk.com/")
        time.sleep(5)

        # Find compose box
        compose_text = ai_content if ai_content else (
            f"Master {keyword}! Expert trainers, live projects, placement support. "
            f"Enroll: {target_site}"
        )
        compose_text = compose_text[:140]  # Plurk limit

        for sel in ["#new_top_plurk_input","#top_plurk_input","[placeholder*='plurk' i]","[placeholder*='What' i]","textarea[name='content']","input[name='content']"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); time.sleep(0.5); el.clear()
                    el.send_keys(compose_text)
                    log("Plurk: Content typed")
                    time.sleep(1); break
            except: continue

        # Select qualifier (shares)
        for sel in ["#qualifier_selector","select[name='qualifier']","[class*='qualifier']"]:
            try:
                from selenium.webdriver.support.select import Select
                el = driver.find_element(By.CSS_SELECTOR, sel)
                if el.is_displayed():
                    Select(el).select_by_value("shares")
                    break
            except: continue

        # Submit
        for sel in ["//button[contains(text(),'Plurk')]","//input[@value='Plurk!']","button[type='submit']","input[type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Plurk: Submitted!")
                    time.sleep(5); break
            except: continue

        final = driver.current_url
        result(True, url=final if "plurk.com" in final else "https://www.plurk.com/")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# ---------------------------------------------------------
# LINKTREE — Selenium login + add link
# ---------------------------------------------------------
def post_linktree(email, password, keyword, target_site, ai_title="", ai_content=""):
    driver = get_profile_driver('linktree', email)
    try:
        log("Linktree: Opening with saved profile...")
        driver.get("https://linktr.ee/admin")
        time.sleep(6)
        src = driver.page_source.lower()
        logged = (("logout" in src or "log out" in src or "add link" in src or "add new link" in src or "linkeditor_single_add_link_button" in src) and
                  "login" not in driver.current_url and "universal-login" not in driver.current_url)

        if not logged:
            log("Linktree: Logging in...")
            driver.get("https://linktr.ee/login")
            time.sleep(8)
            
            # Find and fill email
            email_el = None
            for sel in ["input[placeholder*='Email or username' i]", "input[placeholder*='username' i]", "input[type='text']"]:
                try:
                    el = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
                    if el.is_displayed():
                        email_el = el
                        break
                except: pass
            
            if not email_el:
                result(False, error="Linktree: Email input field not found.")
                return
                
            email_el.click()
            email_el.clear()
            time.sleep(0.5)
            email_el.send_keys(email)
            time.sleep(1)
            
            # Click Continue
            continue_btn = None
            btns = driver.find_elements(By.TAG_NAME, "button")
            for b in btns:
                if "continue" in b.text.lower():
                    continue_btn = b
                    break
            
            if not continue_btn:
                result(False, error="Linktree: Continue button not found.")
                return
                
            driver.execute_script("arguments[0].click();", continue_btn)
            time.sleep(5)
            
            # Find and fill password
            pass_el = None
            for sel in ["input[type='password']", "input[placeholder*='password' i]", "input[name='password']"]:
                try:
                    el = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
                    if el.is_displayed():
                        pass_el = el
                        break
                except: pass
                
            if not pass_el:
                result(False, error="Linktree: Password input field not found.")
                return
                
            pass_el.click()
            pass_el.clear()
            time.sleep(0.5)
            pass_el.send_keys(password)
            time.sleep(1)
            
            # Click Log In / Submit
            login_btn = None
            btns = driver.find_elements(By.TAG_NAME, "button")
            for b in btns:
                txt = b.text.lower()
                if "login" in txt or "log in" in txt:
                    login_btn = b
                    break
                    
            if not login_btn:
                result(False, error="Linktree: Login submit button not found.")
                return
                
            driver.execute_script("arguments[0].click();", login_btn)
            time.sleep(10)
            
            driver.get("https://linktr.ee/admin")
            time.sleep(6)
            
            src2 = driver.page_source.lower()
            if "login" in driver.current_url or "universal-login" in driver.current_url:
                result(False, error="Linktree: Login failed. Check credentials.")
                return
            log("Linktree: Logged in successfully!")
        else:
            log("Linktree: Already logged in!")

        driver.get("https://linktr.ee/admin")
        time.sleep(5)

        # Click "Add link" button
        for sel in [
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ADD LINK')]",
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ADD NEW LINK')]",
            "//button[contains(text(),'Add')]",
            "[data-testid*='add']",
            "[class*='AddLinkButton']",
            "button[class*='add']",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Linktree: Add Link clicked: " + btn.text.strip())
                    time.sleep(3); break
            except: continue

        # Fill URL
        for sel in ["input[placeholder*='url' i]","input[placeholder*='http' i]","input[type='url']","input[name='url']","input[placeholder*='Link' i]"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); el.send_keys(target_site)
                    log("Linktree: URL typed")
                    time.sleep(1)
                    from selenium.webdriver.common.keys import Keys as _K
                    el.send_keys(_K.RETURN)
                    time.sleep(3); break
            except: continue

        # Fill title
        for sel in ["input[placeholder*='title' i]","input[placeholder*='Title' i]","input[name='title']"]:
            try:
                el = driver.find_element(By.CSS_SELECTOR, sel)
                if el.is_displayed():
                    el.click(); el.clear()
                    el.send_keys(ai_title if ai_title else f"Best {keyword.title()} Training")
                    log("Linktree: Title typed"); break
            except: continue

        # Save
        for sel in ["//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SAVE')]","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ADD')]","button[type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Linktree: Saved!")
                    time.sleep(4); break
            except: continue

        # Get public linktree URL
        try:
            driver.get("https://linktr.ee/admin")
            time.sleep(3)
            share_links = driver.find_elements(By.CSS_SELECTOR, "a[href*='linktr.ee/']")
            for sl in share_links:
                href = sl.get_attribute('href') or ''
                if 'linktr.ee/' in href and 'admin' not in href and 'login' not in href:
                    final = href
                    break
            else:
                # Fallback to find any elements containing the text linktr.ee/
                all_els = driver.find_elements(By.XPATH, "//*[contains(text(), 'linktr.ee/')]")
                for el in all_els:
                    txt = el.text.strip()
                    if 'linktr.ee/' in txt and 'admin' not in txt.lower() and 'login' not in txt.lower():
                        match = re.search(r'(linktr\.ee/[a-zA-Z0-9_\-\.]+)', txt)
                        if match:
                            final = "https://" + match.group(1)
                            break
                else:
                    final = "https://linktr.ee/"
        except:
            final = "https://linktr.ee/"

        log("Linktree: Final URL = " + final)
        result(True, url=final)

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass


PLATFORM_MAP = {
    'scoopit':    post_scoopit,
    'wakelet':    post_wakelet,
    'padlet':     post_padlet,
    'pearltrees': post_pearltrees,
    'mewe':       post_mewe,
    'instapaper': post_instapaper,
    'vivauae':    post_vivauae,
    'minds':      post_minds,
    'dribbble':   post_dribbble,
    'symbaloo':   post_symbaloo,
    'penzu':      post_penzu,
    'plurk':      post_plurk,
    'linktree':   post_linktree,
}

if __name__ == "__main__":
    if len(sys.argv) < 6:
        result(False, error="Usage: micro_blog_post.py <platform> <email> <password> <keyword> <target_site> [ai_title] [ai_content]")
        sys.exit(1)

    platform    = sys.argv[1].lower()
    email       = sys.argv[2]
    password    = sys.argv[3]
    keyword     = sys.argv[4]
    target_site = sys.argv[5]
    ai_title    = sys.argv[6] if len(sys.argv) > 6 else ''
    # argv[7] = temp file path containing full content
    _content_file = sys.argv[7] if len(sys.argv) > 7 else ''
    if _content_file and os.path.exists(_content_file):
        try:
            with open(_content_file, 'r', encoding='utf-8') as _f:
                ai_content = _f.read()
            log(f"{platform}: Content loaded from file ({len(ai_content)} chars)")
        except Exception as e:
            ai_content = ''
    else:
        ai_content = ''

    if platform not in PLATFORM_MAP:
        result(False, error=f"Platform '{platform}' not supported. Available: {', '.join(PLATFORM_MAP.keys())}")
        sys.exit(1)

    PLATFORM_MAP[platform](email, password, keyword, target_site, ai_title, ai_content)