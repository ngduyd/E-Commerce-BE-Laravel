FROM php:8.2-fpm

# Cài đặt các extension cần thiết
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpng-dev libonig-dev libxml2-dev libssl-dev pkg-config libsasl2-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

# Cài Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Tạo thư mục làm việc
WORKDIR /var/www

# Copy mã nguồn vào image
COPY . .

# Cài đặt các package PHP (sản xuất)
RUN composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-mongodb

# Cấp quyền cho Laravel
RUN chmod -R 775 storage bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache

# Clear & cache config
RUN php artisan config:clear \
 && php artisan config:cache \
 && php artisan lighthouse:clear-cache

CMD php artisan serve --host=0.0.0.0 --port=10000

