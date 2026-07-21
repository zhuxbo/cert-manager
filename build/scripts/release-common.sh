#!/bin/bash

# Release 公共函数库
# 供 release.sh 使用

# ========================================
# 颜色定义
# ========================================
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# ========================================
# 日志函数
# ========================================
log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1" >&2; }
log_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_step() { echo -e "${CYAN}[STEP]${NC} $1"; }

# ========================================
# 获取版本号
# 优先级：git tag > version.json
# ========================================
get_version() {
    local project_root="$1"
    # 优先从 git tag 获取
    local tag_version=$(git describe --tags --exact-match 2>/dev/null | sed 's/^v//')
    if [ -n "$tag_version" ]; then
        echo "$tag_version"
        return
    fi
    # 回退到 version.json
    local version_file="$project_root/version.json"
    if [ -f "$version_file" ]; then
        grep -o '"version"[[:space:]]*:[[:space:]]*"[^"]*"' "$version_file" | head -1 | cut -d'"' -f4
    else
        echo ""
    fi
}

# ========================================
# 判断是否为开发版
# ========================================
is_dev_version() {
    local version="$1"
    if [[ "$version" =~ -(dev|alpha|beta|rc) ]]; then
        return 0
    fi
    return 1
}

# ========================================
# 获取发布通道
# ========================================
get_channel() {
    local version="$1"
    if is_dev_version "$version"; then
        echo "dev"
    else
        echo "main"
    fi
}

# ========================================
# 构建打包
# 参数: build_dir [version]
# ========================================
build_packages() {
    local build_dir="$1"
    local version="${2:-}"

    log_step "开始构建..."

    cd "$build_dir"
    if [ -f "scripts/package.sh" ]; then
        if [ -n "$version" ]; then
            bash scripts/package.sh --version "$version"
        else
            bash scripts/package.sh
        fi
    else
        log_error "未找到构建脚本: scripts/package.sh"
        return 1
    fi
}

# ========================================
# 生成 releases.json 更新的 Python 脚本
# 用于本地和远程执行
# ========================================
generate_releases_update_script() {
    local releases_file="$1"
    local version="$2"
    local channel="$3"
    local version_dir="$4"
    local rel_path="$5"
    local keep="${6:-5}"

    local created_at=$(date -Iseconds)
    local prerelease="False"
    [ "$channel" = "dev" ] && prerelease="True"

    cat <<PYEOF
import json
import hashlib
import os
from datetime import datetime

releases_file = '$releases_file'
version = '$version'
channel = '$channel'
prerelease = $prerelease
created_at = '$created_at'
rel_path = '$rel_path'
version_dir = '$version_dir'
keep = int('$keep')

def _sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as fp:
        for chunk in iter(lambda: fp.read(65536), b''):
            h.update(chunk)
    return h.hexdigest()

# 构建 assets（含 sha256，install.sh / upgrade.sh 强校验依赖）
assets = []
for f in os.listdir(version_dir):
    if f.endswith('.zip'):
        full = os.path.join(version_dir, f)
        size = os.path.getsize(full)
        assets.append({
            'name': f,
            'size': size,
            'sha256': _sha256(full),
            'browser_download_url': f'{rel_path}/{f}'
        })

new_release = {
    'tag_name': f'v{version}',
    'name': f'v{version}',
    'body': '',
    'prerelease': prerelease,
    'created_at': created_at,
    'published_at': created_at,
    'assets': assets
}

# 读取现有 releases
try:
    with open(releases_file, 'r') as f:
        data = json.load(f)
except:
    data = {'releases': []}

# 移除同版本旧条目
data['releases'] = [r for r in data.get('releases', []) if r.get('tag_name') != f'v{version}']

# 添加新条目
data['releases'].insert(0, new_release)

# 按发布时间排序
data['releases'].sort(key=lambda x: x.get('published_at', ''), reverse=True)

# main/dev 两个通道分别只保留最新 keep 条，避免一个通道挤占另一个通道的配额
kept = []
channel_counts = {'main': 0, 'dev': 0}
for release in data['releases']:
    release_channel = 'dev' if release.get('prerelease') is True else 'main'
    if channel_counts[release_channel] >= keep:
        continue
    kept.append(release)
    channel_counts[release_channel] += 1
data['releases'] = kept

# 保存
with open(releases_file, 'w') as f:
    json.dump(data, f, indent=2, ensure_ascii=False)

print(
    f'releases.json 已更新: v{version} '
    f'(main={channel_counts["main"]}, dev={channel_counts["dev"]})'
)
PYEOF
}

