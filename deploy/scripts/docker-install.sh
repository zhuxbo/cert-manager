#!/bin/bash

# SSL Manager - Docker 交互式安装脚本

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# 加载公共函数
if [ -f "$SCRIPT_DIR/common.sh" ]; then
    source "$SCRIPT_DIR/common.sh"
else
    echo "错误: 找不到 common.sh"
    exit 1
fi

# ========================================
# 全局变量
# ========================================
INSTALL_DIR="/opt/ssl-manager"
MIRROR_REGION="auto"          # china / intl / auto
USE_CONTAINER_DB=true
USE_CONTAINER_REDIS=true
MYSQL_PROFILE="standard"      # minimal / standard / performance
WEB_PORT=80
SSL_ENABLED=false
SSL_PORT=443
SSL_CERT_PATH=""
SSL_KEY_PATH=""

# 数据库配置
DB_HOST="mysql"
DB_PORT="3306"
DB_DATABASE="ssl_manager"
DB_USERNAME="ssl_manager"
DB_PASSWORD=""

# Redis 配置
REDIS_HOST="redis"
REDIS_PORT="6379"
REDIS_PASSWORD=""

# 非交互模式
AUTO_YES=false

# ========================================
# 步骤 1: 显示欢迎信息
# ========================================
show_welcome() {
    echo ""
    echo -e "${CYAN}╔═══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC}         ${GREEN}SSL Manager Docker 部署向导${NC}                       ${CYAN}║${NC}"
    echo -e "${CYAN}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "本向导将引导您完成以下配置："
    echo "  1. Docker 环境检测/安装"
    echo "  2. 镜像源选择"
    echo "  3. 数据库配置"
    echo "  4. Redis 配置"
    echo "  5. 端口配置"
    echo "  6. SSL 证书配置（可选）"
    echo "  7. 安装目录选择"
    echo ""
}

# ========================================
# 步骤 2: Docker 环境检测
# ========================================
step_docker_environment() {
    log_step "步骤 1/7: Docker 环境检测"
    echo ""

    local docker_status=$(check_docker; echo $?)

    case "$docker_status" in
        0)
            log_success "Docker 已安装且运行正常"
            docker --version
            echo ""
            ;;
        1)
            log_warning "未检测到 Docker"
            echo ""
            if [ "$AUTO_YES" = true ]; then
                log_info "自动模式：安装 Docker"
                install_docker "$MIRROR_REGION"
            else
                echo "是否自动安装 Docker？"
                echo "  1. 是，自动安装（推荐）"
                echo "  2. 否，我将手动安装后重新运行此脚本"
                echo ""
                read -p "请选择 (1/2) [1]: " choice < /dev/tty
                choice="${choice:-1}"

                if [ "$choice" = "2" ]; then
                    log_info "请手动安装 Docker 后重新运行此脚本"
                    echo "安装命令: curl -fsSL https://get.docker.com | bash"
                    exit 0
                fi

                # 安装 Docker
                install_docker "$MIRROR_REGION"
            fi
            echo ""
            ;;
        2)
            log_warning "Docker 已安装但服务未运行"
            echo ""
            log_info "正在启动 Docker 服务..."
            systemctl start docker
            systemctl enable docker
            sleep 2

            if docker info &> /dev/null; then
                log_success "Docker 服务已启动"
            else
                log_error "Docker 服务启动失败"
                exit 1
            fi
            echo ""
            ;;
    esac

    # 检查 docker compose
    local compose_cmd=$(check_docker_compose)
    if [ -z "$compose_cmd" ]; then
        log_error "未找到 docker-compose 或 docker compose 命令"
        log_info "请确保 Docker 版本 >= 20.10 或安装 docker-compose"
        exit 1
    fi
    log_success "Docker Compose: $compose_cmd"
    echo ""
}

# ========================================
# 步骤 3: 镜像源选择
# ========================================
step_mirror_selection() {
    log_step "步骤 2/7: 镜像源选择"
    echo ""

    # 如果 install.sh 已经设置了 NETWORK_ENV，直接使用
    if [ -n "$NETWORK_ENV" ]; then
        if [ "$NETWORK_ENV" = "china" ]; then
            MIRROR_REGION="china"
            log_info "使用中国大陆镜像源（继承自安装配置）"
        else
            MIRROR_REGION="intl"
            log_info "使用国际镜像源（继承自安装配置）"
        fi
    elif [ "$AUTO_YES" = true ]; then
        # 自动模式：默认使用中国镜像
        MIRROR_REGION="china"
        NETWORK_ENV="china"
        log_info "自动模式：使用中国大陆镜像源"
        export NETWORK_ENV
    else
        # 没有预设，让用户选择
        echo "请选择镜像源（影响下载速度）："
        echo "  1. 中国大陆镜像（推荐国内服务器）"
        echo "  2. 国际镜像"
        echo ""

        read -p "请选择 (1/2) [1]: " choice < /dev/tty
        choice="${choice:-1}"

        case "$choice" in
            2)
                MIRROR_REGION="intl"
                NETWORK_ENV="global"
                log_info "使用国际镜像源"
                ;;
            *)
                MIRROR_REGION="china"
                NETWORK_ENV="china"
                log_info "使用中国大陆镜像源"
                ;;
        esac
        export NETWORK_ENV
    fi

    # 配置 Docker 镜像加速
    if [ "$MIRROR_REGION" = "china" ]; then
        configure_docker_mirror "china"
    fi
    echo ""
}

