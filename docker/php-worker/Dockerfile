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

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-configure intl && \
    docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    pgsql \
    gd \
    zip \
    intl

# Install Redis extension
RUN apk add --no-cache pcre-dev $PHPIZE_DEPS && \
    pecl install redis && \
    docker-php-ext-enable redis && \
    apk del pcre-dev $PHPIZE_DEPS

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first (for better layer caching)
COPY composer.json composer.lock ./

# Install production dependencies with cache mount
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy application files (after composer install for better caching)
COPY . /var/www/html/

# Copy configurations
COPY docker/php-worker/php.ini /usr/local/etc/php/php.ini
COPY docker/php-worker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php-worker/cron /etc/cron.d/cron

# Set permissions
RUN chmod 0644 /etc/cron.d/cron && \
    crontab /etc/cron.d/cron && \
    touch /var/log/cron.log && \
    mkdir -p /var/log/supervisor && \
    chown -R www-data:www-data /var/www/html /var/log/supervisor /var/log/cron.log

# Switch to root for supervisor and cron
USER root

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

