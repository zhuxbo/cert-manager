#!/bin/bash

# 后端构建脚本
# 使用 Composer 安装生产环境依赖

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }

# 从环境变量获取路径
WORKSPACE_DIR="${WORKSPACE_DIR:-/workspace}"
BACKEND_DIR="$WORKSPACE_DIR/backend"

# 检查构建标志
if [ "${BUILD_BACKEND:-false}" != "true" ]; then
    log_info "后端无需构建，跳过"
    exit 0
fi

if [ ! -d "$BACKEND_DIR" ]; then
    log_error "后端目录不存在: $BACKEND_DIR"
    exit 1
fi

log_info "开始构建后端..."

cd "$BACKEND_DIR"

# 锁文件级别的智能安装跳过
mkdir -p /workspace/.dep_hashes
LOCK_FILE="composer.lock"
HASH_FILE="/workspace/.dep_hashes/backend-composer.lock.sha256"

CURRENT_HASH="missing"
if [ -f "$LOCK_FILE" ]; then
    CURRENT_HASH=$(sha256sum "$LOCK_FILE" | awk '{print $1}')
fi
PREV_HASH=""
[ -f "$HASH_FILE" ] && PREV_HASH=$(cat "$HASH_FILE" 2>/dev/null || echo "")

NEED_INSTALL=false
if [ ! -d "vendor" ]; then
    NEED_INSTALL=true
    log_info "检测到 vendor 不存在，将执行 composer install"
elif [ "$CURRENT_HASH" != "$PREV_HASH" ]; then
    NEED_INSTALL=true
    log_info "composer.lock 发生变更，将执行 composer install"
elif [ "${FORCE_BUILD:-false}" = "true" ]; then
    NEED_INSTALL=true
    log_info "强制构建模式，将执行 composer install"
else
    log_success "composer.lock 未变且 vendor 已存在，跳过依赖安装"
fi

if [ "$NEED_INSTALL" = true ]; then
    log_info "安装 Composer 生产环境依赖..."
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "$CURRENT_HASH" >"$HASH_FILE"
fi

# 清理开发环境的包发现缓存，避免带入生产环境
# 部署脚本会重新生成
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

# 确保 bootstrap/cache 目录存在（部署时需要可写）
mkdir -p bootstrap/cache
# 添加 .keep 保持目录结构（.gitignore 会被 rsync 排除）
touch bootstrap/cache/.keep

# 验证构建结果
if [ -d "vendor" ]; then
    VENDOR_SIZE=$(du -sh vendor | cut -f1)
    VENDOR_PACKAGES=$(find vendor -name "composer.json" | wc -l)
    log_info "Vendor 大小: $VENDOR_SIZE"
    log_info "安装包数量: $VENDOR_PACKAGES"
else
    log_error "后端构建失败：vendor 目录不存在"
    exit 1
fi
