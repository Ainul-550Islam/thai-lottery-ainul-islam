# =============================================================================
# Thai Lottery — production PHP-FPM image.
#
# THREE STAGES, AND WHAT EACH ONE IS ALLOWED TO CONTAIN
#   vendor   composer dependencies only (no source) — cached on composer.lock
#   assets   the Vite build, on a Node 24 image (see the note on engines below)
#   runtime  PHP-FPM, the application, the compiled extensions. No compiler, no
#            Node, no npm, no composer, no git, no shell from a package manager.
#
# WHY A SEPARATE NODE STAGE INSTEAD OF `apk add nodejs npm`
# -------------------------------------------------------
# The previous version installed Alpine's `nodejs` and `npm` packages and then
# ran `npm ci`. Alpine tracks Node 22, while `package.json` requires Node
# >=24 <25 and `.npmrc` sets `engine-strict=true` — so `npm ci` refuses to run
# and the image cannot be built at all. Pinning an official Node 24 image makes
# the build reproducible and keeps Node entirely out of the runtime stage.
#
# WHY BASE IMAGES ARE PINNED BY DIGEST AS WELL AS TAG
# ---------------------------------------------------
# A tag is mutable. `php:8.4-fpm-alpine` is a different image next week, which
# makes "the image we tested" an unfalsifiable claim. The tag stays for
# readability; the digest is what is actually pulled.
#
# WHY THE RUNTIME STAGE HAS NO SHELL TOOLING
# ------------------------------------------
# Most PHP RCE payloads assume they can compile or download something. Removing
# the toolchain does not stop the vulnerability; it removes the second half of
# the attack. If a future change needs a compiler, it belongs in a build stage.
# =============================================================================

ARG PHP_VERSION=8.4
ARG PHP_IMAGE=php:${PHP_VERSION}-fpm-alpine@sha256:a49524d3cec4a77d1807d0878e5bb38146116ae5489ec60c367aee53bb9b0699
ARG NODE_IMAGE=node:24-alpine@sha256:06d7adf3e0ef61a13f1d28682726366f97a15aa1888c934d3ecec86c33918b53
ARG COMPOSER_IMAGE=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc

# -----------------------------------------------------------------------------
# Stage 1 — composer dependencies
# -----------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS vendor

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_HOME=/tmp/composer

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        git \
        unzip \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        oniguruma-dev \
        icu-dev \
        postgresql-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        zip \
    # phpredis rather than predis: it is the C extension REDIS_CLIENT=phpredis
    # in .env.example declares, and it is meaningfully faster on the hot path.
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

# DL3022 wants a stage alias, but BuildKit resolves --from against an image
# reference too, and COMPOSER_IMAGE above is digest-pinned. Adding a stage just
# to satisfy the linter would pull composer into the build graph twice.
# hadolint ignore=DL3022
COPY --from=${COMPOSER_IMAGE} /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Only the manifests, so a source edit does not invalidate this layer.
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --classmap-authoritative \
        --no-scripts

# -----------------------------------------------------------------------------
# Stage 2 — front-end assets (Node 24, matching package.json engines)
# -----------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS assets

WORKDIR /var/www/html

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
COPY public ./public

# The same manifest assertion CI runs, so an image cannot be built whose
# manifest disagrees with its assets. Reusing the script instead of an inline
# `node -e` keeps one implementation and stays lintable.
COPY scripts/ci/assert-vite-manifest.mjs ./scripts/ci/assert-vite-manifest.mjs
RUN npm run build \
    && node scripts/ci/assert-vite-manifest.mjs --require-build

# -----------------------------------------------------------------------------
# Stage 3 — runtime
# -----------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS runtime

# Runtime *libraries* only. Note what is absent: no gcc, no make, no git, no
# npm, no composer, no $PHPIZE_DEPS.
RUN apk add --no-cache \
        fcgi \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libzip \
        oniguruma \
        postgresql-libs \
        tini \
    && rm -rf /var/cache/apk/*

COPY --from=vendor /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=vendor /usr/local/etc/php/conf.d /usr/local/lib/php/conf.d-stage1
RUN cp -a /usr/local/lib/php/conf.d-stage1/. /usr/local/etc/php/conf.d/ \
    && rm -rf /usr/local/lib/php/conf.d-stage1 \
    && rm -f /usr/local/etc/php/conf.d/*.ini.bak
COPY --from=vendor /usr/local/bin/docker-php-entrypoint /usr/local/bin/

COPY docker/php/laravel-entrypoint.sh /usr/local/bin/laravel-entrypoint.sh
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf

# The healthcheck talks to the FPM pool over the same socket nginx uses, so it
# fails when the pool is wedged and not merely when the port is open.
RUN printf '#!/bin/sh\nset -eu\nSCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong\n' > /usr/local/bin/php-fpm-healthcheck \
    && chmod 0755 /usr/local/bin/laravel-entrypoint.sh /usr/local/bin/php-fpm-healthcheck \
    && addgroup -g 10001 -S app \
    && adduser -u 10001 -S app -G app -h /var/www/html -s /sbin/nologin

WORKDIR /var/www/html

# Application source (from the build context) plus the build outputs from the
# two earlier stages. Ownership is set on the whole tree and then narrowed to
# the directories Laravel writes to.
COPY --from=vendor --chown=app:app /var/www/html/vendor ./vendor
COPY --chown=app:app . .
COPY --from=assets --chown=app:app /var/www/html/public/build ./public/build

RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/app/backups \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && rm -f .env .env.* \
    && chown -R app:app storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chmod 0640 storage/app/backups 2>/dev/null || true

# Non-root from here on. uid 10001 has no passwd entry it can log in with and no
# shell, so a compromised FPM worker cannot start an interactive session.
# Numeric uid:gid rather than the name, so the effective identity does not depend
# on how the base image resolves /etc/passwd (hadolint DL3066).
USER 10001:10001

# tini reaps zombies: a crashed Horizon supervisor can orphan children and fill
# the container's PID table. laravel-entrypoint.sh resolves *_FILE secrets and
# then execs, so signals reach php-fpm directly.
ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/laravel-entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]

EXPOSE 9000

# SIGQUIT is php-fpm's graceful shutdown: in-flight requests finish, then the
# master exits. Docker's default SIGTERM kills requests mid-flight, which on a
# payment callback is a lost transaction.
STOPSIGNAL SIGQUIT

HEALTHCHECK --interval=15s --timeout=5s --retries=3 --start-period=30s \
    CMD ["/usr/local/bin/php-fpm-healthcheck"]
