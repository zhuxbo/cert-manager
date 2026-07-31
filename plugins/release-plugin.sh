#!/bin/bash

# 通用插件构建与发布脚本
# 发布到 {RELEASE_DIR}/plugins/{name}/
#
# 配置文件查找优先级:
#   1. plugins/release.conf（插件专用）
#   2. build/release.conf（主系统回落）
#
# 用法:
#   ./plugins/release-plugin.sh easy --version 0.1.0          # 构建+发布
#   ./plugins/release-plugin.sh easy --version 0.1.0 --build-only  # 仅构建
#   ./plugins/release-plugin.sh easy --version 0.1.0 --publish-only # 仅发布已有 zip
#   ./plugins/release-plugin.sh easy --version 0.1.0 --server cn   # 只发布到指定服务器

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
BUILD_DIR="$PROJECT_ROOT/build"

# ========================================
# 颜色和日志
# ========================================
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1" >&2; }
log_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_step() { echo -e "${CYAN}[STEP]${NC} $1"; }

# ========================================
# 帮助
# ========================================
show_help() {
    cat <<EOF
用法: $0 <插件名或目录> [选项]

选项:
  --version VERSION   指定版本号（注入到打包产物，不修改源文件）
  --build-only        仅构建打包，不发布
  --publish-only      仅发布已有的 zip，跳过构建
  --server NAME       只发布到指定服务器
  -h, --help          显示帮助

配置文件查找优先级:
  1. plugins/release.conf（插件专用）
  2. build/release.conf（主系统回落）

示例:
  $0 easy                              构建并远程发布
  $0 easy --build-only                 仅构建
  $0 easy --local                      构建并本地发布
  $0 easy --publish-only --local       仅本地发布已有包
  $0 easy --remote --server cn         仅远程发布到 cn 服务器
EOF
}

# ========================================
# 解析参数
# ========================================
PLUGIN_INPUT=""
BUILD_ONLY=false
PUBLISH_ONLY=false
TARGET_SERVER=""
INPUT_VERSION=""

while [ $# -gt 0 ]; do
    case "$1" in
        --version)
            INPUT_VERSION="$2"
            shift 2
            ;;
        --build-only)
            BUILD_ONLY=true
            shift
            ;;
        --publish-only)
            PUBLISH_ONLY=true
            shift
            ;;
        --server)
            TARGET_SERVER="$2"
            shift 2
            ;;
        -h | --help)
            show_help
            exit 0
            ;;
        -*)
            log_error "未知选项: $1"
            show_help
            exit 1
            ;;
        *)
            PLUGIN_INPUT="$1"
            shift
            ;;
    esac
done

if [ -z "$PLUGIN_INPUT" ]; then
    log_error "请指定插件名或目录"
    show_help
    exit 1
fi

# 解析插件目录
if [ -d "$PLUGIN_INPUT" ]; then
    PLUGIN_DIR="$(cd "$PLUGIN_INPUT" && pwd)"
elif [ -d "$SCRIPT_DIR/$PLUGIN_INPUT" ]; then
    PLUGIN_DIR="$SCRIPT_DIR/$PLUGIN_INPUT"
else
    log_error "插件目录不存在: $PLUGIN_INPUT"
    exit 1
fi

cd "$PLUGIN_DIR"

# 验证 plugin.json
if [ ! -f "plugin.json" ]; then
    log_error "plugin.json 不存在"
    exit 1
fi

# 读取插件名
NAME=$(grep -o '"name"[[:space:]]*:[[:space:]]*"[^"]*"' plugin.json | head -1 | cut -d'"' -f4)

if [ -z "$NAME" ]; then
    log_error "无法从 plugin.json 读取插件名"
    exit 1
fi

# 读取版本要求（可选）
REQUIRES=$(grep -o '"requires"[[:space:]]*:[[:space:]]*"[^"]*"' plugin.json | head -1 | cut -d'"' -f4)

# 版本号必须通过 --version 指定
if [ -z "$INPUT_VERSION" ]; then
    log_error "请通过 --version 指定版本号"
    exit 1
fi
VERSION="${INPUT_VERSION#v}"

