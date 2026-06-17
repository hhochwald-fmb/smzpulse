FROM php:8.2-apache

# .htaccess-Dateien werden für die Sicherheit benötigt
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# PHP-Upload-Limits für Bilder (20 MB) und Logo (5 MB)
RUN { \
        echo 'upload_max_filesize = 25M'; \
        echo 'post_max_size = 30M'; \
        echo 'memory_limit = 128M'; \
        echo 'opcache.enable=0'; \
    } > /usr/local/etc/php/conf.d/smzpulse.ini

# Anwendungscode ins Image kopieren
COPY . /var/www/html/

# www-data bekommt Schreibrecht auf die Datendateien
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80
