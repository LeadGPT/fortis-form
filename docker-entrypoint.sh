#!/bin/sh
set -e

# Railway re-enables extra Apache MPMs at container start, so force exactly one
# (prefork, required for mod_php) at runtime, not just at build time.
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
ln -s ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -s ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# Railway injects $PORT; Apache must listen on it.
PORT="${PORT:-80}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Railway volumes mount as root; make the data dir writable by Apache.
mkdir -p /var/www/html/data
chown -R www-data:www-data /var/www/html/data

exec "$@"
