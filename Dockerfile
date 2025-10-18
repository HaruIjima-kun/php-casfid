FROM php:8.3-fpm


# Extensiones necesarias
RUN apt-get update \
&& apt-get install -y libicu-dev libzip-dev unzip git \
&& docker-php-ext-install intl pdo pdo_mysql \
&& pecl install redis \
&& docker-php-ext-enable redis


# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


WORKDIR /var/www/html
COPY composer.json /var/www/html/
RUN composer install --no-interaction --prefer-dist


COPY . /var/www/html


# Permisos mínimos para storage
RUN mkdir -p storage/logs storage/covers && chown -R www-data:www-data storage


CMD ["php-fpm"]