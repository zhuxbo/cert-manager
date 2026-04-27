#!/bin/bash

# SSL Manager 一键安装入口脚本
# 用法:
#   ./install.sh --url http://release.example.com
#   ./install.sh --url http://release.example.com --version 0.0.10-beta
#   ./install.sh --url http://release.example.com docker
#   ./install.sh --url http://release.example.com bt

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

# 检测服务器是否在中国大陆
# 多层检测，确保准确性
is_china_server() {
    # 如果环境变量已设置，直接使用
    if [ -n "$FORCE_CHINA_MIRROR" ]; then
        [ "$FORCE_CHINA_MIRROR" = "1" ] && return 0 || return 1
    fi

    # 1. 检查云服务商元数据 - 阿里云
    local aliyun_region=$(timeout 1 curl -s "http://100.100.100.200/latest/meta-data/region-id" 2>/dev/null || echo "")
    if [ -n "$aliyun_region" ] && [[ "$aliyun_region" =~ ^cn- ]]; then
        return 0
    fi

    # 检查云服务商元数据 - 腾讯云
    local tencent_region=$(timeout 1 curl -s "http://metadata.tencentyun.com/latest/meta-data/region" 2>/dev/null || echo "")
    if [ -n "$tencent_region" ]; then
        if [[ "$tencent_region" =~ ^(ap-beijing|ap-shanghai|ap-guangzhou|ap-chengdu|ap-chongqing|ap-nanjing) ]]; then
            return 0
        fi
        return 1
    fi

    # 检查云服务商元数据 - 华为云
    local huawei_az=$(timeout 1 curl -s "http://169.254.169.254/openstack/latest/meta_data.json" 2>/dev/null | grep -o '"availability_zone":"[^"]*"' | head -1 || echo "")
    if [ -n "$huawei_az" ] && [[ "$huawei_az" =~ cn- ]]; then
        return 0
    fi

    # 2. 检测 baidu.com 可达性 + Google 不可达
    local baidu_ok=false
    if timeout 2 curl -s --head "https://www.baidu.com" >/dev/null 2>&1; then
        baidu_ok=true
    fi

    if [ "$baidu_ok" = true ]; then
        # Google 在国内通常不可访问，如果不可达则认为是国内网络
        if ! timeout 3 curl -s --head "https://www.google.com" >/dev/null 2>&1; then
            return 0
        fi
    fi

    # 3. IP 归属地检测（备选方案）
    local country=""
    # 尝试 ip.sb
    country=$(timeout 3 curl -s "https://api.ip.sb/geoip" 2>/dev/null | grep -o '"country_code":"[^"]*"' | cut -d'"' -f4 || echo "")
    if [ -z "$country" ]; then
        # 尝试 ipinfo.io
        country=$(timeout 3 curl -s "https://ipinfo.io/country" 2>/dev/null | tr -d '\n' || echo "")
    fi

    if [ "$country" = "CN" ]; then
        return 0
    fi

    # 4. 最终判断：如果 baidu 可达但 Google 不可达，认为是国内
    if [ "$baidu_ok" = true ]; then
        if ! timeout 3 curl -s --head "https://www.google.com" >/dev/null 2>&1; then
            return 0
        fi
    fi

    # 默认不使用中国镜像
    return 1
}

# 检测宝塔面板
check_bt_panel() {
    if [ -f "/www/server/panel/BT-Panel" ] || \
       [ -f "/www/server/panel/class/panelPlugin.py" ] || \
       ([ -d "/www/server/panel" ] && [ -f "/www/server/panel/data/port.pl" ]); then
        return 0
    fi
    return 1
}

# 检测 Docker
check_docker() {
    if ! command -v docker &> /dev/null; then
        return 1
    fi
    if ! docker info &> /dev/null; then
        return 2  # Docker 服务未运行
    fi
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

    local base_url="${CUSTOM_RELEASE_URL%/}"  # 移除末尾斜杠
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
        6)  log_info "提示: 无法解析域名，请检查 DNS 或网络配置" ;;
        7)  log_info "提示: 无法连接服务器" ;;
        22) log_info "提示: 服务器返回错误（文件可能不存在）" ;;
        28) log_info "提示: 下载超时" ;;
        35|51|60) log_info "提示: SSL/TLS 错误，旧系统可尝试 yum update ca-certificates" ;;
    esac
    return 1
}

