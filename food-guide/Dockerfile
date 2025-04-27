# 使用官方 PHP 映像，包含 Apache（Laravel 常用）
FROM php:8.2-apache

# 安裝必要的 PHP 擴展和工具
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql

# 安裝 Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 設置工作目錄
WORKDIR /var/www/html

# 複製專案檔案到容器
COPY . .

# 安裝 Laravel 依賴
RUN composer install --no-dev --optimize-autoloader

# 設置 Apache 配置文件，指向 Laravel 的 public 目錄
RUN echo "<Directory /var/www/html/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
    </Directory>\n\
    DocumentRoot /var/www/html/public" > /etc/apache2/sites-available/000-default.conf

# 啟用 Apache 的 rewrite 模組（Laravel 路由需要）
RUN a2enmod rewrite

# 設置環境變數
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

# 設置權限
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 暴露端口
EXPOSE 8080

# 啟動 Apache
CMD ["apache2-foreground"]