#
# PHP Dependencies Stage
#
FROM composer:2.7 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --ignore-platform-reqs \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --prefer-dist \
    --no-dev

#
# Application Stage
#
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    curl \
    unzip

RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-configure intl && \
    docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    pgsql \
    gd \
    zip \
    intl

RUN apk add --no-cache pcre-dev $PHPIZE_DEPS && \
    pecl install redis && \
    docker-php-ext-enable redis && \
    apk del pcre-dev $PHPIZE_DEPS


WORKDIR /var/www/html

COPY --from=vendor /app/vendor/ /var/www/html/vendor/

COPY . /var/www/html/

COPY docker/php/php.ini /usr/local/etc/php/php.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
