#!/usr/bin/env python3
"""
Pinterest Auto-Post via Playwright
Migrated from Selenium for enhanced stability and auto-waiting.
"""
import sys, json, time, os, re
os.environ['PLAYWRIGHT_BROWSERS_PATH'] = '/usr/local/share/playwright'
script_dir = os.path.dirname(os.path.abspath(__file__))

# Profile isolation by sys_user and email hash
try:
    import pwd
    sys_user = pwd.getpwuid(os.getuid())[0]
except Exception:
    import getpass
    sys_user = getpass.getuser()

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error=''):
    print(json.dumps({"success": success, "url": url, "error": error}), flush=True)

def pinterest_post(email, password, keyword, target_site, image_path=None, ai_title="", ai_content=""):
    log(f"Starting Playwright Pinterest post with email: {email}")
    
    import hashlib
    email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest()
    profile_dir = os.path.join(script_dir, f'chrome_profile_pinterest_{email_hash}')
    
    # Clean up lock files from any previous crashed runs
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

    from playwright.sync_api import sync_playwright
    
    with sync_playwright() as p:
        try:
            # Launch persistent context
            launch_args = [
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--disable-blink-features=AutomationControlled",
                "--disable-gpu",
                "--disable-software-rasterizer"
            ]
            
            headless_mode = True
            if os.environ.get("DISPLAY") or os.environ.get("USE_HEADFUL") == "1" or sys.platform != "win32":
                headless_mode = False

            log(f"Launching browser context (headless={headless_mode})...")
            context = p.chromium.launch_persistent_context(
                user_data_dir=profile_dir,
                headless=headless_mode,
                args=launch_args,
                viewport={"width": 1400, "height": 900},
                user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
            )
            
            context.add_init_script("""
                Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
                window.chrome = { runtime: {} };
                Object.defineProperty(navigator, 'plugins', {get: () => [1, 2, 3, 4, 5]});
                Object.defineProperty(navigator, 'languages', {get: () => ['en-US', 'en']});
            """)
            
            page = context.pages[0] if context.pages else context.new_page()
            
            # ── Step 1 & 2: Direct Pin Creation Entry (Reusing Saved Session Cookies) ──
            log("Opening Pin creation tool directly using saved session...")
            page.goto("https://www.pinterest.com/pin-creation-tool/", wait_until="domcontentloaded", timeout=60000)
            page.wait_for_timeout(4000)
            
            already_logged = False
            current_url = page.url.lower()
            if "login" not in current_url and "signup" not in current_url:
                if page.locator("input[type='email'], input[name='username'], input#email").count() == 0:
                    already_logged = True
                    log("Already logged in via saved Chrome session cookies!")
            
            if not already_logged:
                log("Session expired or not logged in — logging in...")
                log("Navigating to Pinterest home page...")
                page.goto("https://www.pinterest.com/", wait_until="domcontentloaded", timeout=60000)
                page.wait_for_timeout(3000)
                
                # Click Log in button on home page to open modal
                login_btn = page.locator("[data-test-id='simple-login-button'], button:has-text('Log in'), div[role='button']:has-text('Log in'), a[href*='/login/']").first
                if login_btn.count() > 0 and login_btn.is_visible():
                    log("Opening Pinterest login modal from home page...")
                    login_btn.click(timeout=5000)
                    page.wait_for_timeout(2000)
                else:
                    log("Navigating to Pinterest /login/ page...")
                    page.goto("https://www.pinterest.com/login/", wait_until="domcontentloaded", timeout=60000)
                    page.wait_for_timeout(3000)
                
                # Email input — human typing + React events
                email_input = page.locator("input[type='email'], input#email, input[name='username']").first
                email_input.wait_for(state="visible", timeout=20000)
                email_input.click()
                try:
                    email_input.press_sequentially(email, delay=40)
                except Exception:
                    email_input.fill(email)
                email_input.dispatch_event("input")
                email_input.dispatch_event("change")
                page.wait_for_timeout(1000)
                
                # Password input — human typing + React events
                pass_input = page.locator("input[type='password'], input#password, input[name='password']").first
                pass_input.click()
                try:
                    pass_input.press_sequentially(password, delay=40)
                except Exception:
                    pass_input.fill(password)
                pass_input.dispatch_event("input")
                pass_input.dispatch_event("change")
                page.wait_for_timeout(1500)
                
                page.wait_for_timeout(3000)
                
                # Submit via trusted click / Enter key
                try:
                    submit_btn = page.locator("button[type='submit'], [data-test-id='registerFormSubmitButton'], [data-test-id='login-button'], div[role='button']:has-text('Log in')").first
                    if submit_btn.count() > 0 and submit_btn.is_visible():
                        log("Submitting Pinterest login form via trusted click...")
                        submit_btn.click(timeout=5000)
                    else:
                        log("Submit button not found — pressing Enter key...")
                        pass_input.press("Enter")
                except Exception as e_sub:
                    log(f"Submit exception: {e_sub}")
                    try:
                        pass_input.press("Enter")
                    except Exception:
                        pass
                
                page.wait_for_timeout(4000)
                
                # Login verification loop — retry up to 3 times if session is not active
                for login_attempt in range(1, 4):
                    log(f"Login attempt {login_attempt}/3 — verifying session via /me/...")
                    try:
                        page.goto("https://www.pinterest.com/me/", wait_until="domcontentloaded", timeout=30000)
                        page.wait_for_timeout(3000)
                        if "/me" not in page.url and "login" not in page.url and page.url.rstrip("/") != "https://www.pinterest.com":
                            log(f"Login OK! Validated active session at profile URL: {page.url}")
                            break
                    except Exception:
                        pass
                    
                    log(f"Session not active yet — retrying direct login submission (Attempt {login_attempt}/3)...")
                    page.goto("https://www.pinterest.com/login/", wait_until="domcontentloaded", timeout=60000)
                    page.wait_for_timeout(3000)
                    try:
                        # Open login modal if redirected to home page
                        login_btn = page.locator("div[role='button']:has-text('Log in'), button:has-text('Log in'), [data-test-id='simple-login-button']").first
                        if login_btn.count() > 0 and login_btn.is_visible():
                            login_btn.click()
                            page.wait_for_timeout(2000)

                        em_in = page.locator("input[type='email'], input#email, input[name='username']").first
                        if em_in.count() > 0 and em_in.is_visible():
                            em_in.click()
                            em_in.fill("")
                            em_in.type(email, delay=30)
                            em_in.dispatch_event("input")
                            page.wait_for_timeout(1000)
                            
                            pw_in = page.locator("input[type='password'], input#password, input[name='password']").first
                            pw_in.click()
                            pw_in.fill("")
                            pw_in.type(password, delay=30)
                            pw_in.dispatch_event("input")
                            page.wait_for_timeout(1500)
                            
                            sub = page.locator("button[type='submit'], [data-test-id='registerFormSubmitButton'], [data-test-id='login-button']").first
                            if sub.count() > 0 and sub.is_visible():
                                sub.click(timeout=5000)
                            else:
                                pw_in.press("Enter")
                            page.wait_for_timeout(5000)
                    except Exception as e_retry:
                        log(f"Login retry attempt {login_attempt} exception: {e_retry}")

            # Extra stabilization wait: handle Pinterest 1-second flash redirect while reading session cookies
            log("Waiting for Pin creation tool UI to stabilize...")
            page.wait_for_timeout(4000)
            try:
                page.wait_for_url(lambda u: "login" not in u.lower() and "signup" not in u.lower(), timeout=15000)
            except Exception:
                pass

            # If briefly on login page during flash redirect, wait for auto-redirect back to pin builder
            if "login" in page.url.lower() or "signup" in page.url.lower():
                log("Temporary flash redirect detected — waiting for auto-redirect to Pin builder...")
                page.wait_for_timeout(5000)
                if "login" in page.url.lower() or "signup" in page.url.lower():
                    page.goto("https://www.pinterest.com/pin-creation-tool/", wait_until="domcontentloaded", timeout=60000)
                    page.wait_for_timeout(5000)

            log("Login OK! Pin builder ready.")
            
            # Complete any onboarding NUX wizard step-by-step for newly created accounts
            try:
                for attempt in range(5):
                    curr_u = page.url.lower()
                    if "nux" in curr_u or "today" in curr_u or page.locator("[data-test-id='nux-picker-card']").count() > 0:
                        log("New account onboarding active — passing wizard step...")
                        cards = page.locator("[data-test-id='nux-picker-card'], div[role='button']:has(img)").all()
                        if len(cards) >= 3:
                            for card in cards[:6]:
                                try:
                                    card.click(timeout=1500)
                                    page.wait_for_timeout(300)
                                except Exception:
                                    pass
                        page.wait_for_timeout(1000)
                        next_btn = page.locator("button:has-text('Next'), button:has-text('Done'), button:has-text('Continue'), [data-test-id*='nux'], [aria-label='Close'], button:has-text('Got it')").first
                        if next_btn.count() > 0 and next_btn.is_visible():
                            log("Clicking onboarding Next/Done button...")
                            next_btn.click(timeout=3000)
                            page.wait_for_timeout(2500)
                    else:
                        break
            except Exception as e_nux:
                log(f"NUX onboarding bypass check: {e_nux}")

            # Determine regional subdomain (e.g. in.pinterest.com)
            current_domain = "in.pinterest.com" if "in.pinterest.com" in page.url else "www.pinterest.com"
            log(f"Opening Pin creation canvas (Domain: {current_domain})...")
            
            # Attempt 1: Direct navigation to regional pin-creation-tool
            try:
                page.goto(f"https://{current_domain}/pin-creation-tool/", wait_until="domcontentloaded", timeout=45000)
            except Exception as e_pb:
                log(f"Pin builder direct navigation: {e_pb}")

            # Wait for React to attach file input
            try:
                page.wait_for_selector("input[type='file'], [data-test-id*='upload']", state="attached", timeout=8000)
            except Exception:
                pass

            # Attempt 2: If input not found, click Create [+] on sidebar
            if page.locator("input[type='file']").count() == 0:
                log("File input not ready — trying left sidebar Create (+) button...")
                create_btn = page.locator("a[aria-label*='Create' i], button[aria-label*='Create' i], [data-test-id='create-button']").first
                if create_btn.count() > 0 and create_btn.is_visible():
                    try:
                        create_btn.click(timeout=5000)
                        page.wait_for_timeout(2000)
                        pin_opt = page.locator("[role='menu'] a:has-text('Pin'), [role='menuitem']:has-text('Pin'), span:has-text('Pin')").first
                        if pin_opt.count() > 0 and pin_opt.is_visible():
                            pin_opt.click(timeout=3000)
                            page.wait_for_timeout(3000)
                    except Exception as e_c:
                        log(f"Sidebar click: {e_c}")

            # Attempt 3: If input still not found, try pin-builder URL
            if page.locator("input[type='file']").count() == 0:
                log(f"Navigating to https://{current_domain}/pin-builder/...")
                try:
                    page.goto(f"https://{current_domain}/pin-builder/", wait_until="domcontentloaded", timeout=45000)
                    page.wait_for_timeout(5000)
                except Exception as e_pb2:
                    log(f"Pin builder fallback 2: {e_pb2}")

            # Dismiss any dialogs / overlays covering the Pin builder
            for _ in range(3):
                try:
                    close_btn = page.locator("button:has-text('Not now'), button:has-text('Skip'), button:has-text('Cancel'), [aria-label='Close'], button:has-text('Got it')").first
                    if close_btn.count() > 0 and close_btn.is_visible():
                        log("Dismissing overlay dialog on Pin builder...")
                        close_btn.click(timeout=3000)
                        page.wait_for_timeout(2000)
                    else:
                        break
                except Exception:
                    break

            file_check = page.locator("input[type='file']").first
            if file_check.count() == 0:
                log(f"File input missing. Current URL: {page.url} | Title: {page.title()}")
                try:
                    debug_img = os.path.join(os.path.dirname(script_dir), 'uploads', 'pinterest_builder_debug.png')
                    page.screenshot(path=debug_img, timeout=5000)
                    log(f"Saved debug screenshot to pinterest_builder_debug.png")
                except Exception:
                    pass
            
            # ── Step 3: Upload image ───────────────────────────────────
            if not image_path or not os.path.exists(image_path):
                uploads_dir = os.path.join(os.path.dirname(script_dir), 'uploads')
                if os.path.exists(uploads_dir):
                    import glob
                    imgs = glob.glob(os.path.join(uploads_dir, '*.jpg')) + glob.glob(os.path.join(uploads_dir, '*.png'))
                    if imgs:
                        imgs.sort(key=os.path.getmtime, reverse=True)
                        image_path = imgs[0]
                        log(f"Auto-selected image from uploads: {image_path}")

            image_uploaded = False
            if image_path and os.path.exists(image_path):
                log(f"Uploading image: {os.path.basename(image_path)}...")
                try:
                    file_input = page.locator("input[type='file'], input[data-test-id*='upload'], input[data-test-id*='storyboard'], [data-test-id='media-upload-input']").first
                    file_input.wait_for(state="attached", timeout=12000)
                    file_input.set_input_files(os.path.abspath(image_path))
                    page.wait_for_timeout(6000)
                    log("Image uploaded!")
                    image_uploaded = True
                except Exception as e:
                    log(f"Image upload: {e}")
            
            if not image_uploaded:
                log("Pin builder canvas file input not ready — halting execution.")
                result(False, error="Pin builder UI inputs not found — please check Pinterest account.")
                context.close()
                return
            
            # ── Step 4: Title ──────────────────────────────────────────
            title = ai_title if ai_title else f"Best {keyword.title()} - {time.strftime('%Y')} Guide"
            log("Filling title...")
            try:
                title_input = page.locator("#storyboard-selector-title, input[id*='storyboard-selector-title'], textarea[id*='storyboard-selector-title'], input[placeholder*='title' i], textarea[placeholder*='title' i], [data-test-id='pin-builder-title'], [data-test-id='pin-draft-title']").first
                title_input.wait_for(state="visible", timeout=10000)
                title_input.click()
                title_input.fill(title[:100])
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
            desc = desc[:500]
            log(f"Description length: {len(desc)} chars")
            log("Filling description...")
            try:
                desc_input = page.locator("#storyboard-selector-description, [data-test-id*='description'], [contenteditable='true'], .public-DraftEditor-editor").first
                desc_input.wait_for(state="visible", timeout=10000)
                desc_input.click()
                page.wait_for_timeout(400)
                desc_input.fill(desc)
                page.wait_for_timeout(300)
                # Dispatch input & change events so Pinterest React state saves the description
                page.evaluate("""() => {
                    const el = document.querySelector("#storyboard-selector-description, [data-test-id*='description'], [contenteditable='true'], .public-DraftEditor-editor");
                    if (el) {
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }""")
                page.keyboard.type(" ")
                log("Description OK!")
            except Exception as e:
                try:
                    desc_input = page.locator("[contenteditable='true']").first
                    desc_input.click()
                    page.keyboard.type(desc, delay=2)
                    log("Description OK (keyboard fallback)!")
                except Exception as e2:
                    log(f"Desc: {e2}")
            
            # ── Step 6: Link ───────────────────────────────────────────
            log("Filling link...")
            try:
                link_input = page.locator("input[name='link'], input[id='WebsiteField'], input[placeholder*='link' i], [data-test-id='pin-builder-link'], [data-test-id='pin-draft-link']").first
                link_input.wait_for(state="visible", timeout=10000)
                link_input.click()
                link_input.fill(target_site)
                log("Link OK!")
            except Exception as e:
                log(f"Link: {e}")
                
            # ── Step 7: Board ──────────────────────────────────────────
            log("Opening board dropdown...")
            try:
                board_btn = page.locator("[data-test-id='board-dropdown-select-button'], [data-test-id='board-picker-select-button'], button:has-text('Select board'), div:has-text('Choose a board')").first
                board_btn.wait_for(state="visible", timeout=10000)
                board_btn.click()
                page.wait_for_timeout(3000)
                log("Board dropdown opened")
                
                flyout = page.locator("[data-test-id='board-picker-flyout'], [role='listbox']")
                if flyout.count() > 0:
                    rows = flyout.locator("[data-test-id='boardWithoutSection'], [role='option']")
                    row_count = rows.count()
                    log(f"Board rows found: {row_count}")
                    
                    if row_count > 0:
                        kw_words = [w.lower() for w in keyword.split() if len(w) > 2]
                        best_idx = None
                        best_score = 0
                        
                        for idx in range(row_count):
                            txt = rows.nth(idx).inner_text().strip().lower()
                            if not txt or 'create' in txt:
                                continue
                            score = sum(1 for w in kw_words if w in txt)
                            if score > best_score:
                                best_score = score
                                best_idx = idx
                                
                        if best_idx is not None:
                            row_to_click = rows.nth(best_idx)
                            log(f"Board selected: '{row_to_click.inner_text().strip()}' (score={best_score})")
                            row_to_click.click()
                        else:
                            first_row = rows.first
                            log(f"Board selected: '{first_row.inner_text().strip()}' (fallback)")
                            first_row.click()
                    else:
                        log("No board rows matched — keeping default board.")
                    page.wait_for_timeout(3000)
            except Exception as e:
                log(f"Board select: {e}")
                
            # ── Step 8: Publish ────────────────────────────────────────
            log("Publishing pin...")
            published = False
            for attempt in range(4):
                try:
                    pub_btn = page.locator("button[data-test-id*='publish'], [data-test-id='board-dropdown-save-button'], button:has-text('Publish'), button:has-text('Save'), div[role='button']:has-text('Publish')").first
                    if pub_btn.count() > 0 and pub_btn.is_visible() and pub_btn.is_enabled():
                        pub_btn.click(timeout=5000)
                        log("Published via Publish button click!")
                        published = True
                        break
                    else:
                        btns = page.locator("button")
                        btn_count = btns.count()
                        for idx in range(btn_count):
                            btn = btns.nth(idx)
                            txt = btn.inner_text().strip()
                            if txt == 'Publish' and btn.is_visible() and btn.is_enabled():
                                btn.click()
                                log("Published via Publish button click!")
                                published = True
                                break
                    if published:
                        break
                except Exception as e:
                    log(f"Publish attempt {attempt+1} failed: {e}")
                page.wait_for_timeout(3000)
                
            # ── Step 9: Get Pin URL ────────────────────────────────────
            log("Waiting for published Pin URL...")
            
            # 1. Immediately check for "See your pin" / "View" toast popup link before it fades out
            for _ in range(10):
                try:
                    see_pin = page.locator("a:has-text('See your pin'), a:has-text('View'), [data-test-id='toast-link'], div[role='alert'] a[href*='/pin/']").first
                    if see_pin.count() > 0 and see_pin.is_visible():
                        log("Clicking 'See your pin' toast link...")
                        see_pin.click()
                        page.wait_for_timeout(2000)
                        break
                except Exception:
                    pass
                page.wait_for_timeout(500)
                
            for _ in range(6):
                cu = page.url
                if "/pin/" in cu:
                    log(f"Captured Pin URL: {cu}")
                    result(True, url=cu)
                    context.close()
                    return
                page.wait_for_timeout(500)
                
            # 2. Search DOM for created /pin/ link
            try:
                pin_links = page.locator("a[href*='/pin/']")
                if pin_links.count() > 0:
                    href = pin_links.first.get_attribute("href")
                    if href and "/pin/" in href:
                        if not href.startswith("http"):
                            href = "https://www.pinterest.com" + href
                        log(f"Captured Pin URL from DOM link: {href}")
                        result(True, url=href)
                        context.close()
                        return
            except Exception:
                pass
                
            page_source = page.content()
            pin_urls = re.findall(r'https://[a-z.]*pinterest\.com/pin/\d+', page_source)
            if pin_urls:
                log(f"Captured Pin URL from page source regex: {pin_urls[0]}")
                result(True, url=pin_urls[0])
                context.close()
                return

            if published:
                # 3. Fallback: Navigate to /me/ and extract the newest created Pin URL from profile
                try:
                    log("Navigating to /me/ to detect exact user profile & latest Pin URL...")
                    page.goto("https://www.pinterest.com/me/", wait_until="domcontentloaded", timeout=20000)
                    page.wait_for_timeout(3000)
                    
                    # Search for newest created pin on profile page
                    profile_pin_links = page.locator("a[href*='/pin/']")
                    if profile_pin_links.count() > 0:
                        href = profile_pin_links.first.get_attribute("href")
                        if href and "/pin/" in href:
                            if not href.startswith("http"):
                                href = "https://www.pinterest.com" + href
                            log(f"Captured newest Pin URL from profile page: {href}")
                            result(True, url=href)
                            context.close()
                            return
                            
                    profile_url = page.url
                    if "pinterest.com" in profile_url and "login" not in profile_url and "signup" not in profile_url:
                        if not profile_url.endswith("/"):
                            profile_url += "/"
                        log(f"Captured user profile URL: {profile_url}")
                        result(True, url=profile_url)
                        context.close()
                        return
                except Exception as e_me:
                    log(f"Profile URL check exception: {e_me}")
                    log(f"/me/ redirect exception: {e_me}")
                uname = email.split("@")[0].lower().replace(".", "")
                result(True, url=f"https://www.pinterest.com/{uname}/")
            else:
                result(False, error="Pin may not have published — check Pinterest account.")
                
            context.close()
            
        except Exception as e:
            try:
                if 'page' in locals():
                    page.screenshot(path=os.path.join(os.path.dirname(script_dir), 'uploads', 'pinterest_error.png'))
                    log("Saved exception error screenshot to pinterest_error.png")
            except Exception as ex:
                log(f"Screenshot exception: {ex}")
            result(False, error=str(e))
            try:
                if 'context' in locals():
                    context.close()
            except:
                pass

if __name__ == "__main__":
    if len(sys.argv) < 5:
        result(False, error="Usage: pinterest_post_playwright.py <email> <password> <keyword> <target_site> [image_path] [ai_title] [ai_content]")
        sys.exit(1)
    pinterest_post(
        sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4],
        sys.argv[5] if len(sys.argv) > 5 else None,
        sys.argv[6] if len(sys.argv) > 6 else "",
        sys.argv[7] if len(sys.argv) > 7 else "",
    )
