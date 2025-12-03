FROM php:8.4-cli-alpine

# Install system dependencies
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

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html/

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts && \
    composer dump-autoload --optimize --classmap-authoritative

COPY docker/php-worker/php.ini /usr/local/etc/php/php.ini
COPY docker/php-worker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php-worker/cron /etc/cron.d/cron
COPY docker/php-worker/entrypoint.sh /entrypoint.sh

RUN chmod 0644 /etc/cron.d/cron && \
    chmod +x /entrypoint.sh && \
    crontab /etc/cron.d/cron && \
    touch /var/log/cron.log && \
    mkdir -p /var/log/supervisor && \
    chown -R www-data:www-data /var/www/html /var/log/supervisor /var/log/cron.log

USER root

ENTRYPOINT ["/entrypoint.sh"]

