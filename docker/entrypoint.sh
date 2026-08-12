#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
    export APP_KEY
fi

DB_HOST="${DB_DIRECT_HOST:-postgres}" DB_PORT="${DB_DIRECT_PORT:-5432}" php artisan config:cache
php artisan migrate --force --no-interaction
php artisan config:clear
php artisan config:cache
php artisan route:cache

exec /usr/bin/supervisord -c /etc/supervisord.conf
