#!/usr/bin/env python3
"""
Generic Selenium Auto-Poster — handles multiple platforms
Platforms: substack, linktree, symbaloo, penzu, wakelet, padlet, 
           pearltrees, photobucket, behance, scoopit, tumblr
Usage: python generic_post.py <platform> <email> <password> <keyword> <target_site> [content]
"""
import sys, json, time, os
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from webdriver_manager.chrome import ChromeDriverManager

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error='', message=''):
    print(json.dumps({"success": success, "url": url, "error": error, "message": message}), flush=True)

def get_driver(platform="generic", email="default", headless=True):
    opts = Options()
    if headless:
        opts.add_argument('--headless=new')
    if sys.platform != "win32":
        opts.add_argument('--disable-gpu')
    opts.add_argument('--no-sandbox')
    opts.add_argument('--disable-dev-shm-usage')
    opts.add_argument('--disable-setuid-sandbox')
    opts.add_argument('--disable-namespace-sandbox')
    opts.add_argument('--window-size=1280,900')
    opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')

    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    try:
        driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
            "source": "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
        })
    except Exception as e:
        pass
    return driver

def wait_and_type(driver, wait, selector, text, by=By.CSS_SELECTOR, clear=True, timeout=15):
    try:
        el = WebDriverWait(driver, timeout).until(EC.element_to_be_clickable((by, selector)))
        el.click()
        if clear:
            el.clear()
        el.send_keys(text)
        return True
    except Exception as e:
        log(f"wait_and_type [{selector}]: {e}")
        return False

def wait_and_click(driver, wait, selector, by=By.CSS_SELECTOR, timeout=15):
    try:
        el = WebDriverWait(driver, timeout).until(EC.element_to_be_clickable((by, selector)))
        el.click()
        return True
    except Exception as e:
        log(f"wait_and_click [{selector}]: {e}")
        return False

# ─────────────────────────────────────────────────────────────
# SUBSTACK
# ─────────────────────────────────────────────────────────────
def post_substack(driver, wait, email, password, keyword, target_site, content):
    title = f"Best {keyword.title()} Training - {time.strftime('%B %Y')}"

    log("Opening Substack login...")
    driver.get("https://substack.com/sign-in")
    time.sleep(3)

    # Click "Sign in with email"
    try:
        email_link = wait.until(EC.element_to_be_clickable(
            (By.XPATH, "//a[contains(text(),'email') or contains(text(),'Email')] | //button[contains(text(),'email')]")
        ))
        email_link.click()
        time.sleep(2)
    except: pass

    wait_and_type(driver, wait, "input[name='email'], input[type='email']", email)
    time.sleep(0.5)
    wait_and_type(driver, wait, "input[name='password'], input[type='password']", password)
    wait_and_click(driver, wait, "button[type='submit']")
    time.sleep(5)

    if "sign-in" in driver.current_url or "login" in driver.current_url:
        result(False, error="Substack login failed.")
        return

    log("Substack: logged in!")
    driver.get("https://substack.com/publish/post/new")
    time.sleep(4)

    # Write title
    wait_and_type(driver, wait, "[placeholder*='Title'], .title-input, [data-placeholder='Title']", title)
    time.sleep(0.5)

    # Write body
    try:
        body = driver.find_element(By.CSS_SELECTOR, ".ProseMirror, [contenteditable='true'].body-input, [data-placeholder*='Tell your story']")
        body.click()
        time.sleep(0.3)
        body.send_keys(content[:3000])
        time.sleep(0.5)
    except Exception as e:
        log(f"Body: {e}")

    # Publish
    wait_and_click(driver, wait, "button[class*='publish'], button[data-test-id='post-publish']")
    time.sleep(3)
    wait_and_click(driver, wait, "button[class*='publish-confirm'], button[data-test-id='confirm-publish']", timeout=8)
    time.sleep(5)

    url = driver.current_url
    result(True, url=url if "substack.com" in url else "https://substack.com/dashboard")

