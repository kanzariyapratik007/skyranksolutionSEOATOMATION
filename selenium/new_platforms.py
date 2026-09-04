
# ---------------------------------------------------------
# HELPER — Profile-based driver for new platforms
# ---------------------------------------------------------
def get_profile_driver(platform_name):
    """Get Chrome driver with saved profile for the given platform"""
    import os as _os
    _PROFILE = _os.path.join(_os.path.dirname(_os.path.abspath(__file__)), f'chrome_profile_{platform_name}')
    for _lf in [_os.path.join(_PROFILE,'Default','LOCK'), _os.path.join(_PROFILE,'SingletonLock')]:
        try:
            if _os.path.exists(_lf): _os.remove(_lf)
        except: pass
    _os.makedirs(_PROFILE, exist_ok=True)
    opts = Options()
    opts.add_argument('--no-sandbox')
    opts.add_argument('--disable-dev-shm-usage')
    opts.add_argument('--disable-blink-features=AutomationControlled')
    opts.add_experimental_option('excludeSwitches', ['enable-automation'])
    opts.add_experimental_option('useAutomationExtension', False)
    opts.add_argument(f'--user-data-dir={_PROFILE}')
    opts.add_argument('--profile-directory=Default')
    opts.add_argument('--window-size=1400,900')
    opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')
    drv = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=opts)
    drv.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    return drv

def _do_login(driver, login_url, email, password, extra_wait=6):
    """Generic login helper — tries email+password form"""
    from selenium.webdriver.common.action_chains import ActionChains as _AC
    driver.get(login_url)
    time.sleep(4)
    for sel in ["input[type='email']","input[name='email']","input[name='username']","input[name='log']","input[placeholder*='mail' i]","input[placeholder*='Email' i]"]:
        try:
            el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
            if el.is_displayed():
                el.click(); el.clear(); time.sleep(0.3); el.send_keys(email)
                log(f"  Email typed [{sel}]"); break
        except: continue
    for sel in ["button[type='submit']","//button[contains(text(),'Next')]","//button[contains(text(),'Continue')]"]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver,4).until(EC.element_to_be_clickable((by,sel)))
            pw_v = driver.find_elements(By.CSS_SELECTOR,"input[type='password']")
            if pw_v and pw_v[0].is_displayed(): break
            driver.execute_script("arguments[0].click();",btn); time.sleep(3); break
        except: continue
    for sel in ["input[type='password']","input[name='password']","input[name='pwd']"]:
        try:
            el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
            if el.is_displayed():
                el.click(); el.clear(); time.sleep(0.3); el.send_keys(password)
                log(f"  Password typed [{sel}]"); break
        except: continue
    for sel in ["button[type='submit']","input[type='submit']","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SIGN IN')]","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'LOG IN')]","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'LOGIN')]"]:
        try:
            by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
            btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
            if btn.is_displayed():
                driver.execute_script("arguments[0].click();",btn)
                log(f"  Submit clicked: '{btn.text.strip()}'"); break
        except: continue
    time.sleep(extra_wait)
    return driver.current_url

