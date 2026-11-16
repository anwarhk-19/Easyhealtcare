FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libpng-dev \
    && docker-php-ext-install pdo_mysql intl zip gd bcmath pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/backend
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader
COPY backend/ ./
RUN composer dump-autoload --optimize && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8000