# ========================================
# 步骤 4: MySQL 配置
# ========================================
step_mysql_config() {
    log_step "步骤 3/7: MySQL 数据库配置"
    echo ""

    if [ "$AUTO_YES" = true ]; then
        # 自动模式：使用容器化 MySQL
        USE_CONTAINER_DB=true
        DB_HOST="mysql"
        DB_PASSWORD=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 16)
        MYSQL_PROFILE="standard"
        log_info "自动模式：使用容器化 MySQL（标准配置）"
        echo "  数据库: $DB_DATABASE"
        echo "  用户名: $DB_USERNAME"
        echo "  密码: (已自动生成)"
    else
        echo "请选择 MySQL 部署方式："
        echo "  1. 容器化 MySQL（推荐，自动配置）"
        echo "  2. 使用外部 MySQL 服务器"
        echo ""

        read -p "请选择 (1/2) [1]: " choice < /dev/tty
        choice="${choice:-1}"

        if [ "$choice" = "2" ]; then
            USE_CONTAINER_DB=false
            configure_external_mysql
        else
            USE_CONTAINER_DB=true
            DB_HOST="mysql"
            DB_PASSWORD=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 16)
            log_success "将使用容器化 MySQL"
            echo "  数据库: $DB_DATABASE"
            echo "  用户名: $DB_USERNAME"
            echo "  密码: (已自动生成)"
            echo ""

            # 选择 MySQL 内存配置
            echo "请选择 MySQL 内存配置："
            echo "  1. 省内存  ~150MB（1-2G 内存服务器，万级以下订单）"
            echo "  2. 标准    ~300MB（2-4G 内存服务器，十万级订单）"
            echo "  3. 高性能  ~600MB（4G 以上内存服务器，大规模部署）"
            echo ""

            read -p "请选择 (1-3) [2]: " profile_choice < /dev/tty
            profile_choice="${profile_choice:-2}"

            case "$profile_choice" in
                1) MYSQL_PROFILE="minimal"; log_info "MySQL 配置：省内存" ;;
                3) MYSQL_PROFILE="performance"; log_info "MySQL 配置：高性能" ;;
                *) MYSQL_PROFILE="standard"; log_info "MySQL 配置：标准" ;;
            esac
        fi
    fi
    echo ""
}

# 配置外部 MySQL
configure_external_mysql() {
    echo ""
    log_info "配置外部 MySQL 连接"
    echo ""

    read -p "MySQL 主机地址: " DB_HOST < /dev/tty
    read -p "MySQL 端口 [3306]: " input < /dev/tty
    DB_PORT="${input:-3306}"
    read -p "数据库名 [ssl_manager]: " input < /dev/tty
    DB_DATABASE="${input:-ssl_manager}"
    read -p "用户名 [ssl_manager]: " input < /dev/tty
    DB_USERNAME="${input:-ssl_manager}"
    read -s -p "密码: " DB_PASSWORD < /dev/tty
    echo ""

    # 测试连接
    log_info "测试 MySQL 连接..."
    if test_mysql_connection "$DB_HOST" "$DB_PORT" "$DB_USERNAME" "$DB_PASSWORD" "$DB_DATABASE"; then
        log_success "MySQL 连接成功"
    else
        log_error "MySQL 连接失败"
        echo ""
        echo "请选择："
        echo "  1. 重新配置"
        echo "  2. 使用容器化 MySQL"
        echo "  3. 退出"
        echo ""

        read -p "请选择 (1-3): " retry < /dev/tty
        case "$retry" in
            1) configure_external_mysql ;;
            2)
                USE_CONTAINER_DB=true
                DB_HOST="mysql"
                DB_PASSWORD=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 16)
                log_info "将使用容器化 MySQL"
                ;;
            *) exit 1 ;;
        esac
    fi
}

# ========================================
# 步骤 5: Redis 配置
# ========================================
step_redis_config() {
    log_step "步骤 4/7: Redis 缓存配置"
    echo ""

    if [ "$AUTO_YES" = true ]; then
        # 自动模式：使用容器化 Redis
        USE_CONTAINER_REDIS=true
        REDIS_HOST="redis"
        REDIS_PASSWORD=""
        log_info "自动模式：使用容器化 Redis"
    else
        echo "请选择 Redis 部署方式："
        echo "  1. 容器化 Redis（推荐，自动配置）"
        echo "  2. 使用外部 Redis 服务器"
        echo ""

        read -p "请选择 (1/2) [1]: " choice < /dev/tty
        choice="${choice:-1}"

        if [ "$choice" = "2" ]; then
            USE_CONTAINER_REDIS=false
            configure_external_redis
        else
            USE_CONTAINER_REDIS=true
            REDIS_HOST="redis"
            REDIS_PASSWORD=""
            log_success "将使用容器化 Redis"
        fi
    fi
    echo ""
}

