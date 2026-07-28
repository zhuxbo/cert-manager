#!/bin/bash

# Monorepo 构建脚本 - 容器化构建主控脚本
# 从 monorepo 直接构建，不需要多仓库同步

set -Eeuo pipefail
trap 'echo -e "\033[0;31m[ERROR]\033[0m 命令失败: ${BASH_COMMAND} (行号: ${LINENO})"' ERR

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

# 记录脚本起始时间
SCRIPT_START_TIME=$(date +%s)

# 将秒数格式化为中文可读时长
human_duration() {
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

# 获取脚本目录和 monorepo 根目录
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MONOREPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BUILD_ENV="$SCRIPT_DIR/build.env"
CUSTOM_DIR="$SCRIPT_DIR/custom"
TEMP_DIR="$SCRIPT_DIR/temp"

# 创建临时目录结构
mkdir -p "$TEMP_DIR"/{reports,production-code,caches/pnpm-store,caches/composer-cache}

# 默认参数
BUILD_MODULE="all"
BUILD_VERSION=""   # 构建版本号
RELEASE_CHANNEL="" # 发布通道: main 或 dev
FORCE_BUILD="false"
REBUILD_IMAGE="false"
CLEAR_CACHE="false"
CREATE_PACKAGE="false" # 是否创建安装包

# 显示帮助信息
show_help() {
    cat <<EOF
Monorepo 构建脚本 - 容器化构建

用法: $0 [选项] [模块]

模块:
  all         构建所有模块（默认）
  api         仅构建后端 API
  admin       仅构建管理端前端
  user        仅构建用户端前端
  nginx       仅更新 nginx 配置
  web         仅更新 web 静态文件

选项:
  --version VER         指定构建版本号（未指定则从 git tag 获取）
  --channel [main|dev]  指定发布通道（默认 main）
  --package             构建完成后创建安装包（完整包+升级包）
  --force-build         强制构建（即使已是最新）
  --clear-cache         构建前清空依赖缓存
  --rebuild-image       强制重建 Docker 镜像
  -h, --help            显示此帮助信息

示例:
  $0                             # 构建所有模块
  $0 api                         # 构建后端
  $0 --version 1.0.0 --package   # 指定版本并创建安装包
  $0 --channel dev               # 构建开发版通道
EOF
    exit 0
}

# 解析命令行参数
ARGS=("$@")
i=0
while [ $i -lt ${#ARGS[@]} ]; do
    arg="${ARGS[$i]}"
    case "$arg" in
        -h | --help)
            show_help
            ;;
        --version)
            next_idx=$((i + 1))
            if [ $next_idx -lt ${#ARGS[@]} ]; then
                BUILD_VERSION="${ARGS[$next_idx]}"
                i=$next_idx
            else
                log_error "--version 需要指定版本号"
                exit 1
            fi
            ;;
        --channel)
            next_idx=$((i + 1))
            if [ $next_idx -lt ${#ARGS[@]} ]; then
                next_arg="${ARGS[$next_idx]}"
                if [[ "$next_arg" =~ ^(main|dev)$ ]]; then
                    RELEASE_CHANNEL="$next_arg"
                    i=$next_idx
                else
                    log_error "--channel 只接受 main 或 dev"
                    exit 1
                fi
            else
                log_error "--channel 需要指定通道（main 或 dev）"
                exit 1
            fi
            ;;
        --force-build)
            FORCE_BUILD="true"
            ;;
        --clear-cache)
            CLEAR_CACHE="true"
            ;;
        --rebuild-image)
            REBUILD_IMAGE="true"
            ;;
        --package)
            CREATE_PACKAGE="true"
            ;;
        --*)
            log_error "未知选项: $arg"
            echo "使用 --help 查看帮助"
            exit 1
            ;;
        *)
            # 模块名
            if [[ "$arg" =~ ^(all|api|admin|user|nginx|web)$ ]]; then
                BUILD_MODULE="$arg"
            else
                log_error "无效的模块名: $arg"
                echo "有效模块: all, api, admin, user, nginx, web"
                exit 1
            fi
            ;;
    esac
    i=$((i + 1))
done

