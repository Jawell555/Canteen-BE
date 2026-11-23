FROM dunglas/frankenphp:1-php8.2-alpine

# Install PostgreSQL (Neon) and recommended Laravel extensions
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    bcmath \
    intl \
    zip \
    opcache

WORKDIR /app

# Install Composer dependencies using cached layers
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# Copy application source code
COPY . .

# Ensure storage directories exist and have proper permissions
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x docker-entrypoint.sh

# Default environment settings
ENV APP_ENV=production
ENV APP_DEBUG=false

ENTRYPOINT ["/app/docker-entrypoint.sh"]
CMD ["frankenphp", "php-server", "--root=/app/public"]