OUTPUT_DIR="$SCRIPT_DIR/temp"
mkdir -p "$OUTPUT_DIR"
OUTPUT_FILE="$NAME-plugin-$VERSION.zip"
OUTPUT="$OUTPUT_DIR/$OUTPUT_FILE"

echo ""
echo -e "${CYAN}╔═══════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║${NC}           ${GREEN}插件构建与发布: $NAME v$VERSION${NC}"
echo -e "${CYAN}╚═══════════════════════════════════════════════════════════╝${NC}"
echo ""

# ========================================
# 查找配置文件（plugins/ 优先，build/ 回落）
# ========================================
find_config() {
    local filename="$1"
    local plugin_conf="$SCRIPT_DIR/$filename"
    local build_conf="$BUILD_DIR/$filename"

    if [ -f "$plugin_conf" ]; then
        log_info "使用插件配置: $plugin_conf" >&2
        echo "$plugin_conf"
    elif [ -f "$build_conf" ]; then
        log_info "使用主系统配置: $build_conf" >&2
        echo "$build_conf"
    else
        echo ""
    fi
}

# ========================================
# zip 不变量守卫
# ========================================
# ① backend/vendor/ 不进发布包（硬不变量：插件 vendor 由主系统 PluginComposerRunner 运行时安装）
# ② 含 backend/composer.json 时必须同含 backend/composer.lock
#    （缺 lock 会使 PluginComposerRunner::lockHash 恒返回 '' 破坏更新检测，composer install 退化为非锁定解析）
verify_zip_invariants() {
    local zip="$1"

    if [ ! -f "$zip" ]; then
        log_error "zip 不存在，无法校验不变量: $zip"
        exit 1
    fi

    local listing
    listing=$(unzip -l "$zip") || {
        log_error "unzip -l 失败，zip 可能已损坏: $zip"
        exit 1
    }

    if echo "$listing" | grep -q 'backend/vendor/'; then
        log_error "发布包含 backend/vendor/，违反不变量（vendor 不入发布包）: $zip"
        log_info "请在 build.json 的 exclude 中排除 backend/vendor/"
        exit 1
    fi

    if echo "$listing" | grep -qE 'backend/composer\.json$'; then
        if ! echo "$listing" | grep -qE 'backend/composer\.lock$'; then
            log_error "发布包含 backend/composer.json 但缺 backend/composer.lock: $zip"
            log_info "缺 lock 会破坏 PluginComposerRunner 更新检测，请将 backend/composer.lock 加入 build.json 的 include"
            exit 1
        fi
    fi

    log_success "zip 不变量校验通过（无 backend/vendor/，composer.json/lock 配对）"
}

