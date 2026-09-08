#!/bin/bash
# ==============================================================================
# Helper Script: Create Apache VirtualHost for a new project on AWS EC2
# Usage: sudo ./setup_virtualhost.sh <project_folder_name> <domain_name>
# Example: sudo ./setup_virtualhost.sh myproject app.example.com
# ==============================================================================

if [ "$#" -ne 2 ]; then
    echo "❌ Error: Missing parameters!"
    echo "Usage: sudo ./setup_virtualhost.sh <project_folder_name> <domain_or_subdomain>"
    echo "Example: sudo ./setup_virtualhost.sh project2 app.mycompany.com"
    exit 1
fi

PROJECT_NAME=$1
DOMAIN_NAME=$2
PROJECT_DIR="/var/www/${PROJECT_NAME}"
CONF_FILE="/etc/apache2/sites-available/${PROJECT_NAME}.conf"

echo "📂 Creating Directory at ${PROJECT_DIR}..."
sudo mkdir -p ${PROJECT_DIR}
sudo chown -R www-data:www-data ${PROJECT_DIR}
sudo chmod -R 775 ${PROJECT_DIR}

echo "⚙️ Creating Apache VirtualHost file at ${CONF_FILE}..."
cat <<EOF | sudo tee "${CONF_FILE}" > /dev/null
<VirtualHost *:80>
    ServerName ${DOMAIN_NAME}
    DocumentRoot ${PROJECT_DIR}

    <Directory ${PROJECT_DIR}>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${PROJECT_NAME}_error.log
    CustomLog \${APACHE_LOG_DIR}/${PROJECT_NAME}_access.log combined
</VirtualHost>
EOF

echo "🔗 Enabling VirtualHost site..."
sudo a2ensite ${PROJECT_NAME}.conf

echo "🔄 Reloading Apache..."
sudo systemctl reload apache2

echo "✅ Success! Project '${PROJECT_NAME}' setup completed for domain '${DOMAIN_NAME}'."
echo "📌 Place your project files inside: ${PROJECT_DIR}"
echo "📌 To add free SSL HTTPS, run: sudo certbot --apache -d ${DOMAIN_NAME}"
