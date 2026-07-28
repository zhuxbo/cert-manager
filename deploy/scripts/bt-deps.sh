#!/bin/bash

# SSL证书管理系统 - 宝塔环境依赖检测脚本
# 自动处理 PHP 函数禁用和扩展检测

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/common.sh"

# 全局变量
# 允许父进程（bt-install.sh）通过环境变量传入选好的版本；缺失时为空，待 detect_php_version 自行扫描填充
PHP_VERSION="${PHP_VERSION:-}"
PHP_CMD="${PHP_CMD:-}"
PHP_INI="${PHP_INI:-}"
NEED_MANUAL_ACTION=false
MANUAL_ACTIONS=()

# 检测并选择 PHP 版本。
# 从 php-requirements.json 读 php_min（缺失兜底 8.3.0），扫 /www/server/php/* 并用
# PHP 自身 version_compare 过滤，最后选最高版本。与 bt-install.sh::select_php_version 对齐，
# 但本函数是 noninteractive（被 bt-install.sh 通过子进程调用），多版本时静默选最高。
#
# 子进程语义：父进程（bt-install.sh）通过环境变量 PHP_VERSION/PHP_CMD 传递已交互选择的版本，
# 优先使用；缺失才走独立扫描（直接 bt-deps.sh 入口或测试时）。否则子进程独立选最高版本
# 会和父进程选择不一致（如父选 8.4，子重扫选最高 8.5）。
detect_php_version() {
    # 父进程已选好 PHP 版本时直接复用
    if [ -n "${PHP_VERSION:-}" ] && [ -x "/www/server/php/$PHP_VERSION/bin/php" ]; then
        PHP_CMD="${PHP_CMD:-/www/server/php/$PHP_VERSION/bin/php}"
        PHP_INI="/www/server/php/$PHP_VERSION/etc/php.ini"
        return 0
    fi

    local req_file="$SCRIPT_DIR/../php-requirements.json"
    local php_min
    php_min=$(_read_req_field "$req_file" "php_min" "8.3.0")

    local php_versions=()
    for ver_dir in /www/server/php/*; do
        [ -d "$ver_dir" ] || continue
        local php_bin="$ver_dir/bin/php"
        [ -x "$php_bin" ] || continue
        local actual
        actual=$("$php_bin" -r 'echo PHP_VERSION;' 2>/dev/null) || continue
        if "$php_bin" -r "exit(version_compare('$actual','$php_min','>=')?0:1);" 2>/dev/null; then
            php_versions+=("$(basename "$ver_dir")")
        fi
    done

    [ ${#php_versions[@]} -eq 0 ] && return 1

    # 多版本时按目录名数字倒序选最高
    if [ ${#php_versions[@]} -gt 1 ]; then
        readarray -t php_versions < <(printf '%s\n' "${php_versions[@]}" | sort -rn)
    fi
    PHP_VERSION="${php_versions[0]}"
    PHP_CMD="/www/server/php/$PHP_VERSION/bin/php"
    PHP_INI="/www/server/php/$PHP_VERSION/etc/php.ini"
    return 0
}

# 提取 ini 文件的 disable_functions 值（截断行尾注释 + 去空白和引号）
# 用法：_ini_disabled_functions <ini_file>
# stdout：函数名逗号分隔字符串（如 exec,shell_exec），无禁用或文件无该字段时输出空
_ini_disabled_functions() {
    grep -E "^disable_functions[[:space:]]*=" "$1" |
        sed -e 's/disable_functions[[:space:]]*=[[:space:]]*//' -e 's/[[:space:]]*;.*//' |
        tr -d ' "'
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
    local disabled_functions
    disabled_functions=$(_ini_disabled_functions "$ini_file")
    local new_disabled="$disabled_functions"

    # 移除指定的函数
    for func in $functions_str; do
        new_disabled=$(echo "$new_disabled" | sed "s/,$func,/,/g" | sed "s/^$func,//g" | sed "s/,$func$//g" | sed "s/^$func$//g")
    done

    # 更新配置文件（POSIX [[:space:]]，兼容 BusyBox sed）
    sed -i "s/^disable_functions[[:space:]]*=.*/disable_functions = $new_disabled/" "$ini_file"
}

