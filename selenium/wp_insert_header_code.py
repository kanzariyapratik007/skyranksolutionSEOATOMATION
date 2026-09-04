#!/usr/bin/env python3
"""
WordPress Header Code Injector via Selenium
Logs into WP-admin and safely inserts or updates code (like GA or Schema) in header.php.
"""
import sys, json, time, os, re, base64
os.environ['WDM_LOG'] = '0'
os.environ['WDM_DIR'] = '/tmp/.wdm'
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

def get_driver(project_id):
    opts = Options()
    if sys.platform != "win32":
        opts.add_argument('--headless=new')
    opts.add_argument('--no-sandbox')
    opts.add_argument('--disable-dev-shm-usage')
    opts.add_argument('--disable-blink-features=AutomationControlled')
    opts.add_experimental_option('excludeSwitches', ['enable-automation'])
    opts.add_experimental_option('useAutomationExtension', False)
    opts.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36')
    opts.add_argument('--window-size=1400,900')
    opts.add_argument('--start-maximized')
    
    profile_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), f'chrome_profile_wp_{project_id}')
    opts.add_argument(f'--user-data-dir={profile_dir}')
    
    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    return driver

def main():
    if len(sys.argv) < 7:
        result(False, error="Usage: wp_insert_header_code.py [project_id] [url] [user] [pass_base64] [block_type] [code_base64]")
        return
        
    project_id = sys.argv[1]
    wp_url     = sys.argv[2]
    wp_user    = sys.argv[3]
    try:
        wp_pass = base64.b64decode(sys.argv[4]).decode('utf-8')
    except Exception:
        wp_pass = sys.argv[4]
        
    block_type = sys.argv[5] # 'ga' or 'schema'
    try:
        code_to_insert = base64.b64decode(sys.argv[6]).decode('utf-8')
    except Exception:
        code_to_insert = sys.argv[6]

    log(f"Starting WP Header Injector for Project #{project_id}...")
    driver = None
    try:
        driver = get_driver(project_id)
        
        login_url = wp_url
        if "/wp-admin" not in login_url and "/wp-login.php" not in login_url:
            login_url = wp_url.rstrip('/') + '/wp-login.php'
            
        log(f"Navigating to login page: {login_url}")
        driver.get(login_url)
        time.sleep(2)
        
        # Log in if needed
        if "/wp-admin" in driver.current_url and "/wp-login.php" not in driver.current_url:
            log("Already logged in. Skipping login.")
        else:
            log("Entering credentials...")
            wait = WebDriverWait(driver, 15)
            user_el = wait.until(EC.presence_of_element_located((By.ID, "user_login")))
            user_el.clear()
            user_el.send_keys(wp_user)
            
            pass_el = driver.find_element(By.ID, "user_pass")
            pass_el.clear()
            pass_el.send_keys(wp_pass)
            
            driver.find_element(By.ID, "wp-submit").click()
            time.sleep(3)
            
        if "wp-admin" not in driver.current_url:
            result(False, error="WordPress login failed.")
            return
            
        # Navigate to Theme File Editor
        editor_url = wp_url.split('/wp-admin')[0].rstrip('/') + '/wp-admin/theme-editor.php'
        log(f"Navigating to Theme File Editor: {editor_url}")
        driver.get(editor_url)
        time.sleep(2)
        
        # Click warning modal if it appears
        try:
            understand_btn = driver.find_element(By.XPATH, "//button[contains(., 'understand') or contains(., 'Understand')]")
            understand_btn.click()
            time.sleep(1)
        except Exception:
            pass
            
        # Locate header.php
        log("Locating header.php...")
        wait = WebDriverWait(driver, 10)
        try:
            header_link = wait.until(EC.presence_of_element_located((By.XPATH, "//a[contains(text(), 'Theme Header') or contains(text(), 'header.php')]")))
            header_link.click()
            time.sleep(2)
        except Exception as e:
            result(False, error=f"Could not find header.php file link in editor list: {str(e)}")
            return
            
        # Read current content
        editor_textarea = driver.find_element(By.ID, "newcontent")
        current_content = editor_textarea.get_attribute("value")
        
        # Define markers
        start_marker = f"<!-- LMT-{block_type.upper()}-START -->"
        end_marker   = f"<!-- LMT-{block_type.upper()}-END -->"
        new_block    = f"\n{start_marker}\n{code_to_insert}\n{end_marker}\n"
        
        # Check if markers already exist for self-healing replacement
        if start_marker in current_content and end_marker in current_content:
            log(f"Found existing {block_type.upper()} markers. Replacing old code block...")
            pattern = re.escape(start_marker) + r".*?" + re.escape(end_marker)
            new_content = re.sub(pattern, new_block.strip(), current_content, flags=re.DOTALL)
        else:
            log(f"Inserting new {block_type.upper()} code block right after <head> tag...")
            new_content = current_content.replace("<head>", f"<head>\n{new_block}")
            
        # Write modified content
        driver.execute_script("arguments[0].value = arguments[1];", editor_textarea, new_content)
        time.sleep(1)
        
        # Save Changes
        log("Saving theme file changes...")
        driver.find_element(By.ID, "submit").click()
        time.sleep(3)
        
        # Check success message
        success_msg = driver.find_elements(By.CSS_SELECTOR, "#message.updated")
        if success_msg:
            log("WordPress header.php updated successfully!")
            result(True, url=driver.current_url)
        else:
            # Recheck error
            err_msg = driver.find_elements(By.CSS_SELECTOR, "#message.error")
            err_text = err_msg[0].text if err_msg else "Unknown file save error"
            result(False, error=f"WordPress file update failed: {err_text}")
            
    except Exception as e:
        result(False, error=str(e))
    finally:
        if driver:
            driver.quit()

if __name__ == "__main__":
    main()
