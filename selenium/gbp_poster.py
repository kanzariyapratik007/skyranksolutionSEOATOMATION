#!/usr/bin/env python3
"""
Google Business Profile Auto-Poster via Selenium.
Uses the user's master Google account profile (chrome_profile_gsc)
to automatically post business updates (with description + poster image).
"""
import sys, json, time, os, base64
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
    if len(sys.argv) < 4:
        result(False, error="Usage: gbp_poster.py [business_name_base64] [post_text_base64] [image_path]")
        return
        
    try:
        business_name = base64.b64decode(sys.argv[1]).decode('utf-8')
        post_text     = base64.b64decode(sys.argv[2]).decode('utf-8')
    except Exception:
        business_name = sys.argv[1]
        post_text     = sys.argv[2]
        
    image_path = sys.argv[3]
    if image_path == 'none':
        image_path = None

    log(f"Starting Google Business Profile Auto-Post for '{business_name}'...")
    
    driver = None
    try:
        driver = get_driver('chrome_profile_gsc', headless=False)
        
        # Step 1: Open Google Search Profile Manager
        driver.get("https://www.google.com/search?q=my+business")
        time.sleep(5)
        
        # Verify if logged in
        if "signin" in driver.current_url or "login" in driver.current_url:
            result(False, error="Google Account session expired. Please log in to Google Search Console first to establish session.")
            return
            
        # Step 2: Handle multi-location list selection if present
        # If user has multiple businesses, Google displays them as list elements
        if "business.google.com" in driver.current_url or "Locations" in driver.title or len(driver.find_elements(By.XPATH, f"//*[contains(text(), '{business_name}')]")) > 0:
            log("Multi-location dashboard or business list detected. Selecting business name...")
            found_business = False
            
            # Look for the business name anchor/button and click
            matches = driver.find_elements(By.XPATH, f"//a[contains(text(), '{business_name}')] | //div[contains(text(), '{business_name}')] | //span[contains(text(), '{business_name}')]")
            for el in matches:
                try:
                    if el.is_displayed():
                        el.click()
                        found_business = True
                        time.sleep(5)
                        break
                except Exception:
                    continue
            
            if not found_business:
                # If cannot click, try direct search with business name to target single business panel
                log(f"Searching business directly: 'my business {business_name}'...")
                driver.get(f"https://www.google.com/search?q=my+business+{business_name}")
                time.sleep(5)

        # Step 3: Find and click "Add update" button
        log("Looking for 'Add update' or 'Create post' button on Google Business panel...")
        add_update_btn = None
        
        selectors = [
            "//div[text()='Add update']",
            "//span[text()='Add update']",
            "//button[contains(@aria-label, 'Add update')]",
            "//a[contains(@href, '/posts/new')]",
            "//div[contains(text(), 'Add update')]",
            "//span[contains(text(), 'Add update')]"
        ]
        
        for sel in selectors:
            try:
                elements = driver.find_elements(By.XPATH, sel)
                for el in elements:
                    if el.is_displayed():
                        add_update_btn = el
                        break
                if add_update_btn:
                    break
            except Exception:
                continue
                
        if not add_update_btn:
            # Fallback direct link attempt if standard UI panel doesn't reveal it
            result(False, error="Could not find 'Add update' button on Google maps card. Please make sure Google account has manager rights for this business name.")
            return
            
        log("Clicking 'Add update' button...")
        driver.execute_script("arguments[0].click();", add_update_btn)
        time.sleep(4)
        
        # Step 4: Locate description textbox inside the posting modal
        log("Locating post text description box...")
        textarea = None
        text_selectors = [
            "//textarea",
            "//div[@role='textbox']",
            "//div[@contenteditable='true']",
            "//div[contains(@class, 'textarea')]"
        ]
        
        for sel in text_selectors:
            try:
                elements = driver.find_elements(By.XPATH, sel)
                for el in elements:
                    if el.is_displayed():
                        textarea = el
                        break
                if textarea:
                    break
            except Exception:
                continue
                
        if not textarea:
            result(False, error="Could not find description text box in the Google update modal.")
            return
            
        textarea.click()
        time.sleep(1)
        textarea.send_keys(post_text)
        time.sleep(2)
        
        # Step 5: Upload image if present
        if image_path and os.path.exists(image_path):
            log(f"Uploading image: {image_path}...")
            file_input = None
            try:
                # Find hidden input type file
                file_input = driver.find_element(By.XPATH, "//input[@type='file']")
            except Exception:
                pass
                
            if file_input:
                file_input.send_keys(os.path.abspath(image_path))
                log("Image uploaded. Waiting for processing...")
                time.sleep(6) # Give time to process image upload
            else:
                log("Could not find upload file input container. Posting text only.")
                
        # Step 6: Find and click Publish button
        log("Publishing update to Google Business Profile...")
        publish_btn = None
        publish_selectors = [
            "//div[text()='Post']",
            "//span[text()='Post']",
            "//button[contains(@aria-label, 'Post')]",
            "//div[contains(text(), 'Publish')]",
            "//span[contains(text(), 'Publish')]",
            "//button[contains(text(), 'Post')]"
        ]
        
        for sel in publish_selectors:
            try:
                elements = driver.find_elements(By.XPATH, sel)
                for el in elements:
                    if el.is_displayed():
                        publish_btn = el
                        break
                if publish_btn:
                    break
            except Exception:
                continue
                
        if not publish_btn:
            result(False, error="Could not locate 'Post' / 'Publish' button inside update modal.")
            return
            
        driver.execute_script("arguments[0].click();", publish_btn)
        log("Publish button clicked. Waiting 5 seconds for complete confirmation...")
        time.sleep(5)
        
        result(True, url="https://business.google.com")
        
    except Exception as e:
        result(False, error=str(e))
    finally:
        if driver:
            driver.quit()

if __name__ == '__main__':
    main()
