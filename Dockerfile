FROM php:8.2-fpm

# Cài các extension cần thiết
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpng-dev libonig-dev libxml2-dev libssl-dev pkg-config libsasl2-dev \
    ca-certificates nginx supervisor \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

# Cài Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# App code
WORKDIR /var/www
COPY . .

# Quyền
RUN chmod -R 775 storage bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache

# Cài Laravel
RUN composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-mongodb \
    && php artisan config:clear && php artisan config:cache

# Copy nginx config
COPY nginx.conf /etc/nginx/nginx.conf

# Khởi động cả nginx + php-fpm
CMD php-fpm -D && nginx -g 'daemon off;'

