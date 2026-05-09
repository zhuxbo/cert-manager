#!/bin/bash

# SSL证书管理系统 - 打包脚本
# 生成完整安装包和升级包

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_step() { echo -e "${CYAN}[STEP]${NC} $1"; }

file_sha256() {
    local file="$1"
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$file" | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$file" | awk '{print $1}'
    elif command -v openssl >/dev/null 2>&1; then
        openssl dgst -sha256 "$file" | awk '{print $NF}'
    else
        log_error "缺少 sha256 工具（sha256sum / shasum / openssl 均未安装）"
        exit 1
    fi
}

# 默认路径
BUILD_DIR="${BUILD_DIR:-$(cd "$SCRIPT_DIR/.." && pwd)}"
PRODUCTION_DIR="${PRODUCTION_DIR:-$BUILD_DIR/temp/production-code}"
OUTPUT_DIR="${OUTPUT_DIR:-$BUILD_DIR/temp/packages}"
CHANNEL="${RELEASE_CHANNEL:-}"
BUILD_CONFIG="$BUILD_DIR/config.json"
VERSION=""

# 显示帮助
show_help() {
    cat <<EOF
SSL证书管理系统 - 打包脚本

用法: $0 [选项]

选项:
  --version VER     指定版本号（优先级最高）
  --source DIR      指定生产代码目录（默认: $PRODUCTION_DIR）
  --output DIR      指定输出目录（默认: $OUTPUT_DIR）
  --channel NAME    指定发布通道 main|dev（自动根据版本号判断）
  -h, --help        显示此帮助信息

版本号获取优先级:
  1. --version 参数
  2. version.json 中的 version 字段

通道自动判断:
  - 包含 -beta/-alpha/-rc/-dev 的版本 → dev 通道
  - 其他版本 → main 通道

EOF
    exit 0
}

# 解析参数
while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)
            VERSION="$2"
            shift 2
            ;;
        --source)
            PRODUCTION_DIR="$2"
            shift 2
            ;;
        --output)
            OUTPUT_DIR="$2"
            shift 2
            ;;
        --channel)
            CHANNEL="$2"
            shift 2
            ;;
        -h | --help)
            show_help
            ;;
        *)
            log_error "未知参数: $1"
            exit 1
            ;;
    esac
done

# 检查生产代码目录
if [ ! -d "$PRODUCTION_DIR" ]; then
    log_error "生产代码目录不存在: $PRODUCTION_DIR"
    log_info "请先运行完整构建: ./build/build.sh --version <version>"
    exit 1
fi
PRODUCTION_DIR="$(cd "$PRODUCTION_DIR" && pwd)"
mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd)"

# 检查 version.json
if [ ! -f "$PRODUCTION_DIR/version.json" ]; then
    log_error "未找到 version.json"
    exit 1
fi

validate_production_dir() {
    local missing=0
    local required_paths=(
        "backend/artisan"
        "backend/composer.json"
        "backend/composer.lock"
        "backend/.env.example"
        "frontend/admin/index.html"
        "frontend/user/index.html"
        "nginx/manager.conf"
    )

    for path in "${required_paths[@]}"; do
        if [ ! -e "$PRODUCTION_DIR/$path" ]; then
            log_error "生产代码缺少必需文件: $path"
            missing=1
        fi
    done

    if [ "$missing" -ne 0 ]; then
        log_info "请先运行完整构建: ./build/build.sh --version <version>"
        exit 1
    fi
}

