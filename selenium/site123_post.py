#!/usr/bin/env python3
"""
Site123 Auto-Post via Selenium
Login → Go to blog → Create new post → Fill title+content+keyword → Publish
Usage: python site123_post.py <email> <password> <keyword> <target_site>
"""
import sys, json, time, os
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.action_chains import ActionChains
from selenium.webdriver.common.keys import Keys
from webdriver_manager.chrome import ChromeDriverManager

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error=''):
    print(json.dumps({"success": success, "url": url, "error": error}), flush=True)

email      = sys.argv[1] if len(sys.argv) > 1 else "kanzariyapratik124@gmail.com"
import hashlib
email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest() if email else "default"

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
try:
    import pwd
    sys_user = pwd.getpwuid(os.getuid())[0]
except Exception:
    import getpass
    sys_user = getpass.getuser()
PROFILE_DIR = os.path.join(SCRIPT_DIR, f'chrome_profile_site123_{email_hash}_{sys_user}')

# Clean lock files
for lf in [os.path.join(PROFILE_DIR,'Default','LOCK'),
           os.path.join(PROFILE_DIR,'SingletonLock')]:
    try:
        if os.path.exists(lf): os.remove(lf)
    except: pass
os.makedirs(PROFILE_DIR, exist_ok=True)
password   = sys.argv[2] if len(sys.argv) > 2 else "@DISHA12@"
keyword    = sys.argv[3] if len(sys.argv) > 3 else "python training bangalore"
target_url = sys.argv[4] if len(sys.argv) > 4 else "https://learnmoretech.in"

opts = Options()
opts.add_argument('--headless=new')
opts.add_argument('--disable-gpu')
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
wait = WebDriverWait(driver, 20)