# 版本号：未指定时从 git tag 获取
if [ -z "$BUILD_VERSION" ]; then
    BUILD_VERSION=$(cd "$MONOREPO_ROOT" && git describe --tags --exact-match HEAD 2>/dev/null | sed 's/^v//' || true)
    if [ -z "$BUILD_VERSION" ]; then
        BUILD_VERSION="0.0.0-dev"
        log_warning "未指定版本号且无 git tag，使用默认值: $BUILD_VERSION"
    else
        log_info "从 git tag 获取版本号: $BUILD_VERSION"
    fi
fi

# 显示构建配置
echo ""
log_info "============================================"
log_info "Monorepo 构建系统"
log_info "============================================"
log_info "构建版本: $BUILD_VERSION"
log_info "构建模块: $BUILD_MODULE"
[ -n "$RELEASE_CHANNEL" ] && log_info "发布通道: $RELEASE_CHANNEL"
log_info "强制构建: $FORCE_BUILD"
log_info "重建镜像: $REBUILD_IMAGE"
log_info "Monorepo: $MONOREPO_ROOT"
log_info "============================================"
echo ""

# 检查 Docker
if ! command -v docker &>/dev/null; then
    log_error "Docker 未安装"
    log_info "请先安装 Docker: https://docs.docker.com/get-docker/"
    exit 1
fi

# 检查 Docker 服务
if ! docker info >/dev/null 2>&1; then
    log_error "Docker 服务未运行"
    log_info "请启动 Docker 服务"
    exit 1
fi

log_success "Docker 检查通过"
echo ""

# 如果 custom/build.env 存在，覆盖默认的 build.env
if [ -f "$CUSTOM_DIR/build.env" ]; then
    log_info "使用 custom/build.env 覆盖默认配置"
    BUILD_ENV="$CUSTOM_DIR/build.env"
fi

# 检查 build.env
if [ ! -f "$BUILD_ENV" ]; then
    log_error "未找到 $BUILD_ENV"
    log_info "请创建 ${BUILD_ENV}，示例见 README"
    exit 1
fi

# 可选：清理依赖缓存
if [ "$CLEAR_CACHE" = "true" ]; then
    log_step "清理依赖缓存与构建产物"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    CACHE_TARGETS=(
        "$TEMP_DIR/caches/pnpm-store"
        "$TEMP_DIR/caches/composer-cache"
        "$TEMP_DIR/.dep_hashes"
        "$TEMP_DIR/workspace"
    )

    for path in "${CACHE_TARGETS[@]}"; do
        if [ -e "$path" ]; then
            log_info "移除: $path"
            rm -rf "$path"
        fi
    done
    log_success "缓存清理完成"
    echo ""
fi

# 读取配置
# shellcheck disable=SC1090
source "$BUILD_ENV"
: "${BASE_IMAGE_NAME:=cnssl-build-base}"
: "${BASE_IMAGE_TAG:=ubuntu-24.04}"
: "${BUILD_IMAGE_NAME:=cnssl-build}"
: "${BUILD_IMAGE_TAG:=latest}"
: "${REBUILD_DAYS:=30}"

BASE_IMAGE_FULL="$BASE_IMAGE_NAME:$BASE_IMAGE_TAG"
BUILD_IMAGE_FULL="$BUILD_IMAGE_NAME:$BUILD_IMAGE_TAG"

to_epoch() {
    local ts="$1"
    if date -d "$ts" +%s >/dev/null 2>&1; then
        date -d "$ts" +%s
        return
    fi
    local base_no_frac="${ts%%.*}"
    if [[ "$ts" =~ [+-][0-9]{2}:[0-9]{2} ]]; then
        local off_part="${ts#*${base_no_frac}}"
        local off_compact
        off_compact=$(echo "$off_part" | sed -E 's/^([+-][0-9]{2}):([0-9]{2}).*$/\1\2/')
        if date -j -f "%Y-%m-%dT%H:%M:%S%z" "${base_no_frac}${off_compact}" "+%s" >/dev/null 2>&1; then
            date -j -f "%Y-%m-%dT%H:%M:%S%z" "${base_no_frac}${off_compact}" "+%s"
            return
        fi
    fi
    if [[ "$ts" == *Z ]]; then
        if date -j -u -f "%Y-%m-%dT%H:%M:%S" "$base_no_frac" "+%s" >/dev/null 2>&1; then
            date -j -u -f "%Y-%m-%dT%H:%M:%S" "$base_no_frac" "+%s"
            return
        fi
    fi
    echo 0
}

