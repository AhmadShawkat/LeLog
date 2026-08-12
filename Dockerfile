FROM php:8.5-fpm-alpine AS php-base

RUN apk add --no-cache nginx pgbouncer postgresql-libs supervisor \
    && apk add --no-cache --virtual .build-dependencies postgresql-dev \
    && docker-php-ext-install -j1 pcntl pdo_pgsql \
    && apk del .build-dependencies \
    && rm -f /etc/nginx/http.d/default.conf /usr/local/etc/php-fpm.d/www.conf.default

FROM php-base AS vendor

WORKDIR /app

COPY --from=composer:2.10 /usr/bin/composer /usr/local/bin/composer
COPY . .

RUN composer install \
    --classmap-authoritative \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist

FROM php-base AS application

WORKDIR /app

COPY --from=vendor --chown=www-data:www-data /app /app
COPY --chown=www-data:www-data docker/nginx.conf /etc/nginx/nginx.conf
COPY --chown=www-data:www-data docker/pgbouncer.ini /etc/pgbouncer/pgbouncer.ini
COPY --chown=www-data:www-data docker/pgbouncer-users.txt /etc/pgbouncer/users.txt
COPY --chown=www-data:www-data docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-lelog.conf
COPY --chown=www-data:www-data docker/supervisord.conf /etc/supervisord.conf
COPY --chown=www-data:www-data docker/entrypoint.sh /usr/local/bin/lelog-entrypoint

RUN chmod 0555 /usr/local/bin/lelog-entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage /var/lib/nginx /var/log/nginx

USER www-data

EXPOSE 8080

ENTRYPOINT ["lelog-entrypoint"]
