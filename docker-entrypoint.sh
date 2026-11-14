#!/bin/sh
set -e
# Railway injects $PORT; Apache must listen on it.
PORT="${PORT:-80}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Railway volumes mount as root; make the data dir writable by Apache.
mkdir -p /var/www/html/data
chown -R www-data:www-data /var/www/html/data

exec "$@"
