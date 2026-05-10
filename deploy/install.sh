#!/bin/bash

# SSL Manager 一键安装入口脚本
# 用法:
# ./install.sh --url http://release.example.com
# ./install.sh --url http://release.example.com --version 0.0.10-beta
# ./install.sh --url http://release.example.com bt
#
# 部署方式：仅支持宝塔面板部署（已移除 Docker 部署，详见 ROADMAP）

set -e

# ========================================
# 配置
# ========================================
TEMP_DIR="/tmp/ssl-manager-install-$$"
SCRIPT_PACKAGE="ssl-manager-script-latest.zip"
REPO_OWNER="zhuxbo"
REPO_NAME="ssl-manager"
# release 服务 URL
# - 部署到 release 服务时，__RELEASE_URL__ 会被替换为实际地址
# - 如果未替换（本地运行），则需要通过 --url 参数指定
RELEASE_URL_PLACEHOLDER="__RELEASE_URL__"
if [[ "$RELEASE_URL_PLACEHOLDER" != "__RELEASE_URL__" ]]; then
    CUSTOM_RELEASE_URL="${CUSTOM_RELEASE_URL:-$RELEASE_URL_PLACEHOLDER}"
else
    CUSTOM_RELEASE_URL="${CUSTOM_RELEASE_URL:-}"
fi

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
# 清理函数
# ========================================
cleanup() {
    if [ -d "$TEMP_DIR" ]; then
        rm -rf "$TEMP_DIR"
    fi
}

trap cleanup EXIT

# ========================================
# 检测函数
# ========================================

# 检测宝塔面板
check_bt_panel() {
    if [ -f "/www/server/panel/BT-Panel" ] ||
        [ -f "/www/server/panel/class/panelPlugin.py" ] ||
        ([ -d "/www/server/panel" ] && [ -f "/www/server/panel/data/port.pl" ]); then
        return 0
    fi
    return 1
}

# ========================================
# 完整性校验
# ========================================

