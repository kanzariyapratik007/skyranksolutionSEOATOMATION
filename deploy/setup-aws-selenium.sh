#!/usr/bin/env bash
# ============================================================
# setup-aws-selenium.sh
# One-click AWS Ubuntu setup for Pinterest Selenium Automation
# ============================================================

set -e

echo "=== [1/4] Updating Package Index ==="
sudo apt-get update -y
sudo apt-get install -y wget curl unzip gnupg xvfb python3-pip python3-venv

echo "=== [2/4] Installing Google Chrome ==="
if ! command -v google-chrome &> /dev/null; then
    wget -q -O - https://dl-ssl.google.com/linux/linux_signing_key.pub | sudo apt-key add -
    echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" | sudo tee /etc/apt/sources.list.d/google-chrome.list
    sudo apt-get update -y
    sudo apt-get install -y google-chrome-stable
else
    echo "Google Chrome is already installed."
fi

echo "=== [3/4] Installing ChromeDriver & Python Dependencies ==="
sudo apt-get install -y chromium-chromedriver || true

python3 -m pip install --break-system-packages --upgrade pip
python3 -m pip install --break-system-packages selenium webdriver-manager

echo "=== [4/4] Verifying Setup ==="
echo -n "Chrome version: " && google-chrome --version
echo -n "ChromeDriver path: " && which chromedriver || echo "/usr/bin/chromedriver"
echo -n "Xvfb path: " && which xvfb-run

echo ""
echo "✅ AWS Selenium Setup Complete!"
echo "Your server can now execute background Pinterest browser automation stealthily via Xvfb virtual screen."
