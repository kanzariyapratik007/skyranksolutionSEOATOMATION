#!/usr/bin/env python3
"""
Mastodon Auto-Post via Playwright
Method: Register App via API → OAuth authorize via Playwright → Extract token → Post
Usage: python mastodon_setup_playwright.py <email> <password> <keyword> <target_site>
"""
import sys, json, time, re, requests, os, hashlib
os.environ['PLAYWRIGHT_BROWSERS_PATH'] = '/usr/local/share/playwright'
script_dir = os.path.dirname(os.path.abspath(__file__))

import urllib3
urllib3.disable_warnings()

# Profile isolation by sys_user and email hash
try:
    import pwd
    sys_user = pwd.getpwuid(os.getuid())[0]
except Exception:
    import getpass
    sys_user = getpass.getuser()

INSTANCE = "mastodon.social"
BASE_URL  = f"https://{INSTANCE}"

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error='', token=''):
    print(json.dumps({"success": success, "url": url, "error": error, "token": token}), flush=True)

def mastodon_full_flow(email, password, keyword, target_site):
    # ── Step 1: Register app to get client_id + client_secret ────
    log("Registering Mastodon app...")
    try:
        r = requests.post(f"{BASE_URL}/api/v1/apps", data={
            "client_name":   "VISIT SITE",
            "redirect_uris": "urn:ietf:wg:oauth:2.0:oob",
            "scopes":        "read write",
            "website":       target_site,
        }, timeout=15, verify=False)
    except Exception as e:
        return None, f"App registration HTTP request failed: {e}"

    if r.status_code != 200:
        return None, f"App registration failed: HTTP {r.status_code}"

    app = r.json()
    client_id     = app["client_id"]
    client_secret = app["client_secret"]
    log(f"App registered! client_id={client_id[:15]}...")

    # ── Step 2: Open OAuth authorize URL in Playwright ──────────────
    auth_url = (f"{BASE_URL}/oauth/authorize"
                f"?client_id={client_id}"
                f"&scope=read+write"
                f"&redirect_uri=urn%3Aietf%3Awg%3Aoauth%3A2.0%3Aoob"
                f"&response_type=code")

    email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest()
    profile_dir = os.path.join(script_dir, f'chrome_profile_mastodon_{email_hash}_{sys_user}')
    
    # Remove locks
    for lock in ['SingletonLock', 'LOCK']:
        lock_path = os.path.join(profile_dir, lock)
        if os.path.exists(lock_path):
            try:
                os.remove(lock_path)
            except:
                pass

    from playwright.sync_api import sync_playwright

    auth_code = None

    with sync_playwright() as p:
        try:
            log("Launching browser context...")
            context = p.chromium.launch_persistent_context(
                user_data_dir=profile_dir,
                headless=True,
                no_viewport=True,
                args=[
                    '--no-sandbox',
                    '--disable-dev-shm-usage',
                    '--disable-blink-features=AutomationControlled'
                ]
            )
            
            page = context.pages[0] if context.pages else context.new_page()
            page.set_viewport_size({"width": 1400, "height": 900})

            # Check login
            log("Checking login...")
            page.goto(f"{BASE_URL}/auth/sign_in", timeout=60000)
            page.wait_for_timeout(3000)

            email_input = page.locator("input#user_email").first
            if email_input.count() > 0 and email_input.is_visible():
                log("Logging in...")
                email_input.fill(email)

                pass_input = page.locator("input#user_password").first
                pass_input.fill(password)
                page.wait_for_timeout(500)

                page.locator("button[type='submit']").first.click()
                page.wait_for_timeout(6000)

                if "sign_in" in page.url:
                    raise Exception("Login failed. Check email/password credentials.")

                # Skip onboarding pages if visible
                if "/start" in page.url or "/onboarding" in page.url:
                    log("Skipping onboarding...")
                    for skip_selector in ["a[href='/home']", "button:has-text('Skip')", "a:has-text('Skip')"]:
                        try:
                            loc = page.locator(skip_selector).first
                            if loc.count() > 0 and loc.is_visible():
                                loc.click()
                                page.wait_for_timeout(3000)
                                break
                        except:
                            pass
                    else:
                        page.goto(f"{BASE_URL}/home", timeout=60000)
                        page.wait_for_timeout(3000)
                
                log("Logged in!")
            else:
                log("Already logged in!")

            # Go to authorize page
            log("Opening OAuth authorize page...")
            page.goto(auth_url, timeout=60000)
            page.wait_for_timeout(4000)

            # Detect unconfirmed email state
            if "auth/edit" in page.url or "confirmation" in page.url:
                raise Exception("Mastodon email not confirmed. Check email inbox and click confirmation link.")

            # Click Authorize
            log(f"Current page URL before authorize: {page.url}")
            try:
                page.screenshot(path=os.path.join(os.path.dirname(script_dir), 'uploads', 'mastodon_auth.png'))
                log("Saved pre-authorization screenshot to mastodon_auth.png")
            except Exception as se:
                log(f"Pre-auth screenshot failed: {se}")

            # Wait for authorize button
            authorize_btn = page.locator("button:has-text('Authorize'), button:has-text('authorize'), button.button--type-submit, button.allow, input[type='submit'][value='Authorize']").first
            try:
                authorize_btn.wait_for(state="visible", timeout=15000)
                log("Clicking authorize button...")
                authorize_btn.click(force=True)
                page.wait_for_timeout(6000)
            except Exception as e:
                log(f"Authorize button wait failed: {e}. Trying fallback form submit...")
                # Fallback form submit
                submit_btn = page.locator("form.button_to button[type='submit'], form.authorize button[type='submit'], button[type='submit']").first
                if submit_btn.count() > 0:
                    submit_btn.click(force=True)
                    page.wait_for_timeout(6000)

            # Extract auth code
            page_src = page.content()

            # 1. Look in code/pre elements first (standard OAuth2 OOB presentation)
            elements = page.locator("code, pre").all()
            for el in elements:
                t = (el.text_content() or "").strip()
                if len(t) >= 20 and re.match(r'^[A-Za-z0-9_\-]+$', t):
                    auth_code = t
                    log(f"Auth code from element: {auth_code[:15]}...")
                    break

            # 2. Look in visible input fields (excluding hidden CSRF tokens)
            if not auth_code:
                inputs = page.locator("input:not([type='hidden'])").all()
                for inp in inputs:
                    val = inp.get_attribute("value") or ""
                    if len(val) >= 20 and re.match(r'^[A-Za-z0-9_\-]+$', val):
                        auth_code = val
                        log(f"Auth code from input: {auth_code[:15]}...")
                        break

            # 3. Look in URL params
            if not auth_code:
                m = re.search(r'code=([A-Za-z0-9_\-]+)', page.url)
                if m:
                    auth_code = m.group(1)
                    log(f"Auth code from URL: {auth_code[:15]}...")

            # 4. Regex from page source
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

            if not auth_code:
                raise Exception("Could not extract authorization code from the callback page.")

            context.close()

        except Exception as e:
            try:
                if 'page' in locals():
                    page.screenshot(path=os.path.join(os.path.dirname(script_dir), 'uploads', 'mastodon_error.png'))
                    log("Saved exception error screenshot to mastodon_error.png")
            except Exception as ex:
                log(f"Screenshot exception: {ex}")
            try:
                if 'context' in locals():
                    context.close()
            except:
                pass
            return None, str(e)

    # ── Step 5: Exchange auth code for access token ───────────────
    log("Exchanging code for access token...")
    try:
        t = requests.post(f"{BASE_URL}/oauth/token", data={
            "client_id":     client_id,
            "client_secret": client_secret,
            "redirect_uri":  "urn:ietf:wg:oauth:2.0:oob",
            "grant_type":    "authorization_code",
            "code":          auth_code,
            "scope":         "read write",
        }, timeout=15, verify=False)
    except Exception as e:
        return None, f"Token exchange HTTP request failed: {e}"

    if t.status_code != 200:
        return None, f"Token exchange failed: HTTP {t.status_code}: {t.text[:200]}"

    token = t.json().get("access_token", "")
    if not token:
        return None, f"No token in response: {t.text[:200]}"

    log(f"Access token retrieved: {token[:15]}...")
    return token, None

