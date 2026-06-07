# 开发用 PHP 8.3 CLI 镜像（仅开发环境，与生产/打包无关）
# 跑 backend：composer install + artisan serve + 测试。代码通过 bind mount 注入，
# 因此这里不 COPY 代码、不在构建期 composer install（会被挂载覆盖），依赖安装放在 entrypoint。

# PHP 版本可切换：默认 8.4（贴合生产），测 8.3 兼容用 `PHP_VERSION=8.3 make rebuild`
ARG PHP_VERSION=8.4
ARG TONGSUO_VERSION=8.4.0

# ---- 阶段 1：编译 Tongsuo（铜锁）国密 openssl —— SM2/SM3/SM4 + 国密双证书 NTLS ----
# 仅开发/CI 用：本地生成 SM2 签名 CSR + 探测国密能力（BinaryLocator::gmOpenssl() 命中 /usr/local/tongsuo/bin/openssl）。
# 装到独立 prefix 与系统 libssl 隔离：PHP 的 openssl_* 仍走系统 OpenSSL 处理 RSA/ECDSA，互不影响。
# install_sw 装软件、install_ssldirs 补默认 openssl.cnf（缺它 `openssl req` 生成 CSR 会报无配置文件）。
FROM debian:bookworm-slim AS tongsuo
ARG TONGSUO_VERSION
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential perl libtext-template-perl wget ca-certificates \
    && wget -qO /tmp/tongsuo.tar.gz "https://github.com/Tongsuo-Project/Tongsuo/archive/refs/tags/${TONGSUO_VERSION}.tar.gz" \
    && tar xf /tmp/tongsuo.tar.gz -C /tmp \
    && cd "/tmp/Tongsuo-${TONGSUO_VERSION}" \
    && ./config --prefix=/usr/local/tongsuo --libdir=lib --openssldir=/usr/local/tongsuo/ssl \
        enable-ntls -Wl,-rpath,/usr/local/tongsuo/lib \
    && make -j"$(nproc)" \
    && make install_sw install_ssldirs \
    && /usr/local/tongsuo/bin/openssl version \
    && /usr/local/tongsuo/bin/openssl ecparam -name SM2 -genkey -noout >/dev/null \
    && rm -rf /tmp/* /usr/local/tongsuo/ssl/misc

# ---- 阶段 2：开发后端镜像 ----
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

# 2b. 国密 openssl（Tongsuo，从阶段 1 拷贝）→ /usr/local/tongsuo/bin/openssl，命中 BinaryLocator::gmOpenssl() 候选路径
COPY --from=tongsuo /usr/local/tongsuo /usr/local/tongsuo

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
