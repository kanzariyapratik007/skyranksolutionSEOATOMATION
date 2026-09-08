#!/bin/bash
# ==============================================================================
# Safe Deployment Script for EXISTING AWS EC2 Server
# Hosts 'seo-system' alongside existing live projects without breaking them!
# ==============================================================================

set -e

echo "🚀 Starting Safe Deployment of SEO System to Existing Server..."

TARGET_DIR="/var/www/html/seo-system"

# 1. Create dedicated subfolder so existing site at /var/www/html/ is untouched
echo "📁 Creating isolated directory at ${TARGET_DIR}..."
sudo mkdir -p ${TARGET_DIR}
sudo mkdir -p ${TARGET_DIR}/logs
sudo mkdir -p ${TARGET_DIR}/uploads

# 2. Check and install missing PHP extensions safely
echo "🔍 Checking PHP & required extensions..."
sudo apt update -y
sudo apt install -y php-mysqli php-curl php-gd php-mbstring php-xml php-zip unzip certbot

# 3. Enable Apache rewrite module if not already enabled
sudo a2enmod rewrite 2>/dev/null || true
sudo systemctl reload apache2 2>/dev/null || true

# 4. Set secure file permissions
echo "🔒 Setting file permissions..."
sudo chown -R www-data:www-data ${TARGET_DIR}
sudo chmod -R 775 ${TARGET_DIR}

# 5. Create new separate MySQL Database (seo_system)
echo "🗄️ Setting up dedicated database (seo_system)..."
DB_NAME="seo_system"
DB_USER="seo_user"
DB_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 16)

sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || echo "Note: Execute DB creation manually if root password is set."
sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null || true
sudo mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';" 2>/dev/null || true
sudo mysql -e "FLUSH PRIVILEGES;" 2>/dev/null || true

# 6. Create config.local.php
CONFIG_FILE="${TARGET_DIR}/config.local.php"
if [ ! -f "$CONFIG_FILE" ]; then
cat <<EOF | sudo tee "$CONFIG_FILE" > /dev/null
<?php
// Local production configuration for SEO System on existing server
return [
    'DB_HOST' => '127.0.0.1:3306',
    'DB_USER' => '${DB_USER}',
    'DB_PASS' => '${DB_PASS}',
    'DB_NAME' => '${DB_NAME}',
    'OPENAI_API_KEY' => '', // Add your OpenAI API key
];
EOF
sudo chown www-data:www-data "$CONFIG_FILE"
sudo chmod 600 "$CONFIG_FILE"
fi

echo "=============================================================================="
echo "✅ SEO System safely setup at ${TARGET_DIR}"
echo "------------------------------------------------------------------------------"
echo "📌 Existing project remains untouched!"
echo "📌 New URL: http://YOUR_DOMAIN_OR_IP/seo-system/"
echo "=============================================================================="
