#!/bin/bash
set -e

###############################################
# Validate required environment variables
###############################################

: "${ARTIFACT_USER:?ARTIFACT_USER environment variable is required}"
: "${ARTIFACT_PASSWORD:?ARTIFACT_PASSWORD environment variable is required}"

echo "========================================="
echo " Starting Custom Artifact Repository"
echo "========================================="

###############################################
# Create HTTP Basic Authentication
###############################################

echo "Creating HTTP Basic Authentication..."

htpasswd -bc /etc/nginx/.htpasswd \
    "$ARTIFACT_USER" \
    "$ARTIFACT_PASSWORD"

###############################################
# Update Web UI Credentials
###############################################

SCRIPT_JS="/var/www/html/browser/script.js"

if [ -f "$SCRIPT_JS" ]; then
    echo "Updating UI credentials..."

    sed -i "s|const DL_USER = \".*\";|const DL_USER = \"$ARTIFACT_USER\";|g" "$SCRIPT_JS"

    sed -i "s|const DL_PASS = \".*\";|const DL_PASS = \"$ARTIFACT_PASSWORD\";|g" "$SCRIPT_JS"
else
    echo "WARNING: $SCRIPT_JS not found. Skipping UI credential update."
fi

###############################################
# Ensure artifact directory exists
###############################################

mkdir -p /var/www/html/artifacts

chown -R www-data:www-data /var/www/html

###############################################
# Start PHP-FPM
###############################################

echo "Starting PHP-FPM..."

service php8.3-fpm start

###############################################
# Validate Nginx Configuration
###############################################

echo "Validating Nginx configuration..."

nginx -t

###############################################
# Reload Nginx
###############################################

echo "Reloading Nginx..."

service nginx reload || true

###############################################
# Start Nginx
###############################################

echo "Starting Nginx..."

exec nginx -g "daemon off;"