# ─────────────────────────────────────────────────────────────
# TUMBLR
# ─────────────────────────────────────────────────────────────
def post_tumblr(driver, wait, email, password, keyword, target_site, content):
    import re
    def clean_title(title_str, kw):
        words = title_str.split()
        clean_words = []
        prev = ""
        for w in words:
            if w.lower() == "training" and prev.lower() == "training":
                continue
            clean_words.append(w)
            prev = w
        return " ".join(clean_words)

    # 1. Try to extract title from content if it starts with h1/p/strong
    title = f"Best {keyword.title()} Training - {time.strftime('%Y')}"
    title = clean_title(title, keyword)
    body_text = content

    m = re.match(r'^\s*<(h[1-6]|p)>\s*(<strong>)?(.*?)(</strong>)?\s*</\1>', content, re.IGNORECASE | re.DOTALL)
    if m:
        extracted = m.group(3).strip()
        extracted = re.sub(r'<[^>]+>', '', extracted)
        if len(extracted) > 5:
            title = clean_title(extracted, keyword)
            body_text = content[m.end():].strip()

    log("Opening Tumblr login...")
    driver.get("https://www.tumblr.com/login")
    time.sleep(5)

    wait_and_type(driver, wait, "input[name='email'], input[type='email'], #signup_email", email)
    time.sleep(0.5)
    wait_and_click(driver, wait, "button[type='submit'], [data-action='next']")
    time.sleep(4)

    wait_and_type(driver, wait, "input[type='password'], #signup_password", password)
    wait_and_click(driver, wait, "button[type='submit'], [data-action='login']")
    time.sleep(8)

    if "login" in driver.current_url:
        result(False, error="Tumblr login failed.")
        return

    log("Tumblr: logged in!")
    compose_clicked = False
    try:
        btns = driver.find_elements(By.CSS_SELECTOR, "button, a")
        for b in btns:
            aria = (b.get_attribute("aria-label") or "").strip().lower()
            txt = b.text.strip().lower()
            if aria == "text" or txt == "text":
                driver.execute_script("arguments[0].click();", b)
                log("Tumblr: clicked dashboard compose button")
                compose_clicked = True
                break
    except Exception as e:
        log(f"Dashboard compose click failed: {e}")

    if not compose_clicked:
        log("Tumblr: Navigating to /new/text...")
        driver.get("https://www.tumblr.com/new/text")
    time.sleep(6)

    # Check if there is an editor iframe and switch to it
    in_iframe = False
    try:
        iframes = driver.find_elements(By.TAG_NAME, "iframe")
        for iframe in iframes:
            cl = (iframe.get_attribute("class") or "").lower()
            if "canvas" in cl or "block-editor" in cl or "editor" in cl:
                driver.switch_to.frame(iframe)
                log("Tumblr: switched to block editor iframe")
                in_iframe = True
                break
    except Exception as e:
        log(f"Failed to switch to iframe: {e}")

    # Title
    try:
        title_el = None
        selectors = [
            "[aria-label='Title']",
            "[data-placeholder='Title']",
            "h1[data-placeholder]",
            ".wp-block-heading",
            "[class*='wp-block-heading']",
            ".heading1",
            "textarea[placeholder='Title']",
            "[placeholder='Title']"
        ]
        for sel in selectors:
            try:
                title_el = driver.find_element(By.CSS_SELECTOR, sel)
                if title_el.is_displayed():
                    break
            except:
                pass
        
        if not title_el:
            title_el = wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, "[role='textbox'], [contenteditable='true']")))
        
        title_el.click()
        time.sleep(0.5)
        title_el.send_keys(Keys.CONTROL + "a")
        title_el.send_keys(Keys.BACKSPACE)
        time.sleep(0.2)
        title_el.send_keys(title)
        log("Tumblr: title typed")
    except Exception as e:
        log(f"Title input failed: {e}")

    # Body (rich HTML paste via DataTransfer)
    try:
        body_el = None
        selectors = [
            "[data-placeholder*='Go ahead']",
            "[data-placeholder*='put anything']",
            "[aria-placeholder*='Go ahead']",
            "[aria-label*='body']",
            "[aria-label*='Post body']",
            ".wp-block-paragraph",
            "[class*='wp-block-paragraph']",
            "[class*='block-editor-rich-text']"
        ]
        for sel in selectors:
            try:
                body_el = driver.find_element(By.CSS_SELECTOR, sel)
                if body_el.is_displayed() and body_el != title_el:
                    break
            except:
                pass
        if not body_el:
            textboxes = driver.find_elements(By.CSS_SELECTOR, "[role='textbox'], [contenteditable='true']")
            for tb in textboxes:
                if tb != title_el and tb.is_displayed():
                    body_el = tb
                    break
        if not body_el:
            raise Exception("No body element found")

        body_el.click()
        time.sleep(0.5)
        
        if "learnmoretech" not in body_text.lower() and "learn more" not in body_text.lower():
            body_text += f"<p>Learn more: <a href='{target_site}'>{target_site}</a></p>"
            
        driver.execute_script("""
            var el = arguments[0];
            var html = arguments[1];
            var dt = new DataTransfer();
            dt.setData('text/html', html);
            dt.setData('text/plain', el.innerText);
            var event = new ClipboardEvent('paste', {
                clipboardData: dt,
                bubbles: true,
                cancelable: true
            });
            el.dispatchEvent(event);
        """, body_el, body_text)
        log("Tumblr: body typed with rich HTML")
    except Exception as e:
        log(f"Body input failed: {e}")

    # Switch back to main document to click Publish button
    if in_iframe:
        try:
            driver.switch_to.default_content()
            log("Tumblr: switched back to default content")
        except Exception as e:
            log(f"Failed to switch to default content: {e}")
    time.sleep(2)

    # Click Post now
    try:
        publish_btn = None
        btns = driver.find_elements(By.TAG_NAME, "button")
        for b in btns:
            txt = b.text.strip().lower()
            aria = (b.get_attribute("aria-label") or "").strip().lower()
            if "post now" in txt or txt == "post" or txt == "publish" or "post" in aria:
                publish_btn = b
                break
        if not publish_btn:
            publish_btn = wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, "button[class*='VxmZd'], button[type='submit']")))
        driver.execute_script("arguments[0].click();", publish_btn)
        log("Tumblr: Post now clicked")
    except Exception as e:
        raise Exception(f"Publish button click failed: {e}")
    time.sleep(4)

    # Confirm publish without tags if prompted
    try:
        confirm_btn = None
        btns = driver.find_elements(By.TAG_NAME, "button")
        for b in btns:
            if b.text.strip().lower() == "post":
                confirm_btn = b
                break
        if confirm_btn:
            driver.execute_script("arguments[0].click();", confirm_btn)
            log("Tumblr: Tags confirmation post clicked")
            time.sleep(6)
    except Exception as e:
        log(f"Tags confirmation click failed: {e}")

    # Extract public blog username and get post link
    final_url = "https://www.tumblr.com/dashboard"
    try:
        # Load user profile to extract link
        driver.get("https://www.tumblr.com/dashboard")
        time.sleep(5)
        links = driver.find_elements(By.TAG_NAME, "a")
        username = ""
        for l in links:
            href = l.get_attribute("href") or ""
            if "tumblr.com/blog/" in href:
                username = href.split("tumblr.com/blog/")[-1].split("/")[0]
                break
        
        if not username:
            for l in links:
                href = l.get_attribute("href") or ""
                if "tumblr.com/" in href and not any(x in href for x in ["/dashboard", "/explore", "/communities", "/settings", "/messages", "/inbox", "/help"]):
                    parts = href.split("tumblr.com/")[-1].split("/")
                    if parts and parts[0]:
                        username = parts[0]
                        break
        
        if username:
            profile_url = f"https://www.tumblr.com/{username}"
            log(f"Tumblr: Profile URL determined = {profile_url}")
            driver.get(profile_url)
            time.sleep(5)
            import re
            links = driver.find_elements(By.TAG_NAME, "a")
            for l in links:
                href = l.get_attribute("href") or ""
                if re.search(r'tumblr\.com/' + re.escape(username) + r'/[0-9]+', href) or re.search(r'tumblr\.com/blog/' + re.escape(username) + r'/[0-9]+', href):
                    final_url = href
                    break
    except Exception as e:
        log(f"Failed to extract post URL: {e}")

    log(f"Tumblr: Final URL = {final_url}")
    result(True, url=final_url)

