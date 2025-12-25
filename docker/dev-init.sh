#!/bin/bash
# 开发环境初始化脚本

set -e

cd /var/www/html

echo "=== 安装 PHP 依赖 ==="
composer install --no-interaction

echo "=== 生成 APP_KEY ==="
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

echo "=== 生成 JWT_SECRET ==="
php artisan jwt:secret --force 2>/dev/null || true

echo "=== 运行数据库迁移 ==="
php artisan migrate --force

echo "=== 清理缓存 ==="
php artisan config:clear
php artisan cache:clear

echo "=== 初始化完成 ==="
