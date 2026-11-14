FROM php:8.3-apache

COPY . /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && (a2dismod -f mpm_event mpm_worker mpm_prefork || true) \
    && a2enmod mpm_prefork rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
