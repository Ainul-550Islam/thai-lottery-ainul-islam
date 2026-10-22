FROM php:8.3-fpm-alpine

# Install system build dependencies and runtime libraries
RUN apk add --no-cache \
    curl \
    git \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install high-performance PHP extensions for financial processing
RUN docker-php-ext-install -j$(nproc) \
    bcmath \
    pdo_mysql \
    opcache \
    pcntl \
    intl \
    mbstring \
    zip

# Install and enable Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Configure OPcache and PHP production runtime
COPY ./docker/php-fpm/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY ./docker/php-fpm/php.ini /usr/local/etc/php/conf.d/custom.ini

# Set working directory
WORKDIR /var/www/html

# Create non-root application user
RUN addgroup -g 1000 -S www && \
    adduser -u 1000 -S www -G www

USER www

EXPOSE 9000

CMD ["php-fpm"]