# ========================================
# 生成远程发布结果校验的 Python 脚本
# ========================================
generate_release_verify_script() {
    local releases_file="$1"
    local version="$2"
    local channel="$3"
    local version_dir="$4"
    local rel_path="$5"
    local latest_dir="$6"
    local keep="${7:-5}"

    local prerelease="False"
    [ "$channel" = "dev" ] && prerelease="True"

    cat <<PYEOF
import hashlib
import json
import os

releases_file = '$releases_file'
version = '$version'
channel = '$channel'
version_dir = '$version_dir'
rel_path = '$rel_path'
latest_dir = '$latest_dir'
keep = int('$keep')
prerelease = $prerelease

def fail(message):
    raise SystemExit(f'发布校验失败: {message}')

def sha256(path):
    digest = hashlib.sha256()
    with open(path, 'rb') as fp:
        for chunk in iter(lambda: fp.read(65536), b''):
            digest.update(chunk)
    return digest.hexdigest()

try:
    with open(releases_file, 'r') as fp:
        data = json.load(fp)
except Exception as exc:
    fail(f'无法读取 releases.json: {exc}')

releases = data.get('releases')
if not isinstance(releases, list):
    fail('releases.json 缺少 releases 数组')

counts = {'main': 0, 'dev': 0}
for release in releases:
    release_channel = 'dev' if release.get('prerelease') is True else 'main'
    counts[release_channel] += 1
if counts['main'] > keep or counts['dev'] > keep:
    fail(f'版本记录超限: main={counts["main"]}, dev={counts["dev"]}, keep={keep}')

matches = [release for release in releases if release.get('tag_name') == f'v{version}']
if len(matches) != 1:
    fail(f'releases.json 中 v{version} 记录数量为 {len(matches)}')
release = matches[0]
if release.get('prerelease') is not prerelease:
    fail(f'v{version} 的通道标记不正确')

expected_names = {
    f'ssl-manager-full-{version}.zip',
    f'ssl-manager-upgrade-{version}.zip',
    f'ssl-manager-script-{version}.zip',
}
actual_files = {
    name for name in os.listdir(version_dir)
    if name.startswith('ssl-manager-') and name.endswith('.zip')
}
if actual_files != expected_names:
    fail(f'远程包文件不完整: expected={sorted(expected_names)}, actual={sorted(actual_files)}')

assets = release.get('assets')
if not isinstance(assets, list):
    fail(f'v{version} 缺少 assets 数组')
assets_by_name = {asset.get('name'): asset for asset in assets}
if set(assets_by_name) != expected_names or len(assets) != len(expected_names):
    fail(f'assets 不完整或重复: {sorted(assets_by_name)}')

for name in sorted(expected_names):
    path = os.path.join(version_dir, name)
    asset = assets_by_name[name]
    if asset.get('size') != os.path.getsize(path):
        fail(f'{name} 大小不一致')
    if asset.get('sha256') != sha256(path):
        fail(f'{name} sha256 不一致')
    if asset.get('browser_download_url') != f'{rel_path}/{name}':
        fail(f'{name} 下载路径不正确')

for package_type in ('full', 'upgrade', 'script'):
    link = os.path.join(latest_dir, f'ssl-manager-{package_type}-latest.zip')
    target = os.path.join(version_dir, f'ssl-manager-{package_type}-{version}.zip')
    if not os.path.islink(link) or os.path.realpath(link) != os.path.realpath(target):
        fail(f'latest 链接不正确: {link}')

root_dir = os.path.dirname(releases_file)
for script_name in ('install.sh', 'upgrade.sh'):
    path = os.path.join(root_dir, script_name)
    if not os.path.isfile(path) or not os.access(path, os.X_OK):
        fail(f'{script_name} 不存在或不可执行')

print(
    f'远程发布校验通过: v{version}, '
    f'main={counts["main"]}, dev={counts["dev"]}, assets={len(expected_names)}'
)
PYEOF
}

