#!/usr/bin/env python3
"""
Ghost.io Selenium Auto-Poster
Usage: python ghost_post.py <email> <password> <keyword> <target_site>
Output: JSON lines {"log": "..."} and final {"success": true/false, "url": "...", "error": "..."}
"""
import sys
import json
import time
import os
import re
import datetime
os.environ['WDM_LOG'] = '0'
try:
    import pwd
    sys_user = pwd.getpwuid(os.getuid())[0]
except Exception:
    import getpass
    sys_user = getpass.getuser()
os.environ['WDM_DIR'] = f'/tmp/.wdm_{sys_user}'

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.common.action_chains import ActionChains
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from webdriver_manager.chrome import ChromeDriverManager


# ── helpers ──────────────────────────────────────────────────────────────────

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error=''):
    print(json.dumps({"success": success, "url": url, "error": error}), flush=True)


def remove_lock_files(profile_dir):
    """Remove Chrome singleton/lock files that block reuse of a profile."""
    locks = [
        os.path.join(profile_dir, 'SingletonLock'),
        os.path.join(profile_dir, 'SingletonCookie'),
        os.path.join(profile_dir, 'SingletonSocket'),
        os.path.join(profile_dir, 'Default', 'LOCK'),
        os.path.join(profile_dir, 'Default', 'lockfile'),
    ]
    for lf in locks:
        try:
            if os.path.exists(lf):
                os.remove(lf)
                log(f"Removed lock: {lf}")
        except Exception as e:
            log(f"Could not remove lock {lf}: {e}")


