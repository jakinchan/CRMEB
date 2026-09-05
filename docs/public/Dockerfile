FROM composer:2.2 AS composer_bin
FROM php:7.4.33-fpm-bullseye
COPY --from=composer_bin /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 bcmath mbstring mysqli pdo_mysql gd zip pcntl sockets opcache \
    && pecl install redis-5.3.7 \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
RUN printf 'upload_max_filesize=100M\npost_max_size=100M\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/crmeb.ini
WORKDIR /var/www
CMD ["php-fpm"]
