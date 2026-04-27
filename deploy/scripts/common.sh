#!/bin/bash

# SSL证书管理系统 - 公共函数库
# 提供日志、检测、工具等通用函数

# ========================================
# Release 服务配置
# ========================================
# 通过环境变量 CUSTOM_RELEASE_URL 或 --url 参数指定自建 release 服务 URL
# 示例: CUSTOM_RELEASE_URL="http://localhost:10002"

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# 日志函数
log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_step() { echo -e "${CYAN}[STEP]${NC} $1"; }

# 获取脚本所在目录
get_script_dir() {
    echo "$(cd "$(dirname "${BASH_SOURCE[1]}")" && pwd)"
}

# 获取时间戳
get_timestamp() {
    date '+%Y%m%d_%H%M%S'
}

# 检测宝塔面板环境
check_bt_panel() {
    if [ -f "/www/server/panel/BT-Panel" ] || \
       [ -f "/www/server/panel/class/panelPlugin.py" ] || \
       ([ -d "/www/server/panel" ] && [ -f "/www/server/panel/data/port.pl" ]); then
        return 0
    fi
    return 1
}

# 检测 Docker 环境
check_docker() {
    if ! command -v docker &> /dev/null; then
        return 1
    fi
    if ! docker info &> /dev/null; then
        return 2  # Docker 服务未运行
    fi
    return 0
}

# 检测 docker-compose
check_docker_compose() {
    if command -v docker-compose &> /dev/null; then
        echo "docker-compose"
        return 0
    elif docker compose version &> /dev/null; then
        echo "docker compose"
        return 0
    fi
    return 1
}

# 检测端口是否被占用
check_port() {
    local port="$1"
    if command -v netstat &> /dev/null; then
        netstat -tuln 2>/dev/null | grep -q ":$port "
    elif command -v ss &> /dev/null; then
        ss -tuln 2>/dev/null | grep -q ":$port "
    else
        # 尝试直接连接
        (echo >/dev/tcp/localhost/$port) 2>/dev/null
    fi
}

# 选择可用端口
select_available_port() {
    local preferred_port="$1"
    local fallback_port="$2"

    if ! check_port "$preferred_port"; then
        echo "$preferred_port"
    elif ! check_port "$fallback_port"; then
        log_warning "端口 $preferred_port 已被占用，使用 $fallback_port"
        echo "$fallback_port"
    else
        log_error "端口 $preferred_port 和 $fallback_port 都被占用"
        return 1
    fi
}

# 测试 MySQL 连接
test_mysql_connection() {
    local host="$1"
    local port="${2:-3306}"
    local user="$3"
    local pass="$4"
    local db="$5"

    if command -v mysql &> /dev/null; then
        mysql -h "$host" -P "$port" -u "$user" -p"$pass" -e "SELECT 1" "$db" &> /dev/null
        return $?
    elif command -v mysqladmin &> /dev/null; then
        mysqladmin -h "$host" -P "$port" -u "$user" -p"$pass" ping &> /dev/null
        return $?
    else
        # 使用 nc 测试端口连通性
        if command -v nc &> /dev/null; then
            nc -z -w 3 "$host" "$port" &> /dev/null
            return $?
        fi
        # 使用 bash 内置测试
        (echo >/dev/tcp/$host/$port) 2>/dev/null
        return $?
    fi
}

# 测试 Redis 连接
test_redis_connection() {
    local host="$1"
    local port="${2:-6379}"
    local pass="$3"

    if command -v redis-cli &> /dev/null; then
        if [ -n "$pass" ]; then
            redis-cli -h "$host" -p "$port" -a "$pass" ping 2>/dev/null | grep -q "PONG"
        else
            redis-cli -h "$host" -p "$port" ping 2>/dev/null | grep -q "PONG"
        fi
        return $?
    else
        # 使用 nc 测试端口连通性
        if command -v nc &> /dev/null; then
            nc -z -w 3 "$host" "$port" &> /dev/null
            return $?
        fi
        (echo >/dev/tcp/$host/$port) 2>/dev/null
        return $?
    fi
}

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

# 版本比较函数
version_compare() {
    local version1="$1"
    local version2="$2"

    version1=$(echo "$version1" | sed 's/^v//' | sed 's/-.*//')
    version2=$(echo "$version2" | sed 's/^v//' | sed 's/-.*//')

    if command -v sort &> /dev/null; then
        local sorted_versions=$(printf '%s\n%s' "$version1" "$version2" | sort -V)
        local lowest=$(echo "$sorted_versions" | head -n1)
        [ "$lowest" = "$version2" ] && return 0 || return 1
    else
        local v1_major=$(echo "$version1" | cut -d. -f1)
        local v1_minor=$(echo "$version1" | cut -d. -f2)
        local v2_major=$(echo "$version2" | cut -d. -f1)
        local v2_minor=$(echo "$version2" | cut -d. -f2)

        if [ "$v1_major" -gt "$v2_major" ]; then
            return 0
        elif [ "$v1_major" -lt "$v2_major" ]; then
            return 1
        fi

        [ "$v1_minor" -ge "$v2_minor" ] && return 0 || return 1
    fi
}