# ========================================
# 显示横幅
# ========================================
show_banner() {
    echo ""
    echo -e "${CYAN}╔═══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC}           ${GREEN}SSL Manager 一键安装程序${NC}                        ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}           ${BLUE}https://github.com/$REPO_OWNER/$REPO_NAME${NC}         ${CYAN}║${NC}"
    echo -e "${CYAN}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# ========================================
# 显示帮助
# ========================================
show_help() {
    cat <<EOF
用法: $0 --url <release_url> [选项] [模式]

模式:
  auto    自动检测环境（默认）
  docker  使用 Docker 安装
  bt      使用宝塔面板安装

选项:
  --url URL              指定 release 服务 URL（必需）
  --version, -v VERSION  指定安装版本
                         latest   最新稳定版（默认）
                         dev      最新开发版
                         x.x.x    指定版本号
  -y                     非交互模式，跳过确认提示
  -h, --help             显示此帮助信息

示例:
  $0 --url http://release.example.com                           # 安装最新稳定版
  $0 --url http://release.example.com --version 0.0.10-beta     # 安装指定版本
  $0 --url http://release.example.com docker                    # Docker 安装
  $0 --url http://release.example.com bt                        # 宝塔安装
  $0 --url http://release.example.com docker -y                 # 非交互式 Docker 安装

环境变量:
  FORCE_CHINA_MIRROR=1   强制使用国内镜像
  FORCE_CHINA_MIRROR=0   强制使用国际源
  AUTO_YES=true          非交互模式
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

    # 解析参数
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --version|-v)
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
            -h|--help)
                show_help
                ;;
            bt|docker|auto)
                mode="$1"
                shift
                ;;
            *)
                log_error "未知参数: $1"
                show_help
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
        if ! command -v $cmd &> /dev/null; then
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
        echo "  1. 中国大陆（使用国内镜像源，推荐国内服务器）"
        echo "  2. 国际网络（使用官方源）"
        echo ""
        read -p "请选择 (1/2) [1]: " network_choice < /dev/tty
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

    # 下载脚本包
    log_step "下载安装脚本..."
    local package_file="$TEMP_DIR/$SCRIPT_PACKAGE"
    if ! download_script_package "$package_file" "$version"; then
        log_error "无法下载安装脚本包"
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

    # 根据模式或环境选择安装方式
    case "$mode" in
        bt)
            # 强制使用宝塔安装
            if check_bt_panel; then
                log_info "使用宝塔面板安装..."
                bash "$script_dir/bt-install.sh" $sub_args
            else
                log_error "未检测到宝塔面板环境"
                log_info "请先安装宝塔面板: https://www.bt.cn/new/download.html"
                exit 1
            fi
            ;;
        docker)
            # 强制使用 Docker 安装
            log_info "使用 Docker 安装..."
            bash "$script_dir/docker-install.sh" $sub_args
            ;;
        auto)
            # 自动检测环境
            log_step "检测运行环境..."

            if check_bt_panel; then
                log_success "检测到宝塔面板环境"
                if [ "$auto_yes" = true ]; then
                    # 非交互模式，默认使用宝塔安装
                    log_info "非交互模式，使用宝塔面板安装..."
                    bash "$script_dir/bt-install.sh" $sub_args
                else
                    echo ""
                    echo "请选择安装方式:"
                    echo "  1. 宝塔面板安装（推荐，适合已有宝塔环境）"
                    echo "  2. Docker 安装（独立容器化部署）"
                    echo ""
                    read -p "请选择 (1/2) [1]: " choice < /dev/tty
                    choice="${choice:-1}"

                    case "$choice" in
                        2)
                            log_info "使用 Docker 安装..."
                            bash "$script_dir/docker-install.sh" $sub_args
                            ;;
                        *)
                            log_info "使用宝塔面板安装..."
                            bash "$script_dir/bt-install.sh" $sub_args
                            ;;
                    esac
                fi
            elif check_docker; then
                log_success "检测到 Docker 环境"
                log_info "使用 Docker 安装..."
                bash "$script_dir/docker-install.sh" $sub_args
            else
                log_warning "未检测到宝塔面板或 Docker 环境"
                if [ "$auto_yes" = true ]; then
                    # 非交互模式，默认使用 Docker 安装
                    log_info "非交互模式，使用 Docker 安装..."
                    bash "$script_dir/docker-install.sh" $sub_args
                else
                    echo ""
                    echo "可选安装方式:"
                    echo ""
                    echo "  1. Docker 安装（推荐）"
                    echo "     脚本将自动安装 Docker 并进行容器化部署"
                    echo ""
                    echo "  2. 宝塔面板安装"
                    echo "     请先安装宝塔面板: https://www.bt.cn/new/download.html"
                    echo "     然后重新运行此脚本"
                    echo ""
                    read -p "是否使用 Docker 安装？(y/n) [y]: " use_docker < /dev/tty
                    use_docker="${use_docker:-y}"

                    case "$use_docker" in
                        n|N)
                            log_info "请安装宝塔面板后重新运行此脚本"
                            exit 0
                            ;;
                        *)
                            log_info "使用 Docker 安装..."
                            bash "$script_dir/docker-install.sh" $sub_args
                            ;;
                    esac
                fi
            fi
            ;;
        *)
            log_error "未知的安装模式: $mode"
            echo ""
            echo "用法:"
            echo "  $0         # 自动检测环境"
            echo "  $0 docker  # 使用 Docker 安装"
            echo "  $0 bt      # 使用宝塔面板安装"
            exit 1
            ;;
    esac
}

# 运行主流程
main "$@"
