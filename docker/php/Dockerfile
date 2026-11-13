# syntax=docker/dockerfile:1

# GasFlow PHP image (php-fpm 8.5).
#   base   - PHP with every extension the app needs
#   dev    - base + Composer, runs as the host user (bind-mounted source)
#   node   - dev + Node.js 26 LTS (Vite needs PHP for Wayfinder route generation)
#   assets - builds the production frontend bundle
#   prod   - optimized image with the code, vendor and built assets baked in

ARG PHP_VERSION=8.5
ARG NODE_VERSION=26
ARG COMPOSER_VERSION=2

FROM composer:${COMPOSER_VERSION} AS composer
FROM node:${NODE_VERSION}-alpine AS nodejs

# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

# Runtime libraries for the extensions below.
RUN apk add --no-cache \
        fcgi \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libpq \
        libwebp \
        libzip \
        tini

# pdo_pgsql, pgsql, redis, intl, bcmath, pcntl, zip, gd.
# OPcache and sodium are already part of the official PHP 8.5 image.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libzip-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl clear-cache \
    && apk del .build-deps \
    && php -m | grep -qiE '^(redis|pdo_pgsql|intl|sodium|Zend OPcache)$'

COPY docker/php/conf.d/gasflow.ini /usr/local/etc/php/conf.d/zz-gasflow.ini
COPY docker/php/php-fpm.d/gasflow.conf /usr/local/etc/php-fpm.d/zz-gasflow.conf

WORKDIR /var/www/html

ENTRYPOINT ["/sbin/tini", "--", "docker-php-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
FROM base AS dev

# Run as the host user so files created in the bind mount stay editable.
ARG UID=1000
ARG GID=1000
RUN apk add --no-cache git shadow unzip \
    && groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g www-data www-data \
    && apk del shadow \
    && chown -R www-data:www-data /var/www

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/gasflow-dev.ini /usr/local/etc/php/conf.d/zz-gasflow-dev.ini

USER www-data

# ---------------------------------------------------------------------------
FROM dev AS node

USER root
COPY --from=nodejs /usr/local/bin/node /usr/local/bin/node
COPY --from=nodejs /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN apk add --no-cache libatomic libstdc++ \
    && ln -sf ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && node --version && npm --version \
    && install -d -o www-data -g www-data /var/www/html/node_modules

USER www-data
CMD ["npm", "run", "dev"]

# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# ---------------------------------------------------------------------------
FROM vendor AS assets

COPY --from=nodejs /usr/local/bin/node /usr/local/bin/node
COPY --from=nodejs /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN apk add --no-cache libatomic libstdc++ \
    && ln -sf ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN composer dump-autoload --no-dev --no-scripts --classmap-authoritative \
    && cp .env.example .env \
    && php artisan key:generate --force \
    && npm run build \
    && rm .env

# ---------------------------------------------------------------------------
FROM base AS prod

COPY docker/php/conf.d/gasflow-prod.ini /usr/local/etc/php/conf.d/zz-gasflow-prod.ini
COPY --from=composer /usr/bin/composer /usr/bin/composer

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /var/www/html/public/build ./public/build

RUN rm -rf docker tests node_modules public/hot .env \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && rm /usr/bin/composer \
    && mkdir -p storage/framework/cache storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
