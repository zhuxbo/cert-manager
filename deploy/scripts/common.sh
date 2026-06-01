#!/bin/bash

# SSL Manager 公共函数库
# 当前消费者：bt-install.sh 通过 source 加载（install.sh / upgrade.sh 不 source，自带同名函数）

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
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_step() { echo -e "${CYAN}[STEP]${NC} $1"; }

# ========================================
# 环境检测
# ========================================

# 检测宝塔面板环境
check_bt_panel() {
    if [ -f "/www/server/panel/BT-Panel" ] ||
        [ -f "/www/server/panel/class/panelPlugin.py" ] ||
        ([ -d "/www/server/panel" ] && [ -f "/www/server/panel/data/port.pl" ]); then
        return 0
    fi
    return 1
}

# ========================================
# 交互
# ========================================

# 确认提示（y/N 或 Y/n）
confirm() {
    local message="$1"
    local default="${2:-n}"

    if [ "$default" = "y" ]; then
        read -p "$message [Y/n]: " choice </dev/tty
        case "$choice" in
            n | N) return 1 ;;
            *) return 0 ;;
        esac
    else
        read -p "$message [y/N]: " choice </dev/tty
        case "$choice" in
            y | Y) return 0 ;;
            *) return 1 ;;
        esac
    fi
}

# ========================================
# SHA256 校验
# ========================================

# 计算文件 SHA256（三平台 fallback：sha256sum / shasum / openssl）
file_sha256() {
    local file="$1"
    if command -v sha256sum &>/dev/null; then
        sha256sum "$file" | cut -d' ' -f1
    elif command -v shasum &>/dev/null; then
        shasum -a 256 "$file" | cut -d' ' -f1
    elif command -v openssl &>/dev/null; then
        openssl dgst -sha256 "$file" | awk '{print $NF}'
    else
        return 1
    fi
}

# 计算文件 SHA384（三平台 fallback：sha384sum / shasum / openssl）
# 用于校验 Composer installer（官方仅提供 SHA384 签名）
file_sha384() {
    local file="$1"
    if command -v sha384sum &>/dev/null; then
        sha384sum "$file" | cut -d' ' -f1
    elif command -v shasum &>/dev/null; then
        shasum -a 384 "$file" | cut -d' ' -f1
    elif command -v openssl &>/dev/null; then
        openssl dgst -sha384 "$file" | awk '{print $NF}'
    else
        return 1
    fi
}

# 强校验文件 SHA256；用法：verify_sha256 <file> <expected_sha256>
# expected 大小写无关；不匹配 / 缺工具 → return 1 + log_error
verify_sha256() {
    local file="$1"
    local expected="$2"

    if [ ! -f "$file" ]; then
        log_error "SHA256 校验失败：文件不存在 $file"
        return 1
    fi
    if [ -z "$expected" ]; then
        log_error "SHA256 校验失败：未提供期望 SHA256"
        return 1
    fi

    local actual
    actual=$(file_sha256 "$file") || {
        log_error "SHA256 校验失败：缺少 sha256sum/shasum/openssl 工具"
        return 1
    }
    actual=$(echo "$actual" | tr 'A-Z' 'a-z')
    expected=$(echo "$expected" | tr 'A-Z' 'a-z')

    if [ "$actual" != "$expected" ]; then
        log_error "SHA256 校验不匹配:"
        log_error "  文件: $file"
        log_error "  期望: $expected"
        log_error "  实际: $actual"
        return 1
    fi

    return 0
}

