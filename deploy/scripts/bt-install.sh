#!/bin/bash

# SSL证书管理系统 - 宝塔环境安装脚本

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# 加载公共函数
source "$SCRIPT_DIR/common.sh"

# 全局变量
INSTALL_DIR="${INSTALL_DIR:-}" # 支持通过环境变量预设
PHP_VERSION=""
PHP_CMD=""
COMPOSER_BIN=""                # composer phar 绝对路径（check_composer 设置；run_composer_install 用 "$PHP_CMD" "$COMPOSER_BIN" 驱动）
AUTO_YES="${AUTO_YES:-false}"  # 非交互模式
SITE_DOMAIN="${SITE_DOMAIN:-}" # 可选：BT 自动建站使用的域名（缺失则跳过 BT API 建站）
BT_KEY="${BT_KEY:-}"           # 可选：宝塔 API key；缺失自动从 /www/server/panel/config/api.json 探测
SITE_REUSE_CONFIRMED=false     # 复用 BT 已有站点时置 true（同意复用即同意覆盖目录，跳过二次询问）

# 数据库连接（交互或参数收集）— 仅支持 mysql
# DB_PASSWORD 4 来源（同 admin 密码模式）：env / --db-password-file=PATH / 交互输入（明文回显）/ 空（mysql 允许）
DB_DRIVER="${DB_DRIVER:-}"
DB_HOST="${DB_HOST:-}"
DB_PORT="${DB_PORT:-}"
DB_DATABASE="${DB_DATABASE:-}"
DB_USERNAME="${DB_USERNAME:-}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_PASSWORD_FILE=""
WWW_USER="www" # 宝塔约定 web 用户

# admin 密码相关
# - 禁止 --admin-password=plain（明文进 shell history）
# - 允许 --admin-password-file=PATH / env ADMIN_PASSWORD / 交互输入
# - 非交互且无任何来源 → 自动生成 16 位
ADMIN_PASSWORD_FILE=""
ADMIN_PASSWORD_GENERATED=false # 标记是否自动生成（用于完成信息打印）

# 解析参数
while [[ $# -gt 0 ]]; do
    case "$1" in
        -y)
            AUTO_YES=true
            shift
            ;;
        --admin-password=* | --admin-password)
            log_error "拒绝命令行明文密码参数 --admin-password=xxx（会进入 shell history）"
            log_info "请改用以下任一方式："
            log_info " 1. 临时文件: --admin-password-file=PATH（chmod 600，脚本读取后自动删除）"
            log_info " 2. 环境变量: ADMIN_PASSWORD=xxx ./install.sh ..."
            log_info " 3. 交互输入: 直接运行（不带 -y），脚本会提示输入"
            log_info " 4. 自动生成: 加 -y 且不提供密码，脚本会生成 16 位强密码并打印"
            exit 1
            ;;
        --admin-password-file=*)
            ADMIN_PASSWORD_FILE="${1#*=}"
            shift
            ;;
        --admin-password-file)
            ADMIN_PASSWORD_FILE="${2:-}"
            shift 2
            ;;
        --site-domain=*)
            SITE_DOMAIN="${1#*=}"
            shift
            ;;
        --site-domain)
            SITE_DOMAIN="${2:-}"
            shift 2
            ;;
        --bt-key=*)
            log_error "拒绝命令行明文 --bt-key=xxx（会进入 shell history 与日志）"
            log_info "请改用："
            log_info " 1. 环境变量: BT_KEY=xxx sudo bash bt-install.sh"
            log_info " 2. 临时文件: --bt-key-file=PATH（chmod 600，读后立即销毁）"
            log_info " 3. 自动探测: 不传任何 BT_KEY，脚本读 /www/server/panel/config/api.json"
            log_info " 4. 交互输入: 自动探测失败时脚本会提示输入"
            exit 1
            ;;
        --bt-key)
            log_error "拒绝命令行明文 --bt-key（同上）"
            exit 1
            ;;
        --bt-key-file=*)
            BT_KEY_FILE="${1#*=}"
            shift
            ;;
        --bt-key-file)
            BT_KEY_FILE="${2:-}"
            shift 2
            ;;
        --db=* | --db-driver=*)
            DB_DRIVER="${1#*=}"
            shift
            ;;
        --db | --db-driver)
            DB_DRIVER="${2:-}"
            shift 2
            ;;
        --db-host=*)
            DB_HOST="${1#*=}"
            shift
            ;;
        --db-host)
            DB_HOST="${2:-}"
            shift 2
            ;;
        --db-port=*)
            DB_PORT="${1#*=}"
            shift
            ;;
        --db-port)
            DB_PORT="${2:-}"
            shift 2
            ;;
        --db-database=*)
            DB_DATABASE="${1#*=}"
            shift
            ;;
        --db-database)
            DB_DATABASE="${2:-}"
            shift 2
            ;;
        --db-username=*)
            DB_USERNAME="${1#*=}"
            shift
            ;;
        --db-username)
            DB_USERNAME="${2:-}"
            shift 2
            ;;
        --db-password=* | --db-password)
            log_error "拒绝 --db-password=xxx 命令行明文（同 --admin-password；进 shell history 风险）"
            log_info "请改用："
            log_info " 1. env: DB_PASSWORD=xxx sudo bash bt-install.sh"
            log_info " 2. 临时文件: --db-password-file=PATH（chmod 600，读后销毁）"
            log_info " 3. 交互输入: 不带 -y，脚本提示输入"
            exit 1
            ;;
        --db-password-file=*)
            DB_PASSWORD_FILE="${1#*=}"
            shift
            ;;
        --db-password-file)
            DB_PASSWORD_FILE="${2:-}"
            shift 2
            ;;
        *)
            shift
            ;;
    esac
done

# BT_KEY 文件来源（与 admin 密码同形）
BT_KEY_FILE="${BT_KEY_FILE:-}"
if [ -n "$BT_KEY_FILE" ] && [ -r "$BT_KEY_FILE" ]; then
    BT_KEY="$(head -n1 "$BT_KEY_FILE" | tr -d '[:space:]')"
    rm -f "$BT_KEY_FILE" # 读后立即销毁，避免落盘
fi

# 检测宝塔环境
# 读取宝塔面板版本号（从 common.py 的 g.version = '11.7.0'）
# 失败时 echo 空串，调用方据此判断
_get_bt_panel_version() {
    local common_py="/www/server/panel/class/common.py"
    [ -r "$common_py" ] || return 0
    grep -oE "g\.version[[:space:]]*=[[:space:]]*'[0-9]+\.[0-9]+\.[0-9]+'" "$common_py" 2>/dev/null |
        head -1 |
        grep -oE '[0-9]+\.[0-9]+\.[0-9]+'
}

# 比较两个语义版本：x.y.z（仅数字段）；$1 ≥ $2 时返回 0，否则 1
_version_ge() {
    local a="$1" b="$2"
    [ "$(printf '%s\n%s\n' "$a" "$b" | sort -V | head -1)" = "$b" ]
}

# 最低支持的宝塔面板版本
BT_MIN_VERSION="11.5.0"