# 检测禁用函数
check_disabled_functions() {
    log_step "检测 PHP 禁用函数"

    # 宝塔有 php.ini (FPM) 和 php-cli.ini (CLI)；部分用户自建 php-fpm.ini 也会写 disable_functions
    # 检测/修复须三者对齐（与 upgrade.sh::_php_env_run_checks 同），否则漏改某文件会永久阻断
    local php_ini="$PHP_INI"
    local php_cli_ini="/www/server/php/$PHP_VERSION/etc/php-cli.ini"
    local php_fpm_ini="/www/server/php/$PHP_VERSION/etc/php-fpm.ini"

    # 必需函数从 php-requirements.json 的 functions.required[] 读取（与版本绑定）
    # fallback 到内置兜底列表：putenv/proc_* 是 Composer/Laravel 运行所需；pcntl_* 是队列管理
    local req_file="$SCRIPT_DIR/../php-requirements.json"
    local required_functions=()
    local from_req
    from_req=$(_read_req_array "$req_file" "functions.required")
    if [ -n "$from_req" ]; then
        readarray -t required_functions <<<"$from_req"
    fi

    if [ ${#required_functions[@]} -eq 0 ]; then
        required_functions=("putenv" "proc_open" "proc_close" "proc_get_status" "proc_terminate" "exec" "shell_exec" "pcntl_signal" "pcntl_alarm" "pcntl_async_signals")
    fi

    # 检查三个配置文件中的禁用函数（合并集）
    local all_disabled=""
    if [ -f "$php_ini" ]; then
        all_disabled="$all_disabled,$(_ini_disabled_functions "$php_ini")"
    fi
    if [ -f "$php_cli_ini" ]; then
        all_disabled="$all_disabled,$(_ini_disabled_functions "$php_cli_ini")"
    fi
    if [ -f "$php_fpm_ini" ]; then
        all_disabled="$all_disabled,$(_ini_disabled_functions "$php_fpm_ini")"
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

    # 同时更新 php.ini、php-cli.ini、php-fpm.ini（与检测范围对齐）
    local functions_str="${functions_to_enable[*]}"

    if [ -f "$php_ini" ]; then
        enable_functions_in_ini "$php_ini" "$functions_str"
        log_info "已更新: php.ini"
    fi

    if [ -f "$php_cli_ini" ]; then
        enable_functions_in_ini "$php_cli_ini" "$functions_str"
        log_info "已更新: php-cli.ini"
    fi

    if [ -f "$php_fpm_ini" ]; then
        enable_functions_in_ini "$php_fpm_ini" "$functions_str"
        log_info "已更新: php-fpm.ini"
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
        # 项目最低要求 BT 11.5+，不再回落旧版 token 字段
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

# BT API 基础 URL 探测（协议自适应；BT 11.x 默认 http，启用面板 SSL 后改 https）
# 与 bt-automate.sh::_resolve_bt_api_base 对称；不复用是为了 bt-deps.sh 独立可跑
# 必须在 _resolve_bt_api_key 设置 BT_PANEL_PORT 之后调用
_resolve_bt_api_base() {
    if [ -n "${BT_API_BASE:-}" ]; then return 0; fi
    local proto status_line
    for proto in https http; do
        status_line=$(curl -ksI --connect-timeout 3 --max-time 3 "$proto://127.0.0.1:$BT_PANEL_PORT/" 2>/dev/null | head -1)
        if echo "$status_line" | grep -qE "^HTTP/"; then
            BT_API_BASE="$proto://127.0.0.1:$BT_PANEL_PORT"
            return 0
        fi
    done
    # 都失败兜底 https（让后续 curl 错误暴露具体问题）
    BT_API_BASE="https://127.0.0.1:$BT_PANEL_PORT"
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
    _resolve_bt_api_base

    local key_md5
    key_md5=$(printf %s "$BT_KEY" | md5sum | awk '{print $1}')
    local now
    now=$(date +%s)
    local token
    token=$(printf %s "${now}${key_md5}" | md5sum | awk '{print $1}')

    local resp curl_exit=0
    resp=$(curl -sk --show-error -X POST "${BT_API_BASE}/files?action=InstallSoft" \
        -d "request_time=${now}&request_token=${token}&name=${ext}&version=${PHP_VERSION}&type=1" \
        -m 30 2>&1) || curl_exit=$?

    if [ "$curl_exit" -ne 0 ]; then
        log_warning "BT API curl 失败 (exit=$curl_exit) url=${BT_API_BASE}/files?action=InstallSoft"
        [ -n "$resp" ] && log_warning "curl 输出: $(echo "$resp" | head -c 200)"
        return 1
    fi

    if ! echo "$resp" | grep -q '"status":[[:space:]]*true'; then
        # HTML 响应（404 / nginx 错误页）单独识别，避免多行 HTML 把日志撑爆
        # 已知触发场景：重装已卸载的扩展、BT 内部短时状态等；不下根因结论，fallback 会处理
        if echo "$resp" | grep -qi '<html'; then
            local title
            title=$(echo "$resp" | grep -oE '<title>[^<]+</title>' | sed -E 's|</?title>||g' | head -1)
            log_info "  BT API 装扩展未成功（${title:-HTML 错误页}），将走 fallback"
        else
            log_info "  BT API 装扩展未成功: $(echo "$resp" | tr -d '\n\r' | head -c 200)，将走 fallback"
        fi
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
# - 基础列表从 php-requirements.json 的 extensions.required[] 全量读取（与 check_php_extensions 同源）
# - extra 参数：补充清单之外的扩展（如 bt-install.sh 按数据库类型动态追加 pdo_mysql / pdo_pgsql）
# - 已加载的扩展自动 skip（循环内 $PHP_CMD -m 检查），无副作用
# - 优先用 BT 11.x API（/files?action=InstallSoft）
# - fallback 1: 老版 BT install.sh 路径（向后兼容）
# - fallback 2: 已编译 .so 直接 sed 启用 php.ini（PHP 内置扩展如 calendar 已随 BT 编译进 extension_dir，
#                BT API 不可用时直接 enable 即可生效）
# 失败的扩展回填 MANUAL_ACTIONS
# 注：redis 不在 required 清单（项目默认 CACHE_DRIVER=file；BT 11.x 装 phpredis 还要先装 igbinary 依赖链复杂）
auto_install_ext() {
    local extra_exts=("$@")

    if [ -z "$PHP_VERSION" ] || [ -z "$PHP_CMD" ]; then
        log_error "PHP 版本未检测，请先运行 detect_php_version"
        return 1
    fi

    # 从 php-requirements.json 读完整 required 清单（单一来源；不同 PHP 版本默认扩展不同，
    # 用清单驱动避免 hardcode 跨版本不准）
    local req_file="$SCRIPT_DIR/../php-requirements.json"
    local target_extensions=()
    local all_required
    all_required=$(_read_req_array "$req_file" "extensions.required")
    if [ -n "$all_required" ]; then
        readarray -t target_extensions <<<"$all_required"
    fi
    # 追加 extra（如 pdo_mysql；放后面让清单内的扩展先尝试）
    if [ ${#extra_exts[@]} -gt 0 ]; then
        target_extensions+=("${extra_exts[@]}")
    fi

    # 去重（extra 与 requirements.json 可能有重叠，去重避免日志噪音）
    local seen_exts="|"
    local dedup_ext=()
    for ext in "${target_extensions[@]}"; do
        [ -z "$ext" ] && continue
        case "$seen_exts" in
            *"|$ext|"*) ;;
            *)
                dedup_ext+=("$ext")
                seen_exts="$seen_exts$ext|"
                ;;
        esac
    done
    target_extensions=("${dedup_ext[@]}")

    if [ ${#target_extensions[@]} -eq 0 ]; then
        log_warning "php-requirements.json 不可读且未传入 extra 扩展，无目标"
        return 0
    fi

    # 先一次性查已加载模块，把 target_extensions 拆为待装 / 已装两组（避免 log_step 误列全量清单）
    local installed_modules
    installed_modules=$("$PHP_CMD" -m 2>/dev/null)
    local to_install=()
    local skipped_ext=()
    for ext in "${target_extensions[@]}"; do
        if echo "$installed_modules" | grep -qi "^$ext$"; then
            skipped_ext+=("$ext")
        else
            to_install+=("$ext")
        fi
    done

    if [ ${#to_install[@]} -eq 0 ]; then
        log_success "所有 required 扩展均已加载（共 ${#skipped_ext[@]} 项）"
        return 0
    fi

    log_step "尝试自动安装缺失的 PHP 扩展: ${to_install[*]}"
    [ ${#skipped_ext[@]} -gt 0 ] && log_info "已装跳过: ${skipped_ext[*]}"

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

    for ext in "${to_install[@]}"; do
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
            log_info "  → 检测到 ${ext}.so 已编译于 ${php_ext_dir}，启用 ini"
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

    # 已装跳过日志已在 log_step 之前打印（避免循环结束后重复列出）

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
        log_info "请到宝塔面板 → 软件商店 → PHP $(_php_pretty_version "$PHP_VERSION") → 设置 → 安装扩展 手工安装"
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
# 部署环境固定为 BT（宝塔面板）；BT 不同 PHP 版本默认编译的扩展不同（如 PHP 8.4 vs 8.3 默认集合可能变化），
# 故按 php-requirements.json 的 extensions.required[] 全量检测，不再 hardcode 排除/手工列表。
# 缺失的扩展统一引导用户运行 auto_install_ext（BT API 自动安装），失败时回落到宝塔面板手工安装。
check_php_extensions() {
    log_step "检测 PHP 扩展"

    local req_file="$SCRIPT_DIR/../php-requirements.json"
    local required_ext=()
    local all_required
    all_required=$(_read_req_array "$req_file" "extensions.required")
    if [ -n "$all_required" ]; then
        readarray -t required_ext <<<"$all_required"
    fi

    # fallback：requirements.json 缺失或解析失败时用兜底列表
    if [ ${#required_ext[@]} -eq 0 ]; then
        # pdo_mysql：Laravel 连 MySQL 必备；calendar：composer.json require ext-calendar
        # 不含 redis：项目默认 CACHE_DRIVER=file，redis 切到 redis driver 时再装
        required_ext=("gd" "zip" "bcmath" "pcntl" "intl" "fileinfo" "openssl" "mbstring" "curl" "xml" "calendar" "pdo_mysql")
    fi

    local missing_ext=()
    for ext in "${required_ext[@]}"; do
        if ! $PHP_CMD -m 2>/dev/null | grep -qi "^$ext$"; then
            missing_ext+=("$ext")
        fi
    done

    if [ ${#missing_ext[@]} -eq 0 ]; then
        log_success "所有必要扩展已安装"
        return 0
    fi

    log_warning "缺少扩展: ${missing_ext[*]}"
    NEED_MANUAL_ACTION=true
    MANUAL_ACTIONS+=("缺少 PHP 扩展，请运行: bash $SCRIPT_DIR/bt-deps.sh auto_install_ext")
    MANUAL_ACTIONS+=("  缺失列表: ${missing_ext[*]}")
    MANUAL_ACTIONS+=("自动安装失败的扩展请到宝塔面板 → 软件商店 → PHP $(_php_pretty_version "$PHP_VERSION") → 设置 → 安装扩展")

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
        log_error "未找到符合要求的 PHP 版本"
        log_info "请在宝塔面板安装 PHP（最低 php-requirements.json 中 php_min，默认 8.3.0）"
        exit 1
    fi
    log_success "PHP $(_php_pretty_version "$PHP_VERSION"): $($PHP_CMD -v | head -1)"

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

    # 末尾不再打"依赖检测完成"——父进程 bt-install.sh::check_dependencies 会统一打 [OK] 依赖检测完成
}

# 子命令派发
# - 不带参数：原 main 流程（检测 + 提示）
# - auto_install_ext：先 detect_php_version，再调 auto_install_ext
case "${1:-}" in
    auto_install_ext)
        # 子命令场景：父进程 bt-install.sh 已经打过 [STEP] 检测系统依赖 + PHP 版本，不重复输出
        # shift 把子命令名移出，剩余参数（extra 扩展，如 redis）原样转发给函数
        shift
        if ! detect_php_version; then
            log_error "未找到符合要求的 PHP 版本"
            exit 1
        fi
        auto_install_ext "$@"
        exit $?
        ;;
    enable_functions)
        # 子命令：从 disable_functions 移除指定函数（同时改 php.ini + php-cli.ini + php-fpm.ini）
        # 用法：PHP_VERSION=84 PHP_CMD=... bash bt-deps.sh enable_functions fn1 fn2 ...
        # 直接 sed ini 文件，绕过 BT API GetPHPConfig（在 CLI ini 单独配置时返回不准）
        # 修复范围须与 upgrade.sh::_php_env_run_checks 的检测范围（3 个 ini）对齐：
        # 用户自建 php-fpm.ini 禁用了函数时，漏改 php-fpm.ini 会导致检测到禁用但修复不覆盖 → 永久阻断
        shift
        if [ $# -eq 0 ]; then
            log_error "enable_functions 至少需要一个函数名"
            exit 1
        fi
        if ! detect_php_version; then
            log_error "未找到符合要求的 PHP 版本"
            exit 1
        fi
        functions_str="$*"
        log_step "解除 PHP 函数禁用: $functions_str"
        updated_any=false
        for ini_file in "/www/server/php/$PHP_VERSION/etc/php.ini" "/www/server/php/$PHP_VERSION/etc/php-cli.ini" "/www/server/php/$PHP_VERSION/etc/php-fpm.ini"; do
            [ -f "$ini_file" ] || continue
            enable_functions_in_ini "$ini_file" "$functions_str"
            log_info "  已更新: $(basename "$ini_file")"
            updated_any=true
        done
        if [ "$updated_any" = false ]; then
            log_warning "未找到 PHP ini 文件，跳过函数启用"
            exit 1
        fi
        log_success "函数启用完成（FPM 重启由调用方负责）"
        exit 0
        ;;
    "")
        main "$@"
        ;;
    *)
        echo "未知子命令: $1"
        echo "用法:"
        echo "  $0                              # 检测依赖（默认）"
        echo "  $0 auto_install_ext [ext...]    # 自动安装缺失 PHP 扩展"
        echo "  $0 enable_functions <fn...>     # 从 disable_functions 移除指定函数"
        exit 1
        ;;
esac
