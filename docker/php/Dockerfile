FROM php:8.1-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip git \
 && docker-php-ext-install pdo_mysql mysqli intl zip \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/99-dev.ini
COPY vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
