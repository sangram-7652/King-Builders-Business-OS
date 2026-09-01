# syntax=docker/dockerfile:1

###############################################################################
# King Builders Business OS — PHP-FPM image
#
# Single image used by the `app`, `queue` and `scheduler` services.
# The runtime command is supplied by docker-compose, not baked in here.
###############################################################################
FROM php:8.3-fpm-bookworm AS base

LABEL org.opencontainers.image.title="king-builders-business-os" \
      org.opencontainers.image.description="Laravel PHP-FPM runtime for King Builders Business OS"

# --- System packages -------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions ------------------------------------------------------
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip

# Redis extension (phpredis) via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# --- Composer ------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Non-root user matching the host UID/GID (keeps mounted files sane) --
ARG WWWUID=1000
ARG WWWGID=1000
RUN groupmod -o -g "${WWWGID}" www-data \
    && usermod  -o -u "${WWWUID}" -g "${WWWGID}" www-data \
    && mkdir -p /var/www/html /home/www-data/.config \
    && chown -R www-data:www-data /var/www/html /home/www-data

# Give the non-root user a real, writable HOME (Composer cache, psysh history…)
ENV HOME=/home/www-data \
    COMPOSER_HOME=/home/www-data/.composer

# --- PHP configuration -------------------------------------------------------
COPY docker/php/php.ini       /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini   /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

# Dependencies first for better layer caching, then the full source.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
