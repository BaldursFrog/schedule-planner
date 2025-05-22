FROM php:8.3-fpm-alpine

WORKDIR /var/www

RUN apk add --no-cache \
    curl \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    libxml2-dev 

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_mysql \ 
    zip \
    intl \
    gd \
    bcmath \
    opcache \
    exif \
    pcntl \
    mbstring \
    xml # Добавил xml, часто полезен

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

RUN composer install --no-interaction --no-plugins --no-scripts --prefer-dist --no-dev --optimize-autoloader

COPY . .

RUN chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

# RUN php artisan config:cache && \
#     php artisan route:cache && \
#     php artisan view:cache

EXPOSE 9000

CMD ["php-fpm", "-F", "--pid", "/tmp/php-fpm.pid"]
