FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libfreetype6-dev libicu-dev libjpeg62-turbo-dev libpng-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 gd intl mysqli opcache pdo_mysql zip \
    && curl -fsSL https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz -o /tmp/ioncube.tar.gz \
    && tar -xzf /tmp/ioncube.tar.gz -C /tmp \
    && cp /tmp/ioncube/ioncube_loader_lin_8.3.so "$(php-config --extension-dir)/ioncube_loader.so" \
    && rm -rf /var/lib/apt/lists/* /tmp/ioncube /tmp/ioncube.tar.gz

RUN a2enmod rewrite \
    && printf '%s\n' 'zend_extension=ioncube_loader.so' > /usr/local/etc/php/conf.d/00-ioncube.ini \
    && printf '%s\n' \
        'max_execution_time=60' \
        'memory_limit=256M' \
        'opcache.enable=1' \
        'opcache.enable_cli=1' \
        > /usr/local/etc/php/conf.d/whmcs.ini

COPY container-init.sh /usr/local/bin/pluginupdater-container-init
RUN chmod 0755 /usr/local/bin/pluginupdater-container-init

ENTRYPOINT ["pluginupdater-container-init"]
CMD ["apache2-foreground"]