# ========================================
# 构建阶段
# ========================================
build_plugin() {
    install_frontend_dependencies() {
        local install_args=(
            install
            --config.confirm-modules-purge=false
            --frozen-lockfile
        )

        if CI=true pnpm "${install_args[@]}" --offline; then
            log_success "依赖安装命中本地 store（offline）"
            return
        fi

        if [ "${PNPM_ALLOW_NETWORK_FALLBACK:-0}" != "1" ]; then
            log_error "本地 pnpm store 不完整，已停止，未自动联网"
            log_info "确认允许联网后使用 PNPM_ALLOW_NETWORK_FALLBACK=1 重跑"
            return 1
        fi

        log_warning "本地 store 不完整，按授权使用 prefer-offline 联网补齐"
        CI=true pnpm "${install_args[@]}" --prefer-offline
    }

    build_frontend_side() (
        set -e

        local side="$1"
        local source_dir="$PLUGIN_DIR/frontend/$side"
        local isolated_frontend_root
        local isolated_side
        isolated_frontend_root=$(mktemp -d)
        isolated_side="$isolated_frontend_root/$side"
        trap 'rm -rf "$isolated_frontend_root"' EXIT

        # 始终在临时独立 workspace 构建，既不受仓库根 workspace 劫持，也不信任
        # pnpm 自动生成的占位配置。保留 frontend/<side>/../shared 的相对布局，
        # 依赖生命周期脚本只放行当前审核过的三项。
        mkdir -p "$isolated_side"
        rsync -a \
            --exclude node_modules \
            --exclude dist \
            "$source_dir/" "$isolated_side/"
        if [ -d "$PLUGIN_DIR/frontend/shared" ]; then
            rsync -a \
                --exclude node_modules \
                --exclude dist \
                "$PLUGIN_DIR/frontend/shared/" \
                "$isolated_frontend_root/shared/"
        fi
        cat >"$isolated_side/pnpm-workspace.yaml" <<'EOF'
allowBuilds:
  '@parcel/watcher': true
  esbuild: true
  vue-demi: true
EOF

        cd "$isolated_side"
        install_frontend_dependencies
        # shared 位于 side 的同级目录，Node 会从 shared 向父目录解析依赖；
        # 将本轮隔离安装结果暴露在临时 frontend 根，避免回落到仓库根 node_modules。
        ln -s "$isolated_side/node_modules" "$isolated_frontend_root/node_modules"
        pnpm build

        rm -rf "$source_dir/dist"
        mkdir -p "$source_dir/dist"
        cp -R "$isolated_side/dist/." "$source_dir/dist/"
    )

    # 构建前端（源码在 frontend/{admin,user}/，产物输出到 dist/）
    for side in admin user; do
        if [ -d "frontend/$side" ] && [ -f "frontend/$side/package.json" ]; then
            log_step "构建 $side 端..."
            build_frontend_side "$side"
            log_success "$side 端构建完成"
        fi
    done

    # 读取 build.json 打包配置
    if [ ! -f "build.json" ]; then
        log_error "build.json 不存在"
        exit 1
    fi

    WORK_DIR=$(mktemp -d)
    PACK_DIR="$WORK_DIR/$NAME"
    mkdir -p "$PACK_DIR"

    log_step "打包产物..."

    # 从 build.json 读取 include 列表并复制
    while IFS= read -r item; do
        item=$(echo "$item" | tr -d '",' | xargs)
        [ -z "$item" ] && continue
        if [ -e "$PLUGIN_DIR/$item" ]; then
            mkdir -p "$PACK_DIR/$(dirname "$item")"
            cp -r "$PLUGIN_DIR/$item" "$PACK_DIR/$item"
        fi
    done < <(grep -A 100 '"include"' build.json | grep '"' | grep -v 'include\|exclude\|\[' | head -20)

    # 注入版本号到临时副本（源文件不含 version 字段）
    if [ -f "$PACK_DIR/plugin.json" ]; then
        sed -i.bak 's/"name"[[:space:]]*:/"version": "'"$VERSION"'", "name":/' "$PACK_DIR/plugin.json"
        rm -f "$PACK_DIR/plugin.json.bak"
        # 用 python 格式化 JSON（保持可读性）
        if command -v python3 &>/dev/null; then
            python3 -c "import json; d=json.load(open('$PACK_DIR/plugin.json')); json.dump(d, open('$PACK_DIR/plugin.json','w'), indent=2, ensure_ascii=False)"
        fi
        log_info "已注入版本号: $VERSION"
    fi

    # 复制前端构建产物（从 dist/ 到 frontend/{admin,user}/）
    for side in admin user; do
        if [ -d "$PLUGIN_DIR/frontend/$side/dist" ]; then
            mkdir -p "$PACK_DIR/frontend/$side"
            cp -f "$PLUGIN_DIR/frontend/$side/dist/"* "$PACK_DIR/frontend/$side/"
            log_info "已复制 $side 端构建产物"
        fi
    done

    # 清理排除项
    while IFS= read -r item; do
        item=$(echo "$item" | tr -d '",' | xargs)
        [ -z "$item" ] && continue
        # 支持路径（如 backend/tests/）和文件名（如 .gitignore）
        if [ -e "$PACK_DIR/$item" ]; then
            rm -rf "$PACK_DIR/$item"
        else
            find "$PACK_DIR" -name "$item" -exec rm -rf {} + 2>/dev/null || true
        fi
    done < <(grep -A 100 '"exclude"' build.json | grep '"' | grep -v 'exclude\|\[' | head -20)

    # 打包（先删除旧 zip，避免 zip 更新模式残留已删除文件）
    rm -f "$OUTPUT"
    cd "$WORK_DIR"
    zip -rq "$OUTPUT" "$NAME"
    rm -rf "$WORK_DIR"

    verify_zip_invariants "$OUTPUT"

    local package_size=$(du -h "$OUTPUT" | cut -f1)
    log_success "打包完成: $OUTPUT ($package_size)"
}