def get_driver():
    profile_dir = os.path.join(os.path.dirname(__file__), 'chrome_profile_ghost')
    os.makedirs(profile_dir, exist_ok=True)
    remove_lock_files(profile_dir)

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
    opts.add_argument(
        '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    )
    opts.add_argument('--window-size=1400,900')

    service = Service(ChromeDriverManager().install())
    driver = webdriver.Chrome(service=service, options=opts)
    driver.execute_script(
        "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
    )
    return driver


def wait_click(driver, selector, by=By.CSS_SELECTOR, timeout=15):
    try:
        el = WebDriverWait(driver, timeout).until(
            EC.element_to_be_clickable((by, selector))
        )
        driver.execute_script(
            "arguments[0].scrollIntoView({block:'center'}); arguments[0].click();", el
        )
        return True
    except Exception as e:
        log(f"click [{selector}]: {e}")
        return False


def wait_type(driver, selector, text, by=By.CSS_SELECTOR, timeout=15, clear=True):
    try:
        el = WebDriverWait(driver, timeout).until(
            EC.element_to_be_clickable((by, selector))
        )
        driver.execute_script("arguments[0].scrollIntoView({block:'center'});", el)
        el.click()
        if clear:
            el.clear()
        el.send_keys(text)
        return True
    except Exception as e:
        log(f"type [{selector}]: {e}")
        return False


# ── main poster ───────────────────────────────────────────────────────────────

def post_to_ghost(email, password, keyword, target_site):
    year = datetime.datetime.now().year
    post_title = f"Best {keyword.title()} Training Guide {year}"
    post_content = (
        f"Looking for the best {keyword} training? "
        f"This guide covers everything you need to know about {keyword} — "
        f"from fundamentals to advanced techniques.\n\n"
        f"Whether you are a beginner or looking to upgrade your skills, "
        f"our expert-curated resources will help you master {keyword} quickly.\n\n"
        f"Learn more and enroll today: {target_site}\n\n"
        f"Topics covered:\n"
        f"- Introduction to {keyword}\n"
        f"- Hands-on projects and assignments\n"
        f"- Industry-recognised certification\n"
        f"- Career guidance and placement support\n\n"
        f"Visit {target_site} for course details, batch schedules and fee structure."
    )

    driver = get_driver()
    wait = WebDriverWait(driver, 25)

    try:
        # ── Step 1: Check if already logged in ──────────────────────────────
        log("Ghost: Opening ghost.io …")
        driver.get("https://ghost.io")
        time.sleep(3)

        current_url = driver.current_url
        log(f"Ghost: Current URL after opening ghost.io → {current_url}")

        # Look for dashboard/admin link or "New post" indicators
        page_src = driver.page_source.lower()
        is_logged_in = (
            'ghost.io/ghost' in current_url
            or 'dashboard' in current_url
            or '/ghost/#' in current_url
        )

        # ── Step 2: Login if needed ──────────────────────────────────────────
        if not is_logged_in:
            log("Ghost: Not logged in, navigating to login page …")
            driver.get("https://ghost.io/login")
            time.sleep(3)

            # Email field
            email_selectors = [
                'input[type="email"]',
                'input[name="email"]',
                '#email',
                'input[placeholder*="email" i]',
            ]
            typed_email = False
            for sel in email_selectors:
                if wait_type(driver, sel, email, timeout=8):
                    typed_email = True
                    log(f"Ghost: Typed email via {sel}")
                    break

            if not typed_email:
                result(False, error="Ghost: Could not find email field")
                return

            time.sleep(0.5)

            # Password field
            pw_selectors = [
                'input[type="password"]',
                'input[name="password"]',
                '#password',
            ]
            typed_pw = False
            for sel in pw_selectors:
                if wait_type(driver, sel, password, timeout=8):
                    typed_pw = True
                    log(f"Ghost: Typed password via {sel}")
                    break

            if not typed_pw:
                result(False, error="Ghost: Could not find password field")
                return

            time.sleep(0.5)

            # Submit
            submit_selectors = [
                'button[type="submit"]',
                'input[type="submit"]',
                'button.gh-btn-primary',
                '//button[contains(translate(text(),"abcdefghijklmnopqrstuvwxyz","ABCDEFGHIJKLMNOPQRSTUVWXYZ"),"SIGN IN")]',
                '//button[contains(translate(text(),"abcdefghijklmnopqrstuvwxyz","ABCDEFGHIJKLMNOPQRSTUVWXYZ"),"LOG IN")]',
            ]
            submitted = False
            for sel in submit_selectors:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                if wait_click(driver, sel, by=by, timeout=8):
                    submitted = True
                    log(f"Ghost: Clicked submit via {sel}")
                    break

            if not submitted:
                # Try pressing Enter on password field
                try:
                    pw_el = driver.find_element(By.CSS_SELECTOR, 'input[type="password"]')
                    pw_el.send_keys(Keys.RETURN)
                    submitted = True
                    log("Ghost: Submitted via Enter key")
                except Exception:
                    pass

            if not submitted:
                result(False, error="Ghost: Could not submit login form")
                return

            time.sleep(5)
            log(f"Ghost: After login → {driver.current_url}")

        # ── Step 3: Navigate to admin dashboard ─────────────────────────────
        current_url = driver.current_url
        log(f"Ghost: Current URL → {current_url}")

        # Try to detect subdomain from URL or page content
        admin_url = None

        # Pattern: https://something.ghost.io/ghost/
        m = re.search(r'https://([^/]+\.ghost\.io)', current_url)
        if m:
            admin_url = f"https://{m.group(1)}/ghost/#/posts"
            log(f"Ghost: Detected admin URL from current URL → {admin_url}")

        if not admin_url:
            # Check page source for site subdomain
            src = driver.page_source
            m2 = re.search(r'href=["\']https://([^"\']+\.ghost\.io)/ghost', src)
            if m2:
                admin_url = f"https://{m2.group(1)}/ghost/#/posts"
                log(f"Ghost: Detected admin URL from page source → {admin_url}")

        if not admin_url:
            # Try common patterns from email-based subdomain
            email_user = email.split('@')[0].lower().replace('.', '-').replace('_', '-')
            admin_url = f"https://{email_user}.ghost.io/ghost/#/posts"
            log(f"Ghost: Guessing admin URL from email → {admin_url}")

        log(f"Ghost: Navigating to admin → {admin_url}")
        driver.get(admin_url)
        time.sleep(4)

        current_url = driver.current_url
        log(f"Ghost: After admin nav → {current_url}")

        # If redirected to login again, try re-login once
        if 'signin' in current_url or 'login' in current_url or 'ghost.io/login' in current_url:
            log("Ghost: Redirected to login, attempting login again …")
            driver.get("https://ghost.io/login")
            time.sleep(3)

            for sel in ['input[type="email"]', 'input[name="email"]']:
                if wait_type(driver, sel, email, timeout=8):
                    break

            for sel in ['input[type="password"]', 'input[name="password"]']:
                if wait_type(driver, sel, password, timeout=8):
                    break

            for sel in ['button[type="submit"]', 'button.gh-btn-primary']:
                if wait_click(driver, sel, timeout=8):
                    break

            time.sleep(5)
            log(f"Ghost: After re-login → {driver.current_url}")

            # Re-detect admin URL
            current_url = driver.current_url
            m = re.search(r'https://([^/]+\.ghost\.io)', current_url)
            if m:
                admin_url = f"https://{m.group(1)}/ghost/#/posts"
            driver.get(admin_url)
            time.sleep(4)
            log(f"Ghost: After 2nd admin nav → {driver.current_url}")

        # ── Step 4: Click "New post" ─────────────────────────────────────────
        log("Ghost: Looking for New Post button …")

        new_post_selectors = [
            # Ghost 5.x
            'a[href*="/ghost/editor/post/new"]',
            'a.gh-btn-primary[href*="editor"]',
            '//a[contains(@href, "/editor/post/new")]',
            '//a[contains(text(), "New post")]',
            '//button[contains(text(), "New post")]',
            '//a[contains(translate(text(),"abcdefghijklmnopqrstuvwxyz","ABCDEFGHIJKLMNOPQRSTUVWXYZ"),"NEW POST")]',
            # Ghost 4.x fallback
            'a.gh-editor-new-post',
            '.gh-btn-editor-new',
        ]

        clicked_new = False
        for sel in new_post_selectors:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            if wait_click(driver, sel, by=by, timeout=8):
                clicked_new = True
                log(f"Ghost: Clicked New Post via {sel}")
                break

        if not clicked_new:
            # Try navigating directly to new post editor URL
            m = re.search(r'https://([^/]+\.ghost\.io)', driver.current_url)
            if m:
                new_post_url = f"https://{m.group(1)}/ghost/#/editor/post/new"
                log(f"Ghost: Direct nav to editor → {new_post_url}")
                driver.get(new_post_url)
                time.sleep(3)
            else:
                result(False, error="Ghost: Could not find or navigate to New Post editor")
                return

        time.sleep(3)
        log(f"Ghost: Editor URL → {driver.current_url}")

        # ── Step 5: Fill title ───────────────────────────────────────────────
        log(f"Ghost: Filling title → {post_title}")

        title_selectors = [
            'textarea.gh-editor-title',
            '.gh-editor-title',
            'textarea[placeholder*="Post Title" i]',
            'textarea[placeholder*="title" i]',
            '[data-placeholder*="Post Title" i]',
            '.koenig-editor__editor .kg-title',
        ]

        typed_title = False
        for sel in title_selectors:
            try:
                el = WebDriverWait(driver, 10).until(
                    EC.presence_of_element_located((By.CSS_SELECTOR, sel))
                )
                driver.execute_script("arguments[0].scrollIntoView({block:'center'});", el)
                el.click()
                time.sleep(0.3)
                el.clear()
                el.send_keys(post_title)
                typed_title = True
                log(f"Ghost: Title typed via {sel}")
                break
            except Exception as e:
                continue

        if not typed_title:
            # Try via JS on contenteditable title
            try:
                driver.execute_script("""
                    var titles = document.querySelectorAll('textarea, [contenteditable="true"]');
                    for (var t of titles) {
                        if (t.className && t.className.toString().includes('title')) {
                            t.focus(); t.click();
                            break;
                        }
                    }
                """)
                time.sleep(0.5)
                actions = ActionChains(driver)
                actions.send_keys(post_title).perform()
                typed_title = True
                log("Ghost: Title typed via ActionChains fallback")
            except Exception as e:
                log(f"Ghost: Title ActionChains fallback failed: {e}")

        if not typed_title:
            result(False, error="Ghost: Could not type post title")
            return

        time.sleep(1)

        # ── Step 6: Fill content body ────────────────────────────────────────
        log("Ghost: Filling post content …")

        # Tab to content area or click it
        content_selectors = [
            '.koenig-editor__editor [contenteditable="true"]',
            '.kg-prose [contenteditable="true"]',
            '.ProseMirror',
            '[data-placeholder*="Begin writing" i]',
            '[data-placeholder*="Write here" i]',
            '.kg-editor [contenteditable="true"]',
            '.koenig-editor [contenteditable="true"]',
        ]

        typed_content = False
        for sel in content_selectors:
            try:
                el = WebDriverWait(driver, 8).until(
                    EC.element_to_be_clickable((By.CSS_SELECTOR, sel))
                )
                driver.execute_script("arguments[0].scrollIntoView({block:'center'});", el)
                el.click()
                time.sleep(0.5)
                el.send_keys(post_content)
                typed_content = True
                log(f"Ghost: Content typed via {sel}")
                break
            except Exception as e:
                continue

        if not typed_content:
            # Tab from title into editor body
            try:
                active = driver.switch_to.active_element
                active.send_keys(Keys.TAB)
                time.sleep(0.5)
                active = driver.switch_to.active_element
                active.send_keys(post_content)
                typed_content = True
                log("Ghost: Content typed via Tab+active_element")
            except Exception as e:
                log(f"Ghost: Content tab fallback failed: {e}")

        if not typed_content:
            # JS inject into first contenteditable in editor
            try:
                driver.execute_script("""
                    var editors = document.querySelectorAll('[contenteditable="true"]');
                    var editor = null;
                    for (var e of editors) {
                        if (!e.className.toString().includes('title')) {
                            editor = e; break;
                        }
                    }
                    if (!editor && editors.length > 1) editor = editors[1];
                    if (editor) {
                        editor.focus();
                        editor.click();
                        document.execCommand('insertText', false, arguments[0]);
                    }
                """, post_content)
                typed_content = True
                log("Ghost: Content typed via JS execCommand")
            except Exception as e:
                log(f"Ghost: JS content inject failed: {e}")

        time.sleep(1)

        # ── Step 7: Publish the post ─────────────────────────────────────────
        log("Ghost: Publishing post …")

        # Ghost 5.x publish flow: click "Publish" button → opens publish menu → click "Publish" confirm
        publish_selectors = [
            '//button[contains(text(), "Publish")]',
            '//button[contains(@class, "gh-btn-primary") and contains(text(), "Publish")]',
            'button.gh-btn-editor-save',
            '.gh-editor-header button.gh-btn-primary',
            '//div[@class="gh-editor-header"]//button[contains(text(), "Publish")]',
            'button[data-test-button="publish-flow"]',
        ]

        clicked_publish = False
        for sel in publish_selectors:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            if wait_click(driver, sel, by=by, timeout=10):
                clicked_publish = True
                log(f"Ghost: Clicked Publish via {sel}")
                break

        if not clicked_publish:
            result(False, error="Ghost: Could not find Publish button")
            return

        time.sleep(2)

        # Handle publish confirmation/modal (Ghost 5.x has a multi-step publish modal)
        confirm_selectors = [
            # "Continue, final review" step
            '//button[contains(text(), "Continue, final review")]',
            '//button[contains(text(), "Publish post, right now")]',
            '//button[contains(text(), "Publish post")]',
            '//button[contains(text(), "Confirm publish")]',
            '//button[contains(text(), "Publish now")]',
            'button[data-test-button="continue-to-publish-flow"]',
            'button[data-test-button="confirm-publish"]',
            '.gh-publish-cta button',
        ]

        # Try clicking through the publish wizard (up to 3 steps)
        for step in range(3):
            found_step = False
            for sel in confirm_selectors:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                try:
                    el = WebDriverWait(driver, 5).until(
                        EC.element_to_be_clickable((by, sel))
                    )
                    driver.execute_script("arguments[0].scrollIntoView({block:'center'}); arguments[0].click();", el)
                    log(f"Ghost: Publish step {step+1} via {sel}")
                    found_step = True
                    time.sleep(2)
                    break
                except Exception:
                    continue

            if not found_step:
                log(f"Ghost: No more publish steps at step {step+1}")
                break

        time.sleep(3)
        log(f"Ghost: After publish → {driver.current_url}")

        # ── Step 8: Get published post URL ────────────────────────────────────
        published_url = ''
        current_url = driver.current_url

        # Check page source for the post URL
        src = driver.page_source

        # Try: "View post" link after publish
        view_link_patterns = [
            r'href=["\']([^"\']*\.ghost\.io/[^"\']+/)["\'].*?[Vv]iew\s+[Pp]ost',
            r'[Vv]iew\s+[Pp]ost.*?href=["\']([^"\']*\.ghost\.io/[^"\']+/)["\']',
        ]
        for pat in view_link_patterns:
            m = re.search(pat, src, re.DOTALL)
            if m:
                published_url = m.group(1)
                log(f"Ghost: Found post URL from View Post link → {published_url}")
                break

        if not published_url:
            # Try to click "View post" link
            try:
                view_el = WebDriverWait(driver, 5).until(
                    EC.presence_of_element_located((By.XPATH, '//a[contains(text(),"View post")]'))
                )
                published_url = view_el.get_attribute('href')
                log(f"Ghost: Got URL from View post element → {published_url}")
            except Exception:
                pass

        if not published_url:
            # Extract subdomain from editor URL and build slug
            m = re.search(r'https://([^/]+\.ghost\.io)', current_url)
            if m:
                site_base = f"https://{m.group(1)}"
                slug = re.sub(r'[^a-z0-9]+', '-', post_title.lower()).strip('-')
                published_url = f"{site_base}/{slug}/"
                log(f"Ghost: Built URL from slug → {published_url}")

        if not published_url:
            published_url = 'https://ghost.io'

        log(f"Ghost: Post published successfully → {published_url}")
        result(True, url=published_url)

    except Exception as e:
        log(f"Ghost: Exception — {e}")
        result(False, error=str(e))
    finally:
        try:
            driver.quit()
        except Exception:
            pass


# ── entry point ───────────────────────────────────────────────────────────────

if __name__ == '__main__':
    if len(sys.argv) < 5:
        result(False, error="Usage: ghost_post.py <email> <password> <keyword> <target_site>")
        sys.exit(1)

    _email       = sys.argv[1]
    _password    = sys.argv[2]
    _keyword     = sys.argv[3]
    _target_site = sys.argv[4]

    post_to_ghost(_email, _password, _keyword, _target_site)