# 从 build/config.json 读取排除列表到临时文件
# 用法: create_exclude_file <package_type> <output_file> [prefix_filter]
# package_type: full 或 upgrade
# prefix_filter: 可选，过滤指定前缀的路径（如 "backend/" 或 "frontend/admin/"）
create_exclude_file() {
    local pkg_type="$1"
    local output_file="$2"
    local prefix_filter="${3:-}"

    # 清空文件
    >"$output_file"

    if [ -f "$BUILD_CONFIG" ] && command -v jq &>/dev/null; then
        # 首先添加 backend 的通用排除规则（生产无关文件，仅当无前缀过滤或过滤 backend 时）
        if [ -z "$prefix_filter" ] || [[ "$prefix_filter" == "backend/" ]]; then
            jq -r '.exclude_patterns.backend[]?' "$BUILD_CONFIG" 2>/dev/null >>"$output_file" || true
        fi

        # 然后添加包类型特定的排除规则
        if [ -z "$prefix_filter" ]; then
            # 无过滤，直接添加所有规则
            jq -r ".package.$pkg_type.exclude[]?" "$BUILD_CONFIG" 2>/dev/null >>"$output_file" || true
        else
            # 有前缀过滤，只提取匹配前缀的规则并去除前缀
            jq -r ".package.$pkg_type.exclude[]?" "$BUILD_CONFIG" 2>/dev/null | while read -r line; do
                if [[ "$line" == "$prefix_filter"* ]]; then
                    # 去除前缀后添加
                    echo "${line#$prefix_filter}"
                fi
            done >>"$output_file"
        fi
    fi

    # 如果配置读取失败，使用默认值
    if [ ! -s "$output_file" ]; then
        # 默认的生产无关文件排除
        cat >>"$output_file" <<EOF
.git/
.github/
.gitignore
.gitattributes
.editorconfig
.pint.json
.cursor/
.idea/
.vscode/
.DS_Store
.env
.env.*
.phpunit.cache/
tests/
phpunit.xml
phpstan.neon
*.md
README*
LICENSE*
EOF
        # 包类型特定排除
        if [ "$pkg_type" = "full" ]; then
            cat >>"$output_file" <<EOF
vendor/
deploy/
storage/upgrades/
storage/backups/
storage/logs/*.log
storage/framework/cache/*
EOF
        elif [ "$pkg_type" = "upgrade" ]; then
            cat >>"$output_file" <<EOF
storage/*
bootstrap/cache/*
vendor/*
EOF
        fi
    fi
}

# 读取版本号（如果未通过参数指定）
if [ -z "$VERSION" ]; then
    VERSION=$(grep -o '"version"[[:space:]]*:[[:space:]]*"[^"]*"' "$PRODUCTION_DIR/version.json" | head -1 | sed 's/.*"\([^"]*\)"$/\1/')
    if [ -z "$VERSION" ]; then
        log_error "无法读取版本号，请使用 --version 参数指定"
        exit 1
    fi
fi

# 自动判断通道（如果未通过参数指定）
if [ -z "$CHANNEL" ]; then
    if [[ "$VERSION" =~ -(dev|alpha|beta|rc) ]]; then
        CHANNEL="dev"
    else
        CHANNEL="main"
    fi
fi

# 清空旧 zip 产物 + 历史遗留 manifest.json。即使后续校验失败，也不保留旧包误导发布。
rm -f "$OUTPUT_DIR"/ssl-manager-*.zip 2>/dev/null || true
rm -f "$OUTPUT_DIR"/manifest.json 2>/dev/null || true # 历史兼容：清理旧版本残留

validate_production_dir

log_info "============================================"
log_info "SSL证书管理系统 - 打包"
log_info "============================================"
log_info "版本号:   $VERSION"
log_info "发布通道: $CHANNEL"
log_info "源目录:   $PRODUCTION_DIR"
log_info "输出目录: $OUTPUT_DIR"
log_info "============================================"
echo ""

# 包文件名
FULL_PACKAGE="ssl-manager-full-$VERSION.zip"
UPGRADE_PACKAGE="ssl-manager-upgrade-$VERSION.zip"
SCRIPT_PACKAGE="ssl-manager-script-$VERSION.zip"

# 临时工作目录
WORK_DIR=$(mktemp -d)
cleanup_on_exit() {
    local status=$?
    rm -rf "$WORK_DIR"
    if [ "$status" -ne 0 ]; then
        # 任一阶段失败都移除半成品，避免 release 目录留下可误用的 zip。
        rm -f \
            "$OUTPUT_DIR/$FULL_PACKAGE" \
            "$OUTPUT_DIR/$UPGRADE_PACKAGE" \
            "$OUTPUT_DIR/$SCRIPT_PACKAGE" 2>/dev/null || true
    fi
}
trap cleanup_on_exit EXIT

# 清理 macOS/Windows 系统文件
cleanup_os_files() {
    local dir="$1"
    find "$dir" -name '.DS_Store' -type f -delete 2>/dev/null || true
    find "$dir" -name '__MACOSX' -type d -prune -exec rm -rf {} + 2>/dev/null || true
    find "$dir" -name 'Thumbs.db' -type f -delete 2>/dev/null || true
}

# 阶段 1: 创建完整安装包
log_step "阶段 1: 创建完整安装包"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

FULL_DIR="$WORK_DIR/full"
mkdir -p "$FULL_DIR"

# 创建排除列表文件
FULL_EXCLUDE_FILE="$WORK_DIR/full-exclude.txt"
create_exclude_file "full" "$FULL_EXCLUDE_FILE"

# 复制文件，使用配置的排除列表
rsync -a --exclude-from="$FULL_EXCLUDE_FILE" "$PRODUCTION_DIR/" "$FULL_DIR/"

# 计算源目录路径（BUILD_DIR 的父目录是项目根目录）
PROJECT_ROOT="$(cd "$BUILD_DIR/.." && pwd)"

# 复制 web 目录（自定义静态页面）
WEB_SOURCE="$BUILD_DIR/web"
if [ -d "$WEB_SOURCE" ]; then
    log_info "复制 web 静态页面..."
    mkdir -p "$FULL_DIR/frontend/web"
    rsync -a --exclude='.git*' "$WEB_SOURCE/" "$FULL_DIR/frontend/web/"
fi

# 复制 nginx 目录（宝塔部署需要）
NGINX_SOURCE="$BUILD_DIR/nginx"
if [ -d "$NGINX_SOURCE" ]; then
    log_info "复制 nginx 配置..."
    mkdir -p "$FULL_DIR/nginx"
    rsync -a --exclude='.git*' "$NGINX_SOURCE/" "$FULL_DIR/nginx/"
fi

# 确保前端目录完整
for app in admin user web; do
    if [ -d "$FULL_DIR/frontend/$app" ]; then
        log_info "已包含前端: $app"
    fi
done

# 检查 nginx 目录
if [ -d "$FULL_DIR/nginx" ]; then
    log_info "已包含 nginx 配置"
fi

# 创建 Laravel 运行时必需的空目录结构（zip -r 会保留空目录）
mkdir -p "$FULL_DIR/backend/storage/"{app/{public,private},framework/{cache,sessions,views},logs,pay}
mkdir -p "$FULL_DIR/backend/bootstrap/cache"
mkdir -p "$FULL_DIR/backend/vendor"

# 创建 version.json（运行时版本信息）
cat >"$FULL_DIR/version.json" <<EOF
{
  "version": "$VERSION",
  "channel": "$CHANNEL",
  "build_time": "$(date -u "+%Y-%m-%dT%H:%M:%SZ")"
}
EOF

# 兼容老 PackageExtractor（main 分支）：仍生成最简 manifest.json 通过线上旧版校验
# 新代码已改读 version.json，不消费 manifest.json
# TODO(2027-01-01): 所有线上部署都升到含 version.json 的新版本后移除此段
cat >"$FULL_DIR/manifest.json" <<EOF
{
  "version": "$VERSION",
  "channel": "$CHANNEL",
  "build_time": "$(date -u "+%Y-%m-%dT%H:%M:%SZ")"
}
EOF

# 清理系统文件后打包
cleanup_os_files "$FULL_DIR"
cd "$WORK_DIR"
zip -rq "$OUTPUT_DIR/$FULL_PACKAGE" full -x "*/.git/*" -x "*/.git*"
FULL_SIZE=$(du -h "$OUTPUT_DIR/$FULL_PACKAGE" | cut -f1)
FULL_SHA256=$(file_sha256 "$OUTPUT_DIR/$FULL_PACKAGE")

log_success "完整包: $FULL_PACKAGE ($FULL_SIZE)"
echo ""

# 阶段 2: 创建升级包
log_step "阶段 2: 创建升级包"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

UPGRADE_DIR="$WORK_DIR/upgrade"
mkdir -p "$UPGRADE_DIR"

# 升级包只包含代码，不包含 vendor、配置和用户数据
# 创建后端排除列表文件（过滤 backend/ 前缀的规则）
UPGRADE_BACKEND_EXCLUDE="$WORK_DIR/upgrade-backend-exclude.txt"
create_exclude_file "upgrade" "$UPGRADE_BACKEND_EXCLUDE" "backend/"

mkdir -p "$UPGRADE_DIR/backend"
rsync -a --exclude-from="$UPGRADE_BACKEND_EXCLUDE" "$PRODUCTION_DIR/backend/" "$UPGRADE_DIR/backend/"

# 升级包不需要 vendor 目录（升级时会保留现有的 vendor）

# 前端：保持 frontend/ 目录结构
# 使用统一的 upgrade.exclude 配置，过滤 frontend/ 前缀的规则
if [ -d "$PRODUCTION_DIR/frontend" ]; then
    mkdir -p "$UPGRADE_DIR/frontend"
    for app in admin user; do
        if [ -d "$PRODUCTION_DIR/frontend/$app" ]; then
            # 创建该前端应用的排除列表（过滤 frontend/$app/ 前缀的规则）
            FRONTEND_EXCLUDE_FILE="$WORK_DIR/upgrade-frontend-$app-exclude.txt"
            create_exclude_file "upgrade" "$FRONTEND_EXCLUDE_FILE" "frontend/$app/"

            rsync -a --exclude-from="$FRONTEND_EXCLUDE_FILE" "$PRODUCTION_DIR/frontend/$app/" "$UPGRADE_DIR/frontend/$app/"
            log_info "升级包已包含前端: $app (已排除用户配置)"
        fi
    done
fi

# 复制 nginx 目录（路由配置，升级时需要更新）
if [ -d "$PRODUCTION_DIR/nginx" ]; then
    cp -r "$PRODUCTION_DIR/nginx" "$UPGRADE_DIR/"
    log_info "升级包已包含 nginx 配置"
fi

# 注意：升级包不包含 web 目录，避免覆盖用户自定义页面

# 创建 version.json（运行时版本信息）
cat >"$UPGRADE_DIR/version.json" <<EOF
{
  "version": "$VERSION",
  "channel": "$CHANNEL",
  "build_time": "$(date -u "+%Y-%m-%dT%H:%M:%SZ")"
}
EOF

# 兼容老 PackageExtractor（main 分支）：仍生成最简 manifest.json 通过线上旧版校验
# 新代码已改读 version.json，不消费 manifest.json
# TODO(2027-01-01): 所有线上部署都升到含 version.json 的新版本后移除此段
cat >"$UPGRADE_DIR/manifest.json" <<EOF
{
  "version": "$VERSION",
  "channel": "$CHANNEL",
  "build_time": "$(date -u "+%Y-%m-%dT%H:%M:%SZ")"
}
EOF

# 创建升级说明
cat >"$UPGRADE_DIR/UPGRADE.md" <<EOF
# SSL证书管理系统 升级包

版本: $VERSION
通道: $CHANNEL
打包时间: $(date "+%Y-%m-%d %H:%M:%S")

## 升级步骤

1. 备份当前版本
2. 解压升级包覆盖文件
3. 安装 PHP 依赖: composer install --no-dev
4. 运行数据库迁移: php artisan migrate --force
5. 清理缓存: php artisan optimize:clear
6. 重启服务

## 注意事项

- 升级包不包含 vendor 目录，需要运行 composer install 安装依赖
- 升级包不包含 .env 配置文件，不会覆盖现有配置
- 升级包不包含 storage 目录，不会影响上传的文件
- 建议在升级前备份数据库
- 如使用 deploy/upgrade.sh 升级，脚本会执行依赖安装、数据库迁移和缓存清理

EOF

# 清理系统文件后打包
cleanup_os_files "$UPGRADE_DIR"
cd "$WORK_DIR"
zip -rq "$OUTPUT_DIR/$UPGRADE_PACKAGE" upgrade -x "*/.git/*" -x "*/.git*"
UPGRADE_SIZE=$(du -h "$OUTPUT_DIR/$UPGRADE_PACKAGE" | cut -d'	' -f1)
UPGRADE_SHA256=$(file_sha256 "$OUTPUT_DIR/$UPGRADE_PACKAGE")

log_success "升级包: $UPGRADE_PACKAGE ($UPGRADE_SIZE)"
echo ""

# 阶段 3: 创建脚本部署包
log_step "阶段 3: 创建脚本部署包"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# 从项目根目录获取 deploy（BUILD_DIR 的父目录）
PROJECT_ROOT="$(cd "$BUILD_DIR/.." && pwd)"
SCRIPT_DIR_SRC="$PROJECT_ROOT/deploy"

if [ -d "$SCRIPT_DIR_SRC" ]; then
    SCRIPT_PKG_DIR="$WORK_DIR/script-deploy"
    mkdir -p "$SCRIPT_PKG_DIR/scripts"

    # 复制脚本文件
    cp "$SCRIPT_DIR_SRC/scripts/"*.sh "$SCRIPT_PKG_DIR/scripts/" 2>/dev/null || true
    cp "$SCRIPT_DIR_SRC/install.sh" "$SCRIPT_PKG_DIR/" 2>/dev/null || true
    cp "$SCRIPT_DIR_SRC/upgrade.sh" "$SCRIPT_PKG_DIR/" 2>/dev/null || true

    # 注：原本生成的 script-deploy/README.md 已弃用（部署脚本不需自带说明文档；
    # 用户文档由 release 站 / repo 的 docs 目录提供）

    # 清理系统文件后打包
    cleanup_os_files "$SCRIPT_PKG_DIR"
    cd "$WORK_DIR"
    zip -rq "$OUTPUT_DIR/$SCRIPT_PACKAGE" script-deploy
    SCRIPT_SIZE=$(du -h "$OUTPUT_DIR/$SCRIPT_PACKAGE" | cut -d'	' -f1)
    SCRIPT_SHA256=$(file_sha256 "$OUTPUT_DIR/$SCRIPT_PACKAGE")

    log_success "脚本包: $SCRIPT_PACKAGE ($SCRIPT_SIZE)"
else
    log_warning "未找到 deploy 目录，跳过脚本包"
    SCRIPT_SIZE=""
    SCRIPT_SHA256=""
fi
echo ""

# 注：原阶段 4 生成的 OUTPUT_DIR/manifest.json（包外 sha256 索引）已弃用，
# release 站 releases.json 由 release-common.sh::generate_releases_update_script 在上传时生成；
# 包内 $FULL_DIR/$UPGRADE_DIR 仍带最简 manifest.json 兼容线上旧 PackageExtractor（见上文 TODO(2027-01-01)）
echo ""

# 完成
log_info "============================================"
log_success "打包完成！"
log_info "============================================"
log_info "输出目录: $OUTPUT_DIR"
log_info ""
log_info "生成的文件:"
log_info "  - $FULL_PACKAGE ($FULL_SIZE) sha256=${FULL_SHA256:0:16}…"
log_info "  - $UPGRADE_PACKAGE ($UPGRADE_SIZE) sha256=${UPGRADE_SHA256:0:16}…"
if [ -n "$SCRIPT_SHA256" ]; then
    log_info "  - $SCRIPT_PACKAGE ($SCRIPT_SIZE) sha256=${SCRIPT_SHA256:0:16}…"
fi
log_info "（包外 sha256 索引由 release-common.sh 写入 releases.json；包内 manifest.json 为线上旧版本兼容层）"
log_info "============================================"