# 配置外部 Redis
configure_external_redis() {
    echo ""
    log_info "配置外部 Redis 连接"
    echo ""

    read -p "Redis 主机地址: " REDIS_HOST < /dev/tty
    read -p "Redis 端口 [6379]: " input < /dev/tty
    REDIS_PORT="${input:-6379}"
    read -s -p "密码 (无密码直接回车): " REDIS_PASSWORD < /dev/tty
    echo ""

    # 测试连接
    log_info "测试 Redis 连接..."
    if test_redis_connection "$REDIS_HOST" "$REDIS_PORT" "$REDIS_PASSWORD"; then
        log_success "Redis 连接成功"
    else
        log_error "Redis 连接失败"
        echo ""
        echo "请选择："
        echo "  1. 重新配置"
        echo "  2. 使用容器化 Redis"
        echo "  3. 退出"
        echo ""

        read -p "请选择 (1-3): " retry < /dev/tty
        case "$retry" in
            1) configure_external_redis ;;
            2)
                USE_CONTAINER_REDIS=true
                REDIS_HOST="redis"
                REDIS_PASSWORD=""
                log_info "将使用容器化 Redis"
                ;;
            *) exit 1 ;;
        esac
    fi
}

# ========================================
# 步骤 6: 端口配置
# ========================================
step_port_config() {
    log_step "步骤 5/7: 端口配置"
    echo ""

    if [ "$AUTO_YES" = true ]; then
        # 自动模式：检测端口，如果 80 被占用则使用 18000
        if check_port_with_details 80; then
            WEB_PORT=18000
            # 如果 18000 也被占用，递增查找
            while check_port_with_details "$WEB_PORT"; do
                WEB_PORT=$((WEB_PORT + 1))
            done
        else
            WEB_PORT=80
        fi
        log_info "自动模式：HTTP 端口 $WEB_PORT"
    else
        # 检测 80 端口
        if check_port_with_details 80; then
            # 端口被占用
            echo ""
            read -p "请输入 HTTP 端口 [18000]: " input < /dev/tty
            WEB_PORT="${input:-18000}"

            # 再次检查
            while check_port_with_details "$WEB_PORT"; do
                echo ""
                read -p "请输入其他端口: " WEB_PORT < /dev/tty
            done
        else
            read -p "HTTP 端口 [80]: " input < /dev/tty
            WEB_PORT="${input:-80}"

            if [ "$WEB_PORT" != "80" ] && check_port_with_details "$WEB_PORT"; then
                log_error "端口 $WEB_PORT 不可用"
                read -p "请输入其他端口: " WEB_PORT < /dev/tty
            fi
        fi
    fi

    log_success "HTTP 端口: $WEB_PORT"
    echo ""
}

# ========================================
# 步骤 7: SSL 配置
# ========================================
step_ssl_config() {
    log_step "步骤 6/7: SSL 证书配置"
    echo ""

    if [ "$AUTO_YES" = true ]; then
        # 自动模式：跳过 SSL 配置
        SSL_ENABLED=false
        log_info "自动模式：跳过 SSL 配置"
    else
        echo "是否配置 SSL 证书？"
        echo "  1. 否，仅使用 HTTP（可通过外部反代配置 SSL）"
        echo "  2. 是，配置 SSL 证书"
        echo ""

        read -p "请选择 (1/2) [1]: " choice < /dev/tty
        choice="${choice:-1}"

        if [ "$choice" = "2" ]; then
            SSL_ENABLED=true
            echo ""
            read -p "SSL 证书文件路径 (.crt/.pem): " SSL_CERT_PATH < /dev/tty
            read -p "SSL 私钥文件路径 (.key): " SSL_KEY_PATH < /dev/tty

            # 验证文件存在
            if [ ! -f "$SSL_CERT_PATH" ]; then
                log_error "证书文件不存在: $SSL_CERT_PATH"
                SSL_ENABLED=false
            elif [ ! -f "$SSL_KEY_PATH" ]; then
                log_error "私钥文件不存在: $SSL_KEY_PATH"
                SSL_ENABLED=false
            else
                # 检测 443 端口
                if check_port_with_details 443; then
                    echo ""
                    read -p "请输入 HTTPS 端口 [8443]: " input < /dev/tty
                    SSL_PORT="${input:-8443}"
                else
                    read -p "HTTPS 端口 [443]: " input < /dev/tty
                    SSL_PORT="${input:-443}"
                fi
                log_success "SSL 已配置"
                echo "  证书: $SSL_CERT_PATH"
                echo "  私钥: $SSL_KEY_PATH"
                echo "  HTTPS 端口: $SSL_PORT"
            fi
        else
            log_info "跳过 SSL 配置"
        fi
    fi
    echo ""
}

# ========================================
# 步骤 8: 安装目录
# ========================================
# 清理旧的 Docker 安装（停止并删除容器）
cleanup_old_installation() {
    local install_dir="$1"

    # 检查是否有旧的 docker-compose.yml
    if [ -f "$install_dir/docker-compose.yml" ]; then
        log_info "检测到旧安装，停止并清理容器..."

        local compose_cmd=$(check_docker_compose)
        if [ -n "$compose_cmd" ]; then
            # 停止并删除容器
            (cd "$install_dir" && $compose_cmd down --remove-orphans 2>/dev/null) || true
        fi
    fi

    # 备用方法：直接停止和删除 ssl-manager 相关容器
    local containers=$(docker ps -a --format '{{.Names}}' 2>/dev/null | grep -E '^ssl-manager-' || true)
    if [ -n "$containers" ]; then
        log_info "清理残留容器..."
        echo "$containers" | xargs -r docker stop 2>/dev/null || true
        echo "$containers" | xargs -r docker rm 2>/dev/null || true
    fi
}