check_environment() {
    log_step "检测宝塔环境"

    if ! check_bt_panel; then
        log_error "未检测到宝塔面板环境"
        log_info "仅支持宝塔面板部署"
        log_info "请安装宝塔面板后重试: https://www.bt.cn/new/download.html"
        exit 1
    fi

    # 版本预检：低于 11.5 的宝塔在 API 返回 / 插件流程上有重大差异，要求先升级
    local bt_version
    bt_version="$(_get_bt_panel_version)"
    if [ -z "$bt_version" ]; then
        log_warning "未能识别宝塔面板版本（common.py 不可读 / 格式异常）"
        log_warning "请确保面板版本 ≥ $BT_MIN_VERSION 后再继续"
    elif ! _version_ge "$bt_version" "$BT_MIN_VERSION"; then
        log_error "宝塔面板版本过低：$bt_version（需要 ≥ $BT_MIN_VERSION）"
        log_info "请到宝塔面板首页升级后再装："
        log_info " bt update # 命令行升级"
        log_info " 或面板首页 → 一键升级"
        exit 1
    else
        log_info "宝塔面板版本: $bt_version（≥ $BT_MIN_VERSION）"
    fi

    log_success "检测到宝塔面板环境"
}

select_php_version() {
    log_step "检测 PHP 版本"

    # 读 php-requirements.json 的 php_min（与版本绑定）；缺失兜底 8.3.0
    local req_file="$DEPLOY_DIR/php-requirements.json"
    local php_min
    php_min=$(_read_req_field "$req_file" "php_min" "8.3.0")
    if [ -f "$req_file" ]; then
        log_info "需求 PHP >= $php_min"
    fi

    # 扫描 /www/server/php/* 目录，按 PHP_VERSION 对比 php_min 筛选
    local php_versions=()
    for ver_dir in /www/server/php/*; do
        [ -d "$ver_dir" ] || continue
        local php_bin="$ver_dir/bin/php"
        [ -x "$php_bin" ] || continue
        local actual
        actual=$("$php_bin" -r 'echo PHP_VERSION;' 2>/dev/null) || continue
        # 用 PHP 自身比较（避免 bash 处理语义化版本不准）
        if "$php_bin" -r "exit(version_compare('$actual','$php_min','>=')?0:1);" 2>/dev/null; then
            php_versions+=("$(basename "$ver_dir")")
        fi
    done

    # 按版本号倒序（高版本优先展示）
    if [ ${#php_versions[@]} -gt 1 ]; then
        readarray -t php_versions < <(printf '%s\n' "${php_versions[@]}" | sort -rn)
    fi

    if [ ${#php_versions[@]} -eq 0 ]; then
        log_error "未检测到符合要求的 PHP 版本（需要 >= $php_min）"
        log_info "请在宝塔面板软件商店安装 PHP $php_min 或更高"
        exit 1
    elif [ ${#php_versions[@]} -eq 1 ]; then
        PHP_VERSION="${php_versions[0]}"
    else
        log_info "检测到多个符合要求的 PHP 版本："
        for i in "${!php_versions[@]}"; do
            local ver="${php_versions[$i]}"
            echo " $((i + 1)). PHP $(_php_pretty_version "$ver")"
        done

        while true; do
            read -p "请选择 (1-${#php_versions[@]}): " choice </dev/tty
            if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le ${#php_versions[@]} ]; then
                PHP_VERSION="${php_versions[$((choice - 1))]}"
                break
            fi
            log_error "无效选择"
        done
    fi

    PHP_CMD="/www/server/php/$PHP_VERSION/bin/php"
    log_success "使用 PHP $(_php_pretty_version "$PHP_VERSION")"
}

# 检测依赖
# BT API 预检结果（detect_bt_key 设置；select_install_dir 据此走单一路径）
BT_KEY_AVAILABLE=false

# 预检 BT API key 是否可用（依赖检测后立即跑，不交互不打印 key）
# 影响：select_install_dir 据此决定单一路径——
# - 可用：仅询问站点域名（站点已存在则询问是否复用，默认 y）
# - 不可用：仅询问安装目录绝对路径（手工配置 nginx）
detect_bt_key() {
    log_step "预检 BT API"

    if [ ! -f "$SCRIPT_DIR/bt-automate.sh" ]; then
        log_warning "bt-automate.sh 缺失 → 走自定义目录路径"
        BT_KEY_AVAILABLE=false
        return 0
    fi

    # source bt-automate.sh（try_bt_automation 会再 source 一次，bash source 幂等）
    # shellcheck source=bt-automate.sh
    source "$SCRIPT_DIR/bt-automate.sh"

    if ! bt_resolve_key 2>/dev/null; then
        log_info "未探测到 BT API key（可到面板 → 设置 → API 接口 启用并加 127.0.0.1 白名单）"
        log_info "→ 走自定义目录路径（nginx / supervisor / cron 全手工）"
        BT_KEY_AVAILABLE=false
        return 0
    fi

    if ! bt_verify_api_key; then
        log_warning "BT API key 验证失败（key 错误 / IP 白名单 / 安全锁）"
        log_info "→ 走自定义目录路径"
        BT_KEY_AVAILABLE=false
        return 0
    fi

    log_success "BT API 可用，将自动建站 + 配置 nginx / supervisor / cron"
    BT_KEY_AVAILABLE=true
    export BT_KEY
}

check_dependencies() {
    log_step "检测系统依赖"

    # 自动安装 base 扩展（fileinfo / intl / mbstring / calendar）+ pdo_mysql
    # pdo_mysql 是 Laravel 连 MySQL 的强需扩展，缺它会让 artisan migrate 失败
    # 失败仍走原 manual_actions 兜底
    # 子进程必须接受父进程选好的 PHP_VERSION/PHP_CMD，否则子进程独立扫描会选最高版本
    if [ -f "$SCRIPT_DIR/bt-deps.sh" ]; then
        PHP_VERSION="$PHP_VERSION" PHP_CMD="$PHP_CMD" \
            bash "$SCRIPT_DIR/bt-deps.sh" auto_install_ext pdo_mysql || true
    fi

    # 运行依赖检测脚本（manual_actions 兜底，强校验 MySQL + pdo_mysql）
    if [ -f "$SCRIPT_DIR/bt-deps.sh" ]; then
        if ! PHP_VERSION="$PHP_VERSION" PHP_CMD="$PHP_CMD" \
            bash "$SCRIPT_DIR/bt-deps.sh"; then
            log_error "依赖检测未通过，请按提示处理后重试"
            exit 1
        fi
    fi

    log_success "依赖检测完成"
}

# 选择安装目录 + 站点域名（合并采集）
#
# 优先级：env / 命令行参数 > 交互
# - INSTALL_DIR 可由环境变量预设
# - SITE_DOMAIN 可由 --site-domain / env SITE_DOMAIN 预设
# - 二者均缺失时：交互三选项；-y 模式严格退出
#
# -y 模式约束（P2）：
# - 同时缺 INSTALL_DIR 与 SITE_DOMAIN → 报错退出（无法决定目录）
# - 仅有 INSTALL_DIR 缺 SITE_DOMAIN → 警告"将跳过自动建站和 vhost 注入"，继续
# - 仅有 SITE_DOMAIN 缺 INSTALL_DIR → INSTALL_DIR=/www/wwwroot/$SITE_DOMAIN
select_install_dir() {
    log_step "选择安装目录与站点域名"

    # 1) env / 命令行已设：直接组合
    if [ -n "${SITE_DOMAIN:-}" ] && [ -z "${INSTALL_DIR:-}" ]; then
        INSTALL_DIR="/www/wwwroot/$SITE_DOMAIN"
        log_info "INSTALL_DIR fallback: /www/wwwroot/\$SITE_DOMAIN = $INSTALL_DIR"
    fi

    # 2) -y 模式严格校验：缺 INSTALL_DIR 且缺 SITE_DOMAIN → 退出
    if [ "$AUTO_YES" = "true" ]; then
        if [ -z "${INSTALL_DIR:-}" ]; then
            log_error "-y 模式必须提供 INSTALL_DIR（env）或 --site-domain（自动推导 /www/wwwroot/<domain>）"
            log_info "示例:"
            log_info " install.sh --url <url> -y --site-domain manager.example.com"
            log_info " INSTALL_DIR=/data/manager install.sh --url <url> -y"
            exit 1
        fi
        if [ -z "${SITE_DOMAIN:-}" ]; then
            log_warning "-y 模式未提供 --site-domain"
            log_warning "→ BT 自动建站、vhost include 注入将跳过；nginx 配置需手工完成"
            log_info "如需全自动，加: --site-domain manager.example.com"
        fi
    elif [ -z "${INSTALL_DIR:-}" ]; then
        # 3) 交互模式：根据 detect_bt_key 的 BT_KEY_AVAILABLE 走单一路径
        echo
        if [ "$BT_KEY_AVAILABLE" = true ]; then
            # === 路径 A：BT API 可用 → 仅询问站点域名 ===
            local input_domain=""
            while [ -z "$input_domain" ]; do
                read -p "请输入站点域名（如 manager.example.com）: " input_domain </dev/tty
                if [ -z "$input_domain" ]; then
                    log_error "域名不能为空"
                elif ! echo "$input_domain" | grep -qE '\.[a-zA-Z]{2,}$'; then
                    log_warning "看起来不是完整域名（缺少 TLD），仍继续？"
                    if ! confirm "确认使用 '$input_domain' 作为站点域名？"; then
                        input_domain=""
                    fi
                fi
            done
            SITE_DOMAIN="$input_domain"

            # 站点已存在则询问是否复用（默认 y），否则将由 try_bt_automation 自动建站
            local existing_path=""
            if existing_path=$(bt_get_site_path "$SITE_DOMAIN" 2>/dev/null) && [ -n "$existing_path" ]; then
                log_warning "BT 已有站点 '$SITE_DOMAIN'（路径: $existing_path）"
                if confirm "是否复用该站点？" "y"; then
                    INSTALL_DIR="$existing_path"
                    log_info "复用已有站点目录: $INSTALL_DIR"
                    # 复用站点 = 同意覆盖目录；step 5 跳过二次询问
                    SITE_REUSE_CONFIRMED=true
                else
                    log_error "用户拒绝复用现有站点，安装中止"
                    exit 0
                fi
            else
                INSTALL_DIR="/www/wwwroot/$SITE_DOMAIN"
            fi
        else
            # === 路径 B：BT API 不可用 → 仅询问安装目录绝对路径 ===
            local input_dir=""
            while [ -z "$input_dir" ]; do
                read -p "请输入安装目录（绝对路径）: " input_dir </dev/tty
                if [ -z "$input_dir" ]; then
                    log_error "目录不能为空"
                elif ! echo "$input_dir" | grep -qE '^/'; then
                    log_error "必须为绝对路径（以 / 开头）"
                    input_dir=""
                fi
            done
            INSTALL_DIR="$input_dir"
            SITE_DOMAIN=""
            log_info "已选择不自动建站，nginx 配置需手工完成"
        fi
    fi

    # 4) 校验
    if [ -z "${INSTALL_DIR:-}" ]; then
        log_error "INSTALL_DIR 为空（脚本逻辑异常）"
        exit 1
    fi
    if ! echo "$INSTALL_DIR" | grep -qE '^/'; then
        log_error "INSTALL_DIR 必须为绝对路径，收到: $INSTALL_DIR"
        exit 1
    fi

    # 5) 已存在目录提示（-y 直接覆盖；SITE_REUSE_CONFIRMED 复用站点已隐含同意，跳过二次询问）
    if [ -d "$INSTALL_DIR" ] && [ "$(ls -A "$INSTALL_DIR" 2>/dev/null | grep -v '^\.' | head -1)" ]; then
        if [ "$SITE_REUSE_CONFIRMED" != "true" ]; then
            log_warning "目录已存在: $INSTALL_DIR"
            if [ "$AUTO_YES" != "true" ]; then
                if ! confirm "是否覆盖安装？"; then
                    exit 0
                fi
            fi
        fi
    fi

    # 6) 创建目录
    if [ ! -d "$INSTALL_DIR" ]; then
        mkdir -p "$INSTALL_DIR"
        chown www:www "$INSTALL_DIR"
    fi

    log_success "安装目录: $INSTALL_DIR"
    if [ -n "${SITE_DOMAIN:-}" ]; then
        log_success "站点域名: $SITE_DOMAIN（将用于 BT 自动建站 + vhost include 注入）"
    fi
}

# 下载应用代码
download_application() {
    log_step "下载应用程序"

    # 使用环境变量中的版本，默认为 latest
    local version="${INSTALL_VERSION:-latest}"

    local temp_dir="/tmp/ssl-manager-download-$$"
    mkdir -p "$temp_dir"

    # 下载完整包到临时目录
    local zip_file="$temp_dir/full.zip"
    local filename
    case "$version" in
        latest | dev | dev-latest)
            filename="ssl-manager-full-latest.zip"
            ;;
        *)
            filename="ssl-manager-full-${version}.zip"
            ;;
    esac

    if ! download_release_file "$filename" "$zip_file" "$version"; then
        log_error "下载失败"
        rm -rf "$temp_dir"
        exit 1
    fi

    # 下载 releases.json + 完整包 sha256 强校验
    local releases_file="$temp_dir/releases.json"
    if ! download_releases_json "$releases_file"; then
        log_error "下载 releases.json 失败"
        rm -rf "$temp_dir"
        exit 1
    fi

    local expected_sha
    expected_sha=$(release_sha256 "$releases_file" "$version" "$filename") || {
        log_error "releases.json 缺失 v$version 的 $filename sha256 字段"
        rm -rf "$temp_dir"
        exit 1
    }

    if ! verify_sha256 "$zip_file" "$expected_sha"; then
        rm -rf "$temp_dir"
        exit 1
    fi
    log_success "完整包 sha256 校验通过"

    # 解压文件
    log_info "解压文件..."
    unzip -qo "$zip_file" -d "$temp_dir"

    # 找到解压后的目录
    local extract_dir="$temp_dir/ssl-manager"
    if [ ! -d "$extract_dir" ]; then
        extract_dir=$(find "$temp_dir" -mindepth 1 -maxdepth 1 -type d -name "ssl-manager*" | head -1)
    fi
    if [ ! -d "$extract_dir" ]; then
        extract_dir="$temp_dir/full"
    fi

    if [ ! -d "$extract_dir" ]; then
        log_error "未找到解压后的目录"
        rm -rf "$temp_dir"
        exit 1
    fi

    # 复制文件到安装目录
    if [ -d "$extract_dir/backend" ]; then
        log_info "复制后端代码..."
        cp -r "$extract_dir/backend" "$INSTALL_DIR/"
    else
        log_error "未找到后端代码"
        rm -rf "$temp_dir"
        exit 1
    fi

    if [ -d "$extract_dir/frontend" ]; then
        log_info "复制前端代码..."
        cp -r "$extract_dir/frontend" "$INSTALL_DIR/"
    fi

    # 复制 Nginx 配置
    if [ -d "$extract_dir/nginx" ]; then
        mkdir -p "$INSTALL_DIR/nginx"
        cp "$extract_dir/nginx"/*.conf "$INSTALL_DIR/nginx/" 2>/dev/null || true

        # 替换 nginx 配置中的占位符
        log_info "处理 nginx 配置..."
        for conf_file in "$INSTALL_DIR/nginx"/*.conf; do
            if [ -f "$conf_file" ]; then
                sed -i "s|__PROJECT_ROOT__|$INSTALL_DIR|g" "$conf_file"
            fi
        done
        log_success "nginx 配置已更新"
    fi

    # 替换 frontend/web 配置中的占位符
    if [ -d "$INSTALL_DIR/frontend/web" ]; then
        for conf_file in "$INSTALL_DIR/frontend/web"/*.conf; do
            if [ -f "$conf_file" ]; then
                sed -i "s|__PROJECT_ROOT__|$INSTALL_DIR|g" "$conf_file"
            fi
        done
    fi

    # 复制版本配置并注入 release_url 和 network
    if [ -f "$extract_dir/version.json" ]; then
        cp "$extract_dir/version.json" "$INSTALL_DIR/"
        local version_file="$INSTALL_DIR/version.json"

        # 使用 PHP 处理 JSON（确保格式正确）
        $PHP_CMD -r "
            \$json = json_decode(file_get_contents('$version_file'), true);
            // 注入 release_url
            if (!empty('$CUSTOM_RELEASE_URL')) {
                \$json['release_url'] = '$CUSTOM_RELEASE_URL';
            }
            // 注入 network 配置（从环境变量读取）
            \$network = getenv('NETWORK_ENV') ?: 'china';
            \$json['network'] = \$network;
            file_put_contents('$version_file', json_encode(\$json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . \"\\n\");
        "

        if [ -n "$CUSTOM_RELEASE_URL" ]; then
            log_info "已配置 release_url: $CUSTOM_RELEASE_URL"
        fi
        if [ -n "$NETWORK_ENV" ]; then
            log_info "已配置 network: $NETWORK_ENV"
        fi
    fi

    # 清理临时文件
    rm -rf "$temp_dir"

    log_success "下载完成"
}

# 检测 Composer（必要时安装 Composer；PHP 依赖由 run_composer_install 执行）
# 设置全局 COMPOSER_BIN，所有 composer 调用都用 "$PHP_CMD" "$COMPOSER_BIN" 显式驱动 phar，
# 避免 phar shebang #!/usr/bin/env php 找到错误版本（多版本系统 root PATH 可能命中老版 PHP）
check_composer() {
    log_step "检测 Composer"

    if command -v composer &>/dev/null; then
        COMPOSER_BIN="$(command -v composer)"
        log_success "Composer 已安装: $COMPOSER_BIN"
    elif [ -f "/usr/local/bin/composer" ]; then
        COMPOSER_BIN="/usr/local/bin/composer"
        log_success "Composer 已安装: $COMPOSER_BIN"
    else
        # 安装 Composer（在临时目录中执行，避免污染当前目录）
        log_info "安装 Composer..."
        local temp_composer_dir="/tmp/composer-install-$$"
        mkdir -p "$temp_composer_dir"
        cd "$temp_composer_dir"
        curl -sS https://getcomposer.org/installer | "$PHP_CMD"
        mv composer.phar /usr/local/bin/composer
        chmod +x /usr/local/bin/composer
        cd - >/dev/null
        rm -rf "$temp_composer_dir"
        COMPOSER_BIN="/usr/local/bin/composer"
        log_success "Composer 安装完成"
    fi

    # 镜像源配置由 run_composer_install 跑（与 install 共用临时 COMPOSER_HOME）
}

# 安装 PHP 依赖（full 包不含 vendor/，运行时拉取）
run_composer_install() {
    log_step "安装 PHP 依赖（composer install）"

    cd "$INSTALL_DIR/backend" || {
        log_error "无法 cd $INSTALL_DIR/backend"
        exit 1
    }

    if [ -f "vendor/autoload.php" ] && [ -d "vendor" ]; then
        log_info "vendor/ 已存在，跳过 composer install"
        return 0
    fi

    if [ ! -f "composer.json" ]; then
        log_error "composer.json 不存在，应用代码可能损坏"
        exit 1
    fi

    # 临时 COMPOSER_HOME（一次性，跑完即删；不污染持久目录，不进宝塔备份）
    local tmp_home
    tmp_home="$(mktemp -d /tmp/composer-home-XXXXXX)"
    chown -R "$WWW_USER:$WWW_USER" "$tmp_home"

    # 镜像源配置（写到 tmp_home，与 install 共用）
    if [ "${NETWORK_ENV:-}" = "china" ] && [ -x "$COMPOSER_BIN" ]; then
        if sudo -u "$WWW_USER" -E env HOME="$tmp_home" COMPOSER_HOME="$tmp_home" \
            "$PHP_CMD" "$COMPOSER_BIN" config -g repo.packagist composer https://mirrors.aliyun.com/composer/ 2>/dev/null; then
            log_info "已配置 composer 国内镜像源（阿里云）"
        else
            log_warning "配置 composer 镜像源失败，将使用默认源"
        fi
    fi

    # ext-redis 容错：项目默认 CACHE_DRIVER=file 不依赖 phpredis；--ignore-platform-req=ext-redis 跳过缺失校验
    log_info "执行 composer install --no-dev --optimize-autoloader（容忍 ext-redis 缺失）"
    # sudo -u www 跑 → vendor 直接归 www；"$PHP_CMD" "$COMPOSER_BIN" 显式驱动 phar 锁定 PHP 版本
    local rc=0
    sudo -u "$WWW_USER" -E env COMPOSER_ALLOW_SUPERUSER=1 HOME="$tmp_home" COMPOSER_HOME="$tmp_home" \
        "$PHP_CMD" "$COMPOSER_BIN" install --no-dev --no-interaction --no-progress --optimize-autoloader \
        --ignore-platform-req=ext-redis 2>&1 || rc=$?

    # 清理临时目录（成功失败都删）
    rm -rf "$tmp_home"

    if [ "$rc" -ne 0 ]; then
        log_error "composer install 失败"
        exit 1
    fi

    if [ ! -f "vendor/autoload.php" ]; then
        log_error "composer install 完成但 vendor/autoload.php 仍不存在"
        exit 1
    fi
    log_success "PHP 依赖已安装"
}

# 设置权限
set_permissions() {
    log_step "设置文件权限"

    # 创建 backups 目录（备份和升级包存储）
    mkdir -p "$INSTALL_DIR/backups/upgrades"

    # 创建 storage 子目录（文件缓存等功能需要，必须在 chown 之前创建）
    mkdir -p "$INSTALL_DIR/backend/storage/logs" \
        "$INSTALL_DIR/backend/storage/framework/cache/data" \
        "$INSTALL_DIR/backend/storage/framework/sessions" \
        "$INSTALL_DIR/backend/storage/framework/views" \
        "$INSTALL_DIR/backend/storage/app/public"

    # 使用 chown -R 一次性设置权限（比 find -exec 快得多）
    # 忽略 .user.ini 的错误（宝塔会锁定此文件）
    chown -R www:www "$INSTALL_DIR" 2>/dev/null || true

    # 确保 storage 和 cache 目录可写
    if [ -d "$INSTALL_DIR/backend/storage" ]; then
        chmod -R 775 "$INSTALL_DIR/backend/storage" 2>/dev/null || true
    fi

    if [ -d "$INSTALL_DIR/backend/bootstrap/cache" ]; then
        chmod -R 775 "$INSTALL_DIR/backend/bootstrap/cache" 2>/dev/null || true
    fi

    # 确保 backups 目录可写
    chmod -R 775 "$INSTALL_DIR/backups" 2>/dev/null || true

    # 验证权限设置
    local owner=$(stat -c '%U' "$INSTALL_DIR/backend" 2>/dev/null || echo "unknown")
    if [ "$owner" = "www" ]; then
        log_success "权限设置完成"
    else
        log_warning "权限设置可能未完全生效，当前所有者: $owner"
        log_info "请手动执行: chown -R www:www $INSTALL_DIR"
    fi
}

# ====================================================================
# 数据库支持（仅 mysql）
# ====================================================================

# 设定数据库驱动（仅 mysql）
select_db_driver() {
    log_step "数据库驱动"

    if [ -n "$DB_DRIVER" ] && [ "$DB_DRIVER" != "mysql" ]; then
        log_error "无效的 --db 值: $DB_DRIVER（仅支持 mysql）"
        exit 1
    fi
    DB_DRIVER="mysql"
    log_info "数据库驱动: mysql"
}

# 测试 MySQL 连接（用 MYSQL_PWD 环境变量传密码，避免 -p"$pwd" 在 ps -ef 暴露）
# 优先 mysql 客户端 → mysqladmin ping → 端口连通性 fallback
# 返回 0 = 通过；1 = 失败（错误信息已 log_error）
_test_mysql_connection() {
    log_info "测试 MySQL 连接..."

    if command -v mysql &>/dev/null; then
        local err
        if err=$(MYSQL_PWD="$DB_PASSWORD" mysql \
            -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
            --connect-timeout=5 \
            -e "SELECT 1" "$DB_DATABASE" 2>&1); then
            return 0
        fi
        log_error "MySQL 连接失败: $(echo "$err" | head -3)"
        return 1
    fi

    if command -v mysqladmin &>/dev/null; then
        local err
        if err=$(MYSQL_PWD="$DB_PASSWORD" mysqladmin \
            -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
            --connect-timeout=5 ping 2>&1); then
            log_warning "mysqladmin ping 通过，但未验证 database '$DB_DATABASE' 是否存在 / 账号是否有权限"
            return 0
        fi
        log_error "mysqladmin 连接失败: $(echo "$err" | head -3)"
        return 1
    fi

    # 客户端缺失：仅测试端口连通性
    log_warning "未安装 mysql / mysqladmin 客户端，仅测试端口连通性"
    if command -v nc &>/dev/null && nc -z -w 3 "$DB_HOST" "$DB_PORT" &>/dev/null; then
        log_warning "端口可达，但无法验证账号/密码/数据库（migrate 阶段会暴露真实错误）"
        return 0
    fi
    if (echo >/dev/tcp/"$DB_HOST"/"$DB_PORT") 2>/dev/null; then
        log_warning "端口可达，但无法验证账号/密码/数据库（migrate 阶段会暴露真实错误）"
        return 0
    fi

    log_error "无法连接到 $DB_HOST:$DB_PORT"
    return 1
}

# 收集数据库连接信息（mysql 必填）+ 测试连接，失败循环重新输入
# 命令行 / env 已设置的字段作为默认，回车保留
collect_db_credentials() {
    log_step "收集 $DB_DRIVER 连接信息"

    # 默认值
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT="${DB_PORT:-3306}"
    DB_DATABASE="${DB_DATABASE:-manager}"
    DB_USERNAME="${DB_USERNAME:-manager}"

    # DB_PASSWORD 一次性来源：file > env（仅函数入口解析；重试循环内走交互）
    if [ -n "$DB_PASSWORD_FILE" ] && [ -r "$DB_PASSWORD_FILE" ]; then
        DB_PASSWORD="$(head -n1 "$DB_PASSWORD_FILE" | tr -d '\r\n')"
        rm -f "$DB_PASSWORD_FILE" # 销毁，防落盘
        log_info "DB_PASSWORD: 来自 --db-password-file"
    elif [ -n "$DB_PASSWORD" ]; then
        log_info "DB_PASSWORD: 来自 env"
    fi

    local attempt=0
    while true; do
        attempt=$((attempt + 1))

        # 交互（仅 -y 之外；重试时所有字段允许回车保留当前值）
        if [ "$AUTO_YES" != "true" ]; then
            if [ "$attempt" -gt 1 ]; then
                echo
                log_warning "重新输入数据库连接信息（回车保留当前值）"
            fi

            local input
            read -r -p "数据库主机 [$DB_HOST]: " input </dev/tty || input=""
            DB_HOST="${input:-$DB_HOST}"

            read -r -p "数据库端口 [$DB_PORT]: " input </dev/tty || input=""
            DB_PORT="${input:-$DB_PORT}"

            read -r -p "数据库名 [$DB_DATABASE]: " input </dev/tty || input=""
            DB_DATABASE="${input:-$DB_DATABASE}"

            read -r -p "数据库用户名 [$DB_USERNAME]: " input </dev/tty || input=""
            DB_USERNAME="${input:-$DB_USERNAME}"

            # 密码：首次循环且 file/env 已设则跳过；否则提示
            # 重试时回车保留当前值（避免每次都要重输正确字段）
            if [ "$attempt" -eq 1 ] && [ -n "$DB_PASSWORD" ]; then
                : # 已用 file/env 设置，本次不交互
            else
                local pwd_prompt="数据库密码（空密码请回车）: "
                [ "$attempt" -gt 1 ] && pwd_prompt="数据库密码（回车保留当前值）: "
                local pwd_input=""
                read -r -p "$pwd_prompt" pwd_input </dev/tty || pwd_input=""
                if [ "$attempt" -gt 1 ] && [ -z "$pwd_input" ]; then
                    : # 重试时回车保留
                else
                    DB_PASSWORD="$pwd_input"
                fi
            fi
        fi

        # 必填校验
        if [ -z "$DB_USERNAME" ]; then
            log_error "数据库用户名不能为空（$DB_DRIVER 模式）"
            [ "$AUTO_YES" = "true" ] && exit 1
            continue
        fi

        log_info "数据库配置: $DB_DRIVER@$DB_HOST:$DB_PORT/$DB_DATABASE 用户=$DB_USERNAME"

        if _test_mysql_connection; then
            log_success "MySQL 连接测试通过"
            return 0
        fi

        # 失败处理：-y 模式直接退出，交互模式循环重试
        if [ "$AUTO_YES" = "true" ]; then
            log_error "MySQL 连接失败（-y 模式不重试）"
            log_info "请检查 host/port/user/password/database，确认 MySQL 运行中且账号有访问权限"
            exit 1
        fi
    done
}

# 写入 / 替换 .env 文件中指定字段（幂等）
_set_env_var() {
    local file="$1"
    local key="$2"
    local val="$3"
    # 含空格 / 引号 / # 的值必须加双引号（Laravel dotenv 解析要求）
    local quoted_val="$val"
    if printf '%s' "$val" | grep -qE '[[:space:]"#]'; then
        # 转义内部双引号
        quoted_val="\"$(printf '%s' "$val" | sed 's/"/\\"/g')\""
    fi
    # sed 转义 |、&、\ 三个特殊字符（用 | 作分隔符，避免与 / 冲突）
    local escaped
    escaped=$(printf '%s' "$quoted_val" | sed -e 's|[\\&|]|\\&|g')

    if grep -qE "^${key}=" "$file"; then
        # macOS sed 需要 -i ''，Linux 直接 -i；用 -i.bak + rm 兼容两端
        sed -i.bak "s|^${key}=.*|${key}=${escaped}|" "$file"
        rm -f "${file}.bak"
    else
        echo "${key}=${quoted_val}" >>"$file"
    fi
}

# 按数据库版本选择最优 collation（恢复旧 install.php 的版本自动切换逻辑，整合安装后曾遗漏）
# MySQL 8.0+ → utf8mb4_0900_ai_ci；MySQL 5.7 → utf8mb4_unicode_520_ci；
# MariaDB（不支持 0900 系列）→ utf8mb4_unicode_ci；连不上/无客户端时回落全版本通用的 unicode_ci
_detect_db_collation() {
    local version="" major
    if command -v mysql &>/dev/null; then
        version=$(MYSQL_PWD="$DB_PASSWORD" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
            -N -s -e "SELECT VERSION()" 2>/dev/null | head -n1)
    fi
    if [ -z "$version" ]; then
        printf 'utf8mb4_unicode_ci'
        return
    fi
    if printf '%s' "$version" | grep -qiE 'mariadb'; then
        printf 'utf8mb4_unicode_ci'
        return
    fi
    major=$(printf '%s' "$version" | grep -oE '[0-9]+\.[0-9]+' | head -n1)
    if [ -n "$major" ] && awk "BEGIN{exit !($major >= 8.0)}"; then
        printf 'utf8mb4_0900_ai_ci'
    else
        printf 'utf8mb4_unicode_520_ci'
    fi
}

# 生成 .env 文件
# - APP_KEY / JWT_SECRET 现场生成
# - mysql DB_CONNECTION + 连接字段
# - chmod 600 + chown www
generate_env_file() {
    log_step "生成 .env 文件"

    local env_file="$INSTALL_DIR/backend/.env"
    local env_example="$INSTALL_DIR/backend/.env.example"

    if [ ! -f "$env_example" ]; then
        log_error ".env.example 不存在: $env_example"
        exit 1
    fi

    cp "$env_example" "$env_file"

    # 安全密钥
    local app_key jwt_secret
    app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
    # JWT_SECRET：admin/user/api token 签发；空值会导致 login 500（tymon/jwt-auth 报 "Secret is not set"）
    jwt_secret="$(openssl rand -base64 64 | tr -d '\n')"

    # APP_NAME 默认走 .env.example 的 ssl；扫 /www/wwwroot/* 已有站点，
    # 仅在 ssl 被占用时追加序号（ssl1/ssl2/...）避免缓存前缀冲突
    local taken=""
    for other_env in /www/wwwroot/*/backend/.env; do
        if [ -f "$other_env" ] && [ "$other_env" != "$env_file" ]; then
            local n
            n=$(grep -E '^APP_NAME=' "$other_env" 2>/dev/null | head -n1 | sed -E 's/^APP_NAME=//; s/^"//; s/"$//')
            if [ -n "$n" ]; then
                taken="$taken $n "
            fi
        fi
    done
    if echo "$taken" | grep -q ' ssl '; then
        local i=1
        while echo "$taken" | grep -q " ssl$i "; do
            i=$((i + 1))
        done
        _set_env_var "$env_file" "APP_NAME" "ssl$i"
        log_info "检测到同机器已有站点 — APP_NAME 设为 ssl$i 避免缓存前缀冲突"
    fi
    _set_env_var "$env_file" "APP_ENV" "production"
    _set_env_var "$env_file" "APP_DEBUG" "false"
    _set_env_var "$env_file" "APP_KEY" "$app_key"
    _set_env_var "$env_file" "JWT_SECRET" "$jwt_secret"

    # APP_URL 留空走 config/app.php 默认；用户在宝塔配好 https 后自行写入 .env

    # 数据库字段（仅 mysql）
    _set_env_var "$env_file" "DB_CONNECTION" "$DB_DRIVER"
    _set_env_var "$env_file" "DB_HOST" "$DB_HOST"
    _set_env_var "$env_file" "DB_PORT" "$DB_PORT"
    _set_env_var "$env_file" "DB_DATABASE" "$DB_DATABASE"
    _set_env_var "$env_file" "DB_USERNAME" "$DB_USERNAME"
    _set_env_var "$env_file" "DB_PASSWORD" "$DB_PASSWORD"

    # DB_COLLATION：按数据库版本自动选择最优排序规则（恢复旧 install.php 逻辑）
    local db_collation
    db_collation=$(_detect_db_collation)
    _set_env_var "$env_file" "DB_COLLATION" "$db_collation"
    log_info "DB_COLLATION=$db_collation（按数据库版本自动选择）"

    # CACHE_DRIVER / QUEUE_CONNECTION / SESSION_DRIVER 不写入 — 已是 config 默认值

    # 权限
    chmod 600 "$env_file"
    chown "$WWW_USER:$WWW_USER" "$env_file" 2>/dev/null || true

    log_success ".env 已生成 (chmod 600)"
}

