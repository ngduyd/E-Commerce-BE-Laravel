FROM php:8.2-fpm

# Cài PHP extension cần thiết
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpng-dev libonig-dev libxml2-dev libssl-dev pkg-config libsasl2-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

# Cài composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

# Cài Laravel dependencies
RUN composer install --optimize-autoloader --no-dev

# Cấp quyền cho Laravel
RUN chmod -R 775 storage bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

# Cache config (nếu .env đã set đúng)
RUN php artisan config:clear && php artisan config:cache

# Không migrate, không seed
CMD php-fpm