# ========================================
# 生成公网发布结果校验的 Python 脚本
# ========================================
generate_public_release_verify_script() {
    local base_url="$1"
    local version="$2"
    local channel="$3"
    local keep="${4:-5}"

    local prerelease="False"
    [ "$channel" = "dev" ] && prerelease="True"

    cat <<PYEOF
import hashlib
import json
import time
from urllib.parse import urljoin
from urllib.request import Request, urlopen

base_url = '$base_url'.rstrip('/') + '/'
version = '$version'
channel = '$channel'
keep = int('$keep')
prerelease = $prerelease

def fail(message):
    raise RuntimeError(message)

def open_with_retry(url):
    last_error = None
    for attempt in range(1, 6):
        try:
            return urlopen(Request(url, headers={'Cache-Control': 'no-cache'}), timeout=60)
        except Exception as exc:
            last_error = exc
            if attempt < 5:
                time.sleep(attempt)
    fail(f'访问失败 {url}: {last_error}')

last_error = None
for attempt in range(1, 6):
    try:
        with open_with_retry(urljoin(base_url, 'releases.json')) as response:
            data = json.load(response)

        releases = data.get('releases')
        if not isinstance(releases, list):
            fail('releases.json 缺少 releases 数组')

        counts = {'main': 0, 'dev': 0}
        for item in releases:
            item_channel = 'dev' if item.get('prerelease') is True else 'main'
            counts[item_channel] += 1
        if counts['main'] > keep or counts['dev'] > keep:
            fail(f'版本记录超限: main={counts["main"]}, dev={counts["dev"]}, keep={keep}')

        matches = [item for item in releases if item.get('tag_name') == f'v{version}']
        if len(matches) != 1 or matches[0].get('prerelease') is not prerelease:
            fail(f'未找到通道正确且唯一的 v{version} 记录')
        release = matches[0]

        expected_names = {
            f'ssl-manager-full-{version}.zip',
            f'ssl-manager-upgrade-{version}.zip',
            f'ssl-manager-script-{version}.zip',
        }
        assets = release.get('assets')
        if not isinstance(assets, list):
            fail(f'v{version} 缺少 assets 数组')
        assets_by_name = {asset.get('name'): asset for asset in assets}
        if set(assets_by_name) != expected_names or len(assets) != len(expected_names):
            fail(f'assets 不完整或重复: {sorted(assets_by_name)}')

        for name in sorted(expected_names):
            asset = assets_by_name[name]
            expected_path = f'{channel}/v{version}/{name}'
            if asset.get('browser_download_url') != expected_path:
                fail(f'{name} 下载路径不正确')
            asset_url = urljoin(base_url, expected_path)
            digest = hashlib.sha256()
            size = 0
            with open_with_retry(asset_url) as response:
                while True:
                    chunk = response.read(65536)
                    if not chunk:
                        break
                    digest.update(chunk)
                    size += len(chunk)
            if size != asset.get('size'):
                fail(f'{name} 公网下载大小不一致')
            if digest.hexdigest() != asset.get('sha256'):
                fail(f'{name} 公网下载 sha256 不一致')

        for script_name in ('install.sh', 'upgrade.sh'):
            with open_with_retry(urljoin(base_url, script_name)) as response:
                if not response.read(1):
                    fail(f'{script_name} 公网内容为空')

        print(
            f'公网发布校验通过: v{version}, '
            f'main={counts["main"]}, dev={counts["dev"]}, assets={len(expected_names)}'
        )
        break
    except Exception as exc:
        last_error = exc
        if attempt < 5:
            time.sleep(attempt)
else:
    raise SystemExit(f'发布校验失败: {last_error}')
PYEOF
}

# ========================================
# 本地更新 releases.json
# ========================================
update_releases_json_local() {
    local release_dir="$1"
    local version="$2"
    local channel="$3"
    local version_dir="$4"
    local keep="${5:-5}"

    log_step "更新 releases.json..."

    local releases_file="$release_dir/releases.json"
    local rel_path="${version_dir#$release_dir/}"

    if command -v python3 &>/dev/null; then
        generate_releases_update_script "$releases_file" "$version" "$channel" "$version_dir" "$rel_path" "$keep" | python3
    else
        log_error "需要 python3 来更新 releases.json"
        return 1
    fi

    log_success "releases.json 已更新"
}