# 跑 artisan migrate + db:seed
run_artisan_install() {
    log_step "运行 artisan migrate + db:seed"

    cd "$INSTALL_DIR/backend"

    if ! sudo -u "$WWW_USER" "$PHP_CMD" artisan migrate --force 2>&1; then
        log_error "migrate 失败 — 检查数据库连接 / 用户权限 / 扩展是否安装"
        exit 1
    fi
    log_success "migrate 完成"

    if sudo -u "$WWW_USER" "$PHP_CMD" artisan db:seed --force 2>&1; then
        log_success "db:seed 完成（默认 admin/123456）"
    else
        log_warning "db:seed 失败（可能已 seed 过；可手工验证）"
    fi
}

# 设置 admin 初始密码（合并采集 + 应用，无 .admin_password 中转文件）
# 4 来源优先级：--admin-password-file > env ADMIN_PASSWORD > 交互输入（明文回显）> 自动生成
# 设计：seed 之后直接调 admin:reset-password 改密码；密码全程仅在 bash 变量中，不写磁盘
# - 文件来源：读完立即 rm（避免残留）
# - env 来源：读完 unset（避免暴露给子进程）
# - 自动生成：保留打印变量供完成信息输出，打印后清空
setup_admin_password() {
    log_step "设置 admin 初始密码"

    local password=""

    # 1. 临时文件（fail-fast：文件不存在立即报错）
    if [ -n "$ADMIN_PASSWORD_FILE" ]; then
        if [ ! -f "$ADMIN_PASSWORD_FILE" ]; then
            log_error "--admin-password-file 文件不存在: $ADMIN_PASSWORD_FILE"
            exit 1
        fi
        password=$(cat "$ADMIN_PASSWORD_FILE")
        rm -f "$ADMIN_PASSWORD_FILE" # 读后立即销毁，避免落盘
        log_info "已从临时文件读取密码并销毁原文件"

    # 2. 环境变量
    elif [ -n "${ADMIN_PASSWORD:-}" ]; then
        password="$ADMIN_PASSWORD"
        unset ADMIN_PASSWORD
        log_info "已从环境变量读取密码"

    # 3. 交互输入（仅非 -y 模式；用户回车跳过则走"自动生成"）
    # 不隐藏回显：安装是一次性私有操作，避免输入看不见出错；登录后立即修改更稳
    elif [ "$AUTO_YES" != "true" ]; then
        echo
        echo "可选：现在设置 admin 初始密码（直接回车则自动生成 16 位强密码）"
        read -r -p "Admin 初始密码: " password </dev/tty
    fi

    # 4. 自动生成（非交互或交互跳过）
    if [ -z "$password" ]; then
        if ! command -v openssl &>/dev/null; then
            log_error "缺少 openssl，无法生成 admin 密码；请安装后重试或显式提供密码"
            exit 1
        fi
        password=$(openssl rand -base64 12 | tr -d '+/=' | cut -c1-16)
        ADMIN_PASSWORD_GENERATED=true
        ADMIN_PASSWORD_PLAIN="$password" # 保留打印变量，由 show_complete_info 输出后立即清空
        log_info "已自动生成 16 位强密码（将在末尾汇总信息中显示）"
    fi

    # 直接调 admin:reset-password 修改（避免 .admin_password 文件中转）
    cd "$INSTALL_DIR/backend"
    # yes y 管道应付 ResetAdminPasswordCommand 内部 $this->confirm（非交互下默认拒绝）
    # 吞掉 artisan 输出（"建议管理员登录后立即修改密码"对脚本场景冗余）；失败时再回放
    local artisan_output
    if artisan_output=$(yes y | sudo -u "$WWW_USER" "$PHP_CMD" artisan admin:reset-password admin "$password" 2>&1); then
        log_success "admin 密码已设置"
    else
        log_error "admin:reset-password 失败"
        echo "$artisan_output"
        # 安全：失败也清空内存变量
        password=""
        ADMIN_PASSWORD_PLAIN=""
        exit 1
    fi

    # 清空内存变量（自动生成场景由 show_complete_info 打印后再清；其他场景立即清）
    if [ "$ADMIN_PASSWORD_GENERATED" != "true" ]; then
        password=""
    fi
}

