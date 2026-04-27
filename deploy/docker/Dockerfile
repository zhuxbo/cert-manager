FROM php:8.3-fpm-alpine

# === Alpine 镜像源（中国用户取消注释）===
# __ALPINE_MIRROR__

# 安装系统依赖
RUN apk add --no-cache \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    curl-dev \
    openssl-dev \
    linux-headers \
    mysql-client \
    $PHPIZE_DEPS

# 安装 PHP 扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        intl \
        bcmath \
        pcntl \
        opcache \
        exif \
        calendar

# 安装 Redis 扩展
RUN pecl install redis && docker-php-ext-enable redis

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# === Composer 镜像源（中国用户取消注释）===
# __COMPOSER_MIRROR__

# 设置工作目录
WORKDIR /var/www/html

# 设置权限
RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
