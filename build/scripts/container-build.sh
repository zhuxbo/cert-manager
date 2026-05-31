#!/bin/bash

# Monorepo 构建系统 - 容器内主构建脚本
# 从 /source (只读挂载的 monorepo) 构建，输出到 /workspace

set -Eeuo pipefail
trap 'echo -e "\033[0;31m[ERROR]\033[0m 容器内命令失败: ${BASH_COMMAND} (行号: ${LINENO})"' ERR

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# 日志函数
log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_step() { echo -e "${CYAN}[STEP]${NC} $1"; }

# 配置文件路径
CONFIG_FILE="/build/config.json"
CUSTOM_DIR="/build/custom" # 定制目录（可选挂载）
SOURCE_DIR="/source"       # monorepo 根目录（只读挂载）
WORKSPACE_DIR="/workspace" # 工作目录（可写）
PRODUCTION_DIR="/workspace/production-code"

# 解析环境变量
BUILD_MODULE="${BUILD_MODULE:-all}"
BUILD_VERSION="${BUILD_VERSION:-}"
RELEASE_CHANNEL="${RELEASE_CHANNEL:-}"
FORCE_BUILD="${FORCE_BUILD:-false}"

# 构建标志
BUILD_BACKEND=false
BUILD_ADMIN=false
BUILD_USER=false
BUILD_NGINX=false
BUILD_WEB=false

# 根据 BUILD_MODULE 设置构建标志
case "$BUILD_MODULE" in
    all)
        BUILD_BACKEND=true
        BUILD_ADMIN=true
        BUILD_USER=true
        BUILD_NGINX=true
        BUILD_WEB=true
        ;;
    api) BUILD_BACKEND=true ;;
    admin) BUILD_ADMIN=true ;;
    user) BUILD_USER=true ;;
    nginx) BUILD_NGINX=true ;;
    web) BUILD_WEB=true ;;
esac

# 显示构建信息
log_info "============================================"
log_info "Monorepo 构建系统 - 容器内构建"
log_info "============================================"
log_info "构建模块: $BUILD_MODULE"
[ -n "$RELEASE_CHANNEL" ] && log_info "发布通道: $RELEASE_CHANNEL"
log_info "强制构建: $FORCE_BUILD"
log_info "源目录:   $SOURCE_DIR"
log_info "工作目录: $WORKSPACE_DIR"
log_info "============================================"
echo ""

# 合并配置：如果 custom/config.json 存在，用其 build 节点覆盖默认配置
MERGED_CONFIG="/tmp/merged_config.json"
if [ -f "$CUSTOM_DIR/config.json" ]; then
    log_info "检测到 custom/config.json，合并配置..."
    # 使用 jq 合并：custom 的 build 覆盖默认的 build
    jq -s '.[0] * {build: (.[0].build * .[1].build)}' \
        "$CONFIG_FILE" "$CUSTOM_DIR/config.json" >"$MERGED_CONFIG"
    CONFIG_FILE="$MERGED_CONFIG"
    log_success "配置已合并"
fi

# 配置 Git 用户信息
GIT_USER_NAME=$(jq -r '.build.git_user.name' "$CONFIG_FILE")
GIT_USER_EMAIL=$(jq -r '.build.git_user.email' "$CONFIG_FILE")
git config --global user.name "$GIT_USER_NAME"
git config --global user.email "$GIT_USER_EMAIL"
git config --global --add safe.directory '*'
log_success "Git 用户配置: $GIT_USER_NAME <$GIT_USER_EMAIL>"
echo ""

# 创建必要目录
mkdir -p "$WORKSPACE_DIR" "$PRODUCTION_DIR"

# 导出构建标志供子脚本使用
export BUILD_BACKEND BUILD_ADMIN BUILD_USER BUILD_NGINX BUILD_WEB
export BUILD_MODULE BUILD_VERSION RELEASE_CHANNEL FORCE_BUILD
export SOURCE_DIR WORKSPACE_DIR PRODUCTION_DIR CONFIG_FILE

# 阶段 1: 准备工作目录
log_step "阶段 1: 准备工作目录"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# 复制后端源码（如果需要）
if [ "$BUILD_BACKEND" = "true" ]; then
    log_info "复制后端源码..."
    mkdir -p "$WORKSPACE_DIR/backend"
    rsync -a --delete \
        --exclude='.git' \
        --exclude='vendor' \
        --exclude='.idea' \
        --exclude='.vscode' \
        --exclude='storage/debugbar' \
        --exclude='storage/backups' \
        --exclude='storage/upgrades' \
        --exclude='storage/logs/*.log' \
        "$SOURCE_DIR/backend/" "$WORKSPACE_DIR/backend/"
    log_success "后端源码已复制"
fi

