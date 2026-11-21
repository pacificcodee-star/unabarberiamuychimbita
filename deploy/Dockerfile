# syntax=docker/dockerfile:1
# Imagen de producción: Laravel (panel + web pública) servido con Caddy y PHP-FPM.

FROM node:22-alpine AS assets
ENV NODE_OPTIONS=--max-old-space-size=768
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --ignore-platform-req=ext-gmp
COPY . .
RUN composer dump-autoload --optimize --no-dev --ignore-platform-req=ext-gmp

FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache caddy supervisor icu-dev gmp-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath pcntl opcache intl gmp \
    && apk del $PHPIZE_DEPS

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY deploy/php/barberia.ini /usr/local/etc/php/conf.d/zz-barberia.ini
COPY deploy/caddy/Caddyfile /etc/caddy/Caddyfile
COPY deploy/supervisord.conf /etc/supervisord.conf
COPY deploy/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

RUN chmod +x /usr/local/bin/docker-entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache /data /config \
    && rm -f public/storage \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

ENV ROLE=web
EXPOSE 80 443
ENTRYPOINT ["docker-entrypoint"]
