#!/bin/bash
set -e

: "${ARTIFACT_USER:?ARTIFACT_USER environment variable is required}"
: "${ARTIFACT_PASSWORD:?ARTIFACT_PASSWORD environment variable is required}"

# Create HTTP Basic Authentication file
htpasswd -bc /etc/nginx/.htpasswd \
    "$ARTIFACT_USER" \
    "$ARTIFACT_PASSWORD"

# Start PHP
service php8.3-fpm start

# Validate nginx configuration
nginx -t

# Reload nginx
service nginx reload || true

# Run nginx in foreground
exec nginx -g "daemon off;"
