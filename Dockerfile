FROM php:8.3-fpm


# Extensiones necesarias
RUN apt-get update \
&& apt-get install -y libicu-dev libzip-dev unzip git \
&& docker-php-ext-install intl pdo pdo_mysql \
&& pecl install redis \
&& docker-php-ext-enable redis

# Xdebug (solo para CLI/tests; xdebug.mode=off por defecto)
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Desactivar por defecto (la función xdebug_get_headers existirá igualmente)
RUN { \
      echo "zend_extension=xdebug"; \
      echo "xdebug.mode=off"; \
      echo "xdebug.start_with_request=default"; \
    } > /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini


# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


WORKDIR /var/www/html
COPY composer.json /var/www/html/
RUN composer install --no-interaction --prefer-dist


COPY . /var/www/html


# Permisos mínimos para storage
RUN mkdir -p storage/logs storage/covers && chown -R www-data:www-data storage


CMD ["php-fpm"]