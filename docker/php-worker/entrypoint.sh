#!/bin/sh

cd /var/www/html

php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache cleared on startup\n'; }"

php artisan cache:clear || true
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

rm -f bootstrap/cache/config.php bootstrap/cache/routes*.php bootstrap/cache/services.php || true

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