step_install_dir() {
    log_step "步骤 7/7: 安装目录"
    echo ""

    if [ "$AUTO_YES" = true ]; then
        # 自动模式：使用默认目录或命令行指定的目录
        log_info "自动模式：安装目录 $INSTALL_DIR"
        if [ -d "$INSTALL_DIR" ]; then
            log_warning "目录已存在，将覆盖安装: $INSTALL_DIR"
            # 清理旧安装的 Docker 容器
            cleanup_old_installation "$INSTALL_DIR"
        fi
    else
        read -p "安装目录 [/opt/ssl-manager]: " input < /dev/tty
        INSTALL_DIR="${input:-/opt/ssl-manager}"

        if [ -d "$INSTALL_DIR" ]; then
            log_warning "目录已存在: $INSTALL_DIR"
            if ! confirm "是否覆盖安装？"; then
                exit 0
            fi
            # 清理旧安装的 Docker 容器
            cleanup_old_installation "$INSTALL_DIR"
        fi
    fi

    ensure_dir "$INSTALL_DIR"
    log_success "安装目录: $INSTALL_DIR"
    echo ""
}

# ========================================
# 显示配置摘要
# ========================================
show_summary() {
    echo ""
    echo "╔═══════════════════════════════════════════════════════════╗"
    echo "║                     配置摘要                              ║"
    echo "╚═══════════════════════════════════════════════════════════╝"
    echo ""
    echo "安装目录:    $INSTALL_DIR"
    echo "镜像源:      $([ "$MIRROR_REGION" = "china" ] && echo "中国大陆" || echo "国际")"
    echo ""
    echo "MySQL:       $([ "$USE_CONTAINER_DB" = true ] && echo "容器化（$MYSQL_PROFILE）" || echo "外部 ($DB_HOST:$DB_PORT)")"
    echo "Redis:       $([ "$USE_CONTAINER_REDIS" = true ] && echo "容器化" || echo "外部 ($REDIS_HOST:$REDIS_PORT)")"
    echo ""
    echo "HTTP 端口:   $WEB_PORT"
    if [ "$SSL_ENABLED" = true ]; then
        echo "HTTPS 端口:  $SSL_PORT"
        echo "SSL 证书:    $SSL_CERT_PATH"
    fi
    echo ""
    echo "将部署以下服务:"
    echo "  - Nginx (Web 服务器)"
    echo "  - PHP-FPM 8.3 (应用服务器)"
    [ "$USE_CONTAINER_DB" = true ] && echo "  - MySQL 8.4 (数据库, $MYSQL_PROFILE)"
    [ "$USE_CONTAINER_REDIS" = true ] && echo "  - Redis 7 (缓存)"
    echo "  - Queue Worker (队列处理)"
    echo "  - Scheduler (定时任务)"
    echo ""
}

# ========================================
# 创建目录结构
# ========================================
create_directories() {
    log_info "创建目录结构..."

    # 数据目录
    ensure_dir "$INSTALL_DIR/data/mysql"
    ensure_dir "$INSTALL_DIR/data/redis"
    ensure_dir "$INSTALL_DIR/data/logs/nginx"

    # 根目录 backups（备份、升级包）
    ensure_dir "$INSTALL_DIR/backups"
    ensure_dir "$INSTALL_DIR/backups/upgrades"

    # 插件目录
    ensure_dir "$INSTALL_DIR/plugins"

    # 配置目录
    ensure_dir "$INSTALL_DIR/config"
    ensure_dir "$INSTALL_DIR/config/ssl"

    log_success "目录结构已创建"
}

