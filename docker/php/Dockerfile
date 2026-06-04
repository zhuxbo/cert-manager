# 开发用 PHP 8.3 CLI 镜像（仅开发环境，与生产/打包无关）
# 跑 backend：composer install + artisan serve + 测试。代码通过 bind mount 注入，
# 因此这里不 COPY 代码、不在构建期 composer install（会被挂载覆盖），依赖安装放在 entrypoint。

# PHP 版本可切换：默认 8.4（贴合生产），测 8.3 兼容用 `PHP_VERSION=8.3 make rebuild`
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-bookworm

LABEL description="ssl-manager dev backend"

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Asia/Shanghai \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# 1. 系统依赖（PHP 扩展编译所需 + 常用工具）
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip default-mysql-client \
        libicu-dev libzip-dev libonig-dev libxml2-dev \
        libpng-dev libjpeg-dev libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# 2. PHP 扩展（对齐 composer.json require + CI：bcmath/calendar/gd/intl/mbstring/pcntl/pdo_mysql/zip/exif + redis）
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath calendar exif gd intl mbstring pcntl pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis

# 3. Composer（2.8+，来自官方镜像）+ 阿里云镜像加速
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/

# 4. 启用主 php.ini（官方镜像默认无主 ini → php_ini_loaded_file() 为 false）+ 开发调整
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" && { \
        echo "memory_limit=512M"; \
        echo "upload_max_filesize=20M"; \
        echo "post_max_size=20M"; \
        echo "date.timezone=Asia/Shanghai"; \
    } >"$PHP_INI_DIR/conf.d/zz-dev.ini"

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
