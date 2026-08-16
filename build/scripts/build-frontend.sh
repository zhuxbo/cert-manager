#!/bin/bash

# 前端构建脚本 (Monorepo 版本)
# 使用 pnpm workspace 构建前端项目

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

# 检查是否有前端需要构建
if [ "${BUILD_ADMIN:-false}" != "true" ] && [ "${BUILD_USER:-false}" != "true" ]; then
    log_info "无前端项目需要构建，跳过"
    exit 0
fi

# 进入工作目录
cd "$WORKSPACE_DIR"

if [ ! -f "package.json" ] || [ ! -f "pnpm-workspace.yaml" ]; then
    log_error "monorepo 配置文件不存在: package.json 或 pnpm-workspace.yaml"
    exit 1
fi

log_info "开始构建前端..."

# 锁文件级别的智能安装跳过
DEP_HASH_DIR="$WORKSPACE_DIR/.dep_hashes"
mkdir -p "$DEP_HASH_DIR"
LOCK_FILE="pnpm-lock.yaml"
HASH_FILE="$DEP_HASH_DIR/monorepo_pnpm-lock.yaml.sha256"

CURRENT_HASH="missing"
if [ -f "$LOCK_FILE" ]; then
    CURRENT_HASH=$(sha256sum "$LOCK_FILE" | awk '{print $1}')
fi
PREV_HASH=""
[ -f "$HASH_FILE" ] && PREV_HASH=$(cat "$HASH_FILE" 2>/dev/null || echo "")

NEED_INSTALL=false
if [ ! -d "node_modules" ] || [ ! -d "frontend/shared/node_modules" ]; then
    NEED_INSTALL=true
    log_info "检测到 node_modules 不存在，将执行 pnpm install"
elif [ "$CURRENT_HASH" != "$PREV_HASH" ]; then
    NEED_INSTALL=true
    log_info "pnpm-lock.yaml 发生变更，将执行 pnpm install"
elif [ "${FORCE_BUILD:-false}" = "true" ]; then
    NEED_INSTALL=true
    log_info "强制构建模式，将执行 pnpm install"
else
    log_success "pnpm-lock.yaml 未变且 node_modules 已存在，跳过依赖安装"
fi

if [ "$NEED_INSTALL" = true ]; then
    log_info "安装 pnpm workspace 依赖..."
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    # CI=true：容器内无 TTY，pnpm 11 切版本/重建 node_modules 时需非交互确认 purge
    # （否则报 ERR_PNPM_ABORTED_REMOVE_MODULES_DIR_NO_TTY）；并让 supply-chain 校验走 CI 默认放行已落 lockfile
    CI=true pnpm install --frozen-lockfile --prefer-offline
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "$CURRENT_HASH" >"$HASH_FILE"
fi

# 修复可执行文件权限
fix_bin_permissions() {
    local dir="$1"
    if [ -d "$dir/node_modules/.bin" ]; then
        chmod +x "$dir/node_modules/.bin/"* 2>/dev/null || true
        for bin in "$dir/node_modules/.bin/"*; do
            if [ -L "$bin" ]; then
                target=$(readlink -f "$bin" 2>/dev/null || true)
                [ -f "$target" ] && chmod +x "$target" 2>/dev/null || true
            fi
        done
    fi
}

log_info "修复可执行文件权限..."
fix_bin_permissions "$WORKSPACE_DIR"
fix_bin_permissions "$WORKSPACE_DIR/frontend/shared"
[ "${BUILD_ADMIN:-false}" = "true" ] && fix_bin_permissions "$WORKSPACE_DIR/frontend/admin"
[ "${BUILD_USER:-false}" = "true" ] && fix_bin_permissions "$WORKSPACE_DIR/frontend/user"

# 计算前端构建输入 hash（用于增量构建检测）
# 参数：主目录 [依赖目录...]
calc_dir_hash() {
    local dirs=("$@")
    local dependency_files=(
        "$WORKSPACE_DIR/package.json"
        "$WORKSPACE_DIR/pnpm-workspace.yaml"
        "$WORKSPACE_DIR/pnpm-lock.yaml"
        "$WORKSPACE_DIR/.npmrc"
    )

    {
        # 对目录下所有源文件内容计算 hash，排除 node_modules 和 dist
        for dir in "${dirs[@]}"; do
            find "$dir" -type f \( -name "*.ts" -o -name "*.tsx" -o -name "*.vue" -o -name "*.js" -o -name "*.css" -o -name "*.scss" -o -name "*.json" -o -name "*.html" -o -name "*.md" \) \
                ! -path "*/node_modules/*" ! -path "*/dist/*" \
                -exec sha256sum {} \; 2>/dev/null
        done

        for file in "${dependency_files[@]}"; do
            [ -f "$file" ] && sha256sum "$file"
        done
    } | sort | sha256sum | awk '{print $1}'
}