# ========================================
# 下载应用程序
# ========================================
download_application() {
    log_info "下载应用程序..."

    # 使用环境变量中的版本，默认为 latest
    local version="${INSTALL_VERSION:-latest}"

    local temp_dir="/tmp/ssl-manager-download-$$"
    mkdir -p "$temp_dir"

    # 下载完整包
    if ! download_and_extract_full "$temp_dir" "$version"; then
        log_error "下载失败"
        rm -rf "$temp_dir"
        exit 1
    fi

    # 找到解压后的目录
    local extract_dir="$temp_dir/ssl-manager"
    if [ ! -d "$extract_dir" ]; then
        # 使用 -mindepth 1 排除搜索目录本身（因为临时目录名包含 ssl-manager）
        extract_dir=$(find "$temp_dir" -mindepth 1 -maxdepth 1 -type d -name "ssl-manager*" | head -1)
    fi

    # 有时候完整包直接解压在根目录（full 目录）
    if [ ! -d "$extract_dir" ]; then
        extract_dir="$temp_dir/full"
    fi

    if [ ! -d "$extract_dir" ]; then
        log_error "未找到解压后的目录"
        rm -rf "$temp_dir"
        exit 1
    fi

    # 复制后端代码
    if [ -d "$extract_dir/backend" ]; then
        cp -r "$extract_dir/backend" "$INSTALL_DIR/"
    else
        log_error "未找到后端代码"
        rm -rf "$temp_dir"
        exit 1
    fi

    # 复制前端代码
    if [ -d "$extract_dir/frontend" ]; then
        ensure_dir "$INSTALL_DIR/frontend"
        cp -r "$extract_dir/frontend"/* "$INSTALL_DIR/frontend/"
    fi

    # 复制 nginx 配置目录
    if [ -d "$extract_dir/nginx" ]; then
        ensure_dir "$INSTALL_DIR/nginx"
        cp -r "$extract_dir/nginx"/* "$INSTALL_DIR/nginx/"
        log_info "已复制 nginx 配置目录"
    fi

    # 复制版本配置并注入 release_url 和 network
    if [ -f "$extract_dir/version.json" ]; then
        cp "$extract_dir/version.json" "$INSTALL_DIR/"
        local version_file="$INSTALL_DIR/version.json"

        # 使用 Python 处理 JSON
        if command -v python3 &> /dev/null; then
            python3 -c "
import json
import os
with open('$version_file', 'r') as f:
    data = json.load(f)
# 注入 release_url
if '$CUSTOM_RELEASE_URL':
    data['release_url'] = '$CUSTOM_RELEASE_URL'
# 注入 network 配置
network = os.environ.get('NETWORK_ENV', 'china')
data['network'] = network
with open('$version_file', 'w') as f:
    json.dump(data, f, indent=2, ensure_ascii=False)
    f.write('\n')
"
        else
            log_warning "未找到 python3，无法写入配置到 version.json"
        fi

        if [ -n "$CUSTOM_RELEASE_URL" ]; then
            log_info "已配置 release_url: $CUSTOM_RELEASE_URL"
        fi
        if [ -n "$NETWORK_ENV" ]; then
            log_info "已配置 network: $NETWORK_ENV"
        fi
    fi

    # 清理临时文件
    rm -rf "$temp_dir"

    # 确保 frontend/web 目录存在
    mkdir -p "$INSTALL_DIR/frontend/web"

    # 替换 nginx 配置中的占位符（Docker 环境项目根目录为 /var/www/html）
    if [ -f "$INSTALL_DIR/nginx/manager.conf" ]; then
        sed -i 's|__PROJECT_ROOT__|/var/www/html|g' "$INSTALL_DIR/nginx/manager.conf"
    fi
    if [ -f "$INSTALL_DIR/frontend/web/web.conf" ]; then
        sed -i 's|__PROJECT_ROOT__|/var/www/html|g' "$INSTALL_DIR/frontend/web/web.conf"
    fi

    # 设置权限（使用 UID 82，这是 Alpine PHP-FPM 容器中 www-data 的 UID）
    # backend 目录需要 www-data 可写以支持在线升级
    chown -R 82:82 "$INSTALL_DIR/backend" 2>/dev/null || true
    chmod -R 755 "$INSTALL_DIR/backend"
    chmod -R 775 "$INSTALL_DIR/backend/storage"
    chmod -R 775 "$INSTALL_DIR/backups"
    chown -R 82:82 "$INSTALL_DIR/backups" 2>/dev/null || true
    [ -f "$INSTALL_DIR/version.json" ] && chown 82:82 "$INSTALL_DIR/version.json" && chmod 664 "$INSTALL_DIR/version.json"
    # frontend 目录需要 www-data 可读以支持备份
    chown -R 82:82 "$INSTALL_DIR/frontend" 2>/dev/null || true
    chmod -R 755 "$INSTALL_DIR/frontend"

    log_success "应用程序下载完成"
}

# ========================================
# 配置 Docker 文件（使用模板）
# ========================================
setup_docker_files() {
    log_info "配置 Docker 文件..."

    local docker_template_dir="$SCRIPT_DIR/../docker"

    # 1. 复制 Dockerfile
    cp "$docker_template_dir/Dockerfile" "$INSTALL_DIR/Dockerfile"

    # 处理镜像源
    if [ "$MIRROR_REGION" = "china" ]; then
        sed -i 's|# __ALPINE_MIRROR__|RUN sed -i "s/dl-cdn.alpinelinux.org/mirrors.tencent.com/g" /etc/apk/repositories|' "$INSTALL_DIR/Dockerfile"
        sed -i 's|# __COMPOSER_MIRROR__|RUN composer config -g repo.packagist composer https://mirrors.tencent.com/composer/|' "$INSTALL_DIR/Dockerfile"
    else
        # 移除占位符注释
        sed -i '/# __ALPINE_MIRROR__/d' "$INSTALL_DIR/Dockerfile"
        sed -i '/# __COMPOSER_MIRROR__/d' "$INSTALL_DIR/Dockerfile"
    fi

    # 2. 复制 Nginx、PHP、MySQL 配置（先删除可能存在的旧文件）
    rm -rf "$INSTALL_DIR/config/nginx.conf" "$INSTALL_DIR/config/site.conf" "$INSTALL_DIR/config/php.ini" "$INSTALL_DIR/config/my.cnf"
    cp "$docker_template_dir/config/nginx.conf" "$INSTALL_DIR/config/nginx.conf"
    cp "$docker_template_dir/config/site.conf" "$INSTALL_DIR/config/site.conf"
    cp "$docker_template_dir/config/php.ini" "$INSTALL_DIR/config/php.ini"

    # 复制 MySQL 配置（容器化 MySQL 时）
    if [ "$USE_CONTAINER_DB" = true ]; then
        local mysql_cnf="$docker_template_dir/config/mysql/$MYSQL_PROFILE.cnf"
        if [ -f "$mysql_cnf" ]; then
            cp "$mysql_cnf" "$INSTALL_DIR/config/my.cnf"
            log_info "MySQL 配置: $MYSQL_PROFILE"
        else
            cp "$docker_template_dir/config/mysql/standard.cnf" "$INSTALL_DIR/config/my.cnf"
            log_warning "未找到 $MYSQL_PROFILE 配置，使用标准配置"
        fi
    else
        # 外部数据库时创建空配置，避免 docker-compose 挂载报错
        touch "$INSTALL_DIR/config/my.cnf"
    fi

    # 3. 创建 .env 文件
    cp "$docker_template_dir/.env.example" "$INSTALL_DIR/.env"

    # 替换变量
    sed -i "s/__DB_PASSWORD__/$DB_PASSWORD/g" "$INSTALL_DIR/.env"
    sed -i "s/DB_HOST=mysql/DB_HOST=$DB_HOST/g" "$INSTALL_DIR/.env"
    sed -i "s/DB_PORT=3306/DB_PORT=$DB_PORT/g" "$INSTALL_DIR/.env"
    sed -i "s/DB_DATABASE=ssl_manager/DB_DATABASE=$DB_DATABASE/g" "$INSTALL_DIR/.env"
    sed -i "s/DB_USERNAME=ssl_manager/DB_USERNAME=$DB_USERNAME/g" "$INSTALL_DIR/.env"
    sed -i "s/REDIS_HOST=redis/REDIS_HOST=$REDIS_HOST/g" "$INSTALL_DIR/.env"
    sed -i "s/REDIS_PORT=6379/REDIS_PORT=$REDIS_PORT/g" "$INSTALL_DIR/.env"
    sed -i "s/REDIS_PASSWORD=/REDIS_PASSWORD=$REDIS_PASSWORD/g" "$INSTALL_DIR/.env"
    sed -i "s/HTTP_PORT=80/HTTP_PORT=$WEB_PORT/g" "$INSTALL_DIR/.env"

    # 4. 创建 docker-compose.yml
    cp "$docker_template_dir/docker-compose.example.yml" "$INSTALL_DIR/docker-compose.yml"

    # 处理外部数据库（移除 mysql 服务）
    if [ "$USE_CONTAINER_DB" = false ]; then
        # 移除 mysql 服务块和相关依赖
        sed -i '/^  # MySQL 数据库/,/^  # Redis 缓存/{ /^  # Redis 缓存/!d }' "$INSTALL_DIR/docker-compose.yml"
        # 移除 depends_on mysql
        sed -i '/mysql:/d' "$INSTALL_DIR/docker-compose.yml"
        sed -i '/condition: service_healthy/{ N; /mysql/d }' "$INSTALL_DIR/docker-compose.yml"
    fi

    # 处理外部 Redis（移除 redis 服务）
    if [ "$USE_CONTAINER_REDIS" = false ]; then
        # 移除 redis 服务块
        sed -i '/^  # Redis 缓存/,/^networks:/{ /^networks:/!d }' "$INSTALL_DIR/docker-compose.yml"
        # 移除 depends_on redis
        sed -i '/redis:/d' "$INSTALL_DIR/docker-compose.yml"
    fi

    # 处理 SSL 端口
    if [ "$SSL_ENABLED" = true ]; then
        sed -i "s/# HTTPS_PORT=443/HTTPS_PORT=$SSL_PORT/g" "$INSTALL_DIR/.env"
        # 添加 SSL 端口映射到 nginx
        sed -i "/\${HTTP_PORT:-80}:80/a\\      - \"\${HTTPS_PORT:-443}:443\"" "$INSTALL_DIR/docker-compose.yml"
        # 复制 SSL 证书
        cp "$SSL_CERT_PATH" "$INSTALL_DIR/config/ssl/cert.pem"
        cp "$SSL_KEY_PATH" "$INSTALL_DIR/config/ssl/key.pem"
        chmod 600 "$INSTALL_DIR/config/ssl"/*
    fi

    log_success "Docker 文件配置完成"
}


# ========================================
# 生成环境配置
# ========================================
generate_env_file() {
    log_info "生成环境配置..."

    local app_key="base64:$(openssl rand -base64 32)"
    local jwt_secret="$(openssl rand -base64 48 | tr -dc 'a-zA-Z0-9' | head -c 64)"

    cat > "$INSTALL_DIR/backend/.env" <<EOF
APP_NAME="SSL Manager"
APP_ENV=production
APP_KEY=$app_key
APP_DEBUG=false
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_DATABASE=$DB_DATABASE
DB_USERNAME=$DB_USERNAME
DB_PASSWORD=$DB_PASSWORD

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=$REDIS_HOST
REDIS_PASSWORD=${REDIS_PASSWORD:-null}
REDIS_PORT=$REDIS_PORT

JWT_SECRET=$jwt_secret
EOF

    chmod 644 "$INSTALL_DIR/backend/.env"

    # 保存部署信息
    cat > "$INSTALL_DIR/.deploy-info" <<EOF
# SSL Manager 部署信息
# 生成时间: $(date '+%Y-%m-%d %H:%M:%S')

INSTALL_DIR=$INSTALL_DIR
MIRROR_REGION=$MIRROR_REGION
USE_CONTAINER_DB=$USE_CONTAINER_DB
USE_CONTAINER_REDIS=$USE_CONTAINER_REDIS
WEB_PORT=$WEB_PORT
SSL_ENABLED=$SSL_ENABLED
SSL_PORT=$SSL_PORT

DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_DATABASE=$DB_DATABASE
DB_USERNAME=$DB_USERNAME
DB_PASSWORD=$DB_PASSWORD
EOF

    log_success "环境配置已生成"
}

# ========================================
# 启动服务
# ========================================
start_services() {
    log_info "启动 Docker 服务..."

    cd "$INSTALL_DIR"

    local compose_cmd=$(check_docker_compose)

    # 构建镜像
    log_info "构建 Docker 镜像（首次可能需要几分钟）..."
    $compose_cmd build --no-cache

    # 启动服务（--no-build 避免重复构建警告）
    log_info "启动服务..."
    $compose_cmd up -d --no-build

    # 等待服务就绪
    log_info "等待服务就绪..."
    sleep 10

    # 检查服务状态
    $compose_cmd ps

    log_success "服务启动完成"
}

# ========================================
# 初始化应用
# ========================================
init_application() {
    log_info "初始化应用..."

    cd "$INSTALL_DIR"
    local compose_cmd=$(check_docker_compose)

    # 在容器内修复权限（宿主机可能没有 www-data 用户）
    log_info "修复文件权限..."
    # backend/storage（Laravel storage）
    $compose_cmd exec -T php mkdir -p /var/www/html/backend/storage/logs /var/www/html/backend/storage/framework/cache /var/www/html/backend/storage/framework/sessions /var/www/html/backend/storage/framework/views /var/www/html/backend/storage/app/public 2>/dev/null || true
    $compose_cmd exec -T php chown -R www-data:www-data /var/www/html/backend/storage 2>/dev/null || true
    $compose_cmd exec -T php chmod -R 775 /var/www/html/backend/storage 2>/dev/null || true
    # 根目录 backups（备份、升级包）
    $compose_cmd exec -T php mkdir -p /var/www/html/backups /var/www/html/backups/upgrades 2>/dev/null || true
    $compose_cmd exec -T php chown -R www-data:www-data /var/www/html/backups 2>/dev/null || true
    $compose_cmd exec -T php chmod -R 775 /var/www/html/backups 2>/dev/null || true
    # plugins（插件目录，需要可写以支持在线安装插件）
    $compose_cmd exec -T php chown -R www-data:www-data /var/www/html/plugins 2>/dev/null || true
    $compose_cmd exec -T php chmod -R 775 /var/www/html/plugins 2>/dev/null || true
    # version.json（版本配置，需要可写以支持切换通道）
    $compose_cmd exec -T php chown www-data:www-data /var/www/html/version.json 2>/dev/null || true
    $compose_cmd exec -T php chmod 664 /var/www/html/version.json 2>/dev/null || true
    # .env 文件（让 www-data 可读，用于升级时备份）
    $compose_cmd exec -T php chown www-data:www-data /var/www/html/backend/.env 2>/dev/null || true
    $compose_cmd exec -T php chmod 600 /var/www/html/backend/.env 2>/dev/null || true

    # 等待数据库就绪
    if [ "$USE_CONTAINER_DB" = true ]; then
        log_info "等待数据库就绪..."
        local max_attempts=30
        local attempt=0
        while [ $attempt -lt $max_attempts ]; do
            if $compose_cmd exec -T mysql mysqladmin ping -h localhost -u root -p"$DB_PASSWORD" &> /dev/null; then
                break
            fi
            attempt=$((attempt + 1))
            sleep 2
        done

        if [ $attempt -eq $max_attempts ]; then
            log_warning "数据库启动超时，继续尝试..."
        fi
    fi

    # 安装 Composer 依赖（使用宿主机网络模式，避免容器网络问题）
    log_info "安装 Composer 依赖..."

    local composer_success=false

    # 定义镜像源列表（按优先级）
    local mirrors=()
    if [ "$NETWORK_ENV" = "china" ] || [ "$MIRROR_REGION" = "china" ]; then
        mirrors=(
            "https://mirrors.aliyun.com/composer/"
            "https://mirrors.tencent.com/composer/"
            "https://mirrors.huaweicloud.com/repository/php/"
        )
    else
        mirrors=(
            "https://packagist.org"
            "https://mirrors.aliyun.com/composer/"
        )
    fi

    # 获取 PHP 镜像名称
    local php_image
    php_image=$($compose_cmd images php --format "{{.Repository}}:{{.Tag}}" 2>/dev/null | head -1)
    if [ -z "$php_image" ]; then
        php_image="ssl-manager-php:latest"
    fi

    log_info "使用宿主机网络模式安装依赖..."

    local mirror_index=0
    for mirror in "${mirrors[@]}"; do
        mirror_index=$((mirror_index + 1))
        log_info "[$mirror_index/${#mirrors[@]}] 使用镜像源: $mirror"

        # 使用 docker run --network host 直接使用宿主机网络
        # 实时输出日志，让用户能看到进度
        # 注意：使用 || true 防止 set -e 导致脚本退出
        docker run --rm --network host \
            -v "$INSTALL_DIR/backend:/var/www/html" \
            -w /var/www/html \
            -e COMPOSER_PROCESS_TIMEOUT=300 \
            -e COMPOSER_HTTP_TIMEOUT=60 \
            -e COMPOSER_HOME=/tmp/composer \
            "$php_image" \
            sh -c "composer config repo.packagist composer '$mirror' && composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-calendar --prefer-dist --no-interaction" 2>&1 || true

        if [ -f "$INSTALL_DIR/backend/vendor/autoload.php" ]; then
            composer_success=true
            log_success "Composer 依赖安装完成"
            break
        fi

        log_warning "使用 $mirror 安装失败，尝试下一个源..."
    done

    # 最终验证
    if [ "$composer_success" = false ]; then
        log_error "Composer 依赖安装失败"
        log_info "请手动运行:"
        log_info "  docker run --rm --network host -v $INSTALL_DIR/backend:/var/www/html -w /var/www/html $php_image composer install --no-dev"
    fi

    # 运行数据库迁移（超时 120 秒）
    log_info "运行数据库迁移..."
    timeout 120 $compose_cmd exec -T php php artisan migrate --force 2>&1 || log_warning "数据库迁移失败或超时"

    # 初始化数据（超时 60 秒）
    log_info "初始化数据..."
    timeout 60 $compose_cmd exec -T php php artisan db:seed --force 2>&1 || log_warning "数据初始化失败或超时"

    # 优化应用（超时 60 秒）
    log_info "优化应用..."
    timeout 60 $compose_cmd exec -T php php artisan config:cache 2>&1 || log_warning "配置缓存失败"
    timeout 60 $compose_cmd exec -T php php artisan route:cache 2>&1 || log_warning "路由缓存失败"

    # 清理安装脚本（Docker 模式是全流程安装，不需要 Web 安装向导）
    log_info "清理安装脚本..."
    rm -f "$INSTALL_DIR/backend/public/install.php"
    rm -rf "$INSTALL_DIR/backend/public/install-assets"
    log_success "安装脚本已清理"

    log_success "应用初始化完成"
}

# ========================================
# 显示完成信息
# ========================================
show_complete() {
    echo ""
    echo -e "${GREEN}╔═══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║                     部署完成                              ║${NC}"
    echo -e "${GREEN}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "访问地址:"
    echo "  HTTP:  http://服务器IP:$WEB_PORT"
    if [ "$SSL_ENABLED" = true ]; then
        echo "  HTTPS: https://服务器IP:$SSL_PORT"
    fi
    echo ""
    echo "安装目录: $INSTALL_DIR"
    echo ""
    echo "常用命令:"
    echo "  cd $INSTALL_DIR"
    echo "  docker compose ps          # 查看服务状态"
    echo "  docker compose logs -f     # 查看日志"
    echo "  docker compose restart     # 重启服务"
    echo "  docker compose down        # 停止服务"
    echo ""

    if [ "$USE_CONTAINER_DB" = true ]; then
        echo "数据库信息:"
        echo "  数据库: $DB_DATABASE"
        echo "  用户名: $DB_USERNAME"
        echo "  密码:   $DB_PASSWORD"
        echo ""
    fi

    echo "数据目录:"
    echo "  数据库: $INSTALL_DIR/data/mysql"
    echo "  Redis:  $INSTALL_DIR/data/redis"
    echo "  备份:   $INSTALL_DIR/backups"
    echo "  日志:   $INSTALL_DIR/data/logs"
    echo ""
}

# ========================================
# 主函数
# ========================================
main() {
    # 解析命令行参数
    while [[ $# -gt 0 ]]; do
        case "$1" in
            -y|--yes)
                AUTO_YES=true
                shift
                ;;
            --port)
                WEB_PORT="$2"
                shift 2
                ;;
            --dir)
                INSTALL_DIR="$2"
                shift 2
                ;;
            *)
                shift
                ;;
        esac
    done

    # 检查 root 权限
    if [ "$EUID" -ne 0 ]; then
        log_error "请使用 root 权限运行此脚本"
        exit 1
    fi

    show_welcome

    # 交互式配置流程
    step_docker_environment
    step_mirror_selection
    step_mysql_config
    step_redis_config
    step_port_config
    step_ssl_config
    step_install_dir

    # 显示配置摘要
    show_summary

    # 确认部署
    if [ "$AUTO_YES" != true ]; then
        if ! confirm "确认开始部署？" "y"; then
            log_info "取消部署"
            exit 0
        fi
    fi

    echo ""
    log_step "开始部署..."
    echo ""

    # 执行部署
    create_directories
    download_application
    setup_docker_files
    generate_env_file
    start_services
    init_application

    # 显示完成信息
    show_complete
}

# 运行主函数
main "$@"
