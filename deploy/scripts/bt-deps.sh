#!/bin/bash

# SSL证书管理系统 - 宝塔环境依赖检测脚本
# 自动处理 PHP 函数禁用和扩展检测

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/common.sh"

# 全局变量
PHP_VERSION=""
PHP_CMD=""
PHP_INI=""
NEED_MANUAL_ACTION=false
MANUAL_ACTIONS=()

# 检测并选择 PHP 版本（仅支持 8.3/8.4）
detect_php_version() {
    for ver in 84 83; do
        if [ -d "/www/server/php/$ver" ] && [ -x "/www/server/php/$ver/bin/php" ]; then
            PHP_VERSION="$ver"
            PHP_CMD="/www/server/php/$ver/bin/php"
            PHP_INI="/www/server/php/$ver/etc/php.ini"
            return 0
        fi
    done
    return 1
}

# 从配置文件中解除禁用函数
enable_functions_in_ini() {
    local ini_file="$1"
    local functions_str="$2"

    if [ ! -f "$ini_file" ]; then
        return 0
    fi

    # 备份配置文件
    cp "$ini_file" "$ini_file.bak.$(date +%Y%m%d%H%M%S)"

    # 获取当前禁用函数列表
    local disabled_functions=$(grep -E "^disable_functions\s*=" "$ini_file" | sed 's/disable_functions\s*=\s*//' | tr -d ' ')
    local new_disabled="$disabled_functions"

    # 移除指定的函数
    for func in $functions_str; do
        new_disabled=$(echo "$new_disabled" | sed "s/,$func,/,/g" | sed "s/^$func,//g" | sed "s/,$func$//g" | sed "s/^$func$//g")
    done

    # 更新配置文件
    sed -i "s/^disable_functions\s*=.*/disable_functions = $new_disabled/" "$ini_file"
}

