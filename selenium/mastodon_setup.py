#!/usr/bin/env python3
"""
Mastodon Auto-Post
Method: Register App via API → OAuth authorize via Selenium → Extract token → Post
Usage: python mastodon_setup.py <email> <password> <keyword> <target_site>
"""
import sys, json, time, re, requests, os
os.environ['WDM_LOG'] = '0'
os.environ['WDM_DIR'] = '/tmp/.wdm'
import urllib3
urllib3.disable_warnings()

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from webdriver_manager.chrome import ChromeDriverManager

INSTANCE = "mastodon.social"
BASE_URL  = f"https://{INSTANCE}"

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error='', token=''):
    print(json.dumps({"success": success, "url": url, "error": error, "token": token}), flush=True)

def get_driver():
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
    # Incognito — fresh session every time, no cached cookies
    opts.add_argument('--incognito')
    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    return driver

def cj(driver, el):
    driver.execute_script("arguments[0].scrollIntoView({block:'center'}); arguments[0].click();", el)

def mastodon_full_flow(email, password, keyword, target_site):
    # ── Step 1: Register app to get client_id + client_secret ────
    log("Registering Mastodon app...")
    r = requests.post(f"{BASE_URL}/api/v1/apps", data={
        "client_name":   "VISIT SITE",
        "redirect_uris": "urn:ietf:wg:oauth:2.0:oob",
        "scopes":        "read write",
        "website":       target_site,
    }, timeout=15, verify=False)

    if r.status_code != 200:
        return None, f"App registration failed: HTTP {r.status_code}"

    app = r.json()
    client_id     = app["client_id"]
    client_secret = app["client_secret"]
    log(f"App registered! client_id={client_id[:15]}...")

    # ── Step 2: Open OAuth authorize URL in Selenium ──────────────
    auth_url = (f"{BASE_URL}/oauth/authorize"
                f"?client_id={client_id}"
                f"&scope=read+write"
                f"&redirect_uri=urn%3Aietf%3Awg%3Aoauth%3A2.0%3Aoob"
                f"&response_type=code")

    driver = get_driver()
    wait   = WebDriverWait(driver, 25)
    auth_code = None

    try:
        # Login first if needed
        log("Checking login...")
        driver.get(f"{BASE_URL}/home")
        time.sleep(4)

        if "sign_in" in driver.current_url or "/home" not in driver.current_url:
            log("Logging in...")
            driver.get(f"{BASE_URL}/auth/sign_in")
            time.sleep(3)

            # Wait for email field — use JS if send_keys fails
            ef = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input#user_email")))
            try:
                ef.click()
                ef.send_keys(email)
            except:
                driver.execute_script("arguments[0].value=arguments[1]", ef, email)
                driver.execute_script("arguments[0].dispatchEvent(new Event('input',{bubbles:true}))", ef)

            pf = driver.find_element(By.CSS_SELECTOR, "input#user_password")
            try:
                pf.click()
                pf.send_keys(password)
            except:
                driver.execute_script("arguments[0].value=arguments[1]", pf, password)
                driver.execute_script("arguments[0].dispatchEvent(new Event('input',{bubbles:true}))", pf)

            time.sleep(0.5)
            driver.find_element(By.CSS_SELECTOR, "button[type='submit']").click()
            time.sleep(6)

            if "sign_in" in driver.current_url:
                driver.quit()
                return None, "Login failed. Check email/password."

            # Handle new account onboarding (/start page)
            if "/start" in driver.current_url or "/onboarding" in driver.current_url:
                log("New account onboarding — skipping setup steps...")
                # Try to skip/continue
                for skip_sel in ["a[href='/home']", "button[class*='skip']",
                                  "//a[contains(text(),'Skip')]", "//button[contains(text(),'Skip')]",
                                  "//a[contains(text(),'Later')]"]:
                    try:
                        if skip_sel.startswith('//'):
                            el = driver.find_element(By.XPATH, skip_sel)
                        else:
                            el = driver.find_element(By.CSS_SELECTOR, skip_sel)
                        if el.is_displayed():
                            cj(driver, el)
                            time.sleep(3)
                            log(f"Skipped onboarding via: {skip_sel}")
                            break
                    except: continue
                else:
                    driver.get(f"{BASE_URL}/home")
                    time.sleep(3)

            log("Logged in!")

        # ── Step 3: Open authorize page + click Allow ─────────────
        log("Opening OAuth authorize page...")
        driver.get(auth_url)
        time.sleep(4)

        driver.save_screenshot(__file__.replace('mastodon_setup.py','') + 'mastodon_auth.png')

        # Detect email not confirmed
        if "auth/edit" in driver.current_url or "confirmation" in driver.current_url:
            driver.quit()
            return None, "Mastodon account email not confirmed. Check your Gmail inbox and confirm the email from Mastodon first."

        # If redirected to login again, login and retry
        if "sign_in" in driver.current_url:
            log("Redirected to login — logging in again...")
            ef = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "input#user_email")))
            try:
                ef.click(); ef.send_keys(email)
            except:
                driver.execute_script("arguments[0].value=arguments[1]", ef, email)
                driver.execute_script("arguments[0].dispatchEvent(new Event('input',{bubbles:true}))", ef)
            pf = driver.find_element(By.CSS_SELECTOR, "input#user_password")
            try:
                pf.click(); pf.send_keys(password)
            except:
                driver.execute_script("arguments[0].value=arguments[1]", pf, password)
                driver.execute_script("arguments[0].dispatchEvent(new Event('input',{bubbles:true}))", pf)
            time.sleep(0.5)
            driver.find_element(By.CSS_SELECTOR, "button[type='submit']").click()
            time.sleep(6)
            # After login → redirect back to authorize
            if "sign_in" not in driver.current_url and "authorize" not in driver.current_url:
                driver.get(auth_url)
                time.sleep(4)

        # Detect email not confirmed — /auth/edit means pending confirmation
        if "auth/edit" in driver.current_url or "confirmation" in driver.current_url:
            driver.quit()
            return None, "Email not confirmed yet. Please check your Gmail inbox and click the confirmation link from Mastodon."

        log(f"Auth page URL: {driver.current_url}")
        driver.save_screenshot(__file__.replace('mastodon_setup.py','') + 'mastodon_auth2.png')

        # Click "Authorize" button — not "Log in"
        for btn_selector in [
            "//button[contains(text(),'Authorize')]",
            "//input[@value='Authorize']",
            "//button[contains(@class,'allow')]",
            "button[class*='allow']",
            "//form[@class*='authorize'] button[type='submit']",
        ]:
            try:
                if btn_selector.startswith('//'):
                    btn = wait.until(EC.element_to_be_clickable((By.XPATH, btn_selector)))
                else:
                    btn = wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, btn_selector)))
                if btn.is_displayed():
                    log(f"Clicking authorize: '{btn.text or btn.get_attribute('value')}'")
                    cj(driver, btn)
                    time.sleep(4)
                    break
            except:
                continue
        else:
            # Last resort: find all submit buttons, pick one that says Authorize
            btns = driver.find_elements(By.CSS_SELECTOR, "button[type='submit'], input[type='submit']")
            for btn in btns:
                txt = btn.text or btn.get_attribute('value') or ''
                if 'log' not in txt.lower() and btn.is_displayed():
                    log(f"Clicking button: '{txt}'")
                    cj(driver, btn)
                    time.sleep(4)
                    break

        # ── Step 4: Extract auth code from page ───────────────────
        # Mastodon shows the code in a <code> or input after redirect
        page_src = driver.page_source

        # Look in input fields
        for inp in driver.find_elements(By.TAG_NAME, "input"):
            val = inp.get_attribute('value') or ''
            if len(val) >= 20 and re.match(r'^[A-Za-z0-9_\-]+$', val):
                auth_code = val
                log(f"Auth code from input: {auth_code[:15]}...")
                break

        # Look in code/pre elements
        if not auth_code:
            for el in driver.find_elements(By.CSS_SELECTOR, "code, pre, p"):
                t = el.text.strip()
                if len(t) >= 20 and re.match(r'^[A-Za-z0-9_\-]+$', t):
                    auth_code = t
                    log(f"Auth code from element: {auth_code[:15]}...")
                    break

        # Look in URL params
        if not auth_code:
            cur = driver.current_url
            m = re.search(r'code=([A-Za-z0-9_\-]+)', cur)
            if m:
                auth_code = m.group(1)
                log(f"Auth code from URL: {auth_code[:15]}...")

        # Regex from page source
        if not auth_code:
            for pat in [
                r'<code[^>]*>([A-Za-z0-9_\-]{20,})</code>',
                r'authorization_code["\s:=]+([A-Za-z0-9_\-]{20,})',
                r'"code"\s*:\s*"([A-Za-z0-9_\-]{20,})"',
            ]:
                m = re.search(pat, page_src)
                if m:
                    auth_code = m.group(1)
                    log(f"Auth code via regex: {auth_code[:15]}...")
                    break

    except Exception as e:
        driver.quit()
        return None, str(e)
    finally:
        try: driver.quit()
        except: pass

    if not auth_code:
        return None, "Could not extract authorization code. Check mastodon_code.png"

    # ── Step 5: Exchange auth code for access token ───────────────
    log("Exchanging code for access token...")
    t = requests.post(f"{BASE_URL}/oauth/token", data={
        "client_id":     client_id,
        "client_secret": client_secret,
        "redirect_uri":  "urn:ietf:wg:oauth:2.0:oob",
        "grant_type":    "authorization_code",
        "code":          auth_code,
        "scope":         "read write",
    }, timeout=15, verify=False)

    if t.status_code != 200:
        return None, f"Token exchange failed: HTTP {t.status_code}: {t.text[:200]}"

    token = t.json().get("access_token", "")
    if not token:
        return None, f"No token in response: {t.text[:200]}"

    log(f"Access token: {token[:15]}...")
    return token, None