# ========================================
# 生成 releases.json 更新的 Python 脚本
# ========================================
generate_plugin_releases_update() {
    local releases_file="$1"
    local version="$2"
    local zip_path="$3"
    local rel_download_url="$4"

    local created_at=$(date -Iseconds)
    local zip_size=$(stat -f%z "$zip_path" 2>/dev/null || stat -c%s "$zip_path" 2>/dev/null || echo 0)

    cat <<PYEOF
import json
import hashlib

releases_file = '$releases_file'
version = '$version'
created_at = '$created_at'
zip_path = '$zip_path'


def _sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as fp:
        for chunk in iter(lambda: fp.read(65536), b''):
            h.update(chunk)
    return h.hexdigest()


new_release = {
    'tag_name': f'v{version}',
    'name': f'v{version}',
    'body': '',
    'prerelease': False,
    'created_at': created_at,
    'published_at': created_at,
    'assets': [{
        'name': '$(basename "$zip_path")',
        'size': $zip_size,
        'sha256': _sha256(zip_path),
        'browser_download_url': '$rel_download_url'
    }]
}

try:
    with open(releases_file, 'r') as f:
        data = json.load(f)
except:
    data = {'releases': []}

# 移除同版本旧条目
data['releases'] = [r for r in data.get('releases', []) if r.get('tag_name') != f'v{version}']
data['releases'].insert(0, new_release)
data['releases'].sort(key=lambda x: x.get('published_at', ''), reverse=True)

with open(releases_file, 'w') as f:
    json.dump(data, f, indent=2, ensure_ascii=False)

print(f'releases.json 已更新: v{version}')
PYEOF
}