# ========================================
# 生成部署脚本内容（替换 RELEASE_URL）
# ========================================
process_deploy_script() {
    local script_path="$1"
    local release_url="$2"

    if [ -f "$script_path" ]; then
        sed "s|RELEASE_URL_PLACEHOLDER=\"__RELEASE_URL__\"|RELEASE_URL_PLACEHOLDER=\"$release_url\"|g" "$script_path"
    fi
}

# ========================================
# 本地部署脚本
# ========================================
deploy_scripts_local() {
    local project_root="$1"
    local release_dir="$2"
    local release_url="$3"

    log_step "部署脚本..."

    local deploy_dir="$project_root/deploy"

    # 部署 install.sh
    if [ -f "$deploy_dir/install.sh" ]; then
        process_deploy_script "$deploy_dir/install.sh" "$release_url" | sudo tee "$release_dir/install.sh" >/dev/null
        sudo chmod +x "$release_dir/install.sh"
        log_info "已部署: install.sh"
    fi

    # 部署 upgrade.sh
    if [ -f "$deploy_dir/upgrade.sh" ]; then
        process_deploy_script "$deploy_dir/upgrade.sh" "$release_url" | sudo tee "$release_dir/upgrade.sh" >/dev/null
        sudo chmod +x "$release_dir/upgrade.sh"
        log_info "已部署: upgrade.sh"
    fi

    log_success "脚本部署完成"
}

# ========================================
# 更新 latest 符号链接
# ========================================
update_latest_symlinks_local() {
    local release_dir="$1"
    local version_dir="$2"
    local channel="$3"

    log_step "更新 latest 链接..."

    local latest_dir="$release_dir/latest"
    [ "$channel" = "dev" ] && latest_dir="$release_dir/dev-latest"

    sudo mkdir -p "$latest_dir"

    for pkg in "$version_dir"/ssl-manager-*.zip; do
        if [ -f "$pkg" ]; then
            local filename=$(basename "$pkg")
            # 提取包类型（full, upgrade, script）
            local pkg_type=$(echo "$filename" | sed 's/ssl-manager-\([^-]*\)-.*/\1/')
            local latest_name="ssl-manager-$pkg_type-latest.zip"
            sudo rm -f "$latest_dir/$latest_name"
            sudo ln -s "../${version_dir#$release_dir/}/$filename" "$latest_dir/$latest_name"
            log_info "已链接: $latest_name -> $filename"
        fi
    done

    # 验证符号链接有效性
    verify_symlinks "$latest_dir"
}

# ========================================
# 验证符号链接有效性
# ========================================
verify_symlinks() {
    local dir="$1"
    local has_error=false

    if [ ! -d "$dir" ]; then
        return 0
    fi

    for link in "$dir"/*.zip; do
        if [ -L "$link" ]; then
            if [ ! -e "$link" ]; then
                log_warning "失效的符号链接: $link -> $(readlink "$link")"
                has_error=true
            fi
        fi
    done

    if [ "$has_error" = false ]; then
        log_success "符号链接验证通过"
    fi
}

# ========================================
# 清理旧版本（保留最新 N 个）
# ========================================
cleanup_old_versions_local() {
    local release_dir="$1"
    local channel="$2"
    local keep="${3:-5}"

    log_info "清理旧版本（保留 $keep 个）..."

    local channel_dir="$release_dir/$channel"
    if [ -d "$channel_dir" ]; then
        cd "$channel_dir"
        ls -dt v* 2>/dev/null | tail -n +$((keep + 1)) | xargs -r sudo rm -rf
    fi
}

# ========================================
# 检测 Web 用户
# 宝塔环境: www
# 通用 Debian/Ubuntu: www-data
# CentOS/RHEL: nginx
# ========================================
detect_web_user() {
    # 检测宝塔环境
    if [ -d "/www/server/panel" ] && id -u www &>/dev/null; then
        echo "www"
        return
    fi

    # 检测 www-data 用户 (Debian/Ubuntu)
    if id -u www-data &>/dev/null; then
        echo "www-data"
        return
    fi

    # 检测 nginx 用户 (CentOS/RHEL)
    if id -u nginx &>/dev/null; then
        echo "nginx"
        return
    fi

    # 回退到 root
    echo "root"
}

# ========================================
# 打印发布横幅
# ========================================
print_release_banner() {
    local title="$1"
    echo ""
    echo -e "${CYAN}╔═══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC}           ${GREEN}$title${NC}                        ${CYAN}║${NC}"
    echo -e "${CYAN}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
}
