FROM php:8.2-fpm-bookworm

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libicu-dev \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd pdo_mysql zip intl pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint-laravel.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-laravel.sh

# PHP-FPM no pasa las variables de docker-compose a los workers; env[] sí llega a Laravel (env(), VIEW_COMPILED_PATH).
RUN sed -i '/^\[www\]/a env[VIEW_COMPILED_PATH] = /tmp/laravel-views' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-laravel.sh"]
CMD ["php-fpm"]
