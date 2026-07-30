# 开发用 PHP 8.3 CLI 镜像（仅开发环境，与生产/打包无关）
# 跑 backend：composer install + artisan serve + 测试。代码通过 bind mount 注入，
# 因此这里不 COPY 代码、不在构建期 composer install（会被挂载覆盖），依赖安装放在 entrypoint。

# PHP 版本可切换：默认 8.4（贴合生产），测 8.3 兼容用 `PHP_VERSION=8.3 make rebuild`
ARG PHP_VERSION=8.4

# 开发后端镜像（系统 OpenSSL 3.0+ 原生支持 SM2，国密 CSR 生成统一走系统 openssl，与 CI ubuntu-latest 一致）
FROM php:${PHP_VERSION}-cli-bookworm

LABEL description="ssl-manager dev backend"

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Asia/Shanghai \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# 1. 系统依赖（PHP 扩展编译所需 + 常用工具）
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip default-mysql-client jq default-jre-headless \
        libicu-dev libzip-dev libonig-dev libxml2-dev \
        libpng-dev libjpeg-dev libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# 2. PHP 扩展（对齐 composer.json require + CI：bcmath/calendar/gd/intl/mbstring/pcntl/pdo_mysql/zip/exif + redis）
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath calendar exif gd intl mbstring pcntl pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis

# 2b. 覆盖率驱动 pcov：仅供资金核心变异门禁（composer test:mutate）用。
#     默认 pcov.enabled=0，不给普通 `make test` 加每行 hook 开销；变异跑时由 backend/scripts/test-mutate.sh
#     临时写 conf.d 开启（pest --parallel 各 worker 是独立进程，只有 conf.d 能让 worker 也开 pcov）。
#     装进镜像使门禁开箱即跑，免去发布时临时 pecl（宿主机无 php/composer）。
RUN pecl install pcov \
    && docker-php-ext-enable pcov \
    && { \
        echo "pcov.enabled=0"; \
        echo "pcov.directory=/var/www"; \
    } >"$PHP_INI_DIR/conf.d/zz-pcov.ini"

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
