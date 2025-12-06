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
FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    supervisor \
    curl \
    unzip \
    dcron

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

COPY docker/php-worker/php.ini /usr/local/etc/php/php.ini
COPY docker/php-worker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php-worker/cron /etc/cron.d/cron

RUN chmod 0644 /etc/cron.d/cron && \
    crontab /etc/cron.d/cron && \
    touch /var/log/cron.log && \
    mkdir -p /var/log/supervisor && \
    chown -R www-data:www-data /var/www/html /var/log/supervisor /var/log/cron.log

USER root

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
