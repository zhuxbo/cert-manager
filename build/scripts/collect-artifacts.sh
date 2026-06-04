#!/bin/bash

# 汇总构建产物脚本 (Monorepo 版本)
# 将各模块构建产物收集到 production-code 目录

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

# 将秒数格式化为可读时长
format_secs() {
    local secs="$1"
    local h=$((secs / 3600))
    local m=$(((secs % 3600) / 60))
    local s=$((secs % 60))
    if [ "$h" -gt 0 ]; then
        printf "%d小时%02d分%02d秒" "$h" "$m" "$s"
    elif [ "$m" -gt 0 ]; then
        printf "%d分%02d秒" "$m" "$s"
    else
        printf "%d秒" "$s"
    fi
}

# 运行 rsync 并输出精简统计摘要
run_rsync_with_stats() {
    local label="$1"
    shift
    local start_ts end_ts elapsed tmpstats total changed created deleted
    tmpstats=$(mktemp)
    start_ts=$(date +%s)
    if rsync --stats "$@" >"$tmpstats" 2>&1; then
        end_ts=$(date +%s)
        elapsed=$((end_ts - start_ts))
        # 注意：rsync 输出的数字可能带逗号（如 46,144），需要匹配 [0-9,]+ 并移除逗号
        total=$(grep -Eo 'Number of files: [0-9,]+' "$tmpstats" | awk '{gsub(/,/,""); print $4}' | tail -1)
        changed=$(grep -Eo 'Number of (regular )?files transferred: [0-9,]+' "$tmpstats" | awk '{gsub(/,/,""); print $NF}' | tail -1)
        created=$(grep -Eo 'Number of created files: [0-9,]+' "$tmpstats" | awk '{gsub(/,/,""); print $5}' | tail -1)
        deleted=$(grep -Eo 'Number of deleted files: [0-9,]+' "$tmpstats" | awk '{gsub(/,/,""); print $5}' | tail -1)
        rm -f "$tmpstats"
        log_info "$label rsync: 变更 ${changed:-0} / 总 ${total:-0}, 新增 ${created:-0}, 删除 ${deleted:-0}, 用时 $(format_secs "$elapsed")"
    else
        log_error "$label rsync 执行失败"
        echo "===== rsync 输出 ====="
        cat "$tmpstats" || true
        rm -f "$tmpstats"
        return 1
    fi
}

# 从环境变量获取路径
CONFIG_FILE="${CONFIG_FILE:-/build/config.json}"
SOURCE_DIR="${SOURCE_DIR:-/source}"
WORKSPACE_DIR="${WORKSPACE_DIR:-/workspace}"
PRODUCTION_DIR="${PRODUCTION_DIR:-/workspace/production-code}"
FORCE_BUILD="${FORCE_BUILD:-false}"

log_info "开始同步构建产物..."

# 确保生产目录存在
mkdir -p "$PRODUCTION_DIR"
cd "$PRODUCTION_DIR"

# 创建目录结构
mkdir -p backend frontend/admin frontend/user frontend/web nginx

# 复制后端文件
if [ "${BUILD_BACKEND:-false}" = "true" ]; then
    BACKEND_SOURCE="$WORKSPACE_DIR/backend"
    if [ -d "$BACKEND_SOURCE" ]; then
        log_info "复制后端文件（rsync，含排除与 --delete）..."
        mkdir -p "$PRODUCTION_DIR/backend"

        # 生成排除列表文件
        EXCLUDE_FILE="$(mktemp)"

        # 保护：对外接口文档随后端打包（运行时 MetaController::apiDoc 读取 resources/docs/api/*.yaml）
        # 必须在下面 *.md 通配排除“之前” include —— rsync 过滤规则按顺序首个匹配生效
        cat >>"$EXCLUDE_FILE" <<'EOF'
+ /resources/docs/
+ /resources/docs/api/
+ /resources/docs/api/**
EOF

        jq -r '.exclude_patterns.backend[]' "$CONFIG_FILE" 2>/dev/null >>"$EXCLUDE_FILE" || true

        # 额外排除
        cat >>"$EXCLUDE_FILE" <<'EOF'
*.md
README*
LICENSE*
CHANGELOG*
CONTRIBUTING*
.git/
.gitignore
.gitattributes
.editorconfig
.dockerignore
frontend/
nginx/
web/
storage/backups/
storage/upgrades/
storage/logs/*.log
storage/framework/testing/
vendor/**/tests/
vendor/**/Tests/
vendor/**/test/
vendor/**/docs/
vendor/**/doc/
vendor/**/.git/
vendor/**/.github/
vendor/**/examples/
vendor/**/example/
EOF

        # 直接执行 rsync（rsync 本身已优化，只复制有变化的文件）
        run_rsync_with_stats "后端" -a --delete --exclude-from="$EXCLUDE_FILE" "$BACKEND_SOURCE/" "$PRODUCTION_DIR/backend/"
        log_success "后端复制完成"
        rm -f "$EXCLUDE_FILE"
    else
        log_warning "后端目录不存在: $BACKEND_SOURCE"
    fi
