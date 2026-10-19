# PHP-FPM image.
#
# Build arg PHP_INI selects which ini file is baked in:
#   php.ini      (default, local dev: display_errors on, opcache revalidates)
#   php.prod.ini (production: display_errors off, opcache validate_timestamps=0)
#
# Build:  docker compose build
#   prod: docker compose -f docker-compose.yml -f docker-compose.prod.yml build
FROM php:8.5-fpm

# git + unzip : composer needs them to fetch and extract packages
# libzip-dev  : headers for ext-zip (pulls in the libzip runtime, which we keep)
#
# mbstring is compiled into the official php:8.5 images, so there is nothing to
# install for it — but composer.json requires ext-mbstring, so assert it is
# there rather than discovering a base-image change at runtime.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql zip pcntl; \
    php -m | grep -qi '^pcntl$'; \
    php -m | grep -qi '^mbstring$'; \
    rm -rf /var/lib/apt/lists/*

# Composer 2, copied from the official image (no installer script to verify).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# php.ini vs php.prod.ini, chosen by the build arg.
ARG PHP_INI=php.ini
COPY ${PHP_INI} /usr/local/etc/php/php.ini

WORKDIR /var/www
