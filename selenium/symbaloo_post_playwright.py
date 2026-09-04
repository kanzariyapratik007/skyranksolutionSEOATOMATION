#!/usr/bin/env python3
"""
Symbaloo Auto-Post Tile via Playwright
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

def close_consent_modal(page):
    try:
        # Remove Quantcast CMP container overlays and consent iframes directly via JS
        page.evaluate("""
            document.querySelectorAll('iframe[id*="sp_message"], iframe[title*="Consent"], div[id*="sp_message"], #qc-cmp2-container, .qc-cmp2-container, .qc-cmp-cleanslate').forEach(el => el.remove());
        """)
        
        selectors = [
            "button[mode='primary']",
            "button:has-text('AGREE')",
            "button:has-text('Agree')",
            "button:has-text('Accept')",
            "button:has-text('I agree')",
            "button:has-text('Consent')",
            "button[class*='agree']",
            "button[class*='consent']",
            "//button[contains(text(),'Agree')]",
        ]
        for sel in selectors:
            btn = page.locator(sel).first
            if btn.count() > 0 and btn.is_visible():
                try:
                    btn.evaluate("el => el.click()")
                    log(f"Symbaloo: Cookie Consent Accepted via {sel}.")
                    page.wait_for_timeout(1500)
                    return True
                except Exception: pass
    except Exception as e:
        log(f"Symbaloo: Consent check error: {e}")
    return False

def close_adblock_modal(page):
    try:
        page.evaluate("""
            document.querySelectorAll('embedded-app-container[key="adBlockInformationDialog"], div[key="adBlockInformationDialog"], [aria-labelledby="dialogTitle"]').forEach(el => el.remove());
        """)
    except Exception: pass
    try:
        body_text = page.locator("body").inner_text()
        if "ad-block" not in body_text.lower() and "adblock" not in body_text.lower():
            return False
            
        log("Symbaloo: Adblock warning detected, closing...")
        selectors = [
            "button[aria-label*='Close' i]",
            "button[class*='close' i]",
            "[class*='close-btn' i]",
            "[class*='CloseBtn']",
            "span[class*='close' i]"
        ]
        for sel in selectors:
            btns = page.locator(sel)
            for idx in range(btns.count()):
                btn = btns.nth(idx)
                if btn.is_visible():
                    try:
                        btn.evaluate("el => el.click()")
                    except Exception:
                        btn.click(force=True)
                    log(f"Symbaloo: Closed adblock popup via {sel}")
                    page.wait_for_timeout(1500)
                    return True
        # Try escape key
        page.keyboard.press("Escape")
        log("Symbaloo: Pressed Escape to close popup")
        page.wait_for_timeout(1000)
        return True
    except Exception as e:
        log(f"Symbaloo: Adblock close error: {e}")
    return False

def symbaloo_post(email, password, keyword, target_url, custom_mix_url="", ai_description=""):
    log(f"Starting Playwright Symbaloo post for: {email}")
    import hashlib
    email_hash = hashlib.md5(email.lower().encode('utf-8')).hexdigest()
    profile_dir = os.path.join(script_dir, f'chrome_profile_symbaloo_{email_hash}')
    
    # Clean locks
    if os.path.exists(profile_dir):
        for lf in [os.path.join(profile_dir,'Default','LOCK'), os.path.join(profile_dir,'SingletonLock')]:
            try:
                if os.path.exists(lf): os.remove(lf)
            except Exception: pass

    from playwright.sync_api import sync_playwright
    
    with sync_playwright() as p:
        try:
            context = p.chromium.launch_persistent_context(
                profile_dir,
                headless=True,
                viewport={"width": 1280, "height": 800},
                args=[
                    "--no-sandbox",
                    "--disable-setuid-sandbox",
                    "--disable-dev-shm-usage",
                    "--disable-gpu",
                    "--disable-software-rasterizer",
                    "--disable-blink-features=AutomationControlled"
                ],
                user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
            )
            
            page = context.pages[0] if context.pages else context.new_page()
            
            # Navigate to login
            log("Symbaloo: Navigating to login page...")
            try:
                page.goto("https://www.symbaloo.com/login", wait_until="domcontentloaded", timeout=60000)
            except Exception as ge:
                log(f"Symbaloo: login page goto warning ({ge}), continuing...")
            page.wait_for_timeout(4000)
            
            close_consent_modal(page)
            
            if "login" in page.url.lower():
                log("Symbaloo: Logging in...")
                
                # Email input
                email_input = page.locator("input[type='email'], input[name='email'], #email").first
                email_input.wait_for(state="visible", timeout=15000)
                email_input.click()
                email_input.fill(email)
                
                # Password input
                pass_input = page.locator("input[type='password'], #password").first
                pass_input.click()
                pass_input.fill(password)
                
                # Submit - try multiple button selectors or press Enter
                submit_btn = page.locator("button[type='submit'], #login-button, button:has-text('Log in'), button:has-text('Sign in'), button:has-text('Inloggen')").first
                if submit_btn.count() > 0 and submit_btn.is_visible():
                    submit_btn.click()
                else:
                    pass_input.press("Enter")
                
                page.wait_for_timeout(8000)
                close_consent_modal(page)
            else:
                log("Symbaloo: Already logged in!")
                
            # Go to target mix URL
            custom_mix_clean = custom_mix_url.strip() if custom_mix_url else ""
            if custom_mix_clean and not custom_mix_clean.startswith("http"):
                custom_mix_clean = "https://" + custom_mix_clean.lstrip("/")

            target_mix = custom_mix_clean if (custom_mix_clean and "symbaloo.com" in custom_mix_clean) else "https://www.symbaloo.com/"
            log(f"Symbaloo: Navigating to custom target mix = {target_mix}")
            try:
                page.goto(target_mix, wait_until="domcontentloaded", timeout=60000)
            except Exception as ge:
                log(f"Symbaloo: mix page goto warning ({ge}), continuing...")
            page.wait_for_timeout(6000)
            
            close_consent_modal(page)
            close_adblock_modal(page)
            
            log(f"Symbaloo: Custom Mix loaded successfully = {page.url}")
            page.keyboard.press("Escape")
            page.wait_for_timeout(500)
            
            # Check if webmix is read-only public mix and needs to be added to user account
            copy_btn = page.locator("button:has-text('Add this Webmix'), button:has-text('Add Webmix'), button:has-text('Copy webmix')").first
            if copy_btn.count() > 0 and copy_btn.is_visible():
                try:
                    copy_btn.click(force=True)
                    log("Symbaloo: Clicked 'Add this Webmix' button to make it editable!")
                    page.wait_for_timeout(3000)
                except Exception as ce:
                    log(f"Symbaloo: Copy webmix click error: {ce}")

            # Find empty cells
            cells = page.locator("[id^='gridEmptyCell'], div[class*='emptyCell'], div[class*='empty-cell']")
            cell_count = cells.count()
            log(f"Symbaloo: Empty cells = {cell_count}")
            
            if cell_count == 0:
                log("Symbaloo: Webmix is full — trying to create a new Webmix or click Add Webmix...")
                try:
                    add_mix_btn = page.locator("button:has-text('+'), [class*='add-mix'], button:has-text('Add a Webmix'), button:has-text('Add Webmix'), a:has-text('Add Webmix')").first
                    if add_mix_btn.count() > 0 and add_mix_btn.is_visible():
                        add_mix_btn.click()
                        page.wait_for_timeout(2000)
                        mix_name_inp = page.locator("input[placeholder*='Name' i], input[placeholder*='name' i], input[type='text']").first
                        if mix_name_inp.count() > 0 and mix_name_inp.is_visible():
                            mix_name_inp.fill(f"SEO Links {int(time.time())}")
                            page.keyboard.press("Enter")
                            page.wait_for_timeout(4000)
                            cells = page.locator("[id^='gridEmptyCell'], div[class*='emptyCell'], div[class*='empty-cell']")
                            cell_count = cells.count()
                except Exception as e_mix:
                    log(f"Symbaloo: Create Webmix exception: {e_mix}")
            
            if cell_count == 0:
                result(False, error="Symbaloo: No empty cells in this mix and unable to create new webmix")
                context.close()
                return
                
            # Try first few cells to open sidebar
            tile_input = None
            max_cells_to_try = min(cell_count, 10)
            
            for idx in range(max_cells_to_try):
                page.keyboard.press("Escape")
                page.wait_for_timeout(300)
                close_consent_modal(page)
                close_adblock_modal(page)
                
                cell = cells.nth(idx)
                log(f"Symbaloo: Trying empty cell #{idx+1}...")
                
                try:
                    # Direct JS click on empty cell opens Add Tile sidebar
                    cell.evaluate("el => el.click()")
                    page.wait_for_timeout(1500)
                except Exception as e:
                    log(f"Cell JS click exception: {e}")

                # Fallback force click
                try:
                    search_inp_check = page.locator("#tileSearchInput, input[id*='tileSearchInput']").first
                    if search_inp_check.count() == 0 or not search_inp_check.is_visible():
                        cell.click(force=True)
                        page.wait_for_timeout(1500)
                except Exception: pass
                    
                close_adblock_modal(page)
                
                # Check for tile search input (exclude top header searchBarInput)
                for sel in [
                    "#tileSearchInput",
                    "input[id*='tileSearchInput']",
                    "input[name='url']",
                    "input[name*='tile']",
                    "[data-test-id='tile-url-input']",
                    "input[placeholder*='URL' i]",
                    "input[placeholder*='url' i]",
                    "input[placeholder*='website' i]",
                    "input[placeholder*='address' i]",
                    "input[placeholder*='search query' i]",
                    "input[placeholder*='Enter a URL' i]",
                    "input[placeholder*='Add' i]",
                    "div[class*='sidebar'] input",
                    "div[class*='drawer'] input",
                    "div[class*='panel'] input",
                    "input[type='search']",
                    "input[type='text']",
                ]:
                    try:
                        inps = page.locator(sel)
                        for i_idx in range(inps.count()):
                            inp = inps.nth(i_idx)
                            if inp.is_visible():
                                inp_id = inp.get_attribute("id") or ""
                                if inp_id == "searchBarInput":
                                    continue
                                tile_input = inp
                                log(f"Symbaloo: Found tileSearchInput [{sel} (id={inp_id})] on cell #{idx+1}!")
                                break
                        if tile_input:
                            break
                    except Exception:
                        continue
                        
                if tile_input:
                    break

            # Fallback: Try explicit Add Tile buttons if cells click didn't open sidebar
            if not tile_input:
                log("Symbaloo: Cell clicks did not reveal tileSearchInput. Trying fallback Add Tile buttons...")
                for btn_sel in [
                    "button:has-text('Add a tile')",
                    "button:has-text('Add tile')",
                    "button:has-text('Create a tile')",
                    "a:has-text('Add a tile')",
                    "[class*='add-tile']",
                    "[class*='addTile']",
                    "[aria-label*='Add tile' i]",
                    "[data-ue-action*='ADD' i]",
                    "button[id*='add-tile']",
                    "button[class*='add']",
                    "div:has-text('Add a tile')"
                ]:
                    try:
                        add_btn = page.locator(btn_sel).first
                        if add_btn.count() > 0 and add_btn.is_visible():
                            add_btn.click(force=True)
                            log(f"Symbaloo: Clicked fallback Add Tile button [{btn_sel}]")
                            page.wait_for_timeout(2000)
                            for sel in [
                                "#tileSearchInput",
                                "input[id*='tileSearchInput']",
                                "input[placeholder*='URL' i]",
                                "input[placeholder*='url' i]",
                                "input[placeholder*='search' i]"
                            ]:
                                inp = page.locator(sel).first
                                if inp.count() > 0 and inp.is_visible():
                                    inp_id = inp.get_attribute("id") or ""
                                    if inp_id == "searchBarInput":
                                        continue
                                    tile_input = inp
                                    log(f"Symbaloo: Found tileSearchInput [{sel}] via fallback button!")
                                    break
                            if tile_input:
                                break
                    except Exception as e:
                        log(f"Fallback button error ({btn_sel}): {e}")
                        continue
                    
            if not tile_input:
                page.screenshot(path=os.path.join(os.path.dirname(script_dir), 'uploads', 'symbaloo_error.png'))
                result(False, error="Symbaloo: tileSearchInput not found after trying multiple empty cells and fallback buttons")
                context.close()
                return
                
            # Type URL
            try:
                tile_input.click(force=True)
            except: pass
            tile_input.fill(target_url)
            log(f"Symbaloo: URL typed = {target_url}")
            page.wait_for_timeout(1000)
            
            # Press enter
            tile_input.press("Enter")
            log("Symbaloo: Enter pressed on input")
            page.wait_for_timeout(6000)
            
            page.screenshot(path=os.path.join(os.path.dirname(script_dir), 'uploads', 'symbaloo_tile_added.png'))
            page.wait_for_timeout(6000)
            
            # Try to click search result item
            added = False
            for sel in [
                "[class*='result']", "[class*='searchResult']",
                "[class*='suggestion']", "[class*='tileResult']",
            ]:
                try:
                    els = page.locator(sel)
                    for i in range(els.count()):
                        el = els.nth(i)
                        if el.is_visible():
                            el.click()
                            log(f"Symbaloo: Clicked result [{sel}]")
                            page.wait_for_timeout(3000)
                            added = True
                            break
                    if added: break
                except:
                    continue
                    
            if not added:
                log("Symbaloo: No result button — tile auto-added on Enter")
                
            # Look for "Edit Tile"
            page.wait_for_timeout(2000)
            close_adblock_modal(page)
            edit_tile_btn = page.locator("button:has-text('Edit Tile')").first
            if edit_tile_btn.count() > 0 and edit_tile_btn.is_visible():
                try:
                    edit_tile_btn.evaluate("el => el.click()")
                except Exception:
                    edit_tile_btn.click(force=True)
                log("Symbaloo: Edit Tile clicked")
                page.wait_for_timeout(4000)
                
                # Fill URL input
                url_field = page.locator("input[name='url']").first
                if url_field.count() > 0 and url_field.is_visible():
                    url_field.click()
                    url_field.fill(target_url)
                    log("Symbaloo: URL = " + target_url)
                    
                # Fill Name input
                tile_title = "Best " + keyword.title() + " Training"
                name_field = page.locator("input[name='name']").first
                if name_field.count() > 0 and name_field.is_visible():
                    name_field.click()
                    name_field.fill(tile_title)
                    log("Symbaloo: Name = " + tile_title)
                    
                # Fill Description textarea
                if ai_description:
                    tile_desc = ai_description
                else:
                    tile_desc = ("Best " + keyword + " training at Learnmore Technologies. "
                                 "Expert trainers, live projects, placement support. "
                                 "Enroll: " + target_url)
                desc_field = page.locator("textarea").first
                if desc_field.count() > 0 and desc_field.is_visible():
                    desc_field.click()
                    desc_field.fill(tile_desc[:300])
                    log("Symbaloo: Description filled")
                    
            # Click "Finish editing Webmix"
            page.wait_for_timeout(2000)
            close_adblock_modal(page)
            finish_btn = page.locator("button:has-text('Finish'), button:has-text('finish'), button:has-text('Save')").first
            if finish_btn.count() > 0 and finish_btn.is_visible():
                try:
                    finish_btn.evaluate("el => el.click()")
                except Exception:
                    finish_btn.click(force=True)
                log("Symbaloo: Clicked Finish button")
                page.wait_for_timeout(4000)
                
            page.screenshot(path=os.path.join(os.path.dirname(script_dir), 'uploads', 'symbaloo_tile_final.png'))
            log(f"Symbaloo: Final URL = {page.url}")
            result(True, url=page.url)
            context.close()
            
        except Exception as e:
            try:
                if 'page' in locals():
                    page.screenshot(path=os.path.join(os.path.dirname(script_dir), 'uploads', 'symbaloo_error.png'))
                    log("Saved exception error screenshot to symbaloo_error.png")
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
        result(False, error="Usage: symbaloo_post_playwright.py <email> <password> <keyword> <target_site> [custom_mix_url] [ai_description]")
        sys.exit(1)
    symbaloo_post(
        sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4],
        sys.argv[5] if len(sys.argv) > 5 else "",
        sys.argv[6] if len(sys.argv) > 6 else "",
    )