# 显示 Nginx 配置提示
# 显示建站手工提示（仅 try_bt_automation 中自动建站失败时调用）
# 不再无条件提示 — 大多数用户走 BT API 自动建站，看不到这个就好
show_manual_site_hint() {
    echo "宝塔面板手工建站（自动建站失败时使用）:"
    echo " 网站 → 添加站点"
    echo " 域名: ${SITE_DOMAIN:-<您的域名>}"
    echo " 网站目录: $INSTALL_DIR"
    echo " PHP 版本: $(_php_pretty_version "$PHP_VERSION")"
    echo " 建站后到 网站 → ${SITE_DOMAIN:-<域名>} → 配置文件，在 root 行下方加："
    echo " include $INSTALL_DIR/nginx/manager.conf;"
    echo
}

# 显示 supervisor 手工配置提示（BT_KEY 不可用或 API 调用失败时）
show_manual_supervisor_hint() {
    local svc_name="${SITE_DOMAIN:-<您的站点域名>}"
    echo "Supervisor 队列守护进程（宝塔 → 软件商店 → 任务管理器/Supervisor）:"
    echo " 程序名: $svc_name"
    echo " 运行用户: www"
    echo " 目录: $INSTALL_DIR/backend/"
    echo " 启动命令: $PHP_CMD $INSTALL_DIR/backend/artisan queue:work --queue tasks,notifications --tries 3 --delay 5 --max-jobs 1000 --max-time 3600 --memory 128 --timeout 60 --sleep 3"
    echo " 进程数: 1"
    echo
}

