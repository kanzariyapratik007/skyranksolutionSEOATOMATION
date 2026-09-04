#!/usr/bin/env python3
"""
Google Search Console Automated Setup & Verification via Selenium
Uses the user's master Google account profile to add site, extracts HTML verification meta tag,
logs into client's WordPress site to insert the tag, and clicks Verify in GSC.
"""
import sys, json, time, os, re, base64
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from webdriver_manager.chrome import ChromeDriverManager

def log(msg):
    print(json.dumps({"log": msg}), flush=True)

def result(success, url='', error=''):
    print(json.dumps({"success": success, "url": url, "error": error}), flush=True)

def get_driver(profile_name, headless=False):
    opts = Options()
    if headless or sys.platform != "win32":
        opts.add_argument('--headless=new')
        opts.add_argument('--disable-gpu')
        opts.add_argument('--disable-software-rasterizer')
    opts.add_argument('--no-sandbox')
    opts.add_argument('--disable-dev-shm-usage')
    opts.add_argument('--disable-blink-features=AutomationControlled')
    opts.add_experimental_option('excludeSwitches', ['enable-automation'])
    opts.add_experimental_option('useAutomationExtension', False)
    opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')
    opts.add_argument('--window-size=1400,900')
    opts.add_argument('--start-maximized')
    
    # Profile dir
    if sys.platform != "win32":
        import getpass
        sys_user = getpass.getuser().lower()
        profile_dir = os.path.join('/tmp', f"{profile_name}_{sys_user}")
    else:
        profile_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), profile_name)
    opts.add_argument(f'--user-data-dir={profile_dir}')
    
    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    try:
        driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
            "source": "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
        })
    except Exception as e:
        pass
    return driver

