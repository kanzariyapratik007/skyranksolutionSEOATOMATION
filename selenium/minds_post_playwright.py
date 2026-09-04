#!/usr/bin/env python3
"""
Minds.com Auto-Post via Playwright
Migrated from Selenium for enhanced stability and auto-waiting.
"""
import sys, json, time, os, re, hashlib
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

def minds_post(email, password, keyword, target_url, ai_title="", ai_content=""):
    email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest() if email else 'default'
    profile_dir = os.path.join(script_dir, f'chrome_profile_minds_{email_hash}_{sys_user}')
    
    # Remove locks
    for lock in ['SingletonLock', 'LOCK']:
        lock_path = os.path.join(profile_dir, lock)
        if os.path.exists(lock_path):
            try:
                os.remove(lock_path)
            except:
                pass

    from playwright.sync_api import sync_playwright

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
            
            log("Minds: Opening newsfeed directly using saved session...")
            page.goto("https://www.minds.com/newsfeed/subscriptions", wait_until="domcontentloaded", timeout=60000)
            page.wait_for_timeout(4000)
            
            src = page.content().lower()
            logged = ("login" not in page.url.lower() and ("logout" in src or "newsfeed" in page.url or "feed" in page.url or "channel" in page.url))

            if not logged:
                log("Minds: Session expired or not logged in — logging in...")
                page.goto("https://www.minds.com/login", wait_until="domcontentloaded", timeout=60000)
                page.wait_for_timeout(3000)

                # Fill credentials
                page.locator("input#username").wait_for(state="visible", timeout=20000)
                page.locator("input#username").fill(email)
                log("Minds: Username typed")

                page.locator("input#password").fill(password)
                log("Minds: Password typed")
                
                # Click Login
                login_btn = page.locator("button.m-button--blue, button.m-button--solid, button[type='submit']").first
                login_btn.click()
                log("Minds: Login button clicked")

                page.wait_for_timeout(10000)
                
                src2 = page.content().lower()
                if "login" in page.url.lower() and "logout" not in src2:
                    # Retry navigating to feed to check if auth cookies were set
                    page.goto("https://www.minds.com/newsfeed/subscriptions", timeout=60000)
                    page.wait_for_timeout(6000)
                    src2 = page.content().lower()
                    if "login" in page.url.lower() and "logout" not in src2:
                        result(False, error="Minds: Login failed. Please verify credentials or check account status.")
                        context.close()
                        return
                log("Minds: Logged in successfully!")
            else:
                log("Minds: Already logged in!")

            # Go to newsfeed/subscriptions
            page.goto("https://www.minds.com/newsfeed/subscriptions", timeout=60000)
            page.wait_for_timeout(6000)
            
            content_text = ai_content if ai_content else (
                f"🎓 {keyword.title()} — Complete Career Guide\n\n"
                f"Looking to master {keyword}? Here's everything you need to know.\n\n"
                f"✅ Why Learn {keyword}?\n"
                f"- High salary package\n"
                f"- Huge job demand across the industry\n"
                f"- Industry-recognised certification\n"
                f"- Placement support included\n"
                f"- Hands-on live projects\n\n"
                f"📚 What You'll Learn:\n"
                f"From core fundamentals to advanced real-world applications, cover everything you need to become job-ready.\n\n"
                f"🔗 Enroll now: {target_url}"
            )
            link_suffix = f"\n\n🔗 Enroll now: {target_url}"
            content_text = content_text[:900] + link_suffix
            
            # Dismiss any blocking modal dialogs
            modal_close_selectors = [
                "ngb-modal-window button[aria-label='Close']",
                "ngb-modal-window .close",
                ".modal button.btn-close",
                ".modal .close"
            ]
            for selector in modal_close_selectors:
                try:
                    locator = page.locator(selector)
                    if locator.count() > 0 and locator.first.is_visible():
                        locator.first.click()
                        log("Minds: Modal dismissed")
                        page.wait_for_timeout(2000)
                        break
                except:
                    pass

            # Press Escape just in case
            page.keyboard.press("Escape")
            page.wait_for_timeout(1000)

            # Wait and fill compose textarea
            compose_selectors = [
                "textarea[data-ref='composer-textarea']",
                "textarea.m-composerTextarea__message",
                "textarea[placeholder*='mind' i]",
                "textarea[placeholder*='Speak' i]",
                "textarea"
            ]
            
            filled = False
            for selector in compose_selectors:
                try:
                    locator = page.locator(selector).first
                    if locator.count() > 0:
                        log(f"Minds: Found composer element for selector: {selector}")
                        # Force click to expand composer
                        locator.click(force=True)
                        page.wait_for_timeout(1000)
                        
                        # Set value using prototype descriptor override
                        success = page.evaluate("""
                            ([sel, val]) => {
                                var el = document.querySelector(sel);
                                if (!el) return false;
                                el.focus();
                                var setter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value') || 
                                             Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
                                if (setter && setter.set) {
                                    setter.set.call(el, val);
                                } else {
                                    el.value = val;
                                }
                                el.dispatchEvent(new Event('input', {bubbles:true}));
                                el.dispatchEvent(new Event('change', {bubbles:true}));
                                return true;
                            }
                        """, [selector, content_text])
                        
                        if success:
                            log(f"Minds: Content injected via JS into: {selector}")
                            filled = True
                            break
                except Exception as ex:
                    log(f"Minds: Fill exception on {selector}: {ex}")
                    pass

            if not filled:
                raise Exception("Minds: Composer textarea not found or not interactable")

            page.wait_for_timeout(2000)

            # Click Publish/Post button
            posted = False
            post_btn_selectors = [
                "button:has-text('Post')",
                "button:has-text('post')",
                ".m-composerToolbar__action button",
                ".m-composerToolbar__action--post button",
                "button.m-button--blue.m-button--small"
            ]
            for btn_sel in post_btn_selectors:
                try:
                    btn = page.locator(btn_sel).first
                    if btn.count() > 0:
                        btn.click(force=True)
                        log(f"Minds: Clicked post button via selector: {btn_sel}")
                        posted = True
                        page.wait_for_timeout(6000)
                        break
                except:
                    pass
            
            if not posted:
                raise Exception("Minds: Post button not found or not clickable")

            profile_url = f"https://www.minds.com/{email.split('@')[0]}"
            result(True, url=profile_url)
            context.close()

        except Exception as e:
            try:
                if 'page' in locals():
                    page.screenshot(path=os.path.join(os.path.dirname(script_dir), 'uploads', 'minds_error.png'))
                    log("Saved exception error screenshot to minds_error.png")
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
        result(False, error="Usage: minds_post_playwright.py <email> <password> <keyword> <target_url> [ai_title] [ai_content]")
        sys.exit(1)
    
    minds_post(
        sys.argv[1],
        sys.argv[2],
        sys.argv[3],
        sys.argv[4],
        sys.argv[5] if len(sys.argv) > 5 else "",
        sys.argv[6] if len(sys.argv) > 6 else ""
    )
