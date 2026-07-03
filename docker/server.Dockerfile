FROM php:8.2-fpm

RUN mkdir -p /var/www/server/vendor

WORKDIR /var/www/server

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Xdebug but DO NOT auto-enable it at runtime. Loading Xdebug (even
# in coverage mode) overrides zend_execute_ex() and forces OPcache's JIT to
# turn itself off. For coverage runs, load Xdebug explicitly on the command
# line (see composer.json's "test:coverage" script):
#   php -dzend_extension=xdebug.so -dxdebug.mode=coverage artisan test --coverage
RUN pecl install xdebug

RUN apt-get update \
    && apt-get install -y build-essential \
    && apt-get install -y procps \
    telnet \
    iputils-ping \
    && apt-get install -y libzip-dev \
    && apt-get install -y openssl libssl-dev \
    && apt-get install -y libcurl4-openssl-dev \
    && apt-get install -y supervisor \
    && docker-php-ext-install pdo_mysql mysqli \
    && docker-php-ext-install pcntl \
    && docker-php-ext-install opcache \
    && docker-php-ext-install zip \
    && docker-php-ext-install bcmath \
    && apt-get clean; rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

ENV COMPOSER_ALLOW_SUPERUSER=1

# Copy existing application directory contents
COPY --chown=www-data:www-data . /var/www/server

# Sadece "worker" container'ında kullanılır (docker-compose.yml'de
# `command: supervisord ...` ile override edilir); "server" container'ı
# bunu hiç çalıştırmaz, varsayılan php-fpm CMD'si geçerli kalır. Horizon
# VE scheduler aynı container'da supervisord altında iki ayrı process
# olarak çalışır — bkz. docker/supervisord/horizon.conf.
RUN cp docker/supervisord/supervisord.conf /etc/supervisor/supervisord.conf \
    && cp docker/supervisord/horizon.conf /etc/supervisor/conf.d/horizon.conf

RUN composer install

EXPOSE 9000