# ─────────────────────────────────────────────────────────────
# SCOOP.IT
# ─────────────────────────────────────────────────────────────
def post_scoopit(driver, wait, email, password, keyword, target_site, content):
    log("Opening Scoop.it login...")
    driver.get("https://www.scoop.it/login")
    time.sleep(3)

    wait_and_type(driver, wait, "input[name='email'], input[type='email']", email)
    wait_and_type(driver, wait, "input[type='password']", password)
    wait_and_click(driver, wait, "button[type='submit']")
    time.sleep(5)

    if "login" in driver.current_url:
        result(False, error="Scoop.it login failed.")
        return

    log("Scoop.it: logged in!")

    # Go to curate — pass URL to add
    curate_url = f"https://www.scoop.it/curate?url={target_site}"
    driver.get(curate_url)
    time.sleep(4)

    title = f"Best {keyword.title()} Training Guide - {time.strftime('%Y')}"

    # Fill title
    wait_and_type(driver, wait, "input[placeholder*='title' i], [data-field='title']", title, timeout=10)

    # Fill description
    wait_and_type(driver, wait, "textarea[placeholder*='desc' i], [data-field='body'], .insight-form textarea", content[:500], timeout=10)

    # Scoop it / Publish button
    wait_and_click(driver, wait, "button[class*='scoop'], button[data-action*='scoop'], button[class*='publish']", timeout=10)
    time.sleep(5)

    url = driver.current_url
    result(True, url=url if "scoop.it" in url else "https://www.scoop.it")

