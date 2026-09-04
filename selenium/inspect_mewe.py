#!/usr/bin/env python3
import time
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from webdriver_manager.chrome import ChromeDriverManager

opts = Options()
opts.add_argument('--no-sandbox')
opts.add_argument('--disable-blink-features=AutomationControlled')
opts.add_experimental_option('excludeSwitches', ['enable-automation'])
opts.add_argument('--window-size=1400,900')
opts.add_argument('--incognito')
service = Service(ChromeDriverManager().install())
driver = webdriver.Chrome(service=service, options=opts)

try:
    driver.get("https://mewe.com/login")
    time.sleep(6)
    print(f"URL: {driver.current_url}", flush=True)
    print(f"Title: {driver.title}", flush=True)

    inputs = driver.find_elements(By.TAG_NAME, "input")
    print(f"\nInputs: {len(inputs)}", flush=True)
    for i, inp in enumerate(inputs):
        if inp.is_displayed():
            print(f"[{i}] id={inp.get_attribute('id')} name={inp.get_attribute('name')} type={inp.get_attribute('type')} placeholder={inp.get_attribute('placeholder')}", flush=True)

    btns = driver.find_elements(By.TAG_NAME, "button")
    print(f"\nButtons: {len(btns)}", flush=True)
    for btn in btns[:10]:
        if btn.is_displayed():
            print(f"  type={btn.get_attribute('type')} text='{btn.text[:40]}' class={btn.get_attribute('class')[:40]}", flush=True)

    driver.save_screenshot("c:/Users/ADMIN/Desktop/seo-system/selenium/mewe_login.png")
    print("\nScreenshot saved", flush=True)
    time.sleep(3)
except Exception as e:
    print(f"Error: {e}", flush=True)
finally:
    driver.quit()
