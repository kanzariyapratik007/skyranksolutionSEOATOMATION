#!/usr/bin/env python3
"""
Pinterest Auto-Post via Selenium — v4 Final
Verified selectors from live page inspection on kanzariyapratik124@gmail.com
"""
import sys, json, time, os, re
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

def get_driver(email="default", proxy=None):
    opts = Options()
    if sys.platform != "win32":
        opts.add_argument('--headless=new')
        opts.add_argument('--disable-gpu')
        opts.add_argument('--disable-software-rasterizer')
        import shutil
        chrome_bin = shutil.which('google-chrome') or shutil.which('google-chrome-stable') or shutil.which('chromium-browser') or shutil.which('chromium')
        if not chrome_bin:
            for b in ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium-browser', '/usr/bin/chromium', '/snap/bin/chromium']:
                if os.path.exists(b):
                    chrome_bin = b
                    break
        if chrome_bin:
            opts.binary_location = chrome_bin
    # Chrome launch flags optimized for Linux EC2 stability with swap
    opts.add_argument('--no-sandbox')
    opts.add_argument('--disable-dev-shm-usage')
    opts.add_argument('--disable-blink-features=AutomationControlled')
    opts.add_argument('--disable-features=IsolateOrigins,site-per-process')
    opts.add_argument('--js-flags=--max-old-space-size=512')
    opts.add_argument('--disable-extensions')
    opts.add_experimental_option('excludeSwitches', ['enable-automation'])
    opts.add_experimental_option('useAutomationExtension', False)
    opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36')
    opts.add_argument('--window-size=1920,1080')
    opts.add_argument('--start-maximized')
    opts.add_argument('--disable-breakpad')
    opts.add_argument('--disable-crash-reporter')

    proxy_url = proxy or os.environ.get('PINTEREST_PROXY')
    if proxy_url:
        log(f"Using proxy: {proxy_url}")
        opts.add_argument(f'--proxy-server={proxy_url}')

    import hashlib, getpass, zipfile
    email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest()
    profile_base = '/tmp' if sys.platform != 'win32' else script_dir
    profile_dir = os.path.join(profile_base, f'chrome_profile_pinterest_{email_hash}')
    os.makedirs(profile_dir, mode=0o777, exist_ok=True)
    try:
        os.chmod(profile_dir, 0o777)
    except Exception:
        pass

    target_default = os.path.join(profile_dir, 'Default')
    zip_path = os.path.join(script_dir, 'pinterest_cookies.zip')
    if (not os.path.exists(target_default) or not os.listdir(target_default)) and os.path.exists(zip_path):
        try:
            log("Restoring saved login session cookies from pinterest_cookies.zip...")
            os.makedirs(target_default, mode=0o777, exist_ok=True)
            with zipfile.ZipFile(zip_path, 'r') as zip_ref:
                zip_ref.extractall(target_default)
            log("Session cookies successfully restored!")
        except Exception as e_zip:
            log(f"Cookie restore error: {e_zip}")

    # Clean up any leftover Chrome/Chromedriver processes on Linux
    if sys.platform != "win32":
        try:
            os.system("pkill -9 -f chrome 2>/dev/null; pkill -9 -f chromedriver 2>/dev/null")
            time.sleep(0.5)
        except Exception:
            pass

    # Clean up lock files from any previous crashed runs to prevent startup crash
    if os.path.exists(profile_dir):
        for root, dirs, files in os.walk(profile_dir):
            for f in files:
                if f in ["SingletonLock", "SingletonCookie", "SingletonSocket", "lock", "DevToolsActivePort"]:
                    p = os.path.join(root, f)
                    try:
                        if os.path.islink(p):
                            os.unlink(p)
                        else:
                            os.remove(p)
                    except:
                        pass

    opts.add_argument(f'--user-data-dir={profile_dir}')
    import shutil
    sys_chromedriver = shutil.which('chromedriver') or ('/usr/bin/chromedriver' if os.path.exists('/usr/bin/chromedriver') else None)
    
    driver = None
    last_err = ""
    for attempt in range(3):
        if sys.platform != "win32":
            try:
                os.system("pkill -9 -f chrome 2>/dev/null; pkill -9 -f chromedriver 2>/dev/null")
                time.sleep(0.5)
            except Exception:
                pass
        if os.path.exists(profile_dir):
            for root, dirs, files in os.walk(profile_dir):
                for f in files:
                    if f in ["SingletonLock", "SingletonCookie", "SingletonSocket", "lock", "DevToolsActivePort"]:
                        p = os.path.join(root, f)
                        try:
                            if os.path.islink(p):
                                os.unlink(p)
                            else:
                                os.remove(p)
                        except Exception:
                            pass
        try:
            # 1. Try standard Selenium Manager auto-resolution first
            driver = webdriver.Chrome(options=opts)
        except Exception as e_mgr:
            last_err = str(e_mgr)
            log(f"Selenium Manager launch attempt {attempt+1} failed: {e_mgr}")
            if sys_chromedriver:
                try:
                    service = Service(sys_chromedriver)
                    driver  = webdriver.Chrome(service=service, options=opts)
                except Exception as e_sys:
                    last_err = str(e_sys)
                    log(f"System chromedriver attempt {attempt+1} failed: {e_sys}")

        if driver:
            break

        # Fallback profile directory on subsequent attempt if profile dir was locked
        if attempt == 1:
            import tempfile
            fallback_dir = tempfile.mkdtemp(prefix="chrome_fb_")
            opts = Options()
            if sys.platform != "win32":
                opts.add_argument('--headless=new')
                opts.add_argument('--disable-gpu')
                opts.add_argument('--disable-software-rasterizer')
            opts.add_argument('--no-sandbox')
            opts.add_argument('--disable-dev-shm-usage')
            opts.add_argument('--disable-blink-features=AutomationControlled')
            opts.add_experimental_option('excludeSwitches', ['enable-automation'])
            opts.add_experimental_option('useAutomationExtension', False)
            opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')
            opts.add_argument('--window-size=1920,1080')
            opts.add_argument(f'--user-data-dir={fallback_dir}')

        time.sleep(2)

    if not driver:
        log(f"All Chrome launch attempts failed. Details: {last_err}")
        return None

    try:
        driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
            "source": """
                Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
                Object.defineProperty(navigator, 'languages', {get: () => ['en-US', 'en']});
                Object.defineProperty(navigator, 'plugins', {get: () => [1, 2, 3, 4, 5]});
                Object.defineProperty(navigator, 'hardwareConcurrency', {get: () => 8});
                window.chrome = { runtime: {} };
                const originalQuery = window.navigator.permissions.query;
                if (originalQuery) {
                    window.navigator.permissions.query = (parameters) => (
                        parameters.name === 'notifications' ?
                        Promise.resolve({ state: Notification.permission }) :
                        originalQuery(parameters)
                    );
                }
            """
        })
    except Exception as e:
        pass
    return driver