def main():
    if len(sys.argv) < 6:
        result(False, error="Usage: gsc_verify.py [project_id] [client_site] [wp_admin_url] [wp_user] [wp_pass_base64]")
        return
        
    project_id   = sys.argv[1]
    client_site  = sys.argv[2]
    wp_admin_url = sys.argv[3]
    wp_user      = sys.argv[4]
    try:
        wp_pass = base64.b64decode(sys.argv[5]).decode('utf-8')
    except Exception:
        wp_pass = sys.argv[5]

    log(f"Starting GSC Setup & Verification for {client_site}...")
    
    gsc_driver = None
    wp_driver = None
    try:
        # Step 1: Open Google Search Console with Master Google Account Profile
        # We run non-headless so user can log in to Google if needed
        gsc_driver = get_driver('chrome_profile_gsc', headless=False)
        gsc_driver.get("https://search.google.com/search-console/welcome")
        time.sleep(4)
        
        # Check if login is needed
        if "signin" in gsc_driver.current_url or "login" in gsc_driver.current_url:
            log("ACTION REQUIRED: Please log in to your Google Account in the opened Chrome window. Waiting 60 seconds...")
            # Wait up to 60s for user to log in
            logged_in = False
            for i in range(12):
                time.sleep(5)
                if "signin" not in gsc_driver.current_url and "login" not in gsc_driver.current_url:
                    logged_in = True
                    break
            if not logged_in:
                result(False, error="Google login timeout. Please login to your Google account first.")
                return
                
        log("Accessing Search Console Dashboard...")
        gsc_driver.get("https://search.google.com/search-console/welcome")
        time.sleep(3)
        
        # Step 2: Add Site Property
        wait = WebDriverWait(gsc_driver, 15)
        # Find URL prefix input
        try:
            # GSC URL prefix input is typically the second input or can be found by text type
            prefix_input = wait.until(EC.presence_of_element_located((By.XPATH, "//input[@aria-label='URL prefix' or contains(@placeholder, 'https://')]")))
            prefix_input.clear()
            prefix_input.send_keys(client_site)
            log("Entered URL prefix. Clicking Continue...")
            
            # Click the continue button inside the URL prefix section
            continue_btn = gsc_driver.find_element(By.XPATH, "//input[@aria-label='URL prefix']/ancestor::div[1]//button[contains(., 'Continue') or @type='submit' or contains(., 'ADD')]")
            continue_btn.click()
        except Exception:
            # Fallback selectors
            try:
                inputs = gsc_driver.find_elements(By.TAG_NAME, "input")
                inputs[1].clear()
                inputs[1].send_keys(client_site)
                buttons = gsc_driver.find_elements(By.TAG_NAME, "button")
                for btn in buttons:
                    if "continue" in btn.text.lower() or "add" in btn.text.lower():
                        btn.click()
                        break
            except Exception as e:
                result(False, error=f"Could not locate GSC input elements: {str(e)}")
                return
                
        time.sleep(5)
        
        # Step 3: Extract verification meta tag
        verification_code = None
        log("Checking GSC verification state...")
        page_html = gsc_driver.page_source
        
        # If already verified
        if "ownership auto-verified" in page_html.lower() or "auto-verified" in page_html.lower() or "property added" in page_html.lower():
            log("Property is already verified in GSC!")
            result(True, url=gsc_driver.current_url)
            return
            
        # Look for HTML tag verification code
        # Search for code box or regex match
        match = re.search(r'google-site-verification"\s+content="([^"]+)"', page_html)
        if not match:
            # Try expanding the HTML tag method in the modal
            try:
                html_tag_header = wait.until(EC.presence_of_element_located((By.XPATH, "//*[contains(text(), 'HTML tag') or contains(text(), 'meta tag')]")))
                html_tag_header.click()
                time.sleep(2)
                page_html = gsc_driver.page_source
                match = re.search(r'google-site-verification"\s+content="([^"]+)"', page_html)
            except Exception:
                pass
                
        if match:
            verification_code = match.group(1)
            log(f"Extracted Verification Token: {verification_code}")
        else:
            result(False, error="Could not find the HTML verification meta tag in GSC.")
            return

        # Step 4: Open WordPress Admin to Insert Verification Tag
        log("Launching WordPress driver to install verification tag...")
        wp_driver = get_driver(f"chrome_profile_wp_{project_id}", headless=False)
        
        login_url = wp_admin_url
        if "/wp-admin" not in login_url and "/wp-login.php" not in login_url:
            login_url = wp_admin_url.rstrip('/') + '/wp-login.php'
            
        log(f"Navigating to WordPress: {login_url}")
        wp_driver.get(login_url)
        time.sleep(2)
        
        # Log in if needed
        if "/wp-admin" in wp_driver.current_url and "/wp-login.php" not in wp_driver.current_url:
            log("Already logged in to WordPress.")
        else:
            log("Entering WordPress credentials...")
            wp_wait = WebDriverWait(wp_driver, 15)
            user_el = wp_wait.until(EC.presence_of_element_located((By.ID, "user_login")))
            user_el.clear()
            user_el.send_keys(wp_user)
            pass_el = wp_driver.find_element(By.ID, "user_pass")
            pass_el.clear()
            pass_el.send_keys(wp_pass)
            wp_driver.find_element(By.ID, "wp-submit").click()
            time.sleep(3)
            
        if "wp-admin" not in wp_driver.current_url:
            result(False, error="WordPress login failed.")
            return
            
        # Install via Theme File Editor (General & Theme Native)
        editor_url = wp_admin_url.split('/wp-admin')[0].rstrip('/') + '/wp-admin/theme-editor.php'
        log(f"Navigating to Theme File Editor: {editor_url}")
        wp_driver.get(editor_url)
        time.sleep(2)
        
        # Click warning modal if it appears (Dismiss "I understand" popup)
        try:
            understand_btn = wp_driver.find_element(By.XPATH, "//button[contains(., 'understand') or contains(., 'Understand')]")
            understand_btn.click()
            time.sleep(1)
        except Exception:
            pass
            
        # Locate header.php in files list
        try:
            header_link = wp_driver.find_element(By.XPATH, "//a[contains(text(), 'Theme Header') or contains(text(), 'header.php')]")
            header_link.click()
            time.sleep(2)
            
            # Read editor text
            editor_textarea = wp_driver.find_element(By.ID, "newcontent")
            editor_content = editor_textarea.get_attribute("value")
            
            meta_tag = f'<meta name="google-site-verification" content="{verification_code}" />'
            
            if "google-site-verification" in editor_content:
                log("Google site verification tag already present in header.php.")
            else:
                log("Inserting meta tag inside header.php...")
                # Insert right after <head>
                new_content = editor_content.replace("<head>", f"<head>\n{meta_tag}")
                # Update textarea
                wp_driver.execute_script("arguments[0].value = arguments[1];", editor_textarea, new_content)
                time.sleep(1)
                
                # Click Submit/Update File
                update_btn = wp_driver.find_element(By.ID, "submit")
                update_btn.click()
                time.sleep(3)
                log("header.php updated successfully.")
        except Exception as e:
            log(f"Theme editor failed ({str(e)}). Trying alternative: Yoast SEO settings...")
            # Try Yoast settings
            yoast_url = wp_admin_url.split('/wp-admin')[0].rstrip('/') + '/wp-admin/admin.php?page=wpseo_tools'
            wp_driver.get(yoast_url)
            time.sleep(2)
            # Since Yoast Settings might vary, try Yoast webmaster connections
            yoast_webmaster_url = wp_admin_url.split('/wp-admin')[0].rstrip('/') + '/wp-admin/admin.php?page=wpseo_dashboard#top#webmaster-tools'
            wp_driver.get(yoast_webmaster_url)
            time.sleep(2)
            try:
                gsc_input = wp_driver.find_element(By.ID, "google")
                gsc_input.clear()
                gsc_input.send_keys(verification_code)
                wp_driver.find_element(By.XPATH, "//input[@type='submit' or @name='submit']").click()
                time.sleep(3)
                log("Verification added via Yoast SEO.")
            except Exception as e2:
                result(False, error=f"Failed to place verification code on website. Theme Editor & Yoast failed: {str(e2)}")
                return
                
        # Close WordPress browser session
        wp_driver.quit()
        wp_driver = None
        
        # Step 5: Go back to GSC and click Verify
        log("Returning to Google Search Console to click Verify...")
        verify_btn = wait.until(EC.presence_of_element_located((By.XPATH, "//button[contains(., 'Verify') or contains(., 'VERIFY')]")))
        verify_btn.click()
        time.sleep(8)
        
        # Check if verified
        success_page = gsc_driver.page_source
        if "ownership verified" in success_page.lower() or "verified" in success_page.lower() or "go to property" in success_page.lower():
            log("🎉 Google Search Console Verification Completed Successfully!")
            result(True, url=gsc_driver.current_url)
        else:
            result(False, error="Verification failed in Google Search Console. Please verify manually.")
            
    except Exception as e:
        result(False, error=str(e))
    finally:
        if gsc_driver:
            gsc_driver.quit()
        if wp_driver:
            wp_driver.quit()

if __name__ == "__main__":
    main()
