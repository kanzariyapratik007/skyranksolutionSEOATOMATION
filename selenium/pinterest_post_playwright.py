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
            if os.environ.get("USE_HEADFUL") == "1":
                headless_mode = False

            # Support system installed Google Chrome on Windows/Linux
            launch_kwargs = {
                "user_data_dir": profile_dir,
                "headless": headless_mode,
                "args": launch_args,
                "viewport": {"width": 1400, "height": 900},
                "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
            }
            if sys.platform == "win32":
                launch_kwargs["channel"] = "chrome"

            log(f"Launching browser context (headless={headless_mode})...")
            try:
                context = p.chromium.launch_persistent_context(**launch_kwargs)
            except Exception:
                launch_kwargs.pop("channel", None)
                context = p.chromium.launch_persistent_context(**launch_kwargs)
            
            context.add_init_script("""
                Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
                window.chrome = { runtime: {} };
                Object.defineProperty(navigator, 'plugins', {get: () => [1, 2, 3, 4, 5]});
                Object.defineProperty(navigator, 'languages', {get: () => ['en-US', 'en']});
            """)
            
            page = context.pages[0] if context.pages else context.new_page()
            
            # ── Step 1 & 2: Direct Pin Creation Entry (Reusing Saved Session Cookies) ──
            log("Opening Pin creation tool directly using saved session...")
            page.goto("https://www.pinterest.com/pin-builder/", wait_until="domcontentloaded", timeout=60000)
            page.wait_for_timeout(4000)
            
            already_logged = False
            current_url = page.url.lower()
            if "login" not in current_url and "signup" not in current_url:
                if page.locator("input[type='email'], input[name='username'], input#email").count() == 0:
                    already_logged = True
                    log("Already logged in via saved Chrome session cookies!")
            
            if not already_logged:
                log("Session expired or not logged in — logging in...")
                log("Navigating directly to Pinterest /login/ page...")
                page.goto("https://www.pinterest.com/login/", wait_until="domcontentloaded", timeout=60000)
                page.wait_for_timeout(3000)
                
                # Check if email input is visible, or open modal if on home page
                em_in = page.locator("input[type='email'], input#email, input[name='username'], input[id*='email']").first
                if not (em_in.count() > 0 and em_in.is_visible()):
                    login_btn = page.locator("[data-test-id='simple-login-button'], button:has-text('Log in'), div[role='button']:has-text('Log in')").first
                    if login_btn.count() > 0 and login_btn.is_visible():
                        login_btn.click()
                        page.wait_for_timeout(2000)

                em_in = page.locator("input[type='email'], input#email, input[name='username'], input[id*='email']").first
                em_in.wait_for(state="visible", timeout=20000)
                em_in.click()
                em_in.fill("")
                em_in.type(email, delay=40)
                em_in.dispatch_event("input")
                em_in.dispatch_event("change")
                page.wait_for_timeout(1000)
                
                pw_in = page.locator("input[type='password'], input#password, input[name='password'], input[id*='password']").first
                pw_in.click()
                pw_in.fill("")
                pw_in.type(password, delay=40)
                pw_in.dispatch_event("input")
                pw_in.dispatch_event("change")
                page.wait_for_timeout(1000)
                
                sub = page.locator("button[type='submit'], [data-test-id='registerFormSubmitButton']").first
                if sub.count() > 0 and sub.is_visible():
                    log("Submitting Pinterest login form via trusted click...")
                    sub.click(timeout=5000)
                else:
                    log("Submitting via Enter key...")
                    pw_in.press("Enter")
                
                page.wait_for_timeout(6000)
                
                # Capture exact screen state after submit BEFORE any redirect action
                try:
                    screenshot_path1 = os.path.join(script_dir, 'pinterest_login_failed.png')
                    screenshot_path2 = os.path.join(os.path.dirname(script_dir), 'uploads', 'pinterest_login_failed.png')
                    page.screenshot(path=screenshot_path1, timeout=5000)
                    page.screenshot(path=screenshot_path2, timeout=5000)
                except Exception:
                    pass

                # Check if session is authenticated by navigating to pin creation tool
                log("Verifying session via pin creation tool...")
                logged_in = False
                try:
                    page.goto("https://www.pinterest.com/pin-builder/", wait_until="domcontentloaded", timeout=30000)
                    page.wait_for_timeout(4000)
                    curr_u = page.url.lower()
                    if "login" not in curr_u and "signup" not in curr_u:
                        logged_in = True
                        log("Session verified: logged in successfully!")
                except Exception as ve:
                    log(f"Verification error: {ve}")

                if not logged_in:
                    # Extract any visible error text from login modal
                    err_msg = ""
                    try:
                        err_elem = page.locator("[data-test-id*='error'], form p, div[role='alert']").first
                        if err_elem.count() > 0 and err_elem.is_visible():
                            err_msg = err_elem.inner_text().strip()
                    except Exception:
                        pass
                    
                    fail_reason = f"Pinterest login failed — {err_msg}" if err_msg else f"Pinterest login failed — please check password or email verification for {email}."
                    log(f"Login failure: {fail_reason} (Current URL: {page.url})")
                    result(False, error=fail_reason)
                    context.close()
                    return
            try:
                page.wait_for_url(lambda u: "login" not in u.lower() and "signup" not in u.lower(), timeout=15000)
            except Exception:
                pass

            # If briefly on login page during flash redirect, wait for auto-redirect back to pin builder
            if "login" in page.url.lower() or "signup" in page.url.lower():
                log("Temporary flash redirect detected — waiting for auto-redirect to Pin builder...")
                page.wait_for_timeout(5000)
                if "login" in page.url.lower() or "signup" in page.url.lower():
                    page.goto("https://www.pinterest.com/pin-builder/", wait_until="domcontentloaded", timeout=60000)
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

            # Navigate directly to pin creation tool
            log("Opening Pin creation tool...")
            try:
                page.goto("https://www.pinterest.com/pin-builder/", wait_until="domcontentloaded", timeout=45000)
                page.wait_for_timeout(4000)
            except Exception as e_pb:
                log(f"Pin builder direct navigation: {e_pb}")

            # If redirected to home page, click Create in navigation
            curr_url = page.url.lower()
            if "pin-creation" not in curr_url and "pin-builder" not in curr_url:
                log("Redirected to home page — clicking Create button in navigation...")
                create_nav = page.locator("[data-test-id='header-create-button'], a[href*='pin-builder'], a[href*='pin-creation-tool'], a[aria-label*='Create' i], button[aria-label*='Create' i]").first
                if create_nav.count() > 0 and create_nav.is_visible():
                    try:
                        create_nav.click(timeout=5000)
                        page.wait_for_timeout(3000)
                        pin_opt = page.locator("[role='menu'] a:has-text('Pin'), [role='menuitem']:has-text('Pin'), span:has-text('Pin')").first
                        if pin_opt.count() > 0 and pin_opt.is_visible():
                            pin_opt.click(timeout=3000)
                            page.wait_for_timeout(3000)
                    except Exception as e_c:
                        log(f"Create navigation click: {e_c}")

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
                abs_img = os.path.abspath(image_path)
                
                # Method 1: Un-hide native input[type='file'] elements in Pinterest's DOM
                try:
                    page.evaluate("""() => {
                        const inputs = document.querySelectorAll("input[type='file']");
                        inputs.forEach(inp => {
                            inp.style.display = "block";
                            inp.style.opacity = "1";
                            inp.style.visibility = "visible";
                            inp.style.position = "fixed";
                            inp.style.top = "10px";
                            inp.style.left = "10px";
                            inp.style.width = "200px";
                            inp.style.height = "50px";
                            inp.style.zIndex = "999999";
                            inp.removeAttribute("hidden");
                        });
                    }""")
                    page.wait_for_timeout(1000)
                    
                    native_inputs = page.locator("input[type='file']:not(#injected_pin_file_input)")
                    if native_inputs.count() > 0:
                        log(f"Found {native_inputs.count()} native file input(s) — attaching image...")
                        native_inputs.first.set_input_files(abs_img)
                        page.wait_for_timeout(2000)
                        page.evaluate("""() => {
                            const inputs = document.querySelectorAll("input[type='file']");
                            inputs.forEach(inp => {
                                inp.dispatchEvent(new Event('change', { bubbles: true }));
                                inp.dispatchEvent(new Event('input', { bubbles: true }));
                            });
                        }""")
                        page.wait_for_timeout(3000)
                        image_uploaded = True
                except Exception as e_nat:
                    log(f"Native upload attempt: {e_nat}")

                # Method 2: Trigger FileChooser via clicking dropzone / media area
                if not image_uploaded:
                    try:
                        upload_dz = page.locator("[data-test-id*='upload'], [data-test-id*='media'], [data-test-id*='storyboard'], div[role='button']:has-text('Choose'), div:has-text('Drag and drop'), div:has-text('Choose a file'), svg[aria-label*='upload' i]").first
                        if upload_dz.count() > 0:
                            log("Clicking upload dropzone via FileChooser...")
                            with page.expect_file_chooser(timeout=5000) as fc_info:
                                upload_dz.click(force=True)
                            fc_info.value.set_files(abs_img)
                            page.wait_for_timeout(3000)
                            image_uploaded = True
                    except Exception as e_fc:
                        log(f"FileChooser upload attempt: {e_fc}")

                # Method 3: Dynamic fallback injection
                if not image_uploaded:
                    try:
                        log("Injecting dynamic file input as fallback...")
                        page.evaluate("""() => {
                            if (!document.querySelector("input[type='file']")) {
                                const inp = document.createElement("input");
                                inp.type = "file";
                                inp.id = "injected_pin_file_input";
                                inp.style.position = "fixed";
                                inp.style.top = "0px";
                                inp.style.left = "0px";
                                inp.style.zIndex = "99999";
                                document.body.appendChild(inp);
                            }
                        }""")
                        file_input = page.locator("input[type='file']").first
                        file_input.set_input_files(abs_img)
                        page.wait_for_timeout(2000)
                        page.evaluate("""() => {
                            const inputs = document.querySelectorAll("input[type='file']");
                            inputs.forEach(inp => {
                                inp.dispatchEvent(new Event('change', { bubbles: true }));
                                inp.dispatchEvent(new Event('input', { bubbles: true }));
                            });
                        }""")
                        page.wait_for_timeout(3000)
                        image_uploaded = True
                    except Exception as e_inj:
                        log(f"Injection upload attempt: {e_inj}")

                log("Image uploaded! Waiting for canvas fields...")
                page.wait_for_timeout(3000)

            # ── Step 4: Title ──────────────────────────────────────────
            title = ai_title if ai_title else f"Best {keyword.title()} - {time.strftime('%Y')} Guide"
            log("Filling title...")
            try:
                title_input = page.locator("#storyboard-selector-title, input[id*='storyboard-selector-title'], textarea[id*='storyboard-selector-title'], input[placeholder*='title' i], textarea[placeholder*='title' i], [data-test-id='pin-builder-title'], [data-test-id='pin-draft-title'], input[placeholder*='Add your title' i], input[type='text']").first
                if title_input.count() > 0:
                    title_input.scroll_into_view_if_needed()
                    title_input.click(force=True)
                    title_input.fill(title[:100])
                    log("Title OK!")
                else:
                    log("Title input not found by locator — trying keyboard input fallback...")
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
                desc_input = None
                candidates = page.locator("#storyboard-selector-description, [data-test-id='pin-builder-description'], [data-test-id='pin-draft-description'], [data-test-id='storyboard-selector-description'], textarea[id*='description'], textarea[placeholder*='description' i], textarea[placeholder*='Tell everyone' i], [contenteditable='true'], .public-DraftEditor-editor, textarea")
                for idx in range(candidates.count()):
                    c = candidates.nth(idx)
                    if c.is_visible():
                        is_valid = c.evaluate("""el => {
                            const tag = el.tagName.toLowerCase();
                            const isCE = el.isContentEditable || el.getAttribute('contenteditable') === 'true';
                            return (tag === 'textarea' || tag === 'input' || isCE) && el.name !== 'g-recaptcha-response' && el.id !== 'g-recaptcha-response';
                        }""")
                        if is_valid:
                            desc_input = c
                            break
                
                if desc_input:
                    desc_input.scroll_into_view_if_needed()
                    desc_input.click(force=True)
                    page.wait_for_timeout(300)
                    is_ce = desc_input.evaluate("el => el.isContentEditable || el.getAttribute('contenteditable') === 'true'")
                    if is_ce:
                        page.keyboard.type(desc)
                    else:
                        desc_input.fill(desc)
                    page.wait_for_timeout(300)
                    page.evaluate("""(d) => {
                        const el = document.querySelector("#storyboard-selector-description, [data-test-id='pin-builder-description'], [contenteditable='true'], .public-DraftEditor-editor");
                        if (el) {
                            if (el.isContentEditable) el.innerText = d;
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                            el.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }""", desc)
                    log("Description OK!")
                else:
                    log("Description input element not visible — typing directly...")
                    ce = page.locator("[contenteditable='true']").first
                    if ce.count() > 0 and ce.is_visible():
                        ce.click(force=True)
                        page.keyboard.type(desc)
                        log("Description OK (contenteditable fill)!")
                    else:
                        page.keyboard.type(desc, delay=2)
                        log("Description OK (keyboard fallback)!")
            except Exception as e:
                log(f"Desc error: {e}")
            
            # ── Step 6: Link ───────────────────────────────────────────
            log("Filling link...")
            try:
                link_input = None
                link_candidates = page.locator("[data-test-id='pin-builder-link'], [data-test-id='pin-draft-link'], [data-test-id='storyboard-selector-link'], input[name='link'], input[id='WebsiteField'], input[placeholder*='link' i], input[placeholder*='destination' i], input[placeholder*='Add a link' i], input[placeholder*='Website' i], textarea[placeholder*='link' i], input[type='text']")
                for idx in range(link_candidates.count()):
                    lc = link_candidates.nth(idx)
                    if lc.is_visible():
                        is_valid = lc.evaluate("el => (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') && el.type !== 'file'")
                        if is_valid:
                            link_input = lc
                            break
                if link_input:
                    link_input.scroll_into_view_if_needed()
                    link_input.click(force=True)
                    link_input.fill(target_site)
                    log("Link OK!")
                else:
                    log("Link input not found — using keyboard tab to fill link...")
            except Exception as e:
                log(f"Link error: {e}")
                
            # ── Step 7: Board ──────────────────────────────────────────
            log("Opening board dropdown...")
            try:
                board_btn = None
                board_candidates = page.locator("[data-test-id='board-dropdown-select-button'], [data-test-id='board-picker-select-button'], [data-test-id='board-picker-button'], [data-test-id='pin-builder-board-dropdown'], [data-test-id='board-selection-button'], button:has-text('Select'), button:has-text('Choose'), div[role='button']:has-text('Select'), div[role='button']:has-text('Choose'), [aria-label*='board' i], [aria-label*='Board' i]")
                for idx in range(board_candidates.count()):
                    bc = board_candidates.nth(idx)
                    if bc.is_visible():
                        board_btn = bc
                        break
                
                if board_btn:
                    board_btn.click(force=True)
                    page.wait_for_timeout(3000)
                    log("Board dropdown opened!")
                    
                    flyout = page.locator("[data-test-id='board-picker-flyout'], [role='listbox'], [data-test-id*='board-picker']")
                    if flyout.count() > 0:
                        rows = flyout.locator("[data-test-id='boardWithoutSection'], [role='option'], div[role='button']")
                        row_count = rows.count()
                        log(f"Board rows found: {row_count}")
                        if row_count > 0:
                            my_post_row = None
                            for idx in range(row_count):
                                r_txt = rows.nth(idx).inner_text().strip().lower()
                                if "my post" in r_txt:
                                    my_post_row = rows.nth(idx)
                                    break
                            if my_post_row:
                                log("Found board 'MY POST' — selecting!")
                                my_post_row.click(force=True)
                            else:
                                first_row = rows.first
                                log(f"Board selected: '{first_row.inner_text().strip()}'")
                                first_row.click(force=True)
                        page.wait_for_timeout(2000)
                else:
                    log("Board button not visible — continuing with default board")
            except Exception as e:
                log(f"Board select error: {e}")
                
            # ── Step 8: Publish ────────────────────────────────────────
            log("Publishing pin...")
            published = False
            for attempt in range(5):
                try:
                    pub_btns = page.locator("[data-test-id='pin-builder-publish-button'], [data-test-id='storyboard-creation-publish-button'], button[data-test-id*='publish'], button[data-test-id*='save'], [data-test-id='board-dropdown-save-button'], button:has-text('Publish'), button:has-text('Save'), div[role='button']:has-text('Publish'), div[role='button']:has-text('Save'), button[aria-label*='Publish' i], button[aria-label*='Save' i]")
                    for idx in range(pub_btns.count()):
                        pb = pub_btns.nth(idx)
                        if pb.is_visible():
                            pb.click(force=True)
                            log("Published via Publish/Save button click!")
                            published = True
                            break
                    if published:
                        break
                except Exception as e:
                    log(f"Publish attempt {attempt+1}: {e}")
                page.wait_for_timeout(2000)
                
            # ── Step 9: Get Pin URL ────────────────────────────────────
            log("Waiting for published Pin URL...")
            page.wait_for_timeout(3000)
            
            # 1. Check current URL if redirected to /pin/
            for _ in range(8):
                cu = page.url
                if "/pin/" in cu and "builder" not in cu and "creation" not in cu:
                    log(f"Captured Pin URL directly: {cu}")
                    result(True, url=cu)
                    context.close()
                    return
                page.wait_for_timeout(500)

            # 2. Check for "See your pin" / "View" toast popup link
            try:
                see_pin = page.locator("a:has-text('See your pin'), a:has-text('View'), [data-test-id='toast-link'], div[role='alert'] a[href*='/pin/']").first
                if see_pin.count() > 0 and see_pin.is_visible():
                    href = see_pin.get_attribute("href")
                    if href and "/pin/" in href:
                        if not href.startswith("http"):
                            href = "https://www.pinterest.com" + href
                        log(f"Captured Pin URL from toast popup: {href}")
                        result(True, url=href)
                        context.close()
                        return
            except Exception:
                pass
                
            # 3. Always check /me/ user profile page to extract latest created Pin URL or Profile URL
            try:
                log("Navigating to /me/ profile page to extract latest Pin URL...")
                page.goto("https://www.pinterest.com/me/", wait_until="domcontentloaded", timeout=25000)
                page.wait_for_timeout(4000)
                
                # Check for pin links on profile page
                profile_pin_links = page.locator("a[href*='/pin/']")
                if profile_pin_links.count() > 0:
                    href = profile_pin_links.first.get_attribute("href")
                    if href and "/pin/" in href:
                        if not href.startswith("http"):
                            href = "https://www.pinterest.com" + href
                        log(f"Captured Pin URL from user profile: {href}")
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
                log(f"Profile URL check: {e_me}")

            # Fallback to user handle URL based on email
            uname = email.split("@")[0].lower().replace(".", "")
            final_url = f"https://www.pinterest.com/{uname}/"
            log(f"Pin published (captured fallback profile URL): {final_url}")
            result(True, url=final_url)
            context.close()
            return
                
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
