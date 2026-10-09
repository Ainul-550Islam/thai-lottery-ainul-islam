# =============================================================================
# multi-stage production image.
#
# STAGE 1 (build)  — compiles PHP extensions, installs composer deps WITHOUT
#                    dev packages, builds Vite assets. Needs a toolchain.
# STAGE 2 (runtime)— receives ONLY the built artifacts. No compiler, no git, no
#                    npm, no composer, no shell access to a package manager.
#
# WHY MULTI-STAGE AND NOT "COPY EVERYTHING"
# A single-stage image ships gcc, node, npm, and the full composer cache into
# production. That is attack surface with no benefit: none of it is needed to
# serve a request. Splitting means the runtime image has no working compiler,
# which is a real mitigation — most PHP RCE payloads assume they can compile.
# =============================================================================

ARG PHP_VERSION=8.4

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
        nodejs \
        npm

RUN docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        zip

# Redis for cache/queue/locks. phpredis rather than predis: it is a C
# extension, an order of magnitude faster, and it is what REDIS_CLIENT=phpredis
# in .env.example already declares.
RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependency layer first so application edits do not invalidate composer cache.
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
    # No gcc, no $PHPIZE_DEPS, no make, no git. If a future step needs to
    # compile, it belongs in the build stage.
    && rm -rf /var/cache/apk/*

COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=build /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=build /usr/local/bin/docker-php-entrypoint /usr/local/bin/

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf

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
    && chown -R app:app storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

USER app

# tini reaps zombies: php-fpm spawns and reaps children, but a worker that
# double-forks (or a crashed Horizon supervisor) can orphan processes and fill
# the PID table of the container.
ENTRYPOINT ["/sbin/tini", "--", "docker-php-entrypoint"]
CMD ["php-fpm", "--nodaemonize"]

EXPOSE 9000

HEALTHCHECK --interval=15s --timeout=5s --retries=3 --start-period=30s \
    CMD php-fpm-healthcheck || exit 1