# 复制前端源码（如果需要构建 admin/user）
if [ "$BUILD_ADMIN" = "true" ] || [ "$BUILD_USER" = "true" ]; then
    log_info "复制前端源码..."

    # 复制 monorepo 根配置文件
    cp "$SOURCE_DIR/package.json" "$WORKSPACE_DIR/"
    cp "$SOURCE_DIR/pnpm-workspace.yaml" "$WORKSPACE_DIR/"
    cp "$SOURCE_DIR/pnpm-lock.yaml" "$WORKSPACE_DIR/"
    [ -f "$SOURCE_DIR/.npmrc" ] && cp "$SOURCE_DIR/.npmrc" "$WORKSPACE_DIR/"

    # 复制前端目录
    mkdir -p "$WORKSPACE_DIR/frontend"

    # 复制 shared（共享代码必须）
    rsync -a --delete \
        --exclude='.git' \
        --exclude='node_modules' \
        --exclude='dist' \
        "$SOURCE_DIR/frontend/shared/" "$WORKSPACE_DIR/frontend/shared/"

    # 复制 admin
    if [ "$BUILD_ADMIN" = "true" ]; then
        rsync -a --delete \
            --exclude='.git' \
            --exclude='node_modules' \
            --exclude='dist' \
            "$SOURCE_DIR/frontend/admin/" "$WORKSPACE_DIR/frontend/admin/"
    fi

    # 复制 user
    if [ "$BUILD_USER" = "true" ]; then
        rsync -a --delete \
            --exclude='.git' \
            --exclude='node_modules' \
            --exclude='dist' \
            "$SOURCE_DIR/frontend/user/" "$WORKSPACE_DIR/frontend/user/"
    fi

    log_success "前端源码已复制"

    # 接口文档源（@apidoc → backend/resources/docs/api）：user/admin 单独构建
    # （BUILD_BACKEND=false）时后端不整体复制，需单独带上供 unplugin-vue-markdown 编译
    if [ "$BUILD_BACKEND" != "true" ] && [ -d "$SOURCE_DIR/backend/resources/docs" ]; then
        mkdir -p "$WORKSPACE_DIR/backend/resources/docs"
        rsync -a --delete \
            "$SOURCE_DIR/backend/resources/docs/" "$WORKSPACE_DIR/backend/resources/docs/"
        log_success "接口文档源已复制（@apidoc）"
    fi

    # 覆盖 logo.svg（如果 custom 中存在）
    if [ -f "$CUSTOM_DIR/logo.svg" ]; then
        log_info "使用自定义 logo.svg 覆盖..."
        if [ "$BUILD_ADMIN" = "true" ] && [ -d "$WORKSPACE_DIR/frontend/admin/public" ]; then
            cp "$CUSTOM_DIR/logo.svg" "$WORKSPACE_DIR/frontend/admin/public/logo.svg"
            log_success "已覆盖 admin logo.svg"
        fi
        if [ "$BUILD_USER" = "true" ] && [ -d "$WORKSPACE_DIR/frontend/user/public" ]; then
            cp "$CUSTOM_DIR/logo.svg" "$WORKSPACE_DIR/frontend/user/public/logo.svg"
            log_success "已覆盖 user logo.svg"
        fi
    fi

    # 覆盖 qrcode.png（如果 custom 中存在，仅 user）
    if [ -f "$CUSTOM_DIR/qrcode.png" ]; then
        if [ "$BUILD_USER" = "true" ] && [ -d "$WORKSPACE_DIR/frontend/user/public" ]; then
            log_info "使用自定义 qrcode.png 覆盖..."
            cp "$CUSTOM_DIR/qrcode.png" "$WORKSPACE_DIR/frontend/user/public/qrcode.png"
            log_success "已覆盖 user qrcode.png"
        fi
    fi
fi

echo ""

# 阶段 2: 后端构建
if [ "$BUILD_BACKEND" = "true" ]; then
    log_step "阶段 2: 后端构建"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    if /build/scripts/build-backend.sh; then
        log_success "后端构建完成"
    else
        log_error "后端构建失败"
        exit 1
    fi
    echo ""
fi

# 阶段 3: 前端构建
if [ "$BUILD_ADMIN" = "true" ] || [ "$BUILD_USER" = "true" ]; then
    log_step "阶段 3: 前端构建"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    if /build/scripts/build-frontend.sh; then
        log_success "前端构建完成"
    else
        log_error "前端构建失败"
        exit 1
    fi
    echo ""
fi

# 阶段 4: 汇总构建产物
log_step "阶段 4: 汇总构建产物"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if /build/scripts/collect-artifacts.sh; then
    log_success "构建产物汇总完成"
else
    log_error "构建产物汇总失败"
    exit 1
fi
echo ""

log_info "[容器] 构建阶段完成"
