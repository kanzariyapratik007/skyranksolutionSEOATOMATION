import sys
import json
import time
from urllib.parse import urlparse
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By

def clean_domain(url):
    try:
        parsed = urlparse(url)
        domain = parsed.netloc.lower()
        if domain.startswith("www."):
            domain = domain[4:]
        return domain
    except:
        return url.lower()

def check_rank(keyword, target_domain):
    chrome_options = Options()
    chrome_options.add_argument("--headless")
    chrome_options.add_argument("--disable-gpu")
    chrome_options.add_argument("--no-sandbox")
    chrome_options.add_argument("--disable-dev-shm-usage")
    chrome_options.add_argument("user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")
    
    driver = webdriver.Chrome(options=chrome_options)
    try:
        url = f"https://www.google.com/search?q={keyword.replace(' ', '+')}&num=100&hl=en"
        driver.get(url)
        time.sleep(4)
        
        # Check if Google shows captcha
        if "captcha" in driver.page_source.lower() or "sorry/index" in driver.current_url:
            return {"success": False, "error": "Google blocked the request with a Captcha"}
            
        # Parse organic links
        # Custom search results usually sit within div.g or inside main results container
        anchors = driver.find_elements(By.CSS_SELECTOR, "div.g a")
        
        links = []
        for a in anchors:
            try:
                href = a.get_attribute("href")
                if href and href.startswith("http") and "google.com" not in href:
                    if href not in links:
                        links.append(href)
            except:
                continue
                
        rank = 0
        target = target_domain.replace("www.", "").lower()
        for idx, link in enumerate(links):
            dom = clean_domain(link)
            if target in dom:
                rank = idx + 1
                break
                
        return {"success": True, "rank": rank, "links": links[:15]}
    except Exception as e:
        return {"success": False, "error": str(e)}
    finally:
        driver.quit()

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print(json.dumps({"success": False, "error": "Missing arguments"}))
        sys.exit(1)
        
    kwd = sys.argv[1]
    tgt = sys.argv[2]
    
    res = check_rank(kwd, tgt)
    print(json.dumps(res))