# 构建函数
build_component() {
    local component="$1"
    local filter="$2"

    local src_dir="$WORKSPACE_DIR/frontend/$filter"
    local shared_dir="$WORKSPACE_DIR/frontend/shared"
    local dist_dir="$src_dir/dist"
    local hash_file="$DEP_HASH_DIR/${filter}_src.sha256"

    # 增量构建检测：检查源码及根依赖配置是否变更（包括 shared 依赖）
    # 接口文档已改由 api-docs 插件提供，不再 build 期编译进 SPA，无需纳入前端 hash
    local current_hash
    current_hash=$(calc_dir_hash "$src_dir" "$shared_dir")
    local prev_hash=""
    [ -f "$hash_file" ] && prev_hash=$(cat "$hash_file" 2>/dev/null || echo "")

    if [ "${FORCE_BUILD:-false}" != "true" ] &&
        [ "$current_hash" = "$prev_hash" ] &&
        [ -d "$dist_dir" ] &&
        [ "$(find "$dist_dir" -type f | wc -l)" -gt 0 ]; then
        log_success "[$component] 构建输入未变更，跳过构建（使用缓存）"
        return 0
    fi

    log_info "[$component] 开始构建..."
    [ "$current_hash" != "$prev_hash" ] && log_info "[$component] 检测到构建输入变更"

    # 清理旧的构建产物
    if [ -d "$dist_dir" ]; then
        log_info "[$component] 清理 dist..."
        rm -rf "$dist_dir"
    fi

    # 释放内存：清理 pnpm 缓存和临时文件
    { sync && echo 3 >/proc/sys/vm/drop_caches; } 2>/dev/null || true

    # 构建策略：
    # - 跳过类型检查（vue-tsc 需要大量内存）
    # - 限制 Node.js 堆内存为 2048MB（容器需 4GB）
    # - 设置 UV_THREADPOOL_SIZE=2 减少 I/O 线程
    # - 禁用 sourcemap 减少内存占用
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    set +e
    UV_THREADPOOL_SIZE=2 \
        NODE_OPTIONS="--max-old-space-size=2048" \
        GENERATE_SOURCEMAP=false \
        pnpm --filter "$filter" exec -- vite build
    BUILD_EXIT_CODE=$?
    set -e
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    # 检查构建退出码
    if [ "$BUILD_EXIT_CODE" -ne 0 ]; then
        # 129=SIGHUP, 137=SIGKILL(OOM), 139=SIGSEGV 通常与内存不足相关
        if [ "$BUILD_EXIT_CODE" -eq 129 ] || [ "$BUILD_EXIT_CODE" -eq 137 ] || [ "$BUILD_EXIT_CODE" -eq 139 ]; then
            log_error "[$component] 构建进程被系统终止 (退出码: $BUILD_EXIT_CODE)"
            log_warning "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
            log_warning "可能是内存不足导致，当前容器限制 4G"
            log_warning "如果构建持续失败，请检查 build.sh 中的 --memory 参数"
            log_warning "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        fi
        log_error "[$component] 构建失败 (退出码: $BUILD_EXIT_CODE)"
        return 1
    fi

    # 验证构建结果
    if [ -d "$dist_dir" ]; then
        DIST_SIZE=$(du -sh "$dist_dir" | cut -f1)
        DIST_FILES=$(find "$dist_dir" -type f | wc -l)
        log_success "[$component] 构建完成"
        log_info "[$component] Dist 大小: $DIST_SIZE"
        log_info "[$component] 文件数量: $DIST_FILES"
        # 保存源码 hash，用于下次增量构建检测
        echo "$current_hash" >"$hash_file"
    else
        log_error "[$component] 构建失败：dist 目录不存在"
        return 1
    fi
}

# 构建管理端
if [ "${BUILD_ADMIN:-false}" = "true" ]; then
    build_component "管理端" "admin"
    # 构建完成后释放内存，为下一个构建做准备
    { sync && echo 3 >/proc/sys/vm/drop_caches; } 2>/dev/null || true
    echo ""
fi

# 构建用户端
if [ "${BUILD_USER:-false}" = "true" ]; then
    build_component "用户端" "user"
    echo ""
fi