# 显示 cron 手工配置提示
show_manual_cron_hint() {
    local svc_name="${SITE_DOMAIN:-<您的站点域名>}"
    echo "Cron 定时任务（宝塔 → 计划任务 → 添加任务 → Shell 脚本）:"
    echo " 任务名: $svc_name"
    echo " 执行周期: 每 1 分钟"
    echo " 脚本内容: $PHP_CMD $INSTALL_DIR/backend/artisan schedule:run >> /dev/null 2>&1"
    echo
}

# 显示 BT 站点 nginx include 手工提示（vhost 注入失败时）
show_manual_vhost_hint() {
    if [ -n "${SITE_DOMAIN:-}" ]; then
        echo "Nginx 配置（宝塔 → 网站 → $SITE_DOMAIN → 配置文件，root 行下方添加）:"
        echo " include $INSTALL_DIR/nginx/manager.conf;"
        echo
    fi
}

# 尝试 BT 自动化：建站 / vhost 注入 / supervisor / cron
# 解耦原则：
# - 建站、supervisor、cron 必需 BT_KEY；缺失走对应的手工提示
# - vhost 注入不依赖 BT_KEY，直接读写 vhost 文件 + nginx -s reload；
# 仅依赖 vhost 文件存在（用户已手工建站时也能跑）
try_bt_automation() {
    if [ ! -f "$SCRIPT_DIR/bt-automate.sh" ]; then
        # 模块缺失：所有自动化降级为手工提示
        log_warning "bt-automate.sh 未找到，跳过所有自动化"
        show_manual_supervisor_hint
        show_manual_cron_hint
        show_manual_vhost_hint
        return 0
    fi

    # shellcheck source=bt-automate.sh
    source "$SCRIPT_DIR/bt-automate.sh"

    # ==== BT_KEY 状态（已由 detect_bt_key 预检并设置 BT_KEY_AVAILABLE / 导出 BT_KEY）====
    # 这里不再二次交互输入；与 detect_bt_key 的单一路径承诺一致
    local has_bt_key="$BT_KEY_AVAILABLE"
    if [ "$has_bt_key" = false ]; then
        log_warning "无可用 BT_KEY；建站 / supervisor / cron 将走手工提示"
        log_info "vhost include 注入不依赖 BT_KEY，下方仍会尝试（前提：vhost 文件存在）"
    fi

    # ==== 1. 建站（必需 BT_KEY + SITE_DOMAIN；复用站点跳过创建）====
    local site_ready=false
    if [ "$SITE_REUSE_CONFIRMED" = "true" ]; then
        # select_install_dir 阶段已确认复用 BT 已有站点，无需再创建
        log_info "站点 $SITE_DOMAIN 已存在并复用，跳过建站"
        site_ready=true
    elif [ -n "${SITE_DOMAIN:-}" ] && [ "$has_bt_key" = true ]; then
        if bt_create_site "$SITE_DOMAIN" "$PHP_VERSION" "$INSTALL_DIR"; then
            site_ready=true
        else
            local rc=$?
            if [ "$rc" = "2" ]; then
                site_ready=true # 已存在视为成功（兜底：未走 select_install_dir 复用路径时）
            else
                log_warning "BT 建站失败，请手工建站"
            fi
        fi
    elif [ -z "${SITE_DOMAIN:-}" ]; then
        log_info "未提供网站域名，跳过自动建站；请到宝塔面板手工添加网站"
    fi

    # ==== 2. vhost include 注入（不依赖 BT_KEY；依赖 vhost 文件存在）====
    # 第 3 参 expected_root=$INSTALL_DIR：校验 BT vhost root 与 INSTALL_DIR 一致
    # 不一致时 -y 模式失败，交互模式询问（防止 SPA 资源 404 部署陷阱）
    local include_injected=false
    if [ -n "${SITE_DOMAIN:-}" ]; then
        if bt_inject_vhost_include "$SITE_DOMAIN" "$INSTALL_DIR/nginx/manager.conf" "$INSTALL_DIR"; then
            include_injected=true
        else
            log_warning "vhost include 注入失败（文件不存在 / root 错位 / 结构异常），请手工添加"
        fi
    fi

    # ==== 3. Supervisor 队列守护进程（必需 BT_KEY；进程名用站点域名保唯一，多站点不冲突）====
    local supervisor_ok=false
    if [ "$has_bt_key" = true ] && [ -n "${SITE_DOMAIN:-}" ]; then
        if bt_ensure_supervisor_plugin; then
            if bt_add_supervisor_process \
                "$SITE_DOMAIN" \
                "www" \
                "$INSTALL_DIR/backend/" \
                "$PHP_CMD $INSTALL_DIR/backend/artisan queue:work --queue tasks,notifications --tries 3 --delay 5 --max-jobs 1000 --max-time 3600 --memory 128 --timeout 60 --sleep 3" \
                1 \
                "$SITE_DOMAIN"; then
                supervisor_ok=true
            else
                log_warning "supervisor 进程添加失败"
            fi
        else
            log_warning "supervisor 插件不可用"
        fi
    fi

    # ==== 4. Cron 定时任务（必需 BT_KEY；任务名用站点域名保唯一）====
    local cron_ok=false
    if [ "$has_bt_key" = true ] && [ -n "${SITE_DOMAIN:-}" ]; then
        if bt_add_crontab "$SITE_DOMAIN" "minute-n" 1 \
            "$PHP_CMD $INSTALL_DIR/backend/artisan schedule:run >> /dev/null 2>&1"; then
            cron_ok=true
        else
            log_warning "cron 添加失败"
        fi
    fi

    # ==== 5. 汇总未完成步骤的手工提示（仅未自动完成的步骤打印）====
    echo
    echo "============================================"
    echo " 自动化结果"
    echo "============================================"
    [ "$site_ready" = true ] && echo "✓ 站点已就绪: $SITE_DOMAIN" ||
        { [ -n "${SITE_DOMAIN:-}" ] && echo "✗ 站点未自动建站，请到宝塔面板 → 网站 → 添加站点 ($SITE_DOMAIN)"; }
    [ "$include_injected" = true ] && echo "✓ vhost include 已注入: $INSTALL_DIR/nginx/manager.conf" ||
        { [ -n "${SITE_DOMAIN:-}" ] && echo "✗ vhost include 未注入"; }
    [ "$supervisor_ok" = true ] && echo "✓ supervisor 已添加: $SITE_DOMAIN" ||
        echo "✗ supervisor 未自动添加"
    [ "$cron_ok" = true ] && echo "✓ cron 已添加: $SITE_DOMAIN（每分钟）" ||
        echo "✗ cron 未自动添加"
    echo

    # 仅在某项失败时打印对应手工配置提示
    local need_manual=false
    [ "$site_ready" != true ] && [ -n "${SITE_DOMAIN:-}" ] && need_manual=true
    [ "$include_injected" != true ] && [ -n "${SITE_DOMAIN:-}" ] && need_manual=true
    [ "$supervisor_ok" != true ] && need_manual=true
    [ "$cron_ok" != true ] && need_manual=true

    if [ "$need_manual" = true ]; then
        echo "未完成步骤的手工配置参考："
        echo
        [ "$site_ready" != true ] && [ -n "${SITE_DOMAIN:-}" ] && show_manual_site_hint
        [ "$include_injected" != true ] && [ -n "${SITE_DOMAIN:-}" ] && show_manual_vhost_hint
        [ "$supervisor_ok" != true ] && show_manual_supervisor_hint
        [ "$cron_ok" != true ] && show_manual_cron_hint
    fi
}