# 检测禁用函数
check_disabled_functions() {
    log_step "检测 PHP 禁用函数"

    # 宝塔有两个配置文件：php.ini (FPM) 和 php-cli.ini (CLI)
    local php_ini="$PHP_INI"
    local php_cli_ini="/www/server/php/$PHP_VERSION/etc/php-cli.ini"

    # 必需的函数：Composer 和 Laravel 运行所需
    # - putenv, proc_*: Composer 依赖安装
    # - exec, shell_exec: 系统命令执行
    # - pcntl_*: Laravel 队列和进程管理
    local required_functions=("putenv" "proc_open" "proc_close" "proc_get_status" "proc_terminate" "exec" "shell_exec" "pcntl_signal" "pcntl_alarm" "pcntl_async_signals")

    # 检查两个配置文件中的禁用函数
    local all_disabled=""
    if [ -f "$php_ini" ]; then
        all_disabled="$all_disabled,$(grep -E "^disable_functions\s*=" "$php_ini" | sed 's/disable_functions\s*=\s*//' | tr -d ' ')"
    fi
    if [ -f "$php_cli_ini" ]; then
        all_disabled="$all_disabled,$(grep -E "^disable_functions\s*=" "$php_cli_ini" | sed 's/disable_functions\s*=\s*//' | tr -d ' ')"
    fi

    if [ -z "$all_disabled" ] || [ "$all_disabled" = "," ]; then
        log_error "未找到 PHP 配置文件"
        return 1
    fi

    local functions_to_enable=()

    for func in "${required_functions[@]}"; do
        if echo "$all_disabled" | grep -qi "\b$func\b"; then
            functions_to_enable+=("$func")
        fi
    done

    if [ ${#functions_to_enable[@]} -eq 0 ]; then
        log_success "所有必要函数已启用"
        return 0
    fi

    log_warning "检测到禁用函数: ${functions_to_enable[*]}"
    log_info "正在自动解除禁用..."

    # 同时更新 php.ini 和 php-cli.ini
    local functions_str="${functions_to_enable[*]}"

    if [ -f "$php_ini" ]; then
        enable_functions_in_ini "$php_ini" "$functions_str"
        log_info "已更新: php.ini"
    fi

    if [ -f "$php_cli_ini" ]; then
        enable_functions_in_ini "$php_cli_ini" "$functions_str"
        log_info "已更新: php-cli.ini"
    fi

    log_success "已解除禁用: ${functions_to_enable[*]}"

    # 重启 PHP-FPM（CLI 不需要重启）
    log_info "重启 PHP 服务..."
    if [ -f "/etc/init.d/php-fpm-$PHP_VERSION" ]; then
        /etc/init.d/php-fpm-$PHP_VERSION restart >/dev/null 2>&1
    elif systemctl is-active --quiet "php-fpm-$PHP_VERSION"; then
        systemctl restart "php-fpm-$PHP_VERSION" >/dev/null 2>&1
    fi

    log_success "PHP 函数配置已更新"
}

# 解析 BT API key
# 优先级：env BT_KEY > /www/server/panel/config/api.json 的 token_crypt 字段
# 写入全局：BT_KEY、BT_PANEL_PORT
_resolve_bt_api_key() {
    if [ -n "${BT_KEY:-}" ]; then
        return 0
    fi
    local api_json="/www/server/panel/config/api.json"
    if [ -r "$api_json" ]; then
        # token_crypt 是 BT 11.x 对外 API key（用于 MD5 签名）
        BT_KEY=$(awk -F'"' '/"token_crypt"/{for(i=1;i<=NF;i++) if($i=="token_crypt"){print $(i+2); exit}}' "$api_json" 2>/dev/null)
    fi
    # 默认面板端口（BT 11.x 自定义端口存在 /www/server/panel/data/port.pl）
    BT_PANEL_PORT="${BT_PANEL_PORT:-}"
    if [ -z "$BT_PANEL_PORT" ] && [ -r "/www/server/panel/data/port.pl" ]; then
        BT_PANEL_PORT=$(tr -d '[:space:]' </www/server/panel/data/port.pl)
    fi
    # BT 11.x 安装时会随机生成端口写入 port.pl；fallback 用 BT 老版默认端口 8888
    BT_PANEL_PORT="${BT_PANEL_PORT:-8888}"
    [ -n "$BT_KEY" ]
}

# 通过 BT 11.x API 安装 PHP 扩展
# 端点：POST /files?action=InstallSoft, name=<ext>&version=<phpv>&type=1（type=1 表示 PHP 扩展）
# 用法：bt_install_so_via_api <ext_name>
# 返回：0=已就绪（异步任务+php -m 验证）；非 0=失败
bt_install_so_via_api() {
    local ext="$1"
    if ! _resolve_bt_api_key; then
        return 1
    fi

    local key_md5
    key_md5=$(printf %s "$BT_KEY" | md5sum | awk '{print $1}')
    local now
    now=$(date +%s)
    local token
    token=$(printf %s "${now}${key_md5}" | md5sum | awk '{print $1}')

    local resp
    resp=$(curl -sk -X POST "https://127.0.0.1:${BT_PANEL_PORT}/files?action=InstallSoft" \
        -d "request_time=${now}&request_token=${token}&name=${ext}&version=${PHP_VERSION}&type=1" \
        -m 30 2>/dev/null)

    if ! echo "$resp" | grep -q '"status":[[:space:]]*true'; then
        log_warning "BT API 返回未成功: $(echo "$resp" | head -c 200)"
        return 1
    fi
    log_info "  → BT 装扩展任务已入队: $ext"

    # 轮询验证就绪（最多 120s；BT 编译扩展可能慢）
    local i=0
    while [ "$i" -lt 60 ]; do
        if "$PHP_CMD" -m 2>/dev/null | grep -qi "^${ext}$"; then
            return 0
        fi
        sleep 2
        i=$((i + 1))
    done
    return 1
}

# 自动安装缺失扩展
# 用法：./bt-deps.sh auto_install_ext [extra_ext1 extra_ext2 ...]
# - base 扩展：fileinfo / intl / mbstring / calendar（Laravel 11 必备 + cal_days_in_month）
# - extra 参数：额外扩展（bt-install.sh 在调用时显式追加 pdo_mysql）
# - 优先用 BT 11.x API（/files?action=InstallSoft）
# - fallback 1: 老版 BT install.sh 路径（向后兼容）
# - fallback 2: 已编译 .so 直接 sed 启用 php.ini（PHP 内置扩展如 calendar 已随 BT 编译进 extension_dir，
#                BT API 不可用时直接 enable 即可生效）
# 失败的扩展回填 MANUAL_ACTIONS
# 注：redis 不在 base 列表（项目默认 CACHE_DRIVER=file；BT 11.x 装 phpredis 还要先装 igbinary 依赖链复杂）
auto_install_ext() {
    local extra_exts=("$@")
    local target_extensions=("fileinfo" "intl" "mbstring" "calendar" "${extra_exts[@]}")

    log_step "尝试自动安装缺失的 PHP 扩展: ${target_extensions[*]}"

    if [ -z "$PHP_VERSION" ] || [ -z "$PHP_CMD" ]; then
        log_error "PHP 版本未检测，请先运行 detect_php_version"
        return 1
    fi

    # fallback 老版 BT install.sh 路径
    local legacy_install_cmd=""
    for candidate in \
        "/www/server/php/$PHP_VERSION/install.sh" \
        "/www/server/php/$PHP_VERSION/install_ext.sh"; do
        if [ -x "$candidate" ] || [ -f "$candidate" ]; then
            legacy_install_cmd="$candidate"
            break
        fi
    done

    # PHP 扩展目录（用于检测 .so 是否已随 BT 编译）
    local php_ext_dir
    php_ext_dir=$("$PHP_CMD" -r 'echo ini_get("extension_dir");' 2>/dev/null)

    local installed_any=false
    local failed_ext=()
    local skipped_ext=()

    for ext in "${target_extensions[@]}"; do
        # 已装则跳过
        if $PHP_CMD -m 2>/dev/null | grep -qi "^$ext$"; then
            skipped_ext+=("$ext")
            continue
        fi

        log_info "正在安装扩展: $ext"
        local installed=false

        # 路径 1：BT 11.x API（首选）
        if bt_install_so_via_api "$ext"; then
            log_success "扩展安装成功: $ext (via BT API)"
            installed=true
            installed_any=true
        fi

        # 路径 2：老版 BT install.sh fallback
        if [ "$installed" = false ] && [ -n "$legacy_install_cmd" ]; then
            if timeout 120 bash "$legacy_install_cmd" install "$ext" >/dev/null 2>&1 ||
                timeout 120 bash "$legacy_install_cmd" "$ext" >/dev/null 2>&1; then
                sleep 1
                if $PHP_CMD -m 2>/dev/null | grep -qi "^$ext$"; then
                    log_success "扩展安装成功: $ext (via legacy script)"
                    installed=true
                    installed_any=true
                fi
            fi
        fi

        # 路径 3：.so 已存在但 ini 未启用（PHP 内置扩展如 calendar 常见）
        # 检测 extension_dir/<ext>.so 是否存在 → 写 cli + fpm 两份 ini → 验证生效
        if [ "$installed" = false ] && [ -n "$php_ext_dir" ] && [ -f "$php_ext_dir/${ext}.so" ]; then
            log_info "  → 检测到 ${ext}.so 已编译于 $php_ext_dir，启用 ini"
            local ini_dir="/www/server/php/$PHP_VERSION/etc"
            for ini_file in "$ini_dir/php.ini" "$ini_dir/php-cli.ini"; do
                if [ -f "$ini_file" ] && ! grep -qE "^[[:space:]]*extension[[:space:]]*=[[:space:]]*${ext}\.so" "$ini_file"; then
                    echo "extension = ${ext}.so" >>"$ini_file"
                fi
            done
            sleep 1
            if $PHP_CMD -m 2>/dev/null | grep -qi "^$ext$"; then
                log_success "扩展启用成功: $ext (via php.ini 直写)"
                installed=true
                installed_any=true
            fi
        fi

        if [ "$installed" = false ]; then
            log_warning "扩展自动安装失败: $ext"
            failed_ext+=("$ext")
        fi
    done

    if [ ${#skipped_ext[@]} -gt 0 ]; then
        log_info "已装跳过: ${skipped_ext[*]}"
    fi

    if [ "$installed_any" = "true" ]; then
        log_info "重启 PHP-FPM 让新扩展生效"
        if [ -f "/etc/init.d/php-fpm-$PHP_VERSION" ]; then
            /etc/init.d/php-fpm-$PHP_VERSION restart >/dev/null 2>&1 || true
        elif systemctl is-active --quiet "php-fpm-$PHP_VERSION" 2>/dev/null; then
            systemctl restart "php-fpm-$PHP_VERSION" >/dev/null 2>&1 || true
        fi
    fi

    if [ ${#failed_ext[@]} -gt 0 ]; then
        log_warning "以下扩展自动安装失败: ${failed_ext[*]}"
        log_info "请到宝塔面板 → 软件商店 → PHP 8.${PHP_VERSION: -1} → 设置 → 安装扩展 手工安装"
        NEED_MANUAL_ACTION=true
        MANUAL_ACTIONS+=("以下扩展自动安装失败，请在宝塔面板手工安装:")
        for ext in "${failed_ext[@]}"; do
            MANUAL_ACTIONS+=("  - $ext")
        done
        return 1
    fi

    log_success "所有目标扩展处理完成"
    return 0
}

# 检测 PHP 扩展
check_php_extensions() {
    log_step "检测 PHP 扩展"

    # 必要扩展分类
    # - 可自动安装：宝塔面板可直接安装
    # - 需手工处理：某些版本需要手工编译或特殊处理
    # - pdo_mysql：Laravel 连 MySQL 必备；bt-install.sh check_dependencies 调用时
    #   通过 auto_install_ext 显式装上，缺它会让 artisan migrate 直接失败
    # - 不含 redis：项目默认 CACHE_DRIVER=file，redis 是可选优化
    #   而非 Laravel 11 必备；用户切到 redis driver 时再装。BT 11.x 装 phpredis 还要先装
    #   igbinary 依赖链复杂，硬性要求会卡住绝大多数无 redis 需求的部署
    # - calendar：composer.json require ext-calendar（cal_days_in_month 在 Date.php 用）；
    #   PHP 内置扩展，BT 编译时已生成 calendar.so，仅需在 ini 启用（auto_install_ext 走 path 3）
    local required_ext=("gd" "zip" "bcmath" "pcntl" "intl" "fileinfo" "openssl" "mbstring" "curl" "xml" "calendar" "pdo_mysql")

    # 需要手工在宝塔面板安装的扩展（无法自动安装）
    local manual_ext=("fileinfo" "intl")

    local missing_ext=()
    local missing_manual=()

    for ext in "${required_ext[@]}"; do
        if ! $PHP_CMD -m 2>/dev/null | grep -qi "^$ext$"; then
            missing_ext+=("$ext")
            # 检查是否需要手工安装
            for m in "${manual_ext[@]}"; do
                if [ "$ext" = "$m" ]; then
                    missing_manual+=("$ext")
                    break
                fi
            done
        fi
    done

    if [ ${#missing_ext[@]} -eq 0 ]; then
        log_success "所有必要扩展已安装"
        return 0
    fi

    log_warning "缺少扩展: ${missing_ext[*]}"

    # 检查是否有需要手工安装的扩展
    if [ ${#missing_manual[@]} -gt 0 ]; then
        NEED_MANUAL_ACTION=true
        MANUAL_ACTIONS+=("请在宝塔面板中安装以下 PHP 扩展:")
        for ext in "${missing_manual[@]}"; do
            MANUAL_ACTIONS+=("  - $ext")
        done
        MANUAL_ACTIONS+=("")
        MANUAL_ACTIONS+=("安装路径: 宝塔面板 → 软件商店 → PHP 8.${PHP_VERSION: -1} → 设置 → 安装扩展")
    fi

    return 1
}

# 检测 MySQL
check_mysql() {
    log_step "检测 MySQL"

    if [ -d "/www/server/mysql" ] || command -v mysql &>/dev/null; then
        log_success "MySQL 已安装"
        return 0
    else
        log_warning "未检测到 MySQL"
        NEED_MANUAL_ACTION=true
        MANUAL_ACTIONS+=("请在宝塔面板中安装 MySQL")
        return 1
    fi
}

# 检测 Redis（可选；项目默认 CACHE_DRIVER=file，redis 仅在 .env 改 driver 时才需要）
check_redis() {
    log_step "检测 Redis（可选）"

    if [ -d "/www/server/redis" ] || command -v redis-server &>/dev/null; then
        log_success "Redis 已安装"
    else
        log_info "未检测到 Redis（可选；项目默认 CACHE_DRIVER=file，无 Redis 不影响安装）"
        log_info "如需切换到 Redis cache/session/queue，请在宝塔面板软件商店安装 Redis 后改 .env"
    fi
    return 0
}

# 检测 Composer（版本 < 2.8 会导致依赖安装错误）
check_composer() {
    log_step "检测 Composer"

    if ! command -v composer &>/dev/null; then
        log_info "Composer 未安装，将在安装时自动安装（最新版）"
        return 0
    fi

    local version=$(composer --version 2>/dev/null | grep -o '[0-9]\+\.[0-9]\+\.[0-9]\+' | head -1)

    if [ -z "$version" ]; then
        log_warning "无法解析 Composer 版本"
        return 0
    fi

    # 比对版本号是否 >= 2.8.0
    local major=$(echo "$version" | cut -d. -f1)
    local minor=$(echo "$version" | cut -d. -f2)
    if [ "$major" -lt 2 ] || { [ "$major" -eq 2 ] && [ "$minor" -lt 8 ]; }; then
        log_warning "Composer $version 版本过低，需要 2.8+"
        NEED_MANUAL_ACTION=true
        MANUAL_ACTIONS+=("Composer 版本 $version 过低（低版本会导致依赖安装错误）")
        MANUAL_ACTIONS+=("请运行: composer self-update")
        return 1
    fi

    log_success "Composer $version 已安装"
    return 0
}

# 显示手工操作提示
show_manual_actions() {
    if [ "$NEED_MANUAL_ACTION" = true ] && [ ${#MANUAL_ACTIONS[@]} -gt 0 ]; then
        echo
        echo "============================================"
        echo "       需要手工处理以下问题"
        echo "============================================"
        echo
        for action in "${MANUAL_ACTIONS[@]}"; do
            echo "$action"
        done
        echo
        log_info "完成以上操作后，请重新运行安装脚本"
        echo
        return 1
    fi
    return 0
}

# 主函数
main() {
    echo
    echo "============================================"
    echo "       宝塔环境依赖检测"
    echo "============================================"
    echo

    # 检测 PHP 版本
    log_step "检测 PHP 环境"
    if ! detect_php_version; then
        log_error "未找到 PHP 8.3+"
        log_info "请在宝塔面板安装 PHP 8.3 或更高版本"
        exit 1
    fi
    log_success "PHP 8.${PHP_VERSION: -1}: $($PHP_CMD -v | head -1)"

    # 检测并修复禁用函数
    check_disabled_functions

    # 检测扩展
    check_php_extensions
    echo

    # 检测 MySQL
    check_mysql
    echo

    # 检测 Redis
    check_redis
    echo

    # 检测 Composer
    check_composer

    # 显示手工操作提示
    if ! show_manual_actions; then
        exit 1
    fi

    log_info "依赖检测完成"
}

# 子命令派发
# - 不带参数：原 main 流程（检测 + 提示）
# - auto_install_ext：先 detect_php_version，再调 auto_install_ext
case "${1:-}" in
    auto_install_ext)
        log_step "检测 PHP 环境"
        if ! detect_php_version; then
            log_error "未找到 PHP 8.3+"
            exit 1
        fi
        log_success "PHP 8.${PHP_VERSION: -1}: $($PHP_CMD -v | head -1)"
        auto_install_ext
        exit $?
        ;;
    "")
        main "$@"
        ;;
    *)
        echo "未知子命令: $1"
        echo "用法:"
        echo "  $0                  # 检测依赖（默认）"
        echo "  $0 auto_install_ext # 自动安装缺失 PHP 扩展（fileinfo/intl/redis）"
        exit 1
        ;;
esac