# 读取 .env 文件变量
read_env_var() {
    local file="$1"
    local key="$2"
    local default="$3"

    if [ -f "$file" ]; then
        local value=$(grep "^$key=" "$file" 2>/dev/null | cut -d'=' -f2- | sed 's/^"//' | sed 's/"$//' | sed "s/^'//" | sed "s/'$//")
        if [ -n "$value" ]; then
            echo "$value"
            return 0
        fi
    fi
    echo "$default"
}

# 设置 .env 文件变量
set_env_var() {
    local file="$1"
    local key="$2"
    local value="$3"

    if [ -f "$file" ]; then
        if grep -q "^$key=" "$file"; then
            sed -i "s|^$key=.*|$key=$value|" "$file"
        else
            echo "$key=$value" >> "$file"
        fi
    else
        echo "$key=$value" > "$file"
    fi
}

# 确认提示
confirm() {
    local message="$1"
    local default="${2:-n}"

    if [ "$default" = "y" ]; then
        read -p "$message [Y/n]: " choice < /dev/tty
        case "$choice" in
            n|N) return 1 ;;
            *) return 0 ;;
        esac
    else
        read -p "$message [y/N]: " choice < /dev/tty
        case "$choice" in
            y|Y) return 0 ;;
            *) return 1 ;;
        esac
    fi
}

# 显示选择菜单
select_menu() {
    local prompt="$1"
    shift
    local options=("$@")

    echo "$prompt"
    for i in "${!options[@]}"; do
        echo "  $((i+1)). ${options[$i]}"
    done

    while true; do
        read -p "请选择 (1-${#options[@]}): " choice < /dev/tty
        if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le ${#options[@]} ]; then
            echo "$((choice-1))"
            return 0
        fi
        log_error "无效选择，请输入 1-${#options[@]} 之间的数字"
    done
}

# 检查命令是否存在
require_command() {
    local cmd="$1"
    local install_hint="$2"

    if ! command -v "$cmd" &> /dev/null; then
        log_error "未找到命令: $cmd"
        if [ -n "$install_hint" ]; then
            log_info "安装提示: $install_hint"
        fi
        return 1
    fi
    return 0
}

# 创建目录（如果不存在）
ensure_dir() {
    local dir="$1"
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
    fi
}

# 备份文件
backup_file() {
    local file="$1"
    local backup_dir="${2:-$(dirname "$file")}"
    local timestamp=$(get_timestamp)

    if [ -f "$file" ]; then
        local backup_name="$(basename "$file").backup.$timestamp"
        cp "$file" "$backup_dir/$backup_name"
        echo "$backup_dir/$backup_name"
    fi
}

# 获取文件的 SHA256 校验和
file_sha256() {
    local file="$1"
    if command -v sha256sum &> /dev/null; then
        sha256sum "$file" | cut -d' ' -f1
    elif command -v shasum &> /dev/null; then
        shasum -a 256 "$file" | cut -d' ' -f1
    else
        openssl dgst -sha256 "$file" | awk '{print $NF}'
    fi
}

# ========================================
# 下载函数
# ========================================

# 解析版本标识
# 用法: resolve_version_tag <version>
# 输出: 解析后的 tag 名称
resolve_version_tag() {
    local version="$1"

    case "$version" in
        latest)
            echo "latest"  # main 分支的 latest tag
            ;;
        dev)
            echo "dev-latest"  # dev 分支的 latest tag
            ;;
        *)
            # 指定版本号，返回原始值
            echo "$version"
            ;;
    esac
}

# 下载 Release 包
# 用法: download_release_file <filename> <save_path> [version]
# version: latest（默认）、dev、或具体版本号如 1.0.0
# 必须配置环境变量 CUSTOM_RELEASE_URL 指定 release 服务
download_release_file() {
    local filename="$1"
    local save_path="$2"
    local version="${3:-latest}"

    # 检查必须的配置
    if [ -z "$CUSTOM_RELEASE_URL" ]; then
        log_error "未配置 release 服务 URL"
        log_info "请使用 --url 参数指定，或设置环境变量 CUSTOM_RELEASE_URL"
        return 1
    fi

    local base_url="${CUSTOM_RELEASE_URL%/}"  # 移除末尾斜杠
    local url=""

    # 处理特殊版本标识
    local tag=$(resolve_version_tag "$version")

    # 构建 URL
    if [[ "$tag" == "dev-latest" ]]; then
        url="$base_url/dev-latest/$filename"
    elif [[ "$tag" == "latest" ]]; then
        url="$base_url/latest/$filename"
    else
        # 开发版放在 dev/ 目录，正式版放在 main/ 目录
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

    # 清理可能的部分下载
    [ -f "$save_path" ] && rm -f "$save_path"

    log_error "下载失败: $filename (curl exit code: $curl_exit)"
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

# 下载脚本包并解压
# 用法: download_and_extract_scripts <dest_dir> [version]
# version: latest（默认）、dev、或具体版本号
download_and_extract_scripts() {
    local dest_dir="$1"
    local version="${2:-latest}"
    local temp_file="/tmp/ssl-manager-script-$$.zip"

    # 根据版本确定文件名
    local filename
    case "$version" in
        latest) filename="ssl-manager-script-latest.zip" ;;
        dev) filename="ssl-manager-script-latest.zip" ;;  # dev 分支也使用 latest 文件名
        *) filename="ssl-manager-script-$version.zip" ;;
    esac

    if download_release_file "$filename" "$temp_file" "$version"; then
        ensure_dir "$dest_dir"
        unzip -qo "$temp_file" -d "$dest_dir"
        rm -f "$temp_file"
        return 0
    fi

    return 1
}