# ========================================
# 远程发布
# ========================================
publish_remote() {
    local config_file=$(find_config "release.conf")
    if [ -z "$config_file" ]; then
        log_error "未找到远程发布配置"
        log_info "请创建以下任一配置文件:"
        log_info "  plugins/release.conf（插件专用）"
        log_info "  build/release.conf（主系统共享）"
        exit 1
    fi
    # 回落到主系统配置时，plugins 目录与主系统目录同级，需要去掉最后一段路径
    local is_fallback_config=false
    if [[ "$config_file" == *"build/release.conf"* ]]; then
        is_fallback_config=true
    fi
    source "$config_file"

    if [ ${#SERVERS[@]} -eq 0 ] || [ -z "$SSH_USER" ] || [ -z "$SSH_KEY" ]; then
        log_error "远程配置不完整，请检查 SERVERS/SSH_USER/SSH_KEY"
        exit 1
    fi

    SSH_KEY="${SSH_KEY/#\~/$HOME}"
    if [ ! -f "$SSH_KEY" ]; then
        log_error "SSH 密钥不存在: $SSH_KEY"
        exit 1
    fi

    local ssh_timeout="${SSH_TIMEOUT:-10}"

    for server_str in "${SERVERS[@]}"; do
        IFS=',' read -r srv_name srv_host srv_port srv_dir srv_url <<<"$server_str"
        srv_port=${srv_port:-22}

        # 过滤指定服务器
        if [ -n "$TARGET_SERVER" ] && [ "$srv_name" != "$TARGET_SERVER" ]; then
            continue
        fi

        log_step "发布到 $srv_name ($srv_host) ..."

        local remote_plugin_dir
        if [ "$is_fallback_config" = true ]; then
            # 回落主系统配置：plugins 与主系统目录同级
            remote_plugin_dir="$(dirname "$srv_dir")/plugins/$NAME"
        else
            remote_plugin_dir="$srv_dir/plugins/$NAME"
        fi
        local remote_version_dir="$remote_plugin_dir/v$VERSION"
        local rel_url="v$VERSION/$OUTPUT_FILE"

        # 创建远程目录 & 上传
        ssh -i "$SSH_KEY" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=$ssh_timeout \
            -p "$srv_port" "$SSH_USER@$srv_host" "mkdir -p $remote_version_dir"

        rsync -avz --progress -e "ssh -i $SSH_KEY -o StrictHostKeyChecking=accept-new -p $srv_port" \
            "$OUTPUT" "$SSH_USER@$srv_host:$remote_version_dir/"
        log_info "已上传: $OUTPUT_FILE"

        # 远程更新 releases.json
        log_info "更新 releases.json ..."
        local remote_releases_file="$remote_plugin_dir/releases.json"
        local zip_size=$(stat -f%z "$OUTPUT" 2>/dev/null || stat -c%s "$OUTPUT" 2>/dev/null || echo 0)

        ssh -i "$SSH_KEY" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=$ssh_timeout \
            -p "$srv_port" "$SSH_USER@$srv_host" "python3 << 'PYEOF'
import json, os, hashlib
from datetime import datetime

releases_file = '$remote_releases_file'
version_dir = '$remote_version_dir'
version = '$VERSION'
requires = '$REQUIRES'
created_at = '$(date -Iseconds)'


def _sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as fp:
        for chunk in iter(lambda: fp.read(65536), b''):
            h.update(chunk)
    return h.hexdigest()


# sha256 由远端对已上传的 zip 计算（hex 小写），与主系统 release-common.sh 线协议一致；
# 主系统 PluginManager 下载后据此强校验（缺失则告警放行）
zip_path = os.path.join(version_dir, '$OUTPUT_FILE')

new_release = {
    'tag_name': f'v{version}',
    'name': f'v{version}',
    'body': '',
    'prerelease': False,
    'created_at': created_at,
    'published_at': created_at,
    'assets': [{
        'name': '$OUTPUT_FILE',
        'size': $zip_size,
        'sha256': _sha256(zip_path),
        'browser_download_url': '$rel_url'
    }]
}

if requires:
    new_release['requires'] = requires

try:
    with open(releases_file, 'r') as f:
        data = json.load(f)
except:
    data = {'releases': []}

data['releases'] = [r for r in data.get('releases', []) if r.get('tag_name') != f'v{version}']
data['releases'].insert(0, new_release)
data['releases'].sort(key=lambda x: x.get('published_at', ''), reverse=True)

# 只保留最新 5 个版本，清理多余的版本目录
max_keep = 5
removed = []
if len(data['releases']) > max_keep:
    plugin_dir = os.path.dirname(releases_file)
    for old in data['releases'][max_keep:]:
        tag = old.get('tag_name', '')
        old_dir = os.path.join(plugin_dir, tag)
        if os.path.isdir(old_dir):
            import shutil
            shutil.rmtree(old_dir)
            removed.append(tag)
    data['releases'] = data['releases'][:max_keep]

with open(releases_file, 'w') as f:
    json.dump(data, f, indent=2, ensure_ascii=False)

parts = [f'releases.json 已更新: v{version}']
if requires:
    parts.append(f'requires {requires}')
if removed:
    parts.append('已清理旧版本: ' + ' '.join(removed))
print(' | '.join(parts))
PYEOF"

        log_success "$srv_name: 发布完成"
        local verify_base_url
        if [ "$is_fallback_config" = true ]; then
            verify_base_url="${srv_url%/*}"
        else
            verify_base_url="$srv_url"
        fi
        log_info "验证: curl $verify_base_url/plugins/$NAME/releases.json | jq ."
    done
}

# ========================================
# 主流程
# ========================================

# 构建
if [ "$PUBLISH_ONLY" = false ]; then
    build_plugin
fi

# 检查 zip 是否存在（发布需要）
if [ "$BUILD_ONLY" = false ]; then
    if [ ! -f "$OUTPUT" ]; then
        log_error "插件包不存在: ${OUTPUT}，请先构建"
        exit 1
    fi

    # 发布前再校验（--publish-only 直接发布既有 zip，会绕过 build 期校验）
    verify_zip_invariants "$OUTPUT"

    # 发布
    publish_remote
fi

echo ""
log_success "完成！"