# 从 releases.json 提取指定 version + asset 的 sha256
# 用法: release_sha256 <releases_file> <version> <asset_filename>
#   version: 0.4.22-beta（不含 v 前缀）
#   asset_filename: ssl-manager-{full|script|upgrade}-<version>.zip
# 不依赖 jq；兼容紧凑 / 展开两种 JSON 布局
# 找不到字段 → 输出空 + return 1
release_sha256() {
    local releases_file="$1"
    local version="$2"
    local asset_name="$3"

    if [ ! -f "$releases_file" ]; then
        return 1
    fi

    local sha
    sha=$(awk -v ver="v$version" -v aname="$asset_name" '
        BEGIN { in_target_release = 0; in_target_asset = 0 }
        # 进入 release 块时检查 tag_name（同行）
        match($0, /"tag_name"[[:space:]]*:[[:space:]]*"v[^"]+"/) {
            s = substr($0, RSTART, RLENGTH)
            gsub(/.*"tag_name"[[:space:]]*:[[:space:]]*"/, "", s)
            gsub(/".*/, "", s)
            in_target_release = (s == ver) ? 1 : 0
            in_target_asset = 0
            next
        }
        # 在目标 release 内：检查 asset 的 name（同行）
        in_target_release && match($0, /"name"[[:space:]]*:[[:space:]]*"[^"]+\.zip"/) {
            s = substr($0, RSTART, RLENGTH)
            gsub(/.*"name"[[:space:]]*:[[:space:]]*"/, "", s)
            gsub(/".*/, "", s)
            in_target_asset = (s == aname) ? 1 : 0
            next
        }
        # 在目标 asset 块内：找 sha256
        in_target_release && in_target_asset && match($0, /"sha256"[[:space:]]*:[[:space:]]*"[^"]+"/) {
            s = substr($0, RSTART, RLENGTH)
            gsub(/.*"sha256"[[:space:]]*:[[:space:]]*"/, "", s)
            gsub(/".*/, "", s)
            print s
            exit
        }
    ' "$releases_file")

    if [ -z "$sha" ]; then
        return 1
    fi
    echo "$sha"
    return 0
}

# ========================================
# 下载
# ========================================

# 解析版本标识 → release URL 路径片段
# latest/dev → 占位符；具体版本号 → 透传
resolve_version_tag() {
    local version="$1"
    case "$version" in
        latest) echo "latest" ;;  # main 通道 latest tag
        dev) echo "dev-latest" ;; # dev 通道 latest tag
        *) echo "$version" ;;
    esac
}

# 下载 Release 包
# 用法: download_release_file <filename> <save_path> [version]
# version: latest（默认）/ dev / 具体版本号（如 1.0.0、0.4.22-beta）
# 必须配置环境变量 CUSTOM_RELEASE_URL
download_release_file() {
    local filename="$1"
    local save_path="$2"
    local version="${3:-latest}"

    if [ -z "$CUSTOM_RELEASE_URL" ]; then
        log_error "未配置 release 服务 URL"
        log_info "请使用 --url 参数指定，或设置环境变量 CUSTOM_RELEASE_URL"
        return 1
    fi

    local base_url="${CUSTOM_RELEASE_URL%/}"
    local url=""

    local tag
    tag=$(resolve_version_tag "$version")

    # 构建 URL：占位符走 latest/dev-latest 目录；具体版本按通道走 main/dev
    if [[ "$tag" == "dev-latest" ]]; then
        url="$base_url/dev-latest/$filename"
    elif [[ "$tag" == "latest" ]]; then
        url="$base_url/latest/$filename"
    else
        if [[ "$version" =~ -(dev|alpha|beta|rc) ]]; then
            url="$base_url/dev/v$version/$filename"
        else
            url="$base_url/main/v$version/$filename"
        fi
    fi

    log_info "下载: $filename (版本: $version)"
    log_info "URL: $url"

    local curl_output=""
    local curl_exit=0
    curl_output=$(curl -fsSL --connect-timeout 10 --max-time 300 -o "$save_path" "$url" 2>&1) || curl_exit=$?

    if [ $curl_exit -eq 0 ]; then
        log_success "下载成功"
        return 0
    fi

    [ -f "$save_path" ] && rm -f "$save_path"

    log_error "下载失败: $filename (curl exit code: $curl_exit)"
    [ -n "$curl_output" ] && log_error "$curl_output"
    case $curl_exit in
        6) log_info "提示: 无法解析域名，请检查 DNS 或网络配置" ;;
        7) log_info "提示: 无法连接服务器" ;;
        22) log_info "提示: 服务器返回错误（文件可能不存在）" ;;
        28) log_info "提示: 下载超时" ;;
        35 | 51 | 60) log_info "提示: SSL/TLS 错误，旧系统可尝试 yum update ca-certificates" ;;
    esac
    return 1
}

# ========================================
# PHP 工具（版本探测 / requirements.json 解析）
# ========================================

