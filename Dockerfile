FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        libgmp-dev libicu-dev libxml2-dev libzip-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd gmp intl mysqli soap zip opcache \
    && a2enmod rewrite headers expires deflate filter \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

COPY . .
# Never copy an installed site's database credentials into the image.
COPY docker/Database.php application/config/Database.php
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/fusioncms.ini
COPY docker/entrypoint.sh /usr/local/bin/fusioncms-entrypoint
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && php docker/state.php snapshot /var/www/html /opt/fusioncms/defaults /var/lib/fusioncms \
    && chmod +x /usr/local/bin/fusioncms-entrypoint \
    && mkdir -p writable/cache writable/backups writable/logs writable/uploads writable/install temp \
    && chown -Rh www-data:www-data application/modules application/themes writable temp \
    && composer check-platform-reqs --no-dev

ENTRYPOINT ["fusioncms-entrypoint"]
CMD ["apache2-foreground"]
HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=6 \
    CMD php -r '$s = @fsockopen("127.0.0.1", 80); exit($s ? 0 : 1);'