def mastodon_post_with_token(token, keyword, target_site, content=''):
    if not content:
        content = (f"Best {keyword} training course! Learn from industry experts "
                   f"with hands-on projects and placement support.\n\n"
                   f"Enroll now: {target_site}\n\n"
                   f"#{keyword.replace(' ','')} #Training #Education #SEO #Career")

    status_text = content[:490] + f"\n{target_site}" if target_site not in content else content[:499]

    resp = requests.post(
        f"{BASE_URL}/api/v1/statuses",
        headers={"Authorization": f"Bearer {token}"},
        data={"status": status_text, "visibility": "public"},
        timeout=20,
        verify=False
    )

    if resp.status_code in (200, 201):
        data = resp.json()
        url  = data.get('url') or data.get('uri', BASE_URL)
        return url, None
    return None, f"HTTP {resp.status_code}: {resp.text[:200]}"

if __name__ == "__main__":
    if len(sys.argv) < 5:
        result(False, error="Usage: mastodon_setup.py <email> <password> <keyword> <target_site>")
        sys.exit(1)

    email       = sys.argv[1]
    password    = sys.argv[2]
    keyword     = sys.argv[3]
    target_site = sys.argv[4]

    # Get token
    token, err = mastodon_full_flow(email, password, keyword, target_site)
    if err or not token:
        result(False, error=f"Token failed: {err}")
        sys.exit(1)

    # Post
    post_url, post_err = mastodon_post_with_token(token, keyword, target_site)
    if post_err:
        result(False, token=token, error=f"Post failed: {post_err}")
    else:
        result(True, url=post_url, token=token)
       