# 把宝塔 PHP 目录名（如 83/84/810）渲染为友好版本号（如 8.3.21）。
# CLI 不可用时回落到目录名拼接（83 → 8.3，810 → 810）。
#
# 注意：upgrade.sh 独立部署，不 source common.sh，需自带同步副本；
#       修改本函数时请同步检查 deploy/upgrade.sh::_php_pretty_version。
_php_pretty_version() {
    local ver="$1"
    local php_bin="/www/server/php/$ver/bin/php"
    if [ -x "$php_bin" ]; then
        local actual
        actual=$("$php_bin" -r 'echo PHP_VERSION;' 2>/dev/null)
        [ -n "$actual" ] && {
            echo "$actual"
            return 0
        }
    fi
    # 仅当 ver 为 2 位（83/84）时拼成 8.X；3 位（810）直接打目录名
    if [ "${#ver}" -eq 2 ]; then
        echo "${ver:0:1}.${ver:1}"
    else
        echo "$ver"
    fi
}

# 探测一个可用的 PHP CLI（任意版本，仅用作工具：解析 JSON / 版本比较）。
# 成功设置全局 PHP_PROBE_BIN 并 return 0；找不到 return 1。
# 已设置 PHP_PROBE_BIN 时直接复用，避免重复扫描。
_probe_any_php() {
    if [ -n "${PHP_PROBE_BIN:-}" ] && [ -x "$PHP_PROBE_BIN" ]; then
        return 0
    fi
    local ver_dir php_bin
    for ver_dir in /www/server/php/*; do
        [ -d "$ver_dir" ] || continue
        php_bin="$ver_dir/bin/php"
        if [ -x "$php_bin" ]; then
            PHP_PROBE_BIN="$php_bin"
            return 0
        fi
    done
    if command -v php &>/dev/null; then
        PHP_PROBE_BIN=$(command -v php)
        return 0
    fi
    return 1
}

# 从 php-requirements.json 读取顶层标量字段（如 php_min / php_recommended）。
# 用法：_read_req_field <req_file> <field> [fallback]
# 文件不存在 / 探测不到 PHP / 字段缺失 → 输出 fallback（默认空）。
_read_req_field() {
    local req_file="$1"
    local field="$2"
    local fallback="${3:-}"
    if [ ! -f "$req_file" ]; then
        echo "$fallback"
        return 0
    fi
    if ! _probe_any_php; then
        echo "$fallback"
        return 0
    fi
    local result
    result=$(REQ_FILE="$req_file" FIELD="$field" "$PHP_PROBE_BIN" -r '
$d = @json_decode(@file_get_contents(getenv("REQ_FILE")), true);
echo is_array($d) && isset($d[getenv("FIELD")]) ? $d[getenv("FIELD")] : "";
' 2>/dev/null)
    if [ -n "$result" ]; then
        echo "$result"
    else
        echo "$fallback"
    fi
}

# 从 php-requirements.json 读取数组字段，每行一个元素（如 extensions.required）。
# 用法：_read_req_array <req_file> <dot.path>
# 文件不存在 / 探测不到 PHP / 路径缺失 → 输出空。
_read_req_array() {
    local req_file="$1"
    local path="$2"
    if [ ! -f "$req_file" ]; then
        return 0
    fi
    if ! _probe_any_php; then
        return 0
    fi
    REQ_FILE="$req_file" PATHSPEC="$path" "$PHP_PROBE_BIN" -r '
$d = @json_decode(@file_get_contents(getenv("REQ_FILE")), true);
foreach (explode(".", getenv("PATHSPEC")) as $k) {
    if (! is_array($d) || ! isset($d[$k])) { exit(0); }
    $d = $d[$k];
}
if (is_array($d)) {
    foreach ($d as $x) { echo $x . "\n"; }
}
' 2>/dev/null
}

# ========================================
# 下载（续）
# ========================================

# 下载 releases.json（全局唯一真相源，含所有版本 + 每个 asset 的 sha256）
# install.sh / upgrade.sh / bt-install 强校验从此读 sha256
download_releases_json() {
    local save_path="$1"
    if [ -z "$CUSTOM_RELEASE_URL" ]; then
        log_error "未配置 release 服务 URL"
        return 1
    fi
    local base_url="${CUSTOM_RELEASE_URL%/}"
    local url="$base_url/releases.json"
    log_info "下载 releases.json: $url"
    if ! curl -fsSL --connect-timeout 10 --max-time 30 -o "$save_path" "$url" 2>/dev/null; then
        log_error "releases.json 下载失败"
        log_error "  URL: $url"
        return 1
    fi
    return 0
}