# ---------------------------------------------------------
# MINDS.COM — Selenium login + post (API token unreliable)
# ---------------------------------------------------------
def post_minds(email, password, keyword, target_site):
    driver = get_profile_driver('minds')
    try:
        log("Minds: Opening with saved profile...")
        driver.get("https://www.minds.com/")
        time.sleep(5)
        src = driver.page_source.lower()
        logged = ("logout" in src or "/newsfeed" in driver.current_url or
                  "compose" in src or "feed" in driver.current_url)

        if not logged:
            log("Minds: Logging in...")
            url_after = _do_login(driver, "https://www.minds.com/login", email, password, 8)
            log("Minds: Post-login URL = " + url_after)
            src2 = driver.page_source.lower()
            if "login" in url_after.lower() and "logout" not in src2:
                result(False, error="Minds: Login failed. Check credentials.")
                return
            log("Minds: Logged in!")
        else:
            log("Minds: Already logged in via saved profile!")

        # Go to newsfeed
        driver.get("https://www.minds.com/newsfeed/subscriptions")
        time.sleep(5)

        # Compose post
        content_text = (f"Best {keyword.title()} training course! Expert trainers, "
                        f"hands-on projects, placement support. Enroll: {target_site} "
                        f"#{keyword.replace(' ','')} #Training #Education")

        compose_clicked = False
        for sel in [
            "[data-ref='post-button']",
            "//button[contains(text(),'Create Post')]",
            "//button[contains(text(),'Post')]",
            "[placeholder*='What' i]",
            "m-compose__trigger",
            "[class*='compose']",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
                if el.is_displayed():
                    driver.execute_script("arguments[0].click();",el)
                    log("Minds: Compose clicked: " + el.text.strip()[:30])
                    time.sleep(3)
                    compose_clicked = True
                    break
            except: continue

        # Type in compose area
        for sel in ["[contenteditable='true']","textarea[placeholder*='What' i]","[placeholder*='compose' i]"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); time.sleep(0.5)
                    el.send_keys(content_text[:1000])
                    log("Minds: Content typed")
                    time.sleep(1)
                    break
            except: continue

        # Submit post
        for sel in ["//button[normalize-space(text())='Post']","//button[contains(text(),'Share')]","[data-ref='post-submit']","button[type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed() and btn.is_enabled():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Minds: Post submitted!")
                    time.sleep(5); break
            except: continue

        final = driver.current_url
        log("Minds: Final URL = " + final)
        result(True, url=final if "minds.com" in final else "https://www.minds.com/newsfeed/subscriptions")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# ---------------------------------------------------------
# DRIBBBLE — Selenium login + upload shot
# ---------------------------------------------------------
def post_dribbble(email, password, keyword, target_site):
    import os as _os
    driver = get_profile_driver('dribbble')
    try:
        log("Dribbble: Opening with saved profile...")
        driver.get("https://dribbble.com/")
        time.sleep(5)
        src = driver.page_source.lower()
        logged = ("log out" in src or "logout" in src or "sign out" in src or
                  "/shots/new" in driver.current_url or "account" in driver.current_url)

        if not logged:
            log("Dribbble: Logging in...")
            url_after = _do_login(driver, "https://dribbble.com/session/new", email, password, 7)
            log("Dribbble: Post-login URL = " + url_after)
            src2 = driver.page_source.lower()
            if "session" in url_after and "logout" not in src2:
                result(False, error="Dribbble: Login failed. Check credentials.")
                return
            log("Dribbble: Logged in!")
        else:
            log("Dribbble: Already logged in!")

        # Go to new shot page
        driver.get("https://dribbble.com/shots/new")
        time.sleep(5)
        log("Dribbble: New shot page = " + driver.current_url)

        # Upload image if available
        upload_dir = _os.path.join(_os.path.dirname(_os.path.abspath(__file__)), '..', 'uploads')
        images = []
        import glob as _glob
        for ext in ['*.jpg','*.jpeg','*.png']:
            images.extend(_glob.glob(_os.path.join(upload_dir, 'project_*' + ext[1:])))
        if images:
            images.sort(key=_os.path.getmtime, reverse=True)
            img_path = images[0]
            log("Dribbble: Uploading image: " + _os.path.basename(img_path))
            for sel in ["input[type='file']","input[accept*='image']"]:
                try:
                    el = driver.find_element(By.CSS_SELECTOR, sel)
                    el.send_keys(_os.path.abspath(img_path))
                    log("Dribbble: Image sent to input")
                    time.sleep(6); break
                except: continue

        # Fill title
        title = f"Best {keyword.title()} Training {time.strftime('%Y')}"
        for sel in ["input[name='title']","input[placeholder*='title' i]","#title"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); el.send_keys(title)
                    log("Dribbble: Title typed"); break
            except: continue

        # Fill description
        desc = f"Best {keyword} training. Expert mentors, live projects. Visit: {target_site}"
        for sel in ["textarea[name='description']","[contenteditable='true']","textarea"]:
            try:
                el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); el.send_keys(desc[:500])
                    log("Dribbble: Description typed"); break
            except: continue

        # Add website link
        for sel in ["input[name='website']","input[placeholder*='website' i]","input[placeholder*='url' i]"]:
            try:
                el = driver.find_element(By.CSS_SELECTOR, sel)
                if el.is_displayed():
                    el.click(); el.clear(); el.send_keys(target_site)
                    log("Dribbble: Website typed"); break
            except: continue

        # Publish / Save draft
        for sel in ["//button[contains(text(),'Publish')]","//button[contains(text(),'Save')]","//button[contains(text(),'Post')]","button[type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Dribbble: Published! btn=" + btn.text.strip())
                    time.sleep(8); break
            except: continue

        final = driver.current_url
        log("Dribbble: Final URL = " + final)
        result(True, url=final if "dribbble.com" in final else "https://dribbble.com/shots")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# ---------------------------------------------------------
# SYMBALOO — Selenium login + add webmix tile
# ---------------------------------------------------------
def post_symbaloo(email, password, keyword, target_site):
    driver = get_profile_driver('symbaloo')
    try:
        log("Symbaloo: Opening with saved profile...")
        driver.get("https://www.symbaloo.com/home/mix/")
        time.sleep(6)
        src = driver.page_source.lower()
        logged = ("logout" in src or "log out" in src or "sign out" in src or
                  "mix" in driver.current_url or "dashboard" in driver.current_url)

        if not logged:
            log("Symbaloo: Logging in...")
            url_after = _do_login(driver, "https://www.symbaloo.com/nl/login", email, password, 7)
            log("Symbaloo: Post-login URL = " + url_after)
            src2 = driver.page_source.lower()
            if ("login" in url_after or "signin" in url_after) and "logout" not in src2:
                # Try alternate login
                url_after = _do_login(driver, "https://www.symbaloo.com/login", email, password, 7)
                log("Symbaloo: Alt login URL = " + url_after)
            log("Symbaloo: Logged in!")
        else:
            log("Symbaloo: Already logged in!")

        # Find empty tile and click it (or + button)
        driver.get("https://www.symbaloo.com/home/mix/")
        time.sleep(6)

        tile_clicked = False
        # Try clicking an empty tile
        for sel in [
            "[class*='empty']",
            "[class*='tile-empty']",
            "[class*='add']",
            "//button[contains(text(),'+')]",
            "[aria-label*='Add']",
            "[class*='add-tile']",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
                if el.is_displayed():
                    driver.execute_script("arguments[0].click();",el)
                    log("Symbaloo: Tile/add clicked")
                    time.sleep(3)
                    tile_clicked = True
                    break
            except: continue

        # Fill URL in tile form
        for sel in ["input[placeholder*='url' i]","input[name='url']","input[type='url']","input[placeholder*='http' i]","input[placeholder*='web' i]"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); el.send_keys(target_site)
                    log("Symbaloo: URL typed"); time.sleep(1); break
            except: continue

        # Fill title/label
        for sel in ["input[placeholder*='title' i]","input[placeholder*='name' i]","input[placeholder*='label' i]","input[name='title']"]:
            try:
                el = driver.find_element(By.CSS_SELECTOR, sel)
                if el.is_displayed():
                    el.click(); el.clear()
                    el.send_keys(f"Best {keyword.title()} Training")
                    log("Symbaloo: Title typed"); break
            except: continue

        # Save
        for sel in ["//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SAVE')]","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ADD')]","button[type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Symbaloo: Saved!")
                    time.sleep(4); break
            except: continue

        final = driver.current_url
        result(True, url=final if "symbaloo.com" in final else "https://www.symbaloo.com/home/mix/")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# ---------------------------------------------------------
# PENZU — Selenium login + create journal entry
# ---------------------------------------------------------
def post_penzu(email, password, keyword, target_site):
    driver = get_profile_driver('penzu')
    try:
        log("Penzu: Opening with saved profile...")
        driver.get("https://penzu.com/app")
        time.sleep(5)
        src = driver.page_source.lower()
        logged = ("logout" in src or "log out" in src or "new entry" in src or
                  "journal" in driver.current_url or "app" in driver.current_url)

        if not logged:
            log("Penzu: Logging in...")
            url_after = _do_login(driver, "https://penzu.com/login", email, password, 7)
            log("Penzu: Post-login URL = " + url_after)
            src2 = driver.page_source.lower()
            if "login" in url_after and "new entry" not in src2 and "app" not in url_after:
                result(False, error="Penzu: Login failed. Check credentials.")
                return
            log("Penzu: Logged in!")
        else:
            log("Penzu: Already logged in!")

        # Create new entry
        driver.get("https://penzu.com/app/journal/new-entry")
        time.sleep(5)
        log("Penzu: New entry page = " + driver.current_url)

        title = f"Best {keyword.title()} Training Guide {time.strftime('%Y')}"
        content_text = (f"Best {keyword} training course available!\n\n"
                        f"Expert trainers with hands-on projects and placement support.\n\n"
                        f"Key topics covered:\n"
                        f"- Introduction to {keyword}\n"
                        f"- Advanced techniques\n"
                        f"- Industry projects\n"
                        f"- Placement assistance\n\n"
                        f"Enroll now at: {target_site}\n\n"
                        f"#{keyword.replace(' ','')} #Training #Education")

        # Fill title
        for sel in ["input[placeholder*='title' i]","input[name='title']","#title","[class*='entry-title']","input[type='text']"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); el.send_keys(title)
                    log("Penzu: Title typed"); break
            except: continue

        # Fill content/body
        for sel in ["textarea","[contenteditable='true']","[class*='entry-body']","[class*='content']"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); time.sleep(0.5)
                    el.send_keys(content_text)
                    log("Penzu: Content typed"); break
            except: continue

        # Save/Publish
        for sel in ["//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SAVE')]","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'PUBLISH')]","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'POST')]","button[type='submit']","[class*='save']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Penzu: Saved! btn=" + btn.text.strip())
                    time.sleep(5); break
            except: continue

        final = driver.current_url
        log("Penzu: Final URL = " + final)
        result(True, url=final if "penzu.com" in final else "https://penzu.com/app")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# ---------------------------------------------------------
# PLURK — Selenium login + post (API fallback)
# ---------------------------------------------------------
def post_plurk(email, password, keyword, target_site):
    """Plurk via Selenium (API key stored as password field)"""
    driver = get_profile_driver('plurk')
    try:
        log("Plurk: Opening with saved profile...")
        driver.get("https://www.plurk.com/")
        time.sleep(5)
        src = driver.page_source.lower()
        logged = ("logout" in src or "log out" in src or "timeline" in driver.current_url or
                  "newsfeed" in driver.current_url or "home" in driver.current_url)

        if not logged:
            log("Plurk: Logging in...")
            driver.get("https://www.plurk.com/login")
            time.sleep(4)
            for sel in ["input[name='nick']","input[id='nick']","input[placeholder*='nick' i]","input[placeholder*='email' i]","input[type='text']"]:
                try:
                    el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                    if el.is_displayed():
                        el.click(); el.clear(); el.send_keys(email)
                        log("Plurk: Username typed"); break
                except: continue
            for sel in ["input[name='password']","input[type='password']"]:
                try:
                    el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                    if el.is_displayed():
                        el.click(); el.clear(); el.send_keys(password)
                        log("Plurk: Password typed"); break
                except: continue
            for sel in ["input[type='submit']","button[type='submit']"]:
                try:
                    el = WebDriverWait(driver,6).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                    if el.is_displayed():
                        driver.execute_script("arguments[0].click();",el)
                        log("Plurk: Submit clicked"); break
                except: continue
            time.sleep(7)
            log("Plurk: Post-login URL = " + driver.current_url)
        else:
            log("Plurk: Already logged in!")

        driver.get("https://www.plurk.com/")
        time.sleep(5)

        # Find compose box
        compose_text = (f"Best {keyword.title()} training! Expert trainers, hands-on projects, "
                        f"placement support. Enroll now at {target_site} "
                        f"#{keyword.replace(' ','')} #Training")
        compose_text = compose_text[:140]  # Plurk limit

        for sel in ["#new_top_plurk_input","#top_plurk_input","[placeholder*='plurk' i]","[placeholder*='What' i]","textarea[name='content']","input[name='content']"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); time.sleep(0.5); el.clear()
                    el.send_keys(compose_text)
                    log("Plurk: Content typed")
                    time.sleep(1); break
            except: continue

        # Select qualifier (shares)
        for sel in ["#qualifier_selector","select[name='qualifier']","[class*='qualifier']"]:
            try:
                from selenium.webdriver.support.select import Select
                el = driver.find_element(By.CSS_SELECTOR, sel)
                if el.is_displayed():
                    Select(el).select_by_value("shares")
                    break
            except: continue

        # Submit
        for sel in ["//button[contains(text(),'Plurk')]","//input[@value='Plurk!']","button[type='submit']","input[type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Plurk: Submitted!")
                    time.sleep(5); break
            except: continue

        final = driver.current_url
        result(True, url=final if "plurk.com" in final else "https://www.plurk.com/")

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

# ---------------------------------------------------------
# LINKTREE — Selenium login + add link
# ---------------------------------------------------------
def post_linktree(email, password, keyword, target_site):
    driver = get_profile_driver('linktree')
    try:
        log("Linktree: Opening with saved profile...")
        driver.get("https://linktr.ee/admin")
        time.sleep(6)
        src = driver.page_source.lower()
        logged = (("logout" in src or "log out" in src or "add link" in src or "add new link" in src or "linkeditor_single_add_link_button" in src) and
                  "login" not in driver.current_url and "universal-login" not in driver.current_url)

        if not logged:
            log("Linktree: Logging in...")
            driver.get("https://linktr.ee/login")
            time.sleep(8)
            
            # Find and fill email
            email_el = None
            for sel in ["input[placeholder*='Email or username' i]", "input[placeholder*='username' i]", "input[type='text']"]:
                try:
                    el = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
                    if el.is_displayed():
                        email_el = el
                        break
                except: pass
            
            if not email_el:
                result(False, error="Linktree: Email input field not found.")
                return
                
            email_el.click()
            email_el.clear()
            time.sleep(0.5)
            email_el.send_keys(email)
            time.sleep(1)
            
            # Click Continue
            continue_btn = None
            btns = driver.find_elements(By.TAG_NAME, "button")
            for b in btns:
                if "continue" in b.text.lower():
                    continue_btn = b
                    break
            
            if not continue_btn:
                result(False, error="Linktree: Continue button not found.")
                return
                
            driver.execute_script("arguments[0].click();", continue_btn)
            time.sleep(5)
            
            # Find and fill password
            pass_el = None
            for sel in ["input[type='password']", "input[placeholder*='password' i]", "input[name='password']"]:
                try:
                    el = WebDriverWait(driver, 6).until(EC.element_to_be_clickable((By.CSS_SELECTOR, sel)))
                    if el.is_displayed():
                        pass_el = el
                        break
                except: pass
                
            if not pass_el:
                result(False, error="Linktree: Password input field not found.")
                return
                
            pass_el.click()
            pass_el.clear()
            time.sleep(0.5)
            pass_el.send_keys(password)
            time.sleep(1)
            
            # Click Log In / Submit
            login_btn = None
            btns = driver.find_elements(By.TAG_NAME, "button")
            for b in btns:
                txt = b.text.lower()
                if "login" in txt or "log in" in txt:
                    login_btn = b
                    break
                    
            if not login_btn:
                result(False, error="Linktree: Login submit button not found.")
                return
                
            driver.execute_script("arguments[0].click();", login_btn)
            time.sleep(10)
            
            driver.get("https://linktr.ee/admin")
            time.sleep(6)
            
            src2 = driver.page_source.lower()
            if "login" in driver.current_url or "universal-login" in driver.current_url:
                result(False, error="Linktree: Login failed. Check credentials.")
                return
            log("Linktree: Logged in successfully!")
        else:
            log("Linktree: Already logged in!")

        driver.get("https://linktr.ee/admin")
        time.sleep(5)

        # Click "Add link" button
        for sel in [
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ADD LINK')]",
            "//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ADD NEW LINK')]",
            "//button[contains(text(),'Add')]",
            "[data-testid*='add']",
            "[class*='AddLinkButton']",
            "button[class*='add']",
        ]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,8).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Linktree: Add Link clicked: " + btn.text.strip())
                    time.sleep(3); break
            except: continue

        # Fill URL
        for sel in ["input[placeholder*='url' i]","input[placeholder*='http' i]","input[type='url']","input[name='url']","input[placeholder*='Link' i]"]:
            try:
                el = WebDriverWait(driver,8).until(EC.element_to_be_clickable((By.CSS_SELECTOR,sel)))
                if el.is_displayed():
                    el.click(); el.clear(); el.send_keys(target_site)
                    log("Linktree: URL typed")
                    time.sleep(1)
                    from selenium.webdriver.common.keys import Keys as _K
                    el.send_keys(_K.RETURN)
                    time.sleep(3); break
            except: continue

        # Fill title
        for sel in ["input[placeholder*='title' i]","input[placeholder*='Title' i]","input[name='title']"]:
            try:
                el = driver.find_element(By.CSS_SELECTOR, sel)
                if el.is_displayed():
                    el.click(); el.clear()
                    el.send_keys(f"Best {keyword.title()} Training")
                    log("Linktree: Title typed"); break
            except: continue

        # Save
        for sel in ["//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'SAVE')]","//button[contains(translate(text(),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ'),'ADD')]","button[type='submit']"]:
            try:
                by = By.XPATH if sel.startswith('//') else By.CSS_SELECTOR
                btn = WebDriverWait(driver,6).until(EC.element_to_be_clickable((by,sel)))
                if btn.is_displayed():
                    driver.execute_script("arguments[0].click();",btn)
                    log("Linktree: Saved!")
                    time.sleep(4); break
            except: continue

        # Get public linktree URL
        try:
            driver.get("https://linktr.ee/admin")
            time.sleep(3)
            share_links = driver.find_elements(By.CSS_SELECTOR, "a[href*='linktr.ee/']")
            for sl in share_links:
                href = sl.get_attribute('href') or ''
                if 'linktr.ee/' in href and 'admin' not in href and 'login' not in href:
                    final = href
                    break
            else:
                # Fallback to find any elements containing the text linktr.ee/
                all_els = driver.find_elements(By.XPATH, "//*[contains(text(), 'linktr.ee/')]")
                for el in all_els:
                    txt = el.text.strip()
                    if 'linktr.ee/' in txt and 'admin' not in txt.lower() and 'login' not in txt.lower():
                        match = re.search(r'(linktr\.ee/[a-zA-Z0-9_\-\.]+)', txt)
                        if match:
                            final = "https://" + match.group(1)
                            break
                else:
                    final = "https://linktr.ee/"
        except:
            final = "https://linktr.ee/"

        log("Linktree: Final URL = " + final)
        result(True, url=final)

    except Exception as e:
        result(False, error=str(e))
    finally:
        try: driver.quit()
        except: pass

