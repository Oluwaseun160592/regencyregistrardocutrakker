#!/bin/bash
set -e

# Default to port 80 if PORT environment variable is not set
PORT="${PORT:-80}"

# Dynamically configure Apache to listen on $PORT (required by platforms like Render)
sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/000-default.conf

# Start Apache in the foreground
exec apache2-foreground

