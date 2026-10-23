# =============================================================================
# multi-stage production image.
#
# STAGE 1 (build)  — compiles PHP extensions, installs composer deps WITHOUT
#                    dev packages, builds Vite assets. Needs a toolchain.
# STAGE 2 (runtime)— receives ONLY the built artifacts. No compiler, no git, no
#                    npm, no composer, no shell access to a package manager.
# =============================================================================

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ─────────────────────────────────────────────────────────────────────────────
# STAGE 0 — node (pinned, reproducible)
# ─────────────────────────────────────────────────────────────────────────────
FROM node:${NODE_VERSION}-alpine AS node

# ─────────────────────────────────────────────────────────────────────────────
# STAGE 1 — build
# ─────────────────────────────────────────────────────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS build

RUN apk add --no-cache \
        $PHPIZE_DEPS \
        git \
        unzip \
        libpng-dev \
        libzip-dev \
        oniguruma-dev \
        icu-dev \
        postgresql-dev \
        linux-headers

RUN docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        zip

RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/lib/node_modules /usr/lib/node_modules
COPY --from=node /usr/local/bin/node /usr/local/bin/node
RUN ln -sf /usr/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --classmap-authoritative \
        --no-scripts

COPY package.json package-lock.json vite.config.js tailwind.config.js postcss.config.js ./
RUN npm ci --no-audit --no-fund

COPY resources ./resources
COPY public ./public
RUN npm run build

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ─────────────────────────────────────────────────────────────────────────────
# STAGE 2 — runtime
# ─────────────────────────────────────────────────────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS runtime

RUN apk add --no-cache \
        icu-libs \
        libzip \
        oniguruma \
        postgresql-libs \
        tini \
        fcgi \
    && rm -rf /var/cache/apk/*

COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=build /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d

COPY docker/php/php-fpm-healthcheck.sh /usr/local/bin/php-fpm-healthcheck
RUN chmod +x /usr/local/bin/php-fpm-healthcheck \
    && echo "ping.path = /ping" >> /usr/local/etc/php-fpm.d/docker.conf \
    && echo "ping.response = pong" >> /usr/local/etc/php-fpm.d/docker.conf

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf

COPY docker/php/laravel-entrypoint.sh /usr/local/bin/laravel-entrypoint
RUN chmod +x /usr/local/bin/laravel-entrypoint

COPY docker/php/export-assets.sh /usr/local/bin/export-assets
RUN chmod +x /usr/local/bin/export-assets

RUN addgroup -g 10001 -S app \
    && adduser -u 10001 -S app -G app -h /var/www/html -s /sbin/nologin

WORKDIR /var/www/html

COPY --from=build --chown=app:app /var/www/html /var/www/html

RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
        /var/www/html/public/build-export \
    && chown -R app:app storage bootstrap/cache /var/www/html/public/build-export \
    && chmod -R ug+rwX storage bootstrap/cache

USER app

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/laravel-entrypoint"]
CMD ["php-fpm", "--nodaemonize"]

EXPOSE 9000

HEALTHCHECK --interval=15s --timeout=5s --retries=3 --start-period=30s \
    CMD php-fpm-healthcheck || exit 1
