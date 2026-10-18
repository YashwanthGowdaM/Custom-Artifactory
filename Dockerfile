FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

# Install required packages
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        nginx \
        nginx-extras \
        php-fpm \
        php-cli \
        apache2-utils && \
    rm -rf /var/lib/apt/lists/*

# Configure PHP upload limits
RUN tee /etc/php/8.3/fpm/conf.d/99-artifact-upload.ini >/dev/null <<'EOF'
upload_max_filesize = 20G
post_max_size = 20G
max_execution_time = 3600
max_input_time = 3600
memory_limit = 512M
EOF

# Remove default nginx site
RUN rm -f /etc/nginx/sites-enabled/default \
          /etc/nginx/sites-available/default

# Copy nginx configuration
COPY nginx/artifacts /etc/nginx/sites-available/artifacts

RUN ln -sf /etc/nginx/sites-available/artifacts \
            /etc/nginx/sites-enabled/artifacts

# Copy application
COPY html/ /var/www/html/

# Create artifact directory
RUN mkdir -p /var/www/html/artifacts

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    find /var/www/html -type d -exec chmod 775 {} \; && \
    find /var/www/html -type f -exec chmod 664 {} \;

# Startup script
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
