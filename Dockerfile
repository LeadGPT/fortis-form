FROM php:8.3-apache

COPY . /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# Exactly one MPM (prefork, required for mod_php): remove every MPM symlink, then link prefork only.
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && ln -s ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
    && ln -s ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
    && a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && ls -l /etc/apache2/mods-enabled | grep -i mpm \
    && apache2ctl -t

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
