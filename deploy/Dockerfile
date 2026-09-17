# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22
ARG NGINX_VERSION=1.29-alpine

FROM composer:2.8 AS composer-binary

FROM php:${PHP_VERSION}-fpm-alpine AS php-base

RUN apk add --no-cache \
        icu-libs \
        libavif \
        libjpeg-turbo \
        libpng \
        libpq \
        libwebp \
        libzip \
    && apk add --no-cache --virtual .build-dependencies \
        $PHPIZE_DEPS \
        icu-dev \
        libavif-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        postgresql-dev \
    && docker-php-ext-configure gd --with-avif --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && apk del .build-dependencies \
    && addgroup -g 10001 -S fastlandings \
    && adduser -u 10001 -S -D -H -G fastlandings fastlandings \
    && adduser -u 10002 -S -D -H -G fastlandings projection

FROM php-base AS php-dependencies

COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer

WORKDIR /workspace

# Infrastructure and template packages come from Packagist. The only local
# Composer package is the design system copied into this repository.
COPY packages/ui/composer.json packages/ui/composer.json
COPY packages/ui/resources packages/ui/resources
COPY packages/ui/src packages/ui/src
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --prefer-dist \
        --optimize-autoloader \
        --no-interaction \
        --no-progress \
        --no-scripts

COPY app app
COPY bootstrap bootstrap
COPY config config
COPY database database
COPY public public
COPY resources resources
COPY routes routes
COPY artisan artisan

RUN mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && APP_ENV=production composer dump-autoload \
        --no-dev \
        --classmap-authoritative \
        --no-interaction

FROM node:${NODE_VERSION}-alpine AS frontend

WORKDIR /workspace

COPY package.json package-lock.json ./
COPY packages/ui/package.json packages/ui/package.json

RUN npm ci --no-audit --no-fund

COPY --from=php-dependencies /workspace/vendor vendor
COPY resources resources
COPY vite.config.js vite.config.js
COPY packages/ui/resources packages/ui/resources

RUN npm run build

FROM php-base AS app

WORKDIR /var/www/html

RUN apk add --no-cache nodejs chromium font-noto font-noto-cjk
ENV FAST_LANDINGS_CHROMIUM_PATH=/usr/bin/chromium

COPY --from=php-dependencies --chown=10001:10001 /workspace/ ./
COPY --from=frontend --chown=10001:10001 /workspace/public/build ./public/build
COPY --from=frontend /workspace/node_modules/playwright ./node_modules/playwright
COPY --from=frontend /workspace/node_modules/playwright-core ./node_modules/playwright-core
COPY --from=frontend /workspace/node_modules/ipaddr.js ./node_modules/ipaddr.js
COPY scripts ./scripts
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-fast-landings.ini
COPY deploy/php-fpm.conf /usr/local/etc/php-fpm.d/zz-fast-landings.conf
COPY deploy/entrypoint.sh /usr/local/bin/fast-landings-entrypoint
COPY deploy/healthcheck.sh /usr/local/bin/fast-landings-healthcheck
COPY deploy/projection-healthcheck.sh /usr/local/bin/projection-healthcheck
COPY deploy/projection-runner.sh /usr/local/bin/projection-runner
COPY deploy/projection-sync.php /opt/fast-landings/projection-sync.php
COPY deploy/caddy-ask.php /opt/fast-landings/caddy-ask.php

RUN chmod 0555 \
        /usr/local/bin/fast-landings-entrypoint \
        /usr/local/bin/fast-landings-healthcheck \
        /usr/local/bin/projection-healthcheck \
        /usr/local/bin/projection-runner \
        /opt/fast-landings/projection-sync.php \
        /opt/fast-landings/caddy-ask.php \
    && chown -R 10001:10001 /var/www/html/storage /var/www/html/bootstrap/cache

USER fastlandings

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/fast-landings-entrypoint"]
CMD ["php-fpm", "-F"]

# Uploaded landing code has a separate image, uid, filesystem and network from
# the panel. Never derive this stage from `app` or copy application secrets.
FROM php:${PHP_VERSION}-fpm-alpine AS landing-php

RUN apk add --no-cache libcurl oniguruma fcgi \
    && apk add --no-cache --virtual .build-dependencies $PHPIZE_DEPS curl-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" curl mbstring opcache \
    && apk del .build-dependencies \
    && addgroup -g 10001 -S landingreaders \
    && adduser -u 10004 -S -D -H -G landingreaders landingphp

COPY deploy/landing-php.ini /usr/local/etc/php/conf.d/zz-landing-runtime.ini
COPY deploy/landing-php-fpm.conf /usr/local/etc/php-fpm.d/zz-landing-runtime.conf
COPY deploy/runtime-bootstrap.php /opt/fast-landings/runtime-bootstrap.php
COPY deploy/landing-php-entrypoint.sh /usr/local/bin/landing-php-entrypoint
COPY deploy/landing-php-healthcheck.sh /usr/local/bin/landing-php-healthcheck

RUN chmod 0555 /usr/local/bin/landing-php-entrypoint /usr/local/bin/landing-php-healthcheck \
    && mkdir -p /srv/landings

WORKDIR /srv/landings
USER landingphp
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/landing-php-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:${NGINX_VERSION} AS web

COPY deploy/nginx.conf /etc/nginx/nginx.conf
COPY --from=app /var/www/html/public /var/www/html/public

RUN rm -rf /etc/nginx/conf.d/*

USER nginx

EXPOSE 8080 8081

ENTRYPOINT ["nginx"]
CMD ["-g", "daemon off;"]
