#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
    export APP_KEY
fi

php artisan config:cache
php artisan route:cache
php artisan migrate --force --no-interaction

exec /usr/bin/supervisord -c /etc/supervisord.conf