# 显示完成信息
# 顺序设计：
# 1. 先跑 try_bt_automation（建站 + supervisor + cron + vhost 注入），它会打印汇总 + 失败步骤的手工提示
# 2. 再打印环境信息 + 登录 URL（用真实域名拼出来）
# 3. 最后打印自动生成的 admin 密码（如有）
show_complete_info() {
    # 1. 跑 BT 自动化（内部已处理失败时的手工提示，不再打印通用 nginx tips）
    try_bt_automation

    echo
    echo "============================================"
    echo " 环境准备完成"
    echo "============================================"
    echo
    echo "安装目录: $INSTALL_DIR"
    echo "PHP 版本: $(_php_pretty_version "$PHP_VERSION")"
    if [ -n "${SITE_DOMAIN:-}" ]; then
        echo "站点域名: $SITE_DOMAIN"
        echo
        echo "管理后台登录地址:"
        echo " http://$SITE_DOMAIN/admin"
        echo "用户中心登录地址:"
        echo " http://$SITE_DOMAIN/user"
    else
        echo
        echo "登录地址: 配置 nginx 后访问 http://<您的域名>/admin"
    fi
    echo

    # 自动生成的密码必须在终端打印一次（首次登录建议立即改密）
    if [ "$ADMIN_PASSWORD_GENERATED" = "true" ] && [ -n "${ADMIN_PASSWORD_PLAIN:-}" ]; then
        echo "============================================"
        echo " Admin 初始密码（请立即记录）"
        echo "============================================"
        echo
        echo " 用户名: admin"
        echo " 密码: $ADMIN_PASSWORD_PLAIN"
        echo
        echo " 登录后建议立即修改密码"
        echo
        # 打印后立即清空内存
        ADMIN_PASSWORD_PLAIN=""
    fi
}