fi

# 复制管理端前端
if [ "${BUILD_ADMIN:-false}" = "true" ]; then
    ADMIN_DIST="$WORKSPACE_DIR/frontend/admin/dist"
    if [ -d "$ADMIN_DIST" ]; then
        log_info "复制管理端前端文件..."
        mkdir -p "$PRODUCTION_DIR/frontend/admin"
        run_rsync_with_stats "admin" -a --delete "$ADMIN_DIST/" "$PRODUCTION_DIR/frontend/admin/"
        log_success "管理端前端复制完成"
    else
        log_error "管理端 dist 目录不存在: $ADMIN_DIST"
    fi
fi

# 复制用户端前端
if [ "${BUILD_USER:-false}" = "true" ]; then
    USER_DIST="$WORKSPACE_DIR/frontend/user/dist"
    if [ -d "$USER_DIST" ]; then
        log_info "复制用户端前端文件..."
        mkdir -p "$PRODUCTION_DIR/frontend/user"
        run_rsync_with_stats "user" -a --delete "$USER_DIST/" "$PRODUCTION_DIR/frontend/user/"
        log_success "用户端前端复制完成"
    else
        log_error "用户端 dist 目录不存在: $USER_DIST"
    fi
fi

# 复制 nginx 配置
if [ "${BUILD_NGINX:-false}" = "true" ]; then
    log_info "复制 nginx 配置..."
    mkdir -p nginx
    run_rsync_with_stats "nginx" -a --delete /build/nginx/ "$PRODUCTION_DIR/nginx/"
    NGINX_FILES=$(find "$PRODUCTION_DIR/nginx" -type f | wc -l)
    log_success "nginx 配置复制完成（$NGINX_FILES 个文件）"
fi

# 复制 web 静态文件
if [ "${BUILD_WEB:-false}" = "true" ]; then
    log_info "复制 web 静态文件..."
    mkdir -p frontend/web
    run_rsync_with_stats "web" -a --delete /build/web/ "$PRODUCTION_DIR/frontend/web/"

    # 覆盖 favicon.ico（如果 custom 中存在）
    CUSTOM_DIR="/build/custom"
    if [ -f "$CUSTOM_DIR/favicon.ico" ]; then
        cp "$CUSTOM_DIR/favicon.ico" "$PRODUCTION_DIR/frontend/web/public/favicon.ico"
        log_success "已覆盖 web favicon.ico"
    fi

    WEB_FILES=$(find "$PRODUCTION_DIR/frontend/web" -type f | wc -l)
    log_success "web 静态文件复制完成（$WEB_FILES 个文件）"
fi

# 获取 monorepo 提交哈希
MONOREPO_COMMIT=""
if [ -d "$SOURCE_DIR/.git" ]; then
    MONOREPO_COMMIT=$(cd "$SOURCE_DIR" && git rev-parse HEAD 2>/dev/null || echo "")
fi

# 从环境变量获取版本号
VERSION="${BUILD_VERSION:-}"
if [ -z "$VERSION" ]; then
    VERSION="0.0.0-dev"
    log_warning "BUILD_VERSION 未设置，使用默认值: $VERSION"
fi

# 通道从环境变量获取
RELEASE_CHANNEL="${RELEASE_CHANNEL:-main}"

# 生成 version.json（运行时使用）
BUILD_TIME=$(date -Iseconds)
cat >"$PRODUCTION_DIR/version.json" <<EOF
{
  "version": "$VERSION",
  "channel": "$RELEASE_CHANNEL",
  "build_time": "$BUILD_TIME",
  "build_commit": "$MONOREPO_COMMIT"
}
EOF
log_success "version.json 已生成"
log_info "版本: $VERSION"
log_info "通道: $RELEASE_CHANNEL"
log_info "Monorepo commit: ${MONOREPO_COMMIT:-N/A}"

# 复制 PHP 环境需求清单到生产代码根（后续 package.sh 会随 rsync 带入 FULL/UPGRADE 包）
# 后台升级 EnvironmentChecker / upgrade.sh 启动前都从这里读
PHP_REQ_SRC="$SOURCE_DIR/build/php-requirements.json"
if [ -f "$PHP_REQ_SRC" ]; then
    cp "$PHP_REQ_SRC" "$PRODUCTION_DIR/php-requirements.json"
    log_success "php-requirements.json 已生成"
else
    log_warning "未找到 build/php-requirements.json，跳过（旧版本无此文件时升级流程会自动 skip 检测）"
fi
