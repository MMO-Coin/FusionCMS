#!/bin/sh
set -eu

php /var/www/html/docker/state.php prepare /var/www/html /opt/fusioncms/defaults /var/lib/fusioncms
mkdir -p /var/www/html/writable/cache /var/www/html/writable/backups \
    /var/www/html/writable/logs /var/www/html/writable/uploads /var/www/html/writable/install
chown -R www-data:www-data /var/www/html/application/config /var/www/html/writable /var/lib/fusioncms

exec docker-php-entrypoint "$@"
