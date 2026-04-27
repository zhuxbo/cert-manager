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

# 检测 PHP 扩展
check_php_extensions() {
    log_step "检测 PHP 扩展"

    # 必要扩展分类
    # - 可自动安装：宝塔面板可直接安装
    # - 需手工处理：某些版本需要手工编译或特殊处理
    local required_ext=("pdo_mysql" "redis" "gd" "zip" "bcmath" "pcntl" "intl" "fileinfo" "openssl" "mbstring" "curl" "xml")

    # 需要手工在宝塔面板安装的扩展（无法自动安装）
    local manual_ext=("redis" "fileinfo" "intl")

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

    if [ -d "/www/server/mysql" ] || command -v mysql &> /dev/null; then
        log_success "MySQL 已安装"
        return 0
    else
        log_warning "未检测到 MySQL"
        NEED_MANUAL_ACTION=true
        MANUAL_ACTIONS+=("请在宝塔面板中安装 MySQL")
        return 1
    fi
}

# 检测 Redis
check_redis() {
    log_step "检测 Redis"

    if [ -d "/www/server/redis" ] || command -v redis-server &> /dev/null; then
        log_success "Redis 已安装"
        return 0
    else
        log_warning "未检测到 Redis"
        NEED_MANUAL_ACTION=true
        MANUAL_ACTIONS+=("请在宝塔面板中安装 Redis")
        return 1
    fi
}

# 检测 Composer（版本 < 2.8 会导致依赖安装错误）
check_composer() {
    log_step "检测 Composer"

    if ! command -v composer &> /dev/null; then
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

main "$@"