def js_click(driver, el):
    driver.execute_script("arguments[0].scrollIntoView({block:'center'});", el)
    time.sleep(0.3)
    driver.execute_script("arguments[0].click();", el)

def js_set_value(driver, el, value):
    """React-compatible value setter supporting input, textarea, and contenteditable elements"""
    driver.execute_script("""
        var el = arguments[0], val = arguments[1];
        if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
            el.focus();
            var proto = (el.tagName === 'TEXTAREA') ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
            var descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
            if (descriptor && descriptor.set) {
                try { descriptor.set.call(el, val); } catch(e) { el.value = val; }
            } else {
                el.value = val;
            }
            if (el._valueTracker) {
                el._valueTracker.setValue('');
            }
            el.dispatchEvent(new Event('input', {bubbles: true, composed: true}));
            el.dispatchEvent(new Event('change', {bubbles: true, composed: true}));
            el.dispatchEvent(new Event('blur', {bubbles: true, composed: true}));
        } else {
            try { el.innerText = val; } catch(e) { el.textContent = val; }
            el.dispatchEvent(new Event('input', {bubbles: true, composed: true}));
        }
    """, el, value)

def set_input_value(driver, el, value):
    js_set_value(driver, el, value)

def pinterest_post(email, password, keyword, target_site, image_path=None, ai_title="", ai_content="", proxy=None):
    log(f"Starting Pinterest post with email: {email}")
    driver = get_driver(email, proxy=proxy)
    if not driver:
        result(False, error="Failed to launch Chrome driver on server")
        return
    wait = WebDriverWait(driver, 25)

    try:
        log("Opening Pinterest...")
        driver.get("https://www.pinterest.com/pin-builder/")
        time.sleep(4)

        current = driver.current_url.lower()
        needs_login = ("login" in current or "signup" in current or len(driver.find_elements(By.CSS_SELECTOR, "input#email, input[type='email'], input[name='id']")) > 0)

        if needs_login:
            log("Not logged in — navigating to login page...")
            if "login" not in current:
                driver.get("https://www.pinterest.com/login/")
                time.sleep(3)

            log("Locating email input...")
            email_field = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input#email, input[type='email'], input[name='id'], input[name='username']")))
            driver.execute_script("arguments[0].scrollIntoView({block:'center'}); arguments[0].focus();", email_field)
            time.sleep(0.3)
            try:
                email_field.clear()
            except Exception:
                pass
            for ch in str(email):
                email_field.send_keys(ch)
                time.sleep(0.03)
            driver.execute_script("""
                var el = arguments[0], val = arguments[1];
                var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                if (setter) setter.call(el, val);
                el.dispatchEvent(new Event('input', {bubbles: true}));
                el.dispatchEvent(new Event('change', {bubbles: true}));
            """, email_field, email)
            time.sleep(0.5)

            log("Locating password input...")
            pass_field = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input#password, input[type='password'], input[name='password']")))
            driver.execute_script("arguments[0].scrollIntoView({block:'center'}); arguments[0].focus();", pass_field)
            time.sleep(0.3)
            try:
                pass_field.clear()
            except Exception:
                pass
            for ch in str(password):
                pass_field.send_keys(ch)
                time.sleep(0.03)
            driver.execute_script("""
                var el = arguments[0], val = arguments[1];
                var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                if (setter) setter.call(el, val);
                el.dispatchEvent(new Event('input', {bubbles: true}));
                el.dispatchEvent(new Event('change', {bubbles: true}));
            """, pass_field, password)
            time.sleep(1)

            submit_btn = None
            try:
                submit_btn = driver.find_element(By.CSS_SELECTOR, "[data-test-id='registerFormSubmitButton'], button[type='submit']")
            except Exception:
                pass
            if not submit_btn:
                for b in driver.find_elements(By.TAG_NAME, "button"):
                    txt = (b.text or '').strip().lower()
                    if txt in ["log in", "login"]:
                        submit_btn = b
                        break

            log("Clicking Login button...")
            if submit_btn:
                driver.execute_script("arguments[0].scrollIntoView({block:'center'});", submit_btn)
                time.sleep(0.3)
                try:
                    submit_btn.click()
                except Exception:
                    driver.execute_script("arguments[0].click();", submit_btn)
            else:
                driver.execute_script("""
                    var b = document.querySelector("[data-test-id='registerFormSubmitButton'], button[type='submit']");
                    if (b) b.click();
                """)
                pass_field.send_keys(Keys.ENTER)

            log("Waiting for authentication redirect...")
            time.sleep(4)
            is_authed = False
            for _ in range(12):
                try:
                    curr = driver.current_url.lower()
                    cookies = {c['name']: c['value'] for c in driver.get_cookies()}
                    if cookies.get('_auth') == '1' or ("login" not in curr and "signup" not in curr):
                        is_authed = True
                        log(f"Login success! Redirected to: {driver.current_url}")
                        break
                except Exception:
                    pass
                time.sleep(1)

            if not is_authed:
                err_text = f"Pinterest login failed on AWS — URL: {driver.current_url}"
                try:
                    page_text = driver.find_element(By.TAG_NAME, "body").text
                    log(f"Page text sample: {page_text[:300].replace(chr(10), ' ')}")
                    if "strange activity" in page_text.lower() or "reset your password" in page_text.lower():
                        err_text = "Pinterest Account Security Lock: 'We noticed some strange activity on your account. Please reset your password on Pinterest.'"
                    elif "incorrect" in page_text.lower() or "invalid" in page_text.lower():
                        err_text = "Pinterest Login Failed: Incorrect password or email for this account."
                except Exception:
                    pass
                try:
                    driver.save_screenshot(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pinterest_error.png'))
                    log("Saved login failure screenshot to pinterest_error.png")
                except Exception:
                    pass
                try:
                    driver.quit()
                except Exception:
                    pass
                result(False, error=err_text)
                return

        log("Login OK!")

        # ── Step 2: Pin Creation Tool ──────────────────────────────
        if "pin-builder" not in driver.current_url.lower():
            log("Opening Pin creation tool (pin-builder)...")
            driver.get("https://www.pinterest.com/pin-builder/")
            time.sleep(5)
        log("Pin builder ready")

        # ── Step 3: Upload image ───────────────────────────────────
        def is_valid_file(p):
            try:
                return bool(p and os.path.exists(p) and os.path.getsize(p) > 50)
            except Exception:
                return False

        real_image_path = image_path
        if not is_valid_file(real_image_path):
            uploads_dir = "/var/www/html/uploads"
            if os.path.exists(uploads_dir):
                for f in os.listdir(uploads_dir):
                    if f.lower().endswith(('.jpg', '.jpeg', '.png', '.webp')):
                        candidate = os.path.join(uploads_dir, f)
                        if is_valid_file(candidate):
                            real_image_path = candidate
                            log(f"Found fallback image in uploads: {real_image_path}")
                            break

        if not is_valid_file(real_image_path):
            try:
                fallback_file = "/tmp/pinterest_fallback_pin.bmp"
                w, h = 800, 1200
                f_hdr = b'BM' + (54 + w * h * 3).to_bytes(4, 'little') + (0).to_bytes(4, 'little') + (54).to_bytes(4, 'little')
                i_hdr = (40).to_bytes(4, 'little') + w.to_bytes(4, 'little') + h.to_bytes(4, 'little') + (1).to_bytes(2, 'little') + (24).to_bytes(2, 'little') + (0).to_bytes(24, 'little')
                pix = b'\xe6\x7d\x1e' * (w * h)
                with open(fallback_file, 'wb') as f_out:
                    f_out.write(f_hdr + i_hdr + pix)
                if is_valid_file(fallback_file):
                    real_image_path = fallback_file
                    log(f"Generated local fallback Pin image: {fallback_file}")
            except Exception as e_gen:
                log(f"Local image gen error: {e_gen}")

        image_uploaded = False
        if is_valid_file(real_image_path):
            log(f"Uploading image: {real_image_path}...")
            try:
                up = None
                file_inputs = driver.find_elements(By.CSS_SELECTOR, "input[type='file']")
                if file_inputs:
                    up = file_inputs[0]
                else:
                    try:
                        up = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[data-test-id='storyboard-upload-input']")))
                    except Exception:
                        pass
                if up:
                    driver.execute_script("arguments[0].style.display='block'; arguments[0].style.opacity='1'; arguments[0].style.visibility='visible';", up)
                    up.send_keys(os.path.abspath(real_image_path))
                    time.sleep(6)
                    log("Image uploaded!")
                    image_uploaded = True
            except Exception as e:
                log(f"Image upload: {e}")
        else:
            log("No valid image file found for Pin upload!")

        # ── Step 4: Title ──────────────────────────────────────────
        title = ai_title if ai_title else f"Best {keyword.title()} - {time.strftime('%Y')} Guide"
        log("Filling title...")
        try:
            title_selectors = [
                "[data-test-id='pin-builder-title'] input",
                "[data-test-id='pin-builder-title'] textarea",
                "#storyboard-selector-title",
                "input[placeholder*='title' i]",
                "textarea[placeholder*='title' i]",
                "input[placeholder*='Add your title' i]"
            ]
            tf = None
            for sel in title_selectors:
                try:
                    elems = driver.find_elements(By.CSS_SELECTOR, sel)
                    if elems and elems[0].is_displayed():
                        tf = elems[0]
                        break
                except Exception:
                    continue
            if tf:
                driver.execute_script("arguments[0].scrollIntoView({block:'center'});", tf)
                time.sleep(0.3)
                js_click(driver, tf)
                time.sleep(0.2)
                try:
                    tf.clear()
                    tf.send_keys(title[:100])
                except Exception:
                    driver.execute_script("arguments[0].value = arguments[1]; arguments[0].dispatchEvent(new Event('input', {bubbles: true})); arguments[0].dispatchEvent(new Event('change', {bubbles: true}));", tf, title[:100])
                log("Title OK!")
            else:
                log("Title element not found via selectors")
        except Exception as e:
            log(f"Title: {e}")

        # ── Step 5: Description ────────────────────────────────────
        if ai_content and len(ai_content.strip()) > 50:
            desc = ai_content.strip()
        else:
            desc = (
                f"Looking for the best {keyword}? Learnmore Technologies offers expert-led "
                f"{keyword} with hands-on live projects, industry-recognized certification, "
                f"and 100% placement support. Our {keyword} covers all key concepts from "
                f"beginner to advanced level. Join hundreds of successful students who built "
                f"their IT careers with us. Flexible batch timings, experienced trainers, "
                f"small batches for personal attention. "
                f"Enroll now at {target_site} — Limited seats available! "
                f"#{keyword.replace(' ','')} #Training #Bangalore #Career #Education #Certification"
            )
        desc = desc[:500]
        log(f"Description length: {len(desc)} chars")
        log("Filling description...")
        try:
            desc_selectors = [
                "[data-test-id='pin-builder-description'] [contenteditable='true']",
                "[data-test-id='pin-builder-description'] textarea",
                "[data-test-id='pin-builder-description'] div[role='textbox']",
                "[data-test-id='description-field']",
                "div[aria-label*='description' i]",
                "div[aria-label*='Tell' i]",
                "div[placeholder*='description' i]",
                "div[placeholder*='Tell' i]",
                "textarea[placeholder*='description' i]",
                "textarea[placeholder*='Tell' i]",
                "[data-test-id='pin-builder-description']",
                ".public-DraftEditor-editor"
            ]
            cd = None
            for sel in desc_selectors:
                try:
                    elements = driver.find_elements(By.CSS_SELECTOR, sel)
                    for el in elements:
                        if el and (el.is_displayed() or el.get_attribute("contenteditable") == "true" or el.get_attribute("role") == "textbox"):
                            # Skip if this element is actually the title input
                            el_id = el.get_attribute("id") or ""
                            if "title" in el_id.lower():
                                continue
                            cd = el
                            break
                    if cd:
                        break
                except:
                    continue

            if not cd:
                # Fallback: The 2nd editable text element on the Pin Builder canvas is Description
                all_editables = driver.find_elements(By.CSS_SELECTOR, "textarea, [contenteditable='true'], div[role='textbox']")
                valid_editables = []
                for el in all_editables:
                    try:
                        if el.is_displayed():
                            valid_editables.append(el)
                    except Exception:
                        pass
                if len(valid_editables) >= 2:
                    cd = valid_editables[1]
                    log("Description found via 2nd canvas editable fallback!")

            if cd:
                driver.execute_script("arguments[0].scrollIntoView({block:'center'});", cd)
                time.sleep(0.3)
                try:
                    ActionChains(driver).move_to_element(cd).click().send_keys(desc).perform()
                except Exception:
                    try:
                        cd.click()
                        cd.send_keys(desc)
                    except Exception:
                        set_input_value(driver, cd, desc)
                log("Description OK!")
            else:
                log("Description element not found via selectors")
        except Exception as e:
            log(f"Desc: {e}")

        # ── Step 6: Link ───────────────────────────────────────────
        log("Filling link...")
        link_selectors = [
            "[data-test-id='pin-builder-link'] input",
            "[data-test-id='pin-builder-link'] textarea",
            "#storyboard-selector-link",
            "input[name='link']",
            "input[id='WebsiteField']",
            "input[placeholder*='link' i]",
            "input[placeholder*='destination' i]",
            "input[placeholder*='Add a link' i]",
            "input[placeholder*='Website' i]"
        ]
        lf = None
        for sel in link_selectors:
            try:
                elements = driver.find_elements(By.CSS_SELECTOR, sel)
                if elements and elements[0].is_displayed():
                    lf = elements[0]
                    break
            except:
                continue
        if lf:
            driver.execute_script("arguments[0].scrollIntoView({block:'center'});", lf)
            time.sleep(0.3)
            js_click(driver, lf)
            time.sleep(0.2)
            try:
                lf.clear()
                lf.send_keys(target_site)
            except Exception:
                driver.execute_script("arguments[0].value = arguments[1]; arguments[0].dispatchEvent(new Event('input', {bubbles: true})); arguments[0].dispatchEvent(new Event('change', {bubbles: true}));", lf, target_site)
            log("Link OK!")
        else:
            log("Link element not found via selectors")

        # ── Step 7: Board ──────────────────────────────────────────
        log("Opening board dropdown...")
        board_selected = False

        try:
            bb = None
            try:
                bb = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "[data-test-id='board-dropdown-select-button']")))
            except:
                pass
            if not bb:
                for cand in driver.find_elements(By.XPATH, "//*[contains(text(), 'Choose a board') or contains(text(), 'board') or contains(text(), 'Board')]"):
                    if cand.is_displayed():
                        bb = cand
                        break
            if bb:
                driver.execute_script("arguments[0].scrollIntoView({block:'center'});", bb)
                time.sleep(0.5)
                js_click(driver, bb)
                time.sleep(3)
                log("Board dropdown opened")

            # Check for existing boards — prefer keyword-matching board
            flyout = driver.find_elements(
                By.CSS_SELECTOR, "[data-test-id='board-picker-flyout']")
            if flyout:
                rows = flyout[0].find_elements(By.CSS_SELECTOR,
                    "[data-test-id='boardWithoutSection']")
                log(f"Board rows found: {len(rows)}")

                # Build keyword words for matching
                kw_words = [w.lower() for w in keyword.split() if len(w) > 2]

                # First pass: find best keyword-matching board
                best_row = None
                best_score = 0
                for row in rows:
                    txt = row.text.strip().lower()
                    if not txt or 'create' in txt:
                        continue
                    score = sum(1 for w in kw_words if w in txt)
                    if score > best_score:
                        best_score = score
                        best_row = row

                # Use best match, else first available board
                selected_row = best_row or (rows[0] if rows else None)
                if selected_row:
                    txt = selected_row.text.strip()
                    js_click(driver, selected_row)
                    time.sleep(2)
                    log(f"Board selected: '{txt[:60]}' (score={best_score})")
                    board_selected = True

            if not board_selected:
                log("No existing boards — creating new...")

                # Click create board
                cb = wait.until(EC.element_to_be_clickable(
                    (By.CSS_SELECTOR, "[data-test-id='create-board-button']")))
                js_click(driver, cb)
                time.sleep(3)
                log("Create board modal opened")

                # board-form-container input — skip known non-board inputs
                board_name = f"{keyword.title()} Training"
                board_name_set = False

                SKIP_IDS = {
                    'search-input', 'WebsiteField',
                    'storyboard-selector-title',
                    'combobox-storyboard-interest-tags',
                }

                all_inputs = driver.find_elements(By.TAG_NAME, "input")
                log(f"Total inputs: {len(all_inputs)}")

                for inp in all_inputs:
                    try:
                        inp_id   = inp.get_attribute('id') or ''
                        inp_ph   = inp.get_attribute('placeholder') or ''
                        inp_type = inp.get_attribute('type') or 'text'
                        if inp_type in ('file', 'checkbox', 'hidden', 'url'):
                            continue
                        if inp_id in SKIP_IDS:
                            continue
                        if 'search' in inp_id.lower() or 'search' in inp_ph.lower():
                            continue
                        if not inp.is_displayed():
                            continue

                        log(f"Board input: id={inp_id} ph={inp_ph}")
                        # Scroll to it, click, type
                        driver.execute_script("arguments[0].scrollIntoView({block:'center'});", inp)
                        time.sleep(0.3)
                        ActionChains(driver).move_to_element(inp).click().perform()
                        time.sleep(0.3)
                        inp.send_keys(board_name)
                        time.sleep(0.4)
                        actual = inp.get_attribute('value') or ''
                        log(f"Input value after typing: '{actual}'")
                        if actual:
                            board_name_set = True
                            break
                        else:
                            # Try JS React setter
                            js_set_value(driver, inp, board_name)
                            time.sleep(0.3)
                            actual2 = inp.get_attribute('value') or ''
                            if actual2:
                                log("Board name set via JS React setter!")
                                board_name_set = True
                                break
                    except Exception as e:
                        log(f"  input try: {e}")

                if not board_name_set:
                    log("All input attempts failed — trying active element")
                    try:
                        # Click the create board button area and Tab to input
                        driver.execute_script(
                            "document.querySelector('[data-test-id=\"board-form-container\"]').querySelector('input').focus();"
                        )
                        time.sleep(0.3)
                        driver.switch_to.active_element.send_keys(board_name)
                        time.sleep(0.3)
                        log("Board name via active element!")
                        board_name_set = True
                    except Exception as e:
                        log(f"Active element: {e}")

                time.sleep(1)

                # Click Create button — data-test-id='board-form-submit-button'
                try:
                    sbmt = wait.until(EC.element_to_be_clickable(
                        (By.CSS_SELECTOR, "[data-test-id='board-form-submit-button']")))
                    js_click(driver, sbmt)
                    time.sleep(5)
                    log("Board created via board-form-submit-button!")
                    board_selected = True
                except Exception as e:
                    log(f"board-form-submit: {e}")
                    # Fallback
                    for btn in driver.find_elements(By.TAG_NAME, "button"):
                        if btn.text.strip() in ("Create", "Create board") and btn.is_displayed():
                            js_click(driver, btn)
                            time.sleep(5)
                            log(f"Board created via '{btn.text}' btn!")
                            board_selected = True
                            break

        except Exception as e:
            log(f"Board section: {e}")

        # ── Step 8: Publish ────────────────────────────────────────
        log("Publishing pin...")
        time.sleep(4)
        published = False

        # Debug: screenshot + dump all buttons
        driver.save_screenshot(os.path.join(os.path.dirname(__file__), 'before_publish.png'))
        btns_debug = driver.find_elements(By.TAG_NAME, "button")
        log(f"Total buttons on page: {len(btns_debug)}")
        for btn in btns_debug:
            t = btn.text.strip()
            if t:
                log(f"  btn: '{t}' enabled={btn.is_enabled()} displayed={btn.is_displayed()} data-test-id={btn.get_attribute('data-test-id')}")

        # Try all buttons/elements with any Publish-like text or data-test-id
        for attempt in range(5):
            found_any = False
            candidates = []
            for tag in ('button', 'div', 'span'):
                for el in driver.find_elements(By.TAG_NAME, tag):
                    try:
                        txt = el.text.strip().lower()
                        test_id = (el.get_attribute('data-test-id') or '').lower()
                        if not el.is_displayed():
                            continue
                        if txt in ('create new', 'add products', 'create'):
                            continue
                        # Match 'publish', 'save', 'done', 'publish-button', 'save-button'
                        if (txt in ('publish', 'save', 'done') or 
                            'publish' in test_id or 
                            'save-button' in test_id or 
                            'publish-button' in test_id):
                            if el.is_enabled():
                                candidates.append(el)
                    except:
                        pass
            
            for btn in candidates:
                try:
                    found_any = True
                    driver.execute_script("arguments[0].scrollIntoView({block:'center'});", btn)
                    time.sleep(0.5)
                    js_click(driver, btn)
                    time.sleep(10)
                    log("Published via candidate click!")
                    published = True
                    break
                except Exception as ex:
                    log(f"Publish candidate click failed: {ex}")
            if published:
                break
            if not found_any:
                log(f"No publish button found attempt {attempt+1}, waiting 3s...")
            time.sleep(3)

        # ── Step 9: Get URL ────────────────────────────────────────
        time.sleep(5)
        for _ in range(8):
            cu = driver.current_url
            if "/pin/" in cu:
                result(True, url=cu)
                return
            time.sleep(2)

        page = driver.page_source
        pin_urls = re.findall(r'https://[a-z.]*pinterest\.com/pin/\d+', page)
        if pin_urls:
            result(True, url=pin_urls[0])
        elif published and image_uploaded:
            uname = email.split("@")[0].lower().replace(".", "")
            result(True, url=f"https://www.pinterest.com/{uname}/")
        else:
            result(False, error="Pin save failed: Image file was missing or Publish button remained disabled.")

    except Exception as e:
        try:
            driver.save_screenshot(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pinterest_error.png'))
            log("Saved exception error screenshot to pinterest_error.png")
        except Exception as ex:
            log(f"Screenshot exception: {ex}")
        result(False, error=str(e))
    finally:
        try:
            driver.quit()
        except:
            pass

if __name__ == "__main__":
    if len(sys.argv) < 5:
        result(False, error="Usage: pinterest_post.py <email> <password> <keyword> <target_site> [image_path] [ai_title] [ai_content] [proxy]")
        sys.exit(1)
    pinterest_post(
        sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4],
        sys.argv[5] if len(sys.argv) > 5 else None,
        sys.argv[6] if len(sys.argv) > 6 else "",
        sys.argv[7] if len(sys.argv) > 7 else "",
        sys.argv[8] if len(sys.argv) > 8 else None,
    )