# 下载完整程序包并解压
# 用法: download_and_extract_full <dest_dir> [version]
# version: latest（默认）、dev、或具体版本号
download_and_extract_full() {
    local dest_dir="$1"
    local version="${2:-latest}"
    local temp_file="/tmp/ssl-manager-full-$$.zip"

    # 根据版本确定文件名
    local filename
    case "$version" in
        latest) filename="ssl-manager-full-latest.zip" ;;
        dev) filename="ssl-manager-full-latest.zip" ;;  # dev 分支也使用 latest 文件名
        *) filename="ssl-manager-full-$version.zip" ;;
    esac

    if download_release_file "$filename" "$temp_file" "$version"; then
        ensure_dir "$dest_dir"
        unzip -qo "$temp_file" -d "$dest_dir"
        rm -f "$temp_file"
        return 0
    fi

    return 1
}

# ========================================
# Docker 镜像源配置
# ========================================

# Docker 镜像源
DOCKER_MIRRORS_CHINA=(
    "https://docker.m.daocloud.io"
    "https://hub-mirror.c.163.com"
)

# 配置 Docker 镜像加速
configure_docker_mirror() {
    local region="${1:-auto}"  # china / intl / auto

    if [ "$region" = "auto" ]; then
        if is_china_server; then
            region="china"
        else
            region="intl"
        fi
    fi

    if [ "$region" != "china" ]; then
        log_info "使用国际 Docker 镜像源"
        return 0
    fi

    log_info "配置 Docker 中国镜像加速..."

    local daemon_json="/etc/docker/daemon.json"
    ensure_dir "$(dirname "$daemon_json")"

    cat > "$daemon_json" << 'EOF'
{
  "registry-mirrors": [
    "https://docker.m.daocloud.io",
    "https://hub-mirror.c.163.com"
  ]
}
EOF

    if systemctl is-active --quiet docker; then
        systemctl daemon-reload
        systemctl restart docker
        log_success "Docker 镜像加速配置完成"
    fi
}

# 获取 Alpine 镜像源配置命令
get_alpine_mirror_cmd() {
    local region="${1:-auto}"

    if [ "$region" = "auto" ]; then
        if is_china_server; then
            region="china"
        else
            region="intl"
        fi
    fi

    if [ "$region" = "china" ]; then
        echo "sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories"
    else
        echo "# Using default Alpine mirrors"
    fi
}

# 获取 Composer 镜像源配置命令
get_composer_mirror_cmd() {
    local region="${1:-auto}"

    if [ "$region" = "auto" ]; then
        if is_china_server; then
            region="china"
        else
            region="intl"
        fi
    fi

    if [ "$region" = "china" ]; then
        echo "composer config -g repo.packagist composer https://mirrors.tencent.com/composer/ && composer config -g process-timeout 600"
    else
        echo "# Using default Composer mirrors"
    fi
}

# 安装 Docker
install_docker() {
    local region="${1:-auto}"

    if [ "$region" = "auto" ]; then
        if is_china_server; then
            region="china"
        else
            region="intl"
        fi
    fi

    if command -v docker &> /dev/null; then
        log_info "Docker 已安装"
        docker --version
        return 0
    fi

    log_step "安装 Docker..."

    if [ "$region" = "china" ]; then
        log_info "使用阿里云镜像安装 Docker"
        curl -fsSL https://get.docker.com | bash -s docker --mirror Aliyun
    else
        log_info "使用官方源安装 Docker"
        curl -fsSL https://get.docker.com | bash
    fi

    # 启动 Docker 服务
    systemctl enable docker
    systemctl start docker

    # 配置镜像加速
    configure_docker_mirror "$region"

    log_success "Docker 安装完成"
}

# 检测端口占用并显示详情
check_port_with_details() {
    local port="$1"

    if ! check_port "$port"; then
        return 1  # 端口未被占用
    fi

    # 获取占用端口的进程信息
    local process_info=""
    if command -v lsof &> /dev/null; then
        process_info=$(lsof -i ":$port" -t 2>/dev/null | head -1)
        if [ -n "$process_info" ]; then
            local pname=$(ps -p "$process_info" -o comm= 2>/dev/null)
            log_warning "端口 $port 被进程 $pname (PID: $process_info) 占用"
        fi
    elif command -v ss &> /dev/null; then
        process_info=$(ss -tlnp "sport = :$port" 2>/dev/null | tail -1)
        if [ -n "$process_info" ]; then
            log_warning "端口 $port 已被占用: $process_info"
        fi
    else
        log_warning "端口 $port 已被占用"
    fi

    return 0  # 端口被占用
}