# ─────────────────────────────────────────────────────────────
# BEHANCE
# ─────────────────────────────────────────────────────────────
def post_behance(driver, wait, email, password, keyword, target_site, content, image_path=None):
    log("Opening Behance (Adobe) login...")
    driver.get("https://www.behance.net/")
    time.sleep(3)

    # Click Sign In
    try:
        sign_in = wait.until(EC.element_to_be_clickable(
            (By.XPATH, "//a[contains(text(),'Sign In') or contains(@href,'adobe')]")
        ))
        sign_in.click()
        time.sleep(3)
    except: pass

    # Adobe login
    wait_and_type(driver, wait, "input[name='username'], input[type='email'], #EmailPage-EmailField", email)
    wait_and_click(driver, wait, "button[class*='continue'], #EmailPage-ContinueButton")
    time.sleep(2)

    wait_and_type(driver, wait, "input[type='password'], #PasswordPage-PasswordField", password)
    wait_and_click(driver, wait, "button[class*='continue'], #PasswordPage-ContinueButton")
    time.sleep(5)

    if "behance.net" not in driver.current_url:
        driver.get("https://www.behance.net/")
        time.sleep(3)

    log("Behance: logged in!")

    # Create new project
    driver.get("https://www.behance.net/work/editor")
    time.sleep(4)

    title = f"Best {keyword.title()} Training - {time.strftime('%Y')}"
    wait_and_type(driver, wait, "input[placeholder*='project' i], [data-testid='project-title']", title)

    # Add text module
    try:
        add_btn = driver.find_element(By.CSS_SELECTOR, "button[class*='add-module'], [aria-label*='Add']")
        add_btn.click()
        time.sleep(1)
        text_btn = driver.find_element(By.XPATH, "//button[contains(text(),'Text')]")
        text_btn.click()
        time.sleep(1)
        editor = driver.find_element(By.CSS_SELECTOR, "[contenteditable='true'], .ql-editor")
        editor.send_keys(content[:2000] + f"\n\nLearn more: {target_site}")
    except Exception as e:
        log(f"Content module: {e}")

    # Publish
    wait_and_click(driver, wait, "button[class*='publish'], [data-testid='publish-button']")
    time.sleep(3)
    wait_and_click(driver, wait, "button[class*='confirm-publish'], [data-testid='confirm']", timeout=8)
    time.sleep(5)

    url = driver.current_url
    result(True, url=url if "behance.net" in url else "https://www.behance.net")

# ─────────────────────────────────────────────────────────────
# PADLET
# ─────────────────────────────────────────────────────────────
def post_padlet(driver, wait, email, password, keyword, target_site, content):
    log("Opening Padlet login...")
    driver.get("https://padlet.com/auth/login")
    time.sleep(3)

    wait_and_type(driver, wait, "input[name='email'], input[type='email']", email)
    wait_and_type(driver, wait, "input[type='password']", password)
    wait_and_click(driver, wait, "button[type='submit']")
    time.sleep(5)

    log("Padlet: logged in!")
    driver.get("https://padlet.com/dashboard")
    time.sleep(3)

    # Create new padlet
    try:
        new_btn = wait.until(EC.element_to_be_clickable(
            (By.XPATH, "//button[contains(text(),'Make a Padlet') or contains(text(),'New')]")
        ))
        new_btn.click()
        time.sleep(4)
    except Exception as e:
        log(f"New padlet: {e}")

    # Use Wall template
    try:
        wall = driver.find_element(By.XPATH, "//button[contains(text(),'Wall')] | //div[contains(text(),'Wall')]")
        wall.click()
        time.sleep(3)
    except: pass

    title = f"Best {keyword.title()} Training - {time.strftime('%Y')}"

    # Set title
    wait_and_type(driver, wait, "input[placeholder*='title' i], [contenteditable][class*='title']", title, timeout=10)
    wait_and_click(driver, wait, "button[type='submit'], button[class*='save']", timeout=8)
    time.sleep(3)

    # Add a post/card with the URL
    try:
        add_post = wait.until(EC.element_to_be_clickable(
            (By.CSS_SELECTOR, "button[class*='add-post'], [aria-label='Add post'], .add-post-button")
        ))
        add_post.click()
        time.sleep(2)

        # Fill card title
        wait_and_type(driver, wait, "[placeholder*='Title'], [class*='post-title']", f"Learn {keyword.title()}", timeout=8)

        # Fill card body
        wait_and_type(driver, wait, "[placeholder*='description' i], [class*='post-body'], [contenteditable]",
                      f"Best {keyword} training. Learn more: {target_site}", timeout=8)

        # Save card
        wait_and_click(driver, wait, "button[class*='save'], button[type='submit']", timeout=8)
        time.sleep(3)
    except Exception as e:
        log(f"Add card: {e}")

    url = driver.current_url
    result(True, url=url if "padlet.com" in url else "https://padlet.com/dashboard")