# 计算文件 SHA256（三平台 fallback：sha256sum / shasum / openssl）
_local_file_sha256() {
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

# 下载 releases.json（全局唯一真相源，含所有版本 + 每个 asset 的 sha256）
# install.sh 强校验从 releases.json 读 sha256，不再使用 manifest.json
_download_releases_json() {
    local save_path="$1"
    local base_url="${CUSTOM_RELEASE_URL%/}"
    local url="$base_url/releases.json"
    log_info "下载 releases.json: $url"
    if ! curl -fsSL --connect-timeout 10 --max-time 30 -o "$save_path" "$url" 2>/dev/null; then
        log_error "releases.json 下载失败"
        log_error " URL: $url"
        return 1
    fi
    return 0
}

# 从 releases.json 解析 latest/dev 占位符到实际版本号
# 用法：_resolve_version <releases.json file> <input_version: latest|dev|X.Y.Z[-beta]>
# stdout: 实际版本号（去掉 v 前缀）
#
# 实现：用 `{`/`}` 计数维护深度（depth），在 depth 1→≥2 时进入 release 块、≥2→1 时退出并判定。
# 旧实现（仅匹配 `^[[:space:]]*\{[[:space:]]*$/` 行作为块边界）会被 `assets` 内嵌的 `{`
# 误触发块重置，导致 indent=2 格式（json.dump 默认）下第一个 release 永远解析失败。
_resolve_version() {
    local releases_file="$1"
    local input="$2"
    if [[ "$input" != "latest" ]] && [[ "$input" != "dev" ]]; then
        echo "$input"
        return 0
    fi
    awk -v target="$input" '
        BEGIN { depth = 0; tag = ""; pre = "" }
        {
            line = $0
            n_open = 0; n_close = 0
            s = line; len = length(s)
            for (i = 1; i <= len; i++) {
                c = substr(s, i, 1)
                if (c == "{") n_open++
                else if (c == "}") n_close++
            }
            new_depth = depth + n_open - n_close

            if (depth == 1 && new_depth >= 2) { tag = ""; pre = "" }

            if (new_depth >= 2 || depth >= 2) {
                if (match(line, /"tag_name"[[:space:]]*:[[:space:]]*"v[^"]+"/)) {
                    t = substr(line, RSTART, RLENGTH)
                    gsub(/.*"tag_name"[[:space:]]*:[[:space:]]*"v/, "", t)
                    gsub(/".*/, "", t)
                    tag = t
                }
 if (match(line, /"prerelease"[[:space:]]*:[[:space:]]*true/)) pre = "true"
                if (match(line, /"prerelease"[[:space:]]*:[[:space:]]*false/)) pre = "false"
            }

            if (depth >= 2 && new_depth == 1) {
                if (tag != "") {
                    if (target == "latest" && pre == "false") { print tag; exit }
 if (target == "dev" && pre == "true" ) { print tag; exit }
                }
            }
            depth = new_depth
        }
    ' "$releases_file"
}

# 从 releases.json 提取指定 version + asset 的 sha256
# 用法：_extract_release_sha256 <releases.json file> <version> <asset filename>
_extract_release_sha256() {
    local releases_file="$1"
    local version="$2"
    local asset_name="$3"
    awk -v ver="v$version" -v aname="$asset_name" '
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
    ' "$releases_file"
}

# 强校验脚本包 SHA256（失败 → 立即 exit 1，不降级）
# 用法：verify_script_package_sha256 <package_file> <releases.json file> <version>
verify_script_package_sha256() {
    local package_file="$1"
    local releases_file="$2"
    local version="$3"
    local asset_name="ssl-manager-script-$version.zip"

    local expected_sha=$(_extract_release_sha256 "$releases_file" "$version" "$asset_name")
    if [ -z "$expected_sha" ]; then
        log_error "releases.json 缺失 v$version 的 $asset_name sha256 字段"
        log_error "无法验证脚本包完整性，安装中止"
        return 1
    fi

    local actual_sha
    actual_sha=$(_local_file_sha256 "$package_file") || {
        log_error "缺少 sha256sum / shasum / openssl 工具，无法校验完整性"
        return 1
    }

    expected_sha=$(echo "$expected_sha" | tr 'A-Z' 'a-z')
    actual_sha=$(echo "$actual_sha" | tr 'A-Z' 'a-z')

    if [ "$actual_sha" != "$expected_sha" ]; then
        log_error "脚本包 SHA256 校验失败 — 包可能被篡改或下载损坏"
        log_error " 文件: $package_file"
        log_error " 期望: $expected_sha"
        log_error " 实际: $actual_sha"
        return 1
    fi

    log_success "脚本包 SHA256 校验通过"
    return 0
}

# ========================================
# 下载函数
# ========================================

# 下载脚本包（支持版本参数）
download_script_package() {
    local save_path="$1"
    local version="${2:-latest}"

    # 检查必须的配置
    if [ -z "$CUSTOM_RELEASE_URL" ]; then
        log_error "未配置 release 服务 URL"
        log_info "请使用 --url 参数指定 release 服务地址"
        return 1
    fi

    local base_url="${CUSTOM_RELEASE_URL%/}" # 移除末尾斜杠
    local url=""

    # 构建 URL
    if [[ "$version" == "latest" ]]; then
        url="$base_url/latest/ssl-manager-script-latest.zip"
    elif [[ "$version" == "dev" ]]; then
        url="$base_url/dev-latest/ssl-manager-script-latest.zip"
    else
        # 开发版放在 dev/ 目录，正式版放在 main/ 目录
        if [[ "$version" =~ -(dev|alpha|beta|rc) ]]; then
            url="$base_url/dev/v$version/ssl-manager-script-$version.zip"
        else
            url="$base_url/main/v$version/ssl-manager-script-$version.zip"
        fi
    fi

    log_info "下载脚本包 (版本: $version)..."
    log_info "URL: $url"

    local curl_output=""
    local curl_exit=0
    curl_output=$(curl -fsSL --connect-timeout 10 --max-time 120 -o "$save_path" "$url" 2>&1) || curl_exit=$?

    if [ $curl_exit -eq 0 ]; then
        log_success "下载成功"
        return 0
    fi

    # 清理可能的部分下载
    [ -f "$save_path" ] && rm -f "$save_path"

    log_error "下载失败 (curl exit code: $curl_exit)"
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
# 显示横幅
# ========================================
show_banner() {
    echo ""
    echo -e "${CYAN}╔═══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC} ${GREEN}SSL Manager 一键安装程序${NC} ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC} ${BLUE}https://github.com/$REPO_OWNER/$REPO_NAME${NC} ${CYAN}║${NC}"
    echo -e "${CYAN}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# ========================================
# 显示帮助
# ========================================
show_help() {
    cat <<EOF
用法: $0 --url <release_url> [选项] [模式]

模式（仅支持宝塔；已移除 Docker 部署）:
 auto 自动检测宝塔环境（默认；未检测到宝塔则报错并提示安装）
 bt 显式使用宝塔面板安装（同 auto，仅做语义提示）

选项:
 --url URL 指定 release 服务 URL（必需）
 --version, -v VERSION 指定安装版本
 latest 最新稳定版（默认）
 dev 最新开发版
 x.x.x 指定版本号
 -y 非交互模式，跳过确认提示
 -h, --help 显示此帮助信息

示例:
 $0 --url http://release.example.com # 安装最新稳定版
 $0 --url http://release.example.com --version 0.0.10-beta # 安装指定版本
 $0 --url http://release.example.com bt # 显式宝塔安装
 $0 --url http://release.example.com bt -y # 非交互式宝塔安装

环境变量:
 FORCE_CHINA_MIRROR=1 强制使用国内镜像
 FORCE_CHINA_MIRROR=0 强制使用国际源
 AUTO_YES=true 非交互模式

完整性校验:
  install.sh 自动从 releases.json 读对应版本 ssl-manager-script-<v>.zip 的 sha256，
  强校验脚本包；失败立即退出。

  首次运行前可手工校验 install.sh 自身：
    curl -fsSLO <release_url>/latest/install.sh
    curl -fsSLO <release_url>/latest/install.sh.sha256
    sha256sum -c install.sh.sha256
  macOS（无 sha256sum）：
    shasum -a 256 -c install.sh.sha256
EOF
    exit 0
}

# ========================================
# 主流程
# ========================================
main() {
    local mode="auto"
    local version="latest"
    local auto_yes="${AUTO_YES:-false}"
    # 未识别参数透传给子脚本（bt-install.sh）
    # install.sh 是入口路由，子脚本有自己的复杂参数（--site-domain / --bt-key 等）
    local -a EXTRA_ARGS=()

    # 解析参数
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --version | -v)
                version="$2"
                shift 2
                ;;
            --url)
                CUSTOM_RELEASE_URL="$2"
                shift 2
                ;;
            -y)
                auto_yes=true
                shift
                ;;
            -h | --help)
                show_help
                ;;
            bt | auto)
                mode="$1"
                shift
                ;;
            docker)
                log_error "Docker 部署已移除（架构简化，集中维护宝塔模式）"
                log_info "请改用宝塔面板部署：$0 --url <url> bt"
                exit 1
                ;;
            *)
                # 未知参数透传给子脚本（保留原始 token，含可能的 = 形式如 --site-domain=xxx）
                EXTRA_ARGS+=("$1")
                shift
                ;;
        esac
    done

    # 导出 AUTO_YES 供子脚本使用
    export AUTO_YES="$auto_yes"

    # 显示自定义 release URL
    if [ -n "$CUSTOM_RELEASE_URL" ]; then
        log_info "使用自定义 release URL: $CUSTOM_RELEASE_URL"
    fi

    show_banner

    # 显示版本信息
    if [ "$version" != "latest" ]; then
        log_info "安装版本: $version"
    fi

    # 检查 root 权限
    if [ "$EUID" -ne 0 ]; then
        log_error "请使用 root 权限运行此脚本"
        log_info "用法: sudo bash $0"
        exit 1
    fi

    # 检查必需命令
    for cmd in curl unzip; do
        if ! command -v $cmd &>/dev/null; then
            log_error "缺少必需命令: $cmd"
            log_info "请先安装: apt install $cmd 或 yum install $cmd"
            exit 1
        fi
    done

    # 网络环境选择（如果用户未手动指定）
    log_step "配置网络环境..."
    if [ -n "$FORCE_CHINA_MIRROR" ]; then
        if [ "$FORCE_CHINA_MIRROR" = "1" ]; then
            log_info "FORCE_CHINA_MIRROR=1，使用国内镜像源"
            export NETWORK_ENV="china"
        else
            log_info "FORCE_CHINA_MIRROR=0，使用国际源"
            export NETWORK_ENV="global"
        fi
    elif [ "$auto_yes" = true ]; then
        # 非交互模式，默认使用国内镜像
        log_info "非交互模式，默认使用国内镜像源"
        export FORCE_CHINA_MIRROR=1
        export NETWORK_ENV="china"
    else
        echo ""
        echo "请选择网络环境:"
        echo " 1. 中国大陆（使用国内镜像源，推荐国内服务器）"
        echo " 2. 国际网络（使用官方源）"
        echo ""
        read -p "请选择 (1/2) [1]: " network_choice </dev/tty
        network_choice="${network_choice:-1}"

        case "$network_choice" in
            2)
                log_info "使用国际源"
                export FORCE_CHINA_MIRROR=0
                export NETWORK_ENV="global"
                ;;
            *)
                log_info "使用国内镜像源"
                export FORCE_CHINA_MIRROR=1
                export NETWORK_ENV="china"
                ;;
        esac
    fi

    # 创建临时目录
    mkdir -p "$TEMP_DIR"

    # 导出版本变量供子脚本使用
    export INSTALL_VERSION="$version"
    export CUSTOM_RELEASE_URL

    # 强校验链：先下 releases.json（含 sha256），再下 zip 包，最后校验
    log_step "校验脚本包完整性..."
    local releases_file="$TEMP_DIR/releases.json"
    if ! _download_releases_json "$releases_file"; then
        log_error "无法验证脚本包完整性，安装中止（sha256 强校验链）"
        exit 1
    fi

    # 解析 latest/dev 占位符到实际版本号（X.Y.Z[-beta]）
    local actual_version
    actual_version=$(_resolve_version "$releases_file" "$version")
    if [ -z "$actual_version" ]; then
        log_error "releases.json 中未找到匹配 \"$version\" 的版本"
        exit 1
    fi
    if [ "$actual_version" != "$version" ]; then
        log_info "解析版本占位符: $version → $actual_version"
        version="$actual_version"
        export INSTALL_VERSION="$version"
    fi

    # 下载脚本包
    log_step "下载安装脚本..."
    local package_file="$TEMP_DIR/$SCRIPT_PACKAGE"
    if ! download_script_package "$package_file" "$version"; then
        log_error "无法下载安装脚本包"
        exit 1
    fi

    # 校验
    if ! verify_script_package_sha256 "$package_file" "$releases_file" "$version"; then
        exit 1
    fi

    # 解压脚本包
    log_step "解压安装脚本..."
    if ! unzip -qo "$package_file" -d "$TEMP_DIR"; then
        log_error "解压失败"
        exit 1
    fi

    # 找到解压后的脚本目录
    local script_dir="$TEMP_DIR/script-deploy/scripts"
    if [ ! -d "$script_dir" ]; then
        script_dir="$TEMP_DIR/scripts"
    fi

    if [ ! -d "$script_dir" ]; then
        log_error "未找到脚本目录"
        exit 1
    fi

    # 设置脚本可执行权限
    chmod +x "$script_dir"/*.sh 2>/dev/null || true

    # 构建子脚本参数
    local sub_args=""
    if [ "$auto_yes" = true ]; then
        sub_args="-y"
    fi

    # 部署方式：仅支持宝塔（已移除 Docker，简化维护）
    # auto / bt 行为一致：检测到宝塔则用宝塔；否则报错并提示安装宝塔
    case "$mode" in
        bt | auto)
            log_step "检测宝塔面板环境..."
            if check_bt_panel; then
                log_success "已检测到宝塔面板"
                log_info "使用宝塔面板安装..."
                bash "$script_dir/bt-install.sh" $sub_args "${EXTRA_ARGS[@]}"
            else
                log_error "未检测到宝塔面板环境（仅支持宝塔部署）"
                log_info "请先安装宝塔面板: https://www.bt.cn/new/download.html"
                log_info "宝塔安装完成后重新运行此脚本"
                exit 1
            fi
            ;;
        *)
            log_error "未知的安装模式: $mode"
            echo ""
            echo "用法:"
            echo " $0 --url <url> # 自动检测宝塔（默认）"
            echo " $0 --url <url> bt # 显式宝塔安装"
            exit 1
            ;;
    esac
}

# 运行主流程
main "$@"