check_image_age() {
    local image_full="$1"
    local max_days="$2"

    if ! docker image inspect "$image_full" >/dev/null 2>&1; then
        echo "not_found"
        return
    fi

    local created
    created=$(docker image inspect "$image_full" --format='{{.Created}}')
    local created_timestamp
    created_timestamp=$(to_epoch "$created")
    local now_timestamp
    now_timestamp=$(date "+%s")
    if [ "$created_timestamp" -eq 0 ]; then
        echo "ok"
        return
    fi
    local age_days=$(((now_timestamp - created_timestamp) / 86400))
    if [ "$age_days" -gt "$max_days" ]; then
        echo "outdated"
    else
        echo "ok"
    fi
}

# 构建基础镜像
BASE_IMAGE_STATUS=$(check_image_age "$BASE_IMAGE_FULL" "$REBUILD_DAYS")

if [ "$REBUILD_IMAGE" = "true" ] || [ "$BASE_IMAGE_STATUS" = "not_found" ] || [ "$BASE_IMAGE_STATUS" = "outdated" ]; then
    log_step "构建基础镜像: $BASE_IMAGE_FULL"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    if [ "$BASE_IMAGE_STATUS" = "outdated" ]; then
        log_warning "基础镜像超过 $REBUILD_DAYS 天，重建中..."
    fi

    docker build \
        -f "$SCRIPT_DIR/Dockerfile.base" \
        -t "$BASE_IMAGE_FULL" \
        "$SCRIPT_DIR"

    log_success "基础镜像构建完成"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
else
    log_info "基础镜像已存在: $BASE_IMAGE_FULL"
    echo ""
fi

# 构建构建镜像
log_step "构建构建镜像: $BUILD_IMAGE_FULL"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

docker build \
    -f "$SCRIPT_DIR/Dockerfile.build" \
    --build-arg BASE_IMAGE="$BASE_IMAGE_FULL" \
    -t "$BUILD_IMAGE_FULL" \
    "$SCRIPT_DIR"

log_success "构建镜像构建完成"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# 准备构建报告文件名
REPORT_TIMESTAMP=$(date "+%Y%m%d-%H%M%S")
BUILD_REPORT="$TEMP_DIR/reports/build-$REPORT_TIMESTAMP.log"
ERROR_REPORT="$TEMP_DIR/reports/error-$REPORT_TIMESTAMP.log"

log_info "构建日志: $BUILD_REPORT"
log_info "错误日志: $ERROR_REPORT"
echo ""

# 启动容器执行构建
log_step "启动容器执行构建"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# 构建 docker run 选项
# 默认不设 --memory：orbstack/cgroup v2 下给容器加内存硬限制会让 pnpm install
# 遍历 node_modules 时 open() 返回 ENOMEM（与内存量无关——6g 在 12g VM 下仍 3 秒崩，
# 不设限制则秒级通过）。仅当显式配置 BUILD_MEMORY 时才加（自担 ENOMEM 风险）。
DOCKER_OPTS=(--rm)
[ -n "${BUILD_MEMORY:-}" ] && DOCKER_OPTS+=(--memory="$BUILD_MEMORY")

# 挂载 monorepo 源代码（只读）
DOCKER_OPTS+=(-v "$MONOREPO_ROOT:/source:ro")

# 挂载工作目录
DOCKER_OPTS+=(-v "$TEMP_DIR:/workspace")

# 挂载构建脚本
DOCKER_OPTS+=(-v "$SCRIPT_DIR/scripts:/build/scripts:ro")

# 挂载 custom 目录（如果存在）
if [ -d "$CUSTOM_DIR" ]; then
    DOCKER_OPTS+=(-v "$CUSTOM_DIR:/build/custom:ro")
    log_info "已挂载 custom 目录"
fi

