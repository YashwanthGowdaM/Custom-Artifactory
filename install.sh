#!/bin/bash
set -e

if [ "$EUID" -ne 0 ]; then
    echo "Run this script as root."
    exit 1
fi

read -rp "Enter Artifact Username: " ARTIFACT_USER

while true; do
    read -rsp "Enter Artifact Password: " ARTIFACT_PASSWORD
    echo
    read -rsp "Confirm Password: " CONFIRM_PASSWORD
    echo

    [ "$ARTIFACT_PASSWORD" = "$CONFIRM_PASSWORD" ] && break

    echo "Passwords do not match. Try again."
done

apt update

apt install -y \
    nginx \
    nginx-extras \
    php-fpm \
    php-cli \
    apache2-utils

cat >/etc/php/8.3/fpm/conf.d/99-artifact-upload.ini <<EOF
upload_max_filesize = 20G
post_max_size = 20G
max_execution_time = 3600
max_input_time = 3600
memory_limit = 512M
EOF

rm -f /etc/nginx/sites-enabled/default
rm -f /etc/nginx/sites-available/default

cp nginx/artifacts /etc/nginx/sites-available/artifacts

ln -sf /etc/nginx/sites-available/artifacts \
       /etc/nginx/sites-enabled/artifacts

mkdir -p /var/www/html

cp -r html/* /var/www/html/

mkdir -p /var/www/html/artifacts

htpasswd -bc /etc/nginx/.htpasswd \
    "$ARTIFACT_USER" \
    "$ARTIFACT_PASSWORD"

SCRIPT_JS="/var/www/html/browser/script.js"

if [ -f "$SCRIPT_JS" ]; then
    sed -Ei "s|const DL_USER = .*;|const DL_USER = '$ARTIFACT_USER';|" "$SCRIPT_JS"
    sed -Ei "s|const DL_PASS = .*;|const DL_PASS = '$ARTIFACT_PASSWORD';|" "$SCRIPT_JS"
fi

chown -R www-data:www-data /var/www/html

find /var/www/html -type d -exec chmod 775 {} \;
find /var/www/html -type f -exec chmod 664 {} \;

systemctl enable nginx
systemctl enable php8.3-fpm

systemctl restart php8.3-fpm

nginx -t

systemctl reload nginx

SERVER_IP=$(hostname -I | awk '{print $1}')

echo
echo "Installation completed."
echo
echo "Browser : http://${SERVER_IP}/browser/"
echo "Upload  : curl -u ${ARTIFACT_USER}:'********' -T ./FILE http://${SERVER_IP}/artifacts/Test/"
echo