def mastodon_post_with_token(token, keyword, target_site):
    content = (f"Best {keyword} training course! Learn from industry experts "
               f"with hands-on projects and placement support.\n\n"
               f"Enroll now: {target_site}\n\n"
               f"#{keyword.replace(' ','')} #Training #Education #SEO #Career")

    status_text = content[:490] + f"\n{target_site}" if target_site not in content else content[:499]

    try:
        resp = requests.post(
            f"{BASE_URL}/api/v1/statuses",
            headers={"Authorization": f"Bearer {token}"},
            data={"status": status_text, "visibility": "public"},
            timeout=20,
            verify=False
        )
    except Exception as e:
        return None, f"Status post request failed: {e}"

    if resp.status_code in (200, 201):
        data = resp.json()
        url  = data.get('url') or data.get('uri', BASE_URL)
        return url, None
    return None, f"HTTP {resp.status_code}: {resp.text[:200]}"

if __name__ == "__main__":
    if len(sys.argv) < 5:
        result(False, error="Usage: mastodon_setup_playwright.py <email> <password> <keyword> <target_site>")
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

    # Post status using the token
    post_url, post_err = mastodon_post_with_token(token, keyword, target_site)
    if post_err:
        result(False, token=token, error=f"Post failed: {post_err}")
    else:
        result(True, url=post_url, token=token)
