#!/usr/bin/env python3
"""
WordPress Auto-Fixer via Selenium
Logs into WP-admin and updates Site Title or Tagline in General Settings.
"""
import sys, json, time, os, base64
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
    
    # Isolated profile folder per project to keep sessions separate
    profile_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), f'chrome_profile_wp_{project_id}')
    opts.add_argument(f'--user-data-dir={profile_dir}')
    
    service = Service(ChromeDriverManager().install())
    driver  = webdriver.Chrome(service=service, options=opts)
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    return driver

def main():
    if len(sys.argv) < 7:
        result(False, error="Usage: wp_autofix.py [project_id] [url] [user] [pass_base64] [type] [value]")
        return
        
    project_id = sys.argv[1]
    wp_url     = sys.argv[2]
    wp_user    = sys.argv[3]
    try:
        wp_pass = base64.b64decode(sys.argv[4]).decode('utf-8')
    except Exception as e:
        wp_pass = sys.argv[4]
        
    fix_type   = sys.argv[5] # 'title' or 'meta_description' (tagline)
    fix_value  = sys.argv[6]

    log(f"Starting WP Auto-Fixer for Project #{project_id}...")
    driver = None
    try:
        driver = get_driver(project_id)
        
        login_url = wp_url
        if "/wp-admin" not in login_url and "/wp-login.php" not in login_url:
            login_url = wp_url.rstrip('/') + '/wp-login.php'
            
        log(f"Navigating to login page: {login_url}")
        driver.get(login_url)
        time.sleep(2)
        
        # Check if already logged in
        if "/wp-admin" in driver.current_url and "/wp-login.php" not in driver.current_url:
            log("Already logged in. Skipping login step.")
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
            result(False, error="Login failed. Check WordPress username and password.")
            return
            
        # Navigate to General Settings
        settings_url = wp_url.split('/wp-admin')[0].rstrip('/') + '/wp-admin/options-general.php'
        log(f"Navigating to General Settings: {settings_url}")
        driver.get(settings_url)
        time.sleep(2)
        
        wait = WebDriverWait(driver, 10)
        if fix_type == 'title':
            log(f"Updating Site Title to: {fix_value}")
            title_el = wait.until(EC.presence_of_element_located((By.ID, "blogname")))
            title_el.clear()
            title_el.send_keys(fix_value)
        elif fix_type == 'meta_description':
            log(f"Updating Tagline to: {fix_value}")
            tagline_el = wait.until(EC.presence_of_element_located((By.ID, "blogdescription")))
            tagline_el.clear()
            tagline_el.send_keys(fix_value)
        else:
            result(False, error=f"Unknown fix type: {fix_type}")
            return
            
        # Save Changes
        log("Saving changes...")
        driver.find_element(By.ID, "submit").click()
        time.sleep(3)
        
        result(True, url=driver.current_url)
    except Exception as e:
        result(False, error=str(e))
    finally:
        if driver:
            driver.quit()

if __name__ == "__main__":
    main()