# ─────────────────────────────────────────────────────────────
# WAKELET
# ─────────────────────────────────────────────────────────────
def post_wakelet(driver, wait, email, password, keyword, target_site, content):
    log("Opening Wakelet login...")
    driver.get("https://wakelet.com/login")
    time.sleep(3)

    wait_and_type(driver, wait, "input[type='email'], input[name='email']", email)
    wait_and_type(driver, wait, "input[type='password']", password)
    wait_and_click(driver, wait, "button[type='submit']")
    time.sleep(5)

    log("Wakelet: logged in!")

    # Create new wake
    driver.get("https://wakelet.com/new")
    time.sleep(4)

    title = f"Best {keyword.title()} Training Resources - {time.strftime('%Y')}"
    wait_and_type(driver, wait, "input[placeholder*='title' i], [class*='title-input']", title, timeout=10)

    # Add link item
    try:
        add_link = wait.until(EC.element_to_be_clickable(
            (By.XPATH, "//button[contains(text(),'Add link') or contains(text(),'Link')]")
        ))
        add_link.click()
        time.sleep(2)

        url_field = driver.find_element(By.CSS_SELECTOR, "input[placeholder*='url' i], input[type='url'], input[placeholder*='link' i]")
        url_field.send_keys(target_site)
        time.sleep(0.5)

        add_btn = driver.find_element(By.CSS_SELECTOR, "button[type='submit'], button[class*='add']")
        add_btn.click()
        time.sleep(3)
    except Exception as e:
        log(f"Add link: {e}")

    # Publish/Save
    wait_and_click(driver, wait, "button[class*='publish'], button[class*='save']", timeout=10)
    time.sleep(5)

    url = driver.current_url
    result(True, url=url if "wakelet.com" in url else "https://wakelet.com/@me")

# ─────────────────────────────────────────────────────────────
# MAIN DISPATCHER
# ─────────────────────────────────────────────────────────────
PLATFORM_MAP = {
    'substack':   post_substack,
    'tumblr':     post_tumblr,
    'scoopit':    post_scoopit,
    'behance':    post_behance,
    'padlet':     post_padlet,
    'wakelet':    post_wakelet,
}

if __name__ == "__main__":
    if len(sys.argv) < 6:
        result(False, error="Usage: generic_post.py <platform> <email> <password> <keyword> <target_site> [content/content_file]")
        sys.exit(1)

    platform    = sys.argv[1].lower()
    email       = sys.argv[2]
    password    = sys.argv[3]
    keyword     = sys.argv[4]
    target_site = sys.argv[5]

    content_arg = sys.argv[6] if len(sys.argv) > 6 else ""
    if content_arg and os.path.exists(content_arg):
        try:
            with open(content_arg, "r", encoding="utf-8") as f:
                content = f.read()
        except Exception as e:
            content = f"Best {keyword} training. Learn more: {target_site}"
    else:
        content = content_arg if content_arg else f"Best {keyword} training. Learn more: {target_site}"

    if platform not in PLATFORM_MAP:
        result(False, error=f"Platform '{platform}' not supported in generic_post.py")
        sys.exit(1)

    driver = get_driver(platform, email, headless=True)
    wait   = WebDriverWait(driver, 20)

    try:
        PLATFORM_MAP[platform](driver, wait, email, password, keyword, target_site, content)
    except Exception as e:
        result(False, error=str(e))
    finally:
        try:
            driver.quit()
        except:
            pass
