FROM composer:2.2 AS composer
FROM php:7.4.33-fpm-bullseye AS app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libcurl4-openssl-dev libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 bcmath mbstring mysqli pdo_mysql gd zip pcntl sockets opcache curl \
    && pecl install redis-5.3.7 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www
COPY crmeb/ ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && composer check-platform-reqs --no-dev \
    && mkdir -p runtime public/uploads public/statics backup \
    && touch public/install.lock \
    && chown -R www-data:www-data runtime public/uploads public/statics backup
COPY deploy/production/php.ini /usr/local/etc/php/conf.d/production.ini
COPY deploy/production/entrypoint.sh /usr/local/bin/crmeb-entrypoint
RUN chmod 755 /usr/local/bin/crmeb-entrypoint && printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-env.conf
ENTRYPOINT ["/usr/local/bin/crmeb-entrypoint"]
CMD ["php-fpm", "-F"]
FROM nginx:1.28 AS web
COPY --from=app /var/www/public /var/www/public
COPY deploy/production/nginx.conf /etc/nginx/conf.d/default.conf