# 主函数
main() {
    log_step "开始宝塔环境安装"

    # 1-2. 环境 + PHP
    check_environment
    select_php_version

    # 3. 数据库驱动选择（提前到依赖检测前，让 bt-deps.sh 按驱动跳过 MySQL 检查）
    # 注：仅选 driver，mysql 连接信息收集留在 collect_db_credentials
    select_db_driver

    # 4. BT API 预检（提前到 check_dependencies 之前；子进程 bt-deps.sh 通过 env 继承 BT_KEY 复用）
    # 探测结果决定 select_install_dir 走的单一路径：
    #   BT API 可用 → 仅问站点域名（已存在询问是否复用）
    #   BT API 不可用 → 仅问安装目录绝对路径
    detect_bt_key

    # 5-7. 依赖 / 目录 / 下载 / Composer（check_dependencies 已知 DB_DRIVER + BT_KEY）
    check_dependencies
    select_install_dir
    download_application
    check_composer

    # 8. 权限
    set_permissions

    # 8.5 安装 PHP 依赖（full 包不含 vendor/，运行时装）
    run_composer_install

    # 9. 数据库连接信息收集（依赖 INSTALL_DIR / WWW_USER）
    collect_db_credentials

    # 10. 生成 .env（APP_KEY / JWT_SECRET 自动生成）
    generate_env_file

    # 11-12. artisan migrate + db:seed
    run_artisan_install

    # 13. admin 密码（4 来源；seed 后直接采集 + reset-password，无 .admin_password 中转文件）
    setup_admin_password

    # 14. 显示完成信息（内部跑 try_bt_automation 自动建站 + supervisor + cron）
    show_complete_info
}

# 运行主函数
main "$@"
