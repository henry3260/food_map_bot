FROM php:8.2-cli

# 安裝必要套件
RUN apt-get update && apt-get install -y unzip git libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip

# 安裝 Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# 複製 Laravel 專案
COPY . .

# 安裝 Laravel 依賴
RUN composer install --no-dev --optimize-autoloader

# 開放 port 8000
EXPOSE 8000

# 使用 Artisan Serve 啟動
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
