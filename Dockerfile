FROM php:8.2-apache

# .htaccess-Dateien werden für die Sicherheit benötigt
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# zip-Erweiterung für das Online-Update im Admin-Bereich
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

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
RUN mkdir -p /var/www/html/storage/backups /var/www/html/storage/tmp \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80
