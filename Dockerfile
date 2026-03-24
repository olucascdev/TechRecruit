FROM node:20-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json tailwind.config.js ./
COPY src ./src
COPY public ./public

RUN npm ci && npm run build:css

FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" curl gd mbstring pdo_mysql xml zip \
    && a2enmod headers remoteip rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

COPY . ./
COPY --from=assets /app/public/assets/app.css /var/www/html/public/assets/app.css
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/app.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/entrypoint.sh /usr/local/bin/techrecruit-entrypoint

RUN chmod +x /usr/local/bin/techrecruit-entrypoint \
    && mkdir -p storage/imports storage/portal-documents \
    && chown -R www-data:www-data storage

EXPOSE 80

ENTRYPOINT ["techrecruit-entrypoint"]
CMD ["apache2-foreground"]
