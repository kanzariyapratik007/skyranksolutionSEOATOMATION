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

def get_driver(email="default"):
    opts = Options()
    if sys.platform != "win32":
        opts.add_argument('--headless=new')
        opts.add_argument('--disable-gpu')
        opts.add_argument('--disable-software-rasterizer')
    # Non-headless: Pinterest board modal needs real rendering
    opts.add_argument('--no-sandbox')
    opts.add_argument('--disable-dev-shm-usage')
    opts.add_argument('--disable-blink-features=AutomationControlled')
    opts.add_experimental_option('excludeSwitches', ['enable-automation'])
    opts.add_experimental_option('useAutomationExtension', False)
    opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')
    opts.add_argument('--window-size=1400,900')
    opts.add_argument('--start-maximized')
    opts.add_argument('--disable-breakpad')
    opts.add_argument('--disable-crash-reporter')
    import hashlib, getpass, tempfile
    email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest()
    sys_user = getpass.getuser().lower()
    profile_dir = os.path.join(tempfile.gettempdir(), f'chrome_profile_pinterest_{email_hash}_{sys_user}')
    # Clean up lock files from any previous crashed runs to prevent startup crash
    if os.path.exists(profile_dir):
        for lock_name in ["SingletonLock", "SingletonCookie", "SingletonSocket", "lock"]:
            lock_path = os.path.join(profile_dir, lock_name)
            if os.path.exists(lock_path) or os.path.islink(lock_path):
                try:
                    if os.path.islink(lock_path):
                        os.unlink(lock_path)
                    else:
                        os.remove(lock_path)
                except:
                    pass

    opts.add_argument(f'--user-data-dir={profile_dir}')
    import shutil
    sys_chromedriver = shutil.which('chromedriver') or ('/usr/bin/chromedriver' if os.path.exists('/usr/bin/chromedriver') else None)
    
    driver = None
    if sys_chromedriver:
        try:
            service = Service(sys_chromedriver)
            driver  = webdriver.Chrome(service=service, options=opts)
        except Exception as e_sys:
            driver = None

    if not driver:
        try:
            service = Service(ChromeDriverManager().install())
            driver  = webdriver.Chrome(service=service, options=opts)
        except Exception as e_cdm:
            driver = webdriver.Chrome(options=opts)

    try:
        driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
            "source": "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
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
            var proto = (el.tagName === 'TEXTAREA') ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
            var descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
            if (descriptor && descriptor.set) {
                try { descriptor.set.call(el, val); } catch(e) { el.value = val; }
            } else {
                el.value = val;
            }
            el.dispatchEvent(new Event('input', {bubbles: true}));
            el.dispatchEvent(new Event('change', {bubbles: true}));
        } else {
            try { el.innerText = val; } catch(e) { el.textContent = val; }
            el.dispatchEvent(new Event('input', {bubbles: true}));
        }
    """, el, value)

def set_input_value(driver, el, value):
    js_set_value(driver, el, value)

def pinterest_post(email, password, keyword, target_site, image_path=None, ai_title="", ai_content=""):
    log(f"Starting Pinterest post with email: {email}")
    driver = get_driver(email)
    wait   = WebDriverWait(driver, 30)

    try:
        # ── Step 1: Login ──────────────────────────────────────────
        log("Checking login status...")
        driver.get("https://www.pinterest.com/")
        time.sleep(4)

        # Check if already logged in (has profile icon or feed)
        already_logged = ("pinterest.com" in driver.current_url and
                          "login" not in driver.current_url and
                          len(driver.find_elements(By.CSS_SELECTOR,
                              "[data-test-id='header-profile'], [data-test-id='header-accounts-options-button']")) > 0)

        if not already_logged:
            log("Not logged in — logging in...")
            driver.get("https://www.pinterest.com/login/")
            time.sleep(4)

            # Wait for email field (may show captcha/block)
            try:
                # Wait for at least one email field to be present
                wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input[type='email'], input#email, input[name='username']")))
                
                # Find all potential email fields and choose the displayed one
                email_fields = driver.find_elements(By.CSS_SELECTOR, "input[type='email'], input#email, input[name='username']")
                email_field = None
                for ef in email_fields:
                    if ef.is_displayed():
                        email_field = ef
                        break
                if not email_field and email_fields:
                    email_field = email_fields[0]

                if email_field:
                    email_field.click()
                    email_field.clear()
                    set_input_value(driver, email_field, email)
                    email_field.send_keys(email)
                    time.sleep(0.5)

                # Find all potential password fields and choose the displayed one
                password_fields = driver.find_elements(By.CSS_SELECTOR, "input[type='password'], input#password, input[name='password']")
                pass_field = None
                for pf in password_fields:
                    if pf.is_displayed():
                        pass_field = pf
                        break
                if not pass_field and password_fields:
                    pass_field = password_fields[0]

                if pass_field:
                    pass_field.click()
                    pass_field.clear()
                    set_input_value(driver, pass_field, password)
                    pass_field.send_keys(password)
                    time.sleep(0.5)
                    pass_field.send_keys(Keys.ENTER)

                # Find all potential submit buttons and choose the displayed one
                submit_buttons = driver.find_elements(By.CSS_SELECTOR, "button[type='submit'], button.red.SignupButton, button.red.LoginButton, [data-test-id='registerFormSubmitButton']")
                submit_btn = None
                for sb in submit_buttons:
                    if sb.is_displayed():
                        submit_btn = sb
                        break
                if not submit_btn and submit_buttons:
                    submit_btn = submit_buttons[0]

                if submit_btn:
                    try:
                        driver.execute_script("arguments[0].click();", submit_btn)
                    except Exception:
                        submit_btn.click()
                time.sleep(8)
            except Exception as e:
                log(f"Login form error: {e}")

            # Verify if logged in by navigating to pin creation tool directly
            driver.get("https://www.pinterest.com/pin-creation-tool/")
            time.sleep(5)

            if "login" in driver.current_url.lower() or "signup" in driver.current_url.lower():
                try:
                    driver.save_screenshot(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pinterest_error.png'))
                    log("Saved login failure screenshot to pinterest_error.png")
                except Exception as ex:
                    log(f"Screenshot exception: {ex}")
                try:
                    driver.quit()
                except Exception:
                    pass
                result(False, error="Pinterest login failed — please check email & password.")
                return

        log("Login OK!")

        # ── Step 2: Pin Creation Tool ──────────────────────────────
        driver.get("https://www.pinterest.com/pin-creation-tool/")
        time.sleep(6)
        log("Pin builder opened")

        # ── Step 3: Upload image ───────────────────────────────────
        if image_path and os.path.exists(image_path):
            log("Uploading image...")
            try:
                up = wait.until(EC.presence_of_element_located(
                    (By.CSS_SELECTOR, "input[data-test-id='storyboard-upload-input']")))
                up.send_keys(os.path.abspath(image_path))
                time.sleep(8)
                log("Image uploaded!")
            except Exception as e:
                log(f"Image upload: {e}")

        # ── Step 4: Title ──────────────────────────────────────────
        title = ai_title if ai_title else f"Best {keyword.title()} - {time.strftime('%Y')} Guide"
        log("Filling title...")
        try:
            tf = wait.until(EC.presence_of_element_located((By.ID, "storyboard-selector-title")))
            driver.execute_script("arguments[0].scrollIntoView({block:'center'});", tf)
            time.sleep(0.5)
            try:
                tf.clear()
                tf.send_keys(title[:100])
            except Exception as e2:
                js_set_value(driver, tf, title[:100])
            log("Title OK!")
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
        # Pinterest max 500 chars
        desc = desc[:500]
        log(f"Description length: {len(desc)} chars")
        log("Filling description...")
        try:
            desc_selectors = [
                "[contenteditable='true']",
                ".public-DraftEditor-editor",
                "[data-test-id*='description']"
            ]
            cd = None
            for sel in desc_selectors:
                try:
                    elements = driver.find_elements(By.CSS_SELECTOR, sel)
                    if elements and elements[0].is_displayed():
                        cd = elements[0]
                        break
                except:
                    continue

            if cd:
                driver.execute_script("arguments[0].scrollIntoView({block:'center'});", cd)
                time.sleep(0.5)
                try:
                    js_click(driver, cd)
                    time.sleep(0.3)
                    driver.execute_script("arguments[0].innerHTML = '';", cd)
                    cd.send_keys(desc)
                except Exception as e2:
                    driver.execute_script("arguments[0].innerHTML = arguments[1];", cd, desc)
                    driver.execute_script("arguments[0].dispatchEvent(new Event('input', {bubbles: true}));", cd)
                log("Description OK!")
            else:
                log("Description element not found via any selectors")
        except Exception as e:
            log(f"Desc: {e}")

        # ── Step 6: Link ───────────────────────────────────────────
        log("Filling link...")
        link_selectors = [
            "input[name='link']",
            "input[id='WebsiteField']",
            "input[placeholder*='link']",
            "input[placeholder*='Link']",
            "input[placeholder*='destination']",
            "input[placeholder*='Destination']",
            "[data-test-id*='link'] input",
            "[data-test-id*='website'] input",
            "[data-test-id*='link']",
            "[data-test-id*='website']"
        ]
        lf = None
        for sel in link_selectors:
            try:
                elements = driver.find_elements(By.CSS_SELECTOR, sel)
                if elements and elements[0].is_displayed():
                    lf = elements[0]
                    log(f"Found link field with selector: {sel}")
                    break
            except:
                continue
        if lf:
            driver.execute_script("arguments[0].scrollIntoView({block:'center'});", lf)
            time.sleep(0.5)
            try:
                lf.clear()
                lf.send_keys(target_site)
            except Exception as e2:
                js_set_value(driver, lf, target_site)
            log("Link OK!")
        else:
            log("Link element not found via any selectors")

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
                        # Match 'publish', 'save', 'done', 'publish-button', 'save-button', etc.
                        if (txt in ('publish', 'save', 'done') or 
                            'publish' in test_id or 
                            'save' in test_id or 
                            'submit' in test_id):
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
        elif published:
            uname = email.split("@")[0].lower().replace(".", "")
            result(True, url=f"https://www.pinterest.com/{uname}/")
        else:
            result(False, error="Pin may not have published — check Pinterest account.")

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
        result(False, error="Usage: pinterest_post.py <email> <password> <keyword> <target_site> [image_path] [ai_title] [ai_content]")
        sys.exit(1)
    pinterest_post(
        sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4],
        sys.argv[5] if len(sys.argv) > 5 else None,
        sys.argv[6] if len(sys.argv) > 6 else "",
        sys.argv[7] if len(sys.argv) > 7 else "",
    )
