#!/bin/bash
# ==============================================================================
# AWS EC2 Ubuntu 22.04 Auto Setup Script for SEO System
# ==============================================================================

set -e

echo "🚀 Starting SEO System AWS EC2 Deployment Setup..."

# 1. Update package list
echo "📦 Updating system packages..."
sudo apt update && sudo apt upgrade -y

# 2. Install Apache2, MySQL, PHP and extensions
echo "🛠️ Installing Apache, MySQL, PHP and required extensions..."
sudo apt install -y apache2 mysql-server php php-cli php-mysql php-curl php-gd php-mbstring php-xml php-zip unzip git certbot python3-certbot-apache

# 3. Enable Apache modules & configurations
echo "⚙️ Configuring Apache mod_rewrite..."
sudo a2enmod rewrite
sudo a2enmod headers

# Allow .htaccess overrides in Apache default site
sudo sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Restart Apache
sudo systemctl restart apache2

# 4. MySQL Database Setup
echo "🗄️ Setting up MySQL Database (seo_system)..."
DB_NAME="seo_system"
DB_USER="seo_user"
DB_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 16)

sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

echo "✅ Database '${DB_NAME}' created with user '${DB_USER}' and generated password: ${DB_PASS}"

# 5. Application setup directory
APP_DIR="/var/www/html/seo-system"

echo "📂 Setting up Application Directory at ${APP_DIR}..."
sudo mkdir -p ${APP_DIR}
sudo mkdir -p ${APP_DIR}/logs
sudo mkdir -p ${APP_DIR}/uploads

# Set Ownership
sudo chown -R www-data:www-data ${APP_DIR}
sudo chmod -R 775 ${APP_DIR}

# 6. Save DB credentials to config.local.php template if missing
CONFIG_FILE="${APP_DIR}/config.local.php"
if [ ! -f "$CONFIG_FILE" ]; then
cat <<EOF | sudo tee "$CONFIG_FILE" > /dev/null
<?php
// Local production configuration generated on AWS EC2 setup
return [
    'DB_HOST' => '127.0.0.1:3306',
    'DB_USER' => '${DB_USER}',
    'DB_PASS' => '${DB_PASS}',
    'DB_NAME' => '${DB_NAME}',
    'OPENAI_API_KEY' => '', // Put your OpenAI API key here
];
EOF
sudo chown www-data:www-data "$CONFIG_FILE"
sudo chmod 600 "$CONFIG_FILE"
echo "✅ Config generated at ${CONFIG_FILE}"
fi

# 7. Configure Cron Jobs for www-data user
echo "⏱️ Setting up Cron Jobs..."
CRON_DAILY="0 9 * * * php ${APP_DIR}/cron-daily.php > ${APP_DIR}/logs/cron_daily.log 2>&1"
CRON_WEEKLY="0 9 * * 1 php ${APP_DIR}/cron-weekly.php > ${APP_DIR}/logs/cron_weekly.log 2>&1"

(sudo crontab -u www-data -l 2>/dev/null; echo "$CRON_DAILY"; echo "$CRON_WEEKLY") | sudo crontab -u www-data -

echo "=============================================================================="
echo "🎉 AWS EC2 Setup Completed Successfully!"
echo "------------------------------------------------------------------------------"
echo "📌 NEXT STEPS:"
echo "1. Upload your code files to: ${APP_DIR}"
echo "2. Import database.sql: sudo mysql -u root ${DB_NAME} < ${APP_DIR}/database.sql"
echo "3. Update API Keys in: ${CONFIG_FILE}"
echo "4. Setup free SSL Certificate (if domain linked):"
echo "   sudo certbot --apache -d yourdomain.com"
echo "=============================================================================="
