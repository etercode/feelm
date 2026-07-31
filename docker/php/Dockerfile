# PHP-FPM on Alpine. The version tracks what the latest Symfony needs (>= 8.4).
FROM php:8.5.7-fpm-alpine3.24 AS base

RUN apk add --no-cache \
        git \
        unzip \
        bash

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# intl is Symfony-recommended; pdo_pgsql for Postgres; gd redraws uploaded
# avatars server-side; the rest are standard.
RUN install-php-extensions \
        intl \
        opcache \
        zip \
        gd \
        pdo_pgsql

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'realpath_cache_ttl=600'; \
        echo 'memory_limit=256M'; \
        # Avatars and cover uploads stay small; PHP's ceiling sits above the
        # app's own validation so oversized files get a clean 422, not a 413.
        echo 'upload_max_filesize=12M'; \
        echo 'post_max_size=16M'; \
    } > /usr/local/etc/php/conf.d/symfony.ini

WORKDIR /var/www/app

# Local dev: the code is bind-mounted from the host.
FROM base AS dev

EXPOSE 9000
CMD ["php-fpm"]

# Production: the app is baked into the image.
FROM base AS prod

COPY composer.json composer.lock symfony.lock ./
COPY bin/ bin/
COPY config/ config/
COPY migrations/ migrations/
COPY public/ public/
COPY scripts/ scripts/
COPY src/ src/
COPY .env .env

ENV APP_ENV=prod

# var/ holds the cache and logs; data/crawl is where the crawler keeps its
# cursor and public/media is where it puts posters. All four are written at
# runtime by www-data, so they have to exist and be owned before FPM drops
# privileges — otherwise the first crawl fails on an unwritable directory.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
    && mkdir -p var/cache var/log data/crawl public/media \
    && chown -R www-data:www-data var data public/media

EXPOSE 9000
CMD ["php-fpm"]