# 传递构建环境变量
DOCKER_OPTS+=(-e BUILD_MODULE="$BUILD_MODULE")
DOCKER_OPTS+=(-e BUILD_VERSION="$BUILD_VERSION")
DOCKER_OPTS+=(-e RELEASE_CHANNEL="$RELEASE_CHANNEL")
DOCKER_OPTS+=(-e FORCE_BUILD="$FORCE_BUILD")

# 依赖缓存复用
DOCKER_OPTS+=(-e COMPOSER_CACHE_DIR=/composer/cache)
DOCKER_OPTS+=(-v "$TEMP_DIR/caches/composer-cache:/composer/cache")
DOCKER_OPTS+=(-e PNPM_STORE_DIR=/pnpm/store)
DOCKER_OPTS+=(-v "$TEMP_DIR/caches/pnpm-store:/pnpm/store")

# 运行容器
log_info "容器将在构建完成后自动销毁"
set +e
# 临时移除 ERR trap：本脚本 set -E（errtrace）下，trap 会在管道失败时触发，
# 其内部 echo 会重置 ${PIPESTATUS}，使下面 RUN_STATUS 读到 0 而非容器真实退出码
# —— 构建失败被误报成功。捕获退出码后立即恢复 trap。
trap - ERR
docker run "${DOCKER_OPTS[@]}" "$BUILD_IMAGE_FULL" 2>&1 | tee "$BUILD_REPORT"
RUN_STATUS=${PIPESTATUS[0]}
trap 'echo -e "\033[0;31m[ERROR]\033[0m 命令失败: ${BASH_COMMAND} (行号: ${LINENO})"' ERR
set -e

if [ "$RUN_STATUS" -eq 0 ]; then
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    log_success "构建流程完成！"
    echo ""
    log_info "构建报告: $BUILD_REPORT"

    # 显示构建摘要
    if [ -f "$TEMP_DIR/production-code/version.json" ]; then
        VERSION=$(awk -F '"' '/"version"/ {for(i=1;i<=NF;i++){if($i=="version"){print $(i+2); exit}}}' "$TEMP_DIR/production-code/version.json" 2>/dev/null || echo "")
        BUILD_TIME=$(awk -F '"' '/"build_time"/ {for(i=1;i<=NF;i++){if($i=="build_time"){print $(i+2); exit}}}' "$TEMP_DIR/production-code/version.json" 2>/dev/null || echo "")
        [ -n "$VERSION" ] && log_info "构建版本: $VERSION"
        [ -n "$BUILD_TIME" ] && log_info "构建时间: $BUILD_TIME"
        END_TIME=$(date +%s)
        ELAPSED=$((END_TIME - SCRIPT_START_TIME))
        log_info "构建用时: $(human_duration "$ELAPSED")"
        log_info "生产代码: $TEMP_DIR/production-code"
    fi

    # 可选：创建安装包
    if [ "$CREATE_PACKAGE" = "true" ]; then
        echo ""
        log_step "创建安装包"
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        PACKAGE_CHANNEL="${RELEASE_CHANNEL:-main}"
        if bash "$SCRIPT_DIR/scripts/package.sh" --channel "$PACKAGE_CHANNEL"; then
            log_success "安装包创建完成"
            log_info "安装包位于: $TEMP_DIR/packages"
        else
            log_warning "安装包创建失败"
        fi
        echo ""
    fi

    echo ""
    log_info "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    log_info "构建完成，产物位于: $TEMP_DIR/production-code"
    log_info "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    exit 0
else
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    log_error "构建失败"

    # 提取错误信息
    {
        echo "==== Matched Errors ===="
        grep -Ei "error|failed|fatal|exception|panic|traceback" "$BUILD_REPORT" || true
        echo -e "\n==== Last 200 Lines ===="
        tail -n 200 "$BUILD_REPORT" || true
    } >"$ERROR_REPORT" 2>/dev/null || true

    if [ -s "$ERROR_REPORT" ]; then
        log_error "错误信息已保存到: $ERROR_REPORT"
        echo ""
        log_info "错误详情:"
        cat "$ERROR_REPORT"
    fi

    END_TIME=$(date +%s)
    ELAPSED=$((END_TIME - SCRIPT_START_TIME))
    log_info "本次构建用时: $(human_duration "$ELAPSED")"
    log_warning "提示：可使用 --clear-cache 清理依赖缓存后再试"

    exit 1
fi
