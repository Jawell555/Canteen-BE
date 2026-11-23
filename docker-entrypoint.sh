#!/bin/sh
set -e

# Set listening port from Render's dynamic PORT variable
export SERVER_NAME=":${PORT:-80}"

# Run optimizations if in production or staging
if [ "$APP_ENV" = "production" ] || [ "$APP_ENV" = "staging" ]; then
    echo "Caching Laravel configuration and routes..."
    php artisan config:cache || true
    php artisan route:cache || true
fi

# Automatically run database migrations on boot
if [ "$RUN_MIGRATIONS" != "false" ]; then
    echo "Running database migrations..."
    php artisan migrate --force || true
fi

# Execute main process (FrankenPHP)
exec "$@"