try:
    # ── Step 1: Open Site123 ──────────────────────────────────
    log("Site123: Opening login page...")
    driver.get("https://app.site123.com/manager/login/login.php?l=en")
    time.sleep(5)
    log(f"Site123: URL = {driver.current_url}")

    # Check if already logged in
    src = driver.page_source.lower()
    already = ("login" not in driver.current_url.lower() and
               ("dashboard" in driver.current_url or "editor" in driver.current_url or
                "my-websites" in driver.current_url))

    if not already:
        log("Site123: Logging in...")

        # Fill email — Site123 uses name='username' with placeholder 'Email Address'
        for sel in ["input[name='username']","input[id='username']",
                    "input[type='email']","input[placeholder*='mail' i]"]:
            try:
                el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); time.sleep(0.3); el.send_keys(email)
                    log(f"Site123: Email typed [{sel}]"); break
            except: continue

        # Fill password
        for sel in ["input[type='password']","input[name='password']"]:
            try:
                el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); time.sleep(0.3); el.send_keys(password)
                    log("Site123: Password typed"); break
            except: continue

        # Submit — Site123 has button[type='submit'] text='Login'
        for sel in [
            "//button[normalize-space(text())='Login']",
            "button[type='submit']",
            "//input[@value='Login']",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log(f"Site123: Login clicked"); break
            except: continue

        time.sleep(8)
        log(f"Site123: Post-login URL = {driver.current_url}")

        if "login" in driver.current_url.lower() or "sign" in driver.current_url.lower():
            # Check for error message
            err_els = driver.find_elements(By.CSS_SELECTOR, "[class*='error'],[class*='alert'],[class*='warning']")
            err_msg = " | ".join([e.text.strip() for e in err_els if e.text.strip()])
            result(False, error=f"Site123: Login failed. {err_msg}")
            driver.quit(); exit()

    log(f"Site123: Logged in! URL = {driver.current_url}")
    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'site123_loggedin.png'))

    # ── Step 2: Go to My Websites / Dashboard ────────────────
    if "items_list.php" not in driver.current_url:
        driver.get("https://app.site123.com/manager/items_list.php")
        time.sleep(6)
        log(f"Site123: Dashboard URL = {driver.current_url}")

    # ── Step 3: Open first website editor ────────────────────
    site_url = ""
    # Switch to iframe if present
    iframes = driver.find_elements(By.ID, "onePageSystemFrame")
    if iframes:
        driver.switch_to.frame(iframes[0])
        log("Site123: Switched to onePageSystemFrame iframe")

    # Selectors inside iframe
    for sel in [
        "a.CardWebsiteName",
        "a.one-page-dash-link",
        "[class*='website-item'] a",
        "[class*='site-item'] a",
    ]:
        try:
            el = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
            if el.is_displayed():
                href = el.get_attribute('href') or ''
                site_url = href
                driver.execute_script("arguments[0].click();", el)
                log(f"Site123: Opened editor: {href[:60]}")
                time.sleep(8)
                break
        except:
            continue

    try:
        driver.switch_to.default_content()
    except:
        pass

    log(f"Site123: Editor URL = {driver.current_url}")
    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'site123_editor.png'))

    # ── Step 4: Navigate to Blog section ─────────────────────
    for sel in [
        "//a[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'BLOG')]",
        "//span[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'BLOG')]",
        "[data-section*='blog']",
        "[href*='blog']",
        "[class*='blog']",
    ]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
            if el.is_displayed():
                driver.execute_script("arguments[0].click();",el)
                log(f"Site123: Blog section clicked")
                time.sleep(6)
                break
        except: continue

    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'site123_blog.png'))

    # Switch to iframe inside Blog page
    iframes = driver.find_elements(By.ID, "onePageSystemFrame")
    if iframes:
        driver.switch_to.frame(iframes[0])
        log("Site123: Switched to onePageSystemFrame iframe inside Blog page")

    # ── Step 5: Add new blog post ─────────────────────────────
    for sel in [
        "//span[normalize-space(text())='Add New Post']",
        "//a[contains(text(),'Add New Post')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ADD POST')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'NEW POST')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ADD')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'CREATE')]",
        "[class*='add-post']","[class*='new-post']",
        "//button[@data-action='add-post']",
    ]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
            if el.is_displayed():
                driver.execute_script("arguments[0].click();",el)
                log(f"Site123: Add post clicked: '{el.text.strip()}'")
                time.sleep(4)
                break
        except: continue

    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'site123_new_post.png'))

    # ── Step 6: Fill title ────────────────────────────────────
    title_text = f"Best {keyword.title()} - Complete Guide {time.strftime('%Y')}"
    for sel in [
        "textarea[name='title']",
        "textarea[placeholder*='title' i]",
        "input[placeholder*='title' i]","input[name*='title' i]",
        "[class*='post-title'] input","[class*='title'] input",
        "input[type='text']",
    ]:
        try:
            el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
            if el.is_displayed():
                el.click(); el.clear(); time.sleep(0.3)
                el.send_keys(title_text)
                log(f"Site123: Title typed")
                break
        except: continue

    # ── Step 7: Fill content ──────────────────────────────────
    content_text = (
        f"Best {keyword} training available at Learnmore Technologies.\n\n"
        f"Our comprehensive {keyword} course covers:\n"
        f"- Expert trainers with industry experience\n"
        f"- Hands-on projects and real-world scenarios\n"
        f"- Placement support and career guidance\n"
        f"- Flexible batch timings\n\n"
        f"Enroll now at: {target_url}\n\n"
        f"Contact us today to start your journey in {keyword}.\n"
        f"Visit: {target_url}"
    )
    for sel in [
        "textarea[placeholder*='content' i]",
        "[contenteditable='true']",
        ".ql-editor",
        ".ProseMirror",
        "[class*='editor-content']",
        "textarea",
    ]:
        try:
            el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
            if el.is_displayed():
                el.click(); time.sleep(0.5)
                el.send_keys(content_text)
                log(f"Site123: Content typed")
                break
        except: continue

    time.sleep(1)

    # ── Step 8: Publish / Save ────────────────────────────────
    for sel in [
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'PUBLISH')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SAVE')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'POST')]",
        "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'DONE')]",
        "button[type='submit']",
        "[class*='publish']","[class*='save-btn']",
    ]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
            if btn.is_displayed() and btn.is_enabled():
                driver.execute_script("arguments[0].click();",btn)
                log(f"Site123: Published! btn='{btn.text.strip()}'")
                time.sleep(6)
                break
        except: continue

    # Switch back to default content to click the site-wide Publish button in the left sidebar
    try:
        driver.switch_to.default_content()
        time.sleep(1)
        
        # Click the green Publish button
        published_btn = False
        for sel in [
            "button.btn-success",
            "a.btn-success",
            "//button[contains(., 'Publish')]",
            "//a[contains(., 'Publish')]",
            "[class*='publish']",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver, 5).until(EC.element_to_be_clickable((by, sel)))
                if btn.is_displayed():
                    btn_text = btn.text.strip()
                    driver.execute_script("arguments[0].click();", btn)
                    log(f"Site123: Clicked site-wide Publish button: {btn_text}")
                    published_btn = True
                    time.sleep(3)
                    break
            except:
                continue
                
        # Confirm popup if any
        if published_btn:
            for conf_sel in [
                "button.btn-primary",
                "button.btn-blue",
                "//button[contains(., 'Yes')]",
                "//button[contains(., 'Ok')]",
                "//button[contains(., 'Publish')]",
            ]:
                try:
                    by = By.XPATH if conf_sel.startswith('//') else By.CSS_SELECTOR
                    c_btn = driver.find_element(by, conf_sel)
                    if c_btn.is_displayed():
                        driver.execute_script("arguments[0].click();", c_btn)
                        log("Site123: Confirmed site-wide Publish")
                        time.sleep(4)
                        break
                except:
                    continue
    except Exception as pe:
        log(f"Site123: Click site-wide Publish failed: {pe}")

    driver.save_screenshot(os.path.join(SCRIPT_DIR, 'site123_published.png'))
    final = driver.current_url
    log(f"Site123: Final URL = {final}")
    result(True, url=final if "site123.com" in final else "https://www.site123.com/my-websites")

except Exception as e:
    result(False, error=str(e))
finally:
    try: driver.quit()
    except: pass
