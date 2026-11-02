FROM php:8.2-apache

# Install PostgreSQL client library for optional Supabase connectivity
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy source code to Apache docroot
COPY . /var/www/html/

# Copy entrypoint script, ensure Unix line endings and execute permissions
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

# Create data and uploads directories with proper permissions
RUN mkdir -p /var/www/html/data /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/data /var/www/html/uploads \
    && chmod -R 775 /var/www/html/data /var/www/html/uploads

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